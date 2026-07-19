/**
 * ProjectPlus — Kanban avançado (Etapa 7, Bloco 1 + 1.1 + 1.2 + 1.3).
 *
 * Board próprio do plugin, HTML/JS puro. Carregado GLOBALMENTE em toda
 * página do GLPI (setup.php), porque também é usado dentro da aba
 * "Kanban (ProjectPlus)" da ficha nativa do projeto (carregada via AJAX
 * pelo próprio GLPI) — não só na tela cheia `front/kanban.php`.
 *
 * ProjectPlusKanban.mount(rootId, dataId, opts) monta um "widget"
 * independente dentro do elemento #rootId (controles + board), lendo o
 * payload do elemento #dataId (script type="application/json"). Pode
 * haver mais de um widget na mesma página (ex.: várias abas), cada um
 * com seu próprio estado — nada é compartilhado em módulo.
 *
 * Bloco 1.2 (ajustes pós-validação): por padrão só aparecem, como
 * cartão, as tarefas de TOPO atribuídas DIRETAMENTE ao projeto da lane
 * (nada de subprojeto "vazando" pra lane do pai). Subprojeto e subtarefa
 * (tarefa mãe/filha) viram estruturas expansíveis por um botão "+",
 * mesmo espírito do "+"/"−" que a Visão geral já usa pra subprojeto —
 * mas 100% client-side aqui, já que todo o payload já chega de uma vez.
 *
 * Interações: alternar swimlane (Projeto / Responsável), expandir/
 * recolher subprojeto (lane) e subtarefa (dentro do cartão), mostrar/
 * ocultar concluídas, busca. Redesenha 100% no cliente ao trocar
 * qualquer controle — mesmo padrão do toggle Semana/Dia/Mês do burndown.
 *
 * SOMENTE LEITURA neste bloco: clique no cartão abre a ficha nativa da
 * tarefa em nova aba. Arrastar-e-soltar com persistência fica para o
 * Bloco 2.
 */
(function () {
    'use strict';

    const ProjectPlusKanban = {};

    // Compatibilidade com a tela cheia (front/kanban.php + kanban.html.twig)
    ProjectPlusKanban.init = function () {
        ProjectPlusKanban.mount('pp-kb-widget', 'pp-kb-data', { defaultLane: 'project' });
    };

    ProjectPlusKanban.mount = function (rootId, dataId, opts) {
        opts = opts || {};
        const root   = document.getElementById(rootId);
        const dataEl = document.getElementById(dataId);
        const holder = root ? root.querySelector('[data-role="board"]') : null;
        if (!root || !dataEl || !holder) {
            return;
        }

        let data = null;
        try {
            data = JSON.parse(dataEl.textContent);
        } catch (e) {
            data = null;
        }
        // Defesa da lição nº 9: payload ausente/errado não pode derrubar a tela
        if (!data || !Array.isArray(data.columns) || !Array.isArray(data.cards) || !data.lanes || !data.projects) {
            holder.innerHTML = '<p class="projectplus-muted">Não foi possível carregar o Kanban.</p>';
            return;
        }

        const state = {
            lane: opts.defaultLane === 'responsible' ? 'responsible' : 'project',
            showDone: false,
            query: '',
            data: data,
            expandedLanes: new Set(),  // ids de PROJETO cujos subprojetos estão visíveis
            expandedTasks: new Set(),  // ids de TAREFA cujas subtarefas estão visíveis
            _holder: holder,           // referência estável p/ handlers montados dentro dos cartões
        };

        const laneBtns = root.querySelectorAll('.pp-kb-seg .pp-seg__btn');
        laneBtns.forEach(function (btn) {
            btn.classList.toggle('pp-seg__btn--active', btn.dataset.ppLane === state.lane);
            btn.addEventListener('click', function () {
                laneBtns.forEach(function (b) { b.classList.remove('pp-seg__btn--active'); });
                btn.classList.add('pp-seg__btn--active');
                state.lane = btn.dataset.ppLane === 'responsible' ? 'responsible' : 'project';
                render(holder, state);
            });
        });

        const doneToggle = root.querySelector('[data-pp-role="done"]');
        if (doneToggle) {
            doneToggle.addEventListener('change', function () {
                state.showDone = doneToggle.checked;
                render(holder, state);
            });
        }

        const search = root.querySelector('[data-pp-role="search"]');
        if (search) {
            search.addEventListener('input', function () {
                state.query = search.value.trim().toLowerCase();
                render(holder, state);
            });
        }

        render(holder, state);
    };

    // ------------------------------------------------------------------
    // Árvore de projetos (lanes "Projeto"): a partir de data.projects
    // (mapa id -> {name, parent_id}), monta a lista ORDENADA e já filtrada
    // pelo estado de expansão atual (raízes sempre visíveis; subprojeto só
    // aparece se o pai estiver em state.expandedLanes).
    // ------------------------------------------------------------------
    function buildProjectLaneRows(projects, expandedLanes) {
        const byParent = {};
        Object.keys(projects).forEach(function (idStr) {
            const id = Number(idStr);
            const p  = projects[idStr];
            const key = String(p.parent_id);
            byParent[key] = byParent[key] || [];
            byParent[key].push({ id: id, name: p.name });
        });
        Object.keys(byParent).forEach(function (k) {
            byParent[k].sort(function (a, b) { return a.name.localeCompare(b.name); });
        });

        const rows = [];
        function walk(parentId, depth) {
            (byParent[String(parentId)] || []).forEach(function (node) {
                const hasChildren = !!byParent[String(node.id)];
                rows.push({ id: node.id, name: node.name, depth: depth, hasChildren: hasChildren });
                if (hasChildren && expandedLanes.has(node.id)) {
                    walk(node.id, depth + 1);
                }
            });
        }
        walk(0, 0);
        return rows;
    }

    function render(holder, state) {
        const data    = state.data;
        const columns = data.columns;

        // Só tarefas de TOPO (sem tarefa-mãe) viram cartão na grade —
        // subtarefa fica disponível em childrenOf p/ aninhar no cartão-mãe.
        const childrenOf = {};
        data.cards.forEach(function (c) {
            if (c.task_parent_id) {
                childrenOf[c.task_parent_id] = childrenOf[c.task_parent_id] || [];
                childrenOf[c.task_parent_id].push(c);
            }
        });

        const topLevel = data.cards.filter(function (c) { return !c.task_parent_id; });
        const q = state.query;
        const cards = topLevel.filter(function (c) {
            if (q === '') {
                // sem busca: só o filtro de concluídas
                return state.showDone || !c.is_done;
            }
            // com busca: o cartão de topo aparece se ELE OU qualquer
            // subtarefa (em qualquer nível) casar — assim "002" encontra a
            // subtarefa e traz junto o cartão-mãe que a contém (ponto do
            // usuário: subtarefa não entrava na busca).
            return subtreeMatchesQuery(c, q, childrenOf, state.showDone);
        });

        if (cards.length === 0) {
            holder.innerHTML = '<p class="projectplus-muted" style="padding:16px;">'
                + 'Nenhuma tarefa encontrada com os filtros atuais.</p>';
            return;
        }

        // linhas (lanes) + regra de agrupamento por modo
        let laneRows;
        let laneKey;
        if (state.lane === 'responsible') {
            laneRows = data.lanes.responsible.map(function (l) { return { id: l.id, name: l.name, depth: 0, hasChildren: false }; });
            laneKey  = 'responsible_id';
        } else {
            laneRows = buildProjectLaneRows(data.projects, state.expandedLanes);
            laneKey  = 'project_id';
        }

        const grouped = {};
        cards.forEach(function (c) {
            const lk = c[laneKey];
            grouped[lk] = grouped[lk] || {};
            grouped[lk][c.state_id] = grouped[lk][c.state_id] || [];
            grouped[lk][c.state_id].push(c);
        });

        // No modo Responsável, some com lane sem nenhum cartão (mesmo
        // comportamento de antes). No modo Projeto, TODA lane visível
        // (raiz ou expandida) permanece — é uma árvore de navegação, não
        // só uma lista de "quem tem cartão".
        const activeLanes = state.lane === 'responsible'
            ? laneRows.filter(function (l) { return !!grouped[l.id]; })
            : laneRows;

        const board = document.createElement('div');
        board.className = 'pp-kb-board';
        board.style.setProperty('--pp-kb-cols', String(columns.length));

        // linha de cabeçalho
        const headRow = document.createElement('div');
        headRow.className = 'pp-kb-row';
        const corner = document.createElement('div');
        corner.className = 'pp-kb-corner';
        corner.textContent = state.lane === 'responsible' ? 'Responsável' : 'Projeto';
        headRow.appendChild(corner);
        columns.forEach(function (col) {
            const h = document.createElement('div');
            h.className = 'pp-kb-col-head';
            h.style.setProperty('--pp-kb-color', col.color);
            h.textContent = col.name + ' (' + countInColumn(grouped, activeLanes, col.id) + ')';
            headRow.appendChild(h);
        });
        board.appendChild(headRow);

        if (activeLanes.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'pp-kb-empty';
            empty.textContent = 'Nenhuma tarefa encontrada com os filtros atuais.';
            board.appendChild(empty);
        }

        activeLanes.forEach(function (lane) {
            const row = document.createElement('div');
            row.className = 'pp-kb-row';

            const label = document.createElement('div');
            label.className = 'pp-kb-lane-label' + (lane.depth > 0 ? ' pp-kb-lane-label--nested' : '');
            label.style.paddingLeft = (12 + lane.depth * 18) + 'px';

            if (state.lane === 'project' && lane.hasChildren) {
                const toggle = document.createElement('button');
                toggle.type = 'button';
                toggle.className = 'pp-kb-lane-toggle';
                const isOpen = state.expandedLanes.has(lane.id);
                toggle.textContent = isOpen ? '−' : '+';
                toggle.title = isOpen ? 'Recolher subprojetos' : 'Mostrar subprojetos';
                toggle.addEventListener('click', function () {
                    if (state.expandedLanes.has(lane.id)) {
                        state.expandedLanes.delete(lane.id);
                    } else {
                        state.expandedLanes.add(lane.id);
                    }
                    render(holder, state);
                });
                label.appendChild(toggle);
            }
            const labelText = document.createElement('span');
            labelText.textContent = lane.name;
            labelText.title = lane.name;
            label.appendChild(labelText);
            row.appendChild(label);

            columns.forEach(function (col) {
                const cell = document.createElement('div');
                cell.className = 'pp-kb-col';
                const list = (grouped[lane.id] && grouped[lane.id][col.id]) || [];
                list.forEach(function (c) {
                    cell.appendChild(cardEl(c, state, childrenOf));
                });
                row.appendChild(cell);
            });

            board.appendChild(row);
        });

        holder.innerHTML = '';
        holder.appendChild(board);
    }

    function cardHay(c) {
        return (c.name + ' ' + c.project_name + ' ' + c.responsible_name).toLowerCase();
    }

    // A tarefa — ou alguma descendente, em qualquer nível — casa com a
    // busca? Respeita o filtro "mostrar concluídas" por nó (uma subtarefa
    // concluída só conta quando o filtro está ligado).
    function subtreeMatchesQuery(c, q, childrenOf, showDone) {
        if ((showDone || !c.is_done) && cardHay(c).indexOf(q) !== -1) {
            return true;
        }
        const kids = childrenOf[c.id] || [];
        for (let i = 0; i < kids.length; i++) {
            if (subtreeMatchesQuery(kids[i], q, childrenOf, showDone)) {
                return true;
            }
        }
        return false;
    }

    function countInColumn(grouped, lanes, colId) {
        let n = 0;
        lanes.forEach(function (l) {
            n += (grouped[l.id] && grouped[l.id][colId]) ? grouped[l.id][colId].length : 0;
        });
        return n;
    }

    function cardEl(c, state, childrenOf, nested) {
        const wrap = document.createElement('div');
        wrap.className = 'pp-kb-card-item'
            + (nested ? ' pp-kb-card-item--sub' : '')
            + (c.blocked ? ' pp-kb-card-item--blocked' : '')
            + (c.is_done ? ' pp-kb-card-item--done' : '');

        const a = document.createElement('a');
        a.href = c.url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.className = 'pp-kb-card-item__link';

        const name = document.createElement('span');
        name.className = 'pp-kb-card-item__name';
        name.textContent = (c.blocked ? '🔒 ' : '') + c.name;
        a.appendChild(name);

        // Linha de meta: no agrupamento por Projeto mostra o responsável;
        // no agrupamento por Responsável mostra o projeto direto da tarefa.
        const meta = document.createElement('span');
        meta.className = 'pp-kb-card-item__project';
        meta.textContent = state.lane === 'responsible' ? c.project_name : c.responsible_name;
        a.appendChild(meta);

        a.appendChild(deadlineEl(c.deadline));

        const badges = document.createElement('span');
        badges.className = 'pp-kb-card-item__badges';
        let html = c.percent + '%';
        if (c.comments > 0) {
            html += ' &nbsp;💬' + c.comments;
        }
        if (c.deps > 0) {
            html += ' &nbsp;🔗' + c.deps;
        }
        badges.innerHTML = html;
        a.appendChild(badges);

        wrap.appendChild(a);

        // Subtarefas (tarefa mãe/filha): aninhadas dentro do próprio
        // cartão, reveladas por "+N subtarefas" — não viram cartões
        // próprios na GRADE (evita brigar com o eixo de colunas por fase),
        // mas Bloco 1.3 (ponto 2 do usuário): a subtarefa expandida usa o
        // MESMO layout de cartão da tarefa (nome/meta/prazo/badges), só que
        // aninhada e recuada, em vez da antiga listinha "nome — %".
        const q = state.query;
        const allKids = childrenOf[c.id] || [];
        // Durante a busca, mostra só as subtarefas cuja subárvore casa (e o
        // ramo fica auto-expandido, pra o resultado aparecer); sem busca,
        // todas as filhas, com expandir/recolher manual.
        const kids = (q !== '')
            ? allKids.filter(function (k) { return subtreeMatchesQuery(k, q, childrenOf, state.showDone); })
            : allKids;

        if (kids.length > 0) {
            const isOpen = (q !== '') ? true : state.expandedTasks.has(c.id);

            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'pp-kb-card-item__tasks-toggle';
            toggle.textContent = (isOpen ? '− ' : '+ ') + kids.length + (kids.length === 1 ? ' subtarefa' : ' subtarefas');
            if (q !== '') {
                // durante a busca o ramo já vem aberto pra mostrar o
                // resultado — o botão vira só um rótulo (não recolhe).
                toggle.disabled = true;
            } else {
                toggle.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    if (state.expandedTasks.has(c.id)) {
                        state.expandedTasks.delete(c.id);
                    } else {
                        state.expandedTasks.add(c.id);
                    }
                    render(state._holder, state);
                });
            }
            wrap.appendChild(toggle);

            if (isOpen) {
                const sub = document.createElement('div');
                sub.className = 'pp-kb-card-item__subcards';
                kids.forEach(function (k) {
                    // reaproveita o próprio cardEl (marca nested = true), o
                    // que dá layout idêntico e ainda permite netos/bisnetos
                    // com o mesmo "+N subtarefas" recursivamente.
                    sub.appendChild(cardEl(k, state, childrenOf, true));
                });
                wrap.appendChild(sub);
            }
        }

        return wrap;
    }

    // Mesmo markup/classes do painel PHP (dashboard.html.twig), aqui
    // montado no cliente a partir do objeto Deadline::compute() embutido.
    function deadlineEl(d) {
        const wrap = document.createElement('span');
        wrap.style.display = 'block';
        wrap.style.margin = '6px 0';

        if (!d || d.state === 'done') {
            return wrap;
        }
        if (d.state === 'none') {
            wrap.innerHTML = '<div class="pp-deadline pp-deadline--none" '
                + 'title="Sem datas planejadas — corrija o planejamento">'
                + '<div class="pp-deadline__fill" style="width:0%"></div></div>';
            return wrap;
        }
        wrap.innerHTML = '<div class="pp-deadline"><div class="pp-deadline__fill pp-deadline__fill--'
            + d.state + '" style="width:' + d.display + '%"></div></div>'
            + '<span class="pp-deadline__label pp-deadline__label--' + d.state + '">' + d.label + '</span>';
        return wrap;
    }

    window.ProjectPlusKanban = ProjectPlusKanban;
})();

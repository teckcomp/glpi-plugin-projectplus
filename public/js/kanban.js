/**
 * ProjectPlus — Kanban avançado (Etapa 7, Bloco 1 + 1.1 + 1.2 + 1.3 + 2a).
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
 * Swimlanes: por Projeto (árvore de projeto raiz + subprojetos, com
 * "+"/"−" nas lanes) ou por Responsável. TODAS as tarefas aparecem como
 * cartão na grade, cada uma na sua raia e na coluna da fase dela — a
 * SUBTAREFA é um cartão comum, só marcada com a tag "Subtarefa da tarefa
 * …" (sem aninhar/esconder; decisão do usuário: menos regra = menos bug).
 *
 * Interações: alternar swimlane (Projeto / Responsável), expandir/recolher
 * subprojeto (lane), mostrar/ocultar concluídas, busca. Redesenha 100% no
 * cliente ao trocar qualquer controle.
 *
 * Bloco 2a: arrastar cartão entre COLUNAS muda a fase da tarefa (POST em
 * ajax/task.php action=kanban_move, token rotacionado; trava por
 * dependência p/ fase finalizada). Só quem pode editar (data-can-edit)
 * arrasta; clique no cartão abre a ficha nativa da tarefa em nova aba.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // i18n (Etapa 6, Bloco 3b)
    //
    // O dicionario vem do PHP em <script type="application/json" id="pp-i18n">
    // (src/I18nJs.php) e e lido por public/js/i18n.js. A chave e o proprio
    // texto em PT-BR: sem dicionario na pagina, __() devolve a chave e a tela
    // continua em portugues.
    // ------------------------------------------------------------------

    function __() {
        var i = window.ProjectPlusI18n;
        return i ? i.t.apply(i, arguments) : arguments[0];
    }

    function _n(singular, plural, n) {
        var i = window.ProjectPlusI18n;
        return i ? i.tn.apply(i, arguments) : (Number(n) === 1 ? singular : plural);
    }

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
            holder.innerHTML = '<p class="projectplus-muted">' +
                escapeText(__('Não foi possível carregar o Kanban.')) + '</p>';
            return;
        }

        const state = {
            lane: opts.defaultLane === 'responsible' ? 'responsible' : 'project',
            showDone: false,
            query: '',
            data: data,
            expandedLanes: new Set(),  // ids de PROJETO cujos subprojetos estão visíveis
            _holder: holder,           // referência estável p/ handlers de re-render
            // Bloco 2a — arrastar-e-soltar (mudar fase): endpoint + token
            // (rotacionado a cada resposta) + permissão de edição.
            ajaxUrl: root.dataset.ajaxUrl || null,
            csrf: root.dataset.csrf || null,
            canEdit: root.dataset.canEdit === '1',
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

        // Mapa id -> cartão (p/ achar o nome da mãe na tag da subtarefa).
        const byId = {};
        data.cards.forEach(function (c) { byId[c.id] = c; });
        const q = state.query;

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

        // TODOS os cartões são visíveis na grade (tarefa de topo E subtarefa),
        // cada um na SUA raia (por projeto/responsável) e na coluna da fase
        // dele. A subtarefa não é mais aninhada nem escondida — é um cartão
        // comum, só marcado com a TAG "Subtarefa da tarefa …" (decisão do
        // usuário: menos regra = menos bug). Filtro simples: concluídas +
        // busca por nome/projeto/responsável, cartão a cartão.
        const cards = data.cards.filter(function (c) {
            if (!state.showDone && c.is_done) { return false; }
            if (q !== '') {
                const hay = (c.name + ' ' + c.project_name + ' ' + c.responsible_name).toLowerCase();
                if (hay.indexOf(q) === -1) { return false; }
            }
            return true;
        });

        if (cards.length === 0) {
            holder.innerHTML = '<p class="projectplus-muted" style="padding:16px;">'
                + escapeText(__('Nenhuma tarefa encontrada com os filtros atuais.')) + '</p>';
            return;
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
        corner.textContent = state.lane === 'responsible' ? __('Responsável') : __('Projeto');
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
            empty.textContent = __('Nenhuma tarefa encontrada com os filtros atuais.');
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
                toggle.title = isOpen ? __('Recolher subprojetos') : __('Mostrar subprojetos');
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
                cell.dataset.stateId = String(col.id);
                cell.dataset.laneId = String(lane.id);
                if (state.canEdit) {
                    // Bloco 2a: soltar um cartão nesta célula move a tarefa
                    // para a fase (coluna) desta célula.
                    cell.addEventListener('dragover', function (e) {
                        e.preventDefault();
                        cell.classList.add('pp-kb-col--drop');
                    });
                    cell.addEventListener('dragleave', function () {
                        cell.classList.remove('pp-kb-col--drop');
                    });
                    cell.addEventListener('drop', function (e) {
                        e.preventDefault();
                        cell.classList.remove('pp-kb-col--drop');
                        const idStr  = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
                        const cardId = parseInt(idStr, 10);
                        if (cardId) {
                            moveCardPhase(state, cardId, col.id);
                        }
                    });
                }
                const list = (grouped[lane.id] && grouped[lane.id][col.id]) || [];
                list.forEach(function (c) {
                    cell.appendChild(cardEl(c, state, byId));
                });
                row.appendChild(cell);
            });

            board.appendChild(row);
        });

        holder.innerHTML = '';
        holder.appendChild(board);
    }

    // ------------------------------------------------------------------
    // Bloco 2a — persistência do arrastar (mudar fase), com rotação de
    // token (lição: endpoints AJAX devolvem 'csrf' novo a cada resposta).
    // ------------------------------------------------------------------
    function ppPost(url, data, onDone) {
        const body = new URLSearchParams();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        })
            .then(function (r) { return r.json(); })
            .then(function (json) { onDone(json || {}); })
            .catch(function () { onDone({ ok: false }); });
    }

    // Atualiza a fase de um cartão no payload em memória (usado após o
    // servidor confirmar a mudança). Retorna true se achou o cartão.
    function applyCardState(data, cardId, newStateId) {
        for (let i = 0; i < data.cards.length; i++) {
            if (data.cards[i].id === cardId) {
                data.cards[i].state_id = newStateId;
                return true;
            }
        }
        return false;
    }

    // Move a tarefa para a coluna/fase alvo: valida no cliente (mesmo
    // cartão/fase = no-op), chama o servidor e só reflete na tela DEPOIS
    // do "ok" (em erro, mostra a mensagem e mantém onde estava).
    function moveCardPhase(state, cardId, newStateId) {
        if (!state.canEdit || !state.ajaxUrl) {
            return;
        }
        let card = null;
        for (let i = 0; i < state.data.cards.length; i++) {
            if (state.data.cards[i].id === cardId) { card = state.data.cards[i]; break; }
        }
        if (!card || card.state_id === newStateId) {
            return;
        }
        ppPost(state.ajaxUrl, {
            action: 'kanban_move',
            task_id: cardId,
            projectstates_id: newStateId,
            _glpi_csrf_token: state.csrf,
        }, function (resp) {
            if (resp && resp.csrf) { state.csrf = resp.csrf; }
            if (resp && resp.ok) {
                applyCardState(state.data, cardId, newStateId);
            } else if (resp && resp.message) {
                window.alert(resp.message);
            }
            render(state._holder, state);
        });
    }

    function countInColumn(grouped, lanes, colId) {
        let n = 0;
        lanes.forEach(function (l) {
            n += (grouped[l.id] && grouped[l.id][colId]) ? grouped[l.id][colId].length : 0;
        });
        return n;
    }

    function cardEl(c, state, byId) {
        const isSub = !!c.task_parent_id;
        const wrap = document.createElement('div');
        wrap.className = 'pp-kb-card-item'
            + (isSub ? ' pp-kb-card-item--sub' : '')
            + (c.blocked ? ' pp-kb-card-item--blocked' : '')
            + (c.is_done ? ' pp-kb-card-item--done' : '');

        // Bloco 2a: TODO cartão (tarefa de topo E subtarefa) é arrastável
        // p/ quem pode editar — cada um é um cartão próprio na grade, então
        // arrastar a subtarefa não mexe mais na mãe. Muda só a fase.
        if (state.canEdit) {
            wrap.draggable = true;
            wrap.addEventListener('dragstart', function (e) {
                if (e.dataTransfer) {
                    e.dataTransfer.setData('text/plain', String(c.id));
                    e.dataTransfer.effectAllowed = 'move';
                }
                wrap.classList.add('pp-kb-card-item--dragging');
            });
            wrap.addEventListener('dragend', function () {
                wrap.classList.remove('pp-kb-card-item--dragging');
            });
        }

        const a = document.createElement('a');
        a.href = c.url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.className = 'pp-kb-card-item__link';
        // o link é draggable por padrão no HTML — desliga pra o arraste do
        // cartão não virar "arrastar o link".
        a.draggable = false;

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
        if (c.is_done) {
            // Selo de conclusão (pedido de 31/07/2026): além da cor do cartão,
            // um check textual explícito. Só texto TRADUZIDO passa por aqui.
            html += ' <span class="pp-kb-donebadge">✓ ' + escapeText(__('Concluída')) + '</span>';
        }
        if (c.comments > 0) {
            html += ' &nbsp;💬' + c.comments;
        }
        if (c.deps > 0) {
            html += ' &nbsp;🔗' + c.deps;
        }
        badges.innerHTML = html;
        a.appendChild(badges);

        wrap.appendChild(a);

        // Subtarefa: sem aninhar nem esconder — só uma TAG dizendo de qual
        // tarefa ela é filha (decisão do usuário). O nome da mãe vem do
        // payload (byId); se a mãe não estiver no payload, mostra "—".
        if (isSub) {
            const parent = byId[c.task_parent_id];
            const tag = document.createElement('div');
            tag.className = 'pp-kb-card-item__subtag';
            tag.textContent = __('Subtarefa da tarefa: %s', parent ? parent.name : '—');
            wrap.appendChild(tag);
        }

        return wrap;
    }

    // Escapa texto que entra em HTML/atributo montado por concatenacao.
    // So e usado com texto TRADUZIDO (o resto do cartao usa textContent).
    function escapeText(str) {
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
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
                + 'title="' + escapeText(__('Sem datas planejadas — corrija o planejamento')) + '">'
                + '<div class="pp-deadline__fill" style="width:0%"></div></div>';
            return wrap;
        }
        wrap.innerHTML = '<div class="pp-deadline"><div class="pp-deadline__fill pp-deadline__fill--'
            + d.state + '" style="width:' + d.display + '%"></div></div>'
            + '<span class="pp-deadline__label pp-deadline__label--' + d.state + '">' + d.label + '</span>';
        return wrap;
    }

    // Exposto só para os testes isolados (jsdom) do Bloco 2a.
    ProjectPlusKanban._test = { applyCardState: applyCardState };

    window.ProjectPlusKanban = ProjectPlusKanban;
})();

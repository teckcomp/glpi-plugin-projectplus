/**
 * ProjectPlus — Kanban de PROJETOS (Etapa 8, Bloco 4).
 *
 * Board somente-leitura do papel Cliente: colunas = fases dos projetos,
 * cartões = projetos e subprojetos. HTML/JS puro, sem dependência externa,
 * mesmas classes CSS do Kanban de tarefas (pp-kb-*).
 *
 * Diferenças propositais em relação a kanban.js:
 *  - SEM swimlanes (o board é uma grade de uma linha só por coluna);
 *  - subprojeto é cartão comum, marcado com a tag "Subprojeto de: <pai>"
 *    (mesmo tratamento da subtarefa no Kanban de tarefas).
 *
 * Ajuste 4b.2: quem PODE editar projeto arrasta o cartão entre as colunas
 * para mudar a fase (POST em ajax/project.php action=kanban_move, token
 * rotacionado a cada resposta). O Cliente continua somente leitura —
 * data-can-edit vem "0" e nem o arraste nem o endpoint respondem. A trava
 * de fase finalizada (projeto com tarefa/subprojeto aberto) é a MESMA da
 * ficha nativa e a recusa vem com a mensagem do servidor.
 *
 * Carregado só na tela front/projectkanban.php (não é global).
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

    // Interpola %s/%d na ordem dos argumentos — usada só no FALLBACK, quando
    // window.ProjectPlusI18n ainda não carregou. O i18n.js entra pelo hook
    // ADD_JAVASCRIPT do GLPI e executa DEPOIS do <script> inline do template;
    // sem esta interpolação, msgid com %s aparecia literal no primeiro
    // desenho (ex.: "Subtarefa da tarefa: %s" no Kanban — corrigido 31/07/2026).
    function ppFmt(text, args) {
        if (!args.length) { return String(text); }
        var i = 0;
        return String(text).replace(/%[sd%]/g, function (m) {
            if (m === '%%') { return '%'; }
            var v = args[i++];
            return (v === undefined || v === null) ? '' : String(v);
        });
    }

    function __(msgid) {
        var i = window.ProjectPlusI18n;
        if (i) { return i.t.apply(i, arguments); }
        return ppFmt(msgid, Array.prototype.slice.call(arguments, 1));
    }

    function _n(singular, plural, n) {
        var i = window.ProjectPlusI18n;
        if (i) { return i.tn.apply(i, arguments); }
        return ppFmt(Number(n) === 1 ? singular : plural,
                     Array.prototype.slice.call(arguments, 3));
    }

    const ProjectPlusProjectKanban = {};

    ProjectPlusProjectKanban.init = function () {
        ProjectPlusProjectKanban.mount('pp-pkb-widget', 'pp-pkb-data');
    };

    ProjectPlusProjectKanban.mount = function (rootId, dataId) {
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
        // Defesa da lição nº 9: payload ausente/errado não derruba a tela.
        if (!data || !Array.isArray(data.columns) || !Array.isArray(data.cards)) {
            holder.innerHTML = '<p class="projectplus-muted">' +
                escapeText(__('Não foi possível carregar o Kanban de projetos.')) + '</p>';
            return;
        }

        const state = {
            showDone: false,
            query: '',
            data: data,
            _holder: holder,                       // referência estável p/ re-render
            ajaxUrl: root.dataset.ajaxUrl || null, // ajax/project.php
            csrf: root.dataset.csrf || null,
            canEdit: root.dataset.canEdit === '1',
        };

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

    // Filtra os cartões pelo estado atual dos controles (concluídos/busca).
    function visibleCards(state) {
        const q = state.query;
        return state.data.cards.filter(function (c) {
            if (!state.showDone && c.is_done) { return false; }
            if (q !== '') {
                const hay = (c.name + ' ' + (c.parent_name || '') + ' ' + (c.manager || '')).toLowerCase();
                if (hay.indexOf(q) === -1) { return false; }
            }
            return true;
        });
    }

    function render(holder, state) {
        const columns = state.data.columns;
        const cards   = visibleCards(state);

        if (cards.length === 0) {
            holder.innerHTML = '<p class="projectplus-muted" style="padding:16px;">'
                + escapeText(__('Nenhum projeto encontrado com os filtros atuais.')) + '</p>';
            return;
        }

        const grouped = {};
        cards.forEach(function (c) {
            grouped[c.state_id] = grouped[c.state_id] || [];
            grouped[c.state_id].push(c);
        });

        const board = document.createElement('div');
        board.className = 'pp-kb-board pp-pkb-board';
        board.style.setProperty('--pp-kb-cols', String(columns.length));

        // Cabeçalho das colunas (fases) com a contagem de projetos.
        const headRow = document.createElement('div');
        headRow.className = 'pp-kb-row';
        const corner = document.createElement('div');
        corner.className = 'pp-kb-corner';
        corner.textContent = __('Fase');
        headRow.appendChild(corner);
        columns.forEach(function (col) {
            const h = document.createElement('div');
            h.className = 'pp-kb-col-head';
            h.style.setProperty('--pp-kb-color', col.color);
            h.textContent = col.name + ' (' + ((grouped[col.id] || []).length) + ')';
            headRow.appendChild(h);
        });
        board.appendChild(headRow);

        // Uma única linha de células (sem swimlanes).
        const row = document.createElement('div');
        row.className = 'pp-kb-row';
        const label = document.createElement('div');
        label.className = 'pp-kb-lane-label';
        label.style.paddingLeft = '12px';
        const labelText = document.createElement('span');
        labelText.textContent = __('Projetos');
        label.appendChild(labelText);
        row.appendChild(label);

        columns.forEach(function (col) {
            const cell = document.createElement('div');
            cell.className = 'pp-kb-col';
            cell.dataset.stateId = String(col.id);
            if (state.canEdit) {
                // Soltar um cartão aqui move o PROJETO para a fase desta coluna.
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
                    const idStr = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
                    const cardId = parseInt(idStr, 10);
                    if (cardId) {
                        moveProjectPhase(state, cardId, col.id);
                    }
                });
            }
            (grouped[col.id] || []).forEach(function (c) {
                cell.appendChild(cardEl(c, state));
            });
            row.appendChild(cell);
        });
        board.appendChild(row);

        holder.innerHTML = '';
        holder.appendChild(board);
    }

    function cardEl(c, state) {
        const isSub = !!c.parent_id;
        const wrap = document.createElement('div');
        wrap.className = 'pp-kb-card-item'
            + (isSub ? ' pp-kb-card-item--sub' : '')
            + (c.is_done ? ' pp-kb-card-item--done' : '');

        if (state && state.canEdit) {
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
        a.draggable = false;

        const name = document.createElement('span');
        name.className = 'pp-kb-card-item__name';
        name.textContent = c.name;
        a.appendChild(name);

        const meta = document.createElement('span');
        meta.className = 'pp-kb-card-item__project';
        meta.textContent = c.manager || '';
        a.appendChild(meta);

        a.appendChild(deadlineEl(c.deadline));

        const badges = document.createElement('span');
        badges.className = 'pp-kb-card-item__badges';
        let text = c.percent + '%';
        if (c.tasks_total > 0) {
            text += '  •  ' + c.tasks_done + '/' + c.tasks_total + ' ' + __('tarefas');
        }
        badges.textContent = text;
        a.appendChild(badges);

        wrap.appendChild(a);

        if (isSub) {
            const tag = document.createElement('div');
            tag.className = 'pp-kb-card-item__subtag';
            tag.textContent = __('Subprojeto de: %s', c.parent_name || '—');
            wrap.appendChild(tag);
        }

        return wrap;
    }

    // ------------------------------------------------------------------
    // Ajuste 4b.2 — persistência do arrastar (mudar fase do projeto), com
    // rotação de token: todo endpoint AJAX devolve 'csrf' novo na resposta.
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

    function applyCardState(data, cardId, newStateId) {
        for (let i = 0; i < data.cards.length; i++) {
            if (data.cards[i].id === cardId) {
                data.cards[i].state_id = newStateId;
                return true;
            }
        }
        return false;
    }

    // Sem update otimista: só reflete na tela DEPOIS do "ok" do servidor.
    // Em recusa (fase finalizada com item aberto), mostra a mensagem e o
    // cartão fica onde estava.
    function moveProjectPhase(state, cardId, newStateId) {
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
            project_id: cardId,
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

    // Escapa texto que entra em HTML/atributo montado por concatenacao.
    function escapeText(str) {
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Mesmo markup/classes da barra de prazo do painel (dashboard.html.twig),
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

    // Exposto só para os testes isolados (jsdom).
    ProjectPlusProjectKanban._test = { visibleCards: visibleCards, applyCardState: applyCardState };

    window.ProjectPlusProjectKanban = ProjectPlusProjectKanban;
})();

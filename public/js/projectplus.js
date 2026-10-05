/**
 * ProjectPlus — front-end (HTML/JS puro + SVG).
 *
 * - initDashboard(): gráficos, expansão de subprojetos e painel de tarefas
 * - Barra de prazo (Bloco 4-revisado): % do período planejado consumido,
 *   calculada no PHP (src/Deadline.php) e apenas renderizada aqui
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
    //
    // Os nomes sao __ e _n (como no PHP) de proposito: "t" colidiria com o
    // parametro `t` usado nos forEach de tarefa espalhados pelo arquivo.
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

    const ProjectPlus = {};

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Fases do conjunto de um TIPO de projeto (Etapa 9).
    //
    // O mapa vem do servidor em #pp-data (chave phases_by_type). Cascata
    // igual a do PHP (TypePhase::statesFor): conjunto do tipo -> conjunto
    // padrao (chave 0) -> todas as fases. Sem o mapa na pagina devolve a
    // lista completa, ou seja, o comportamento anterior a Etapa 9.
    // ------------------------------------------------------------------

    function phasesForType(typeId) {
        const map = (ppData && ppData.phases_by_type) ? ppData.phases_by_type : null;
        if (!map) {
            return Array.isArray(ppData.states) ? ppData.states : [];
        }
        const own = map[String(parseInt(typeId, 10) || 0)];
        if (Array.isArray(own) && own.length) {
            return own;
        }
        const def = map['0'];
        if (Array.isArray(def) && def.length) {
            return def;
        }
        return Array.isArray(ppData.states) ? ppData.states : [];
    }

    /**
     * Reescreve as opcoes de um <select> de fase, preservando o valor atual
     * quando ele ainda existe na lista nova. O valor 0 ("--") e sempre a
     * primeira opcao, para o campo continuar podendo ficar vazio.
     */
    function fillStateSelect(select, list, selectedId) {
        const keep = (selectedId != null) ? String(selectedId) : String(select.value || '0');
        let html = '<option value="0">\u2014</option>';
        let found = false;
        (Array.isArray(list) ? list : []).forEach(function (o) {
            const sel = (String(o.id) === keep);
            if (sel) { found = true; }
            html += '<option value="' + o.id + '"' + (sel ? ' selected' : '') + '>' +
                escapeHtml(o.name) + '</option>';
        });
        select.innerHTML = html;
        select.value = found ? keep : '0';
    }

    /**
     * Modal "Novo projeto": trocar o Tipo refaz a lista do campo Estado com
     * as fases do conjunto daquele tipo (Etapa 9).
     */
    function initNewProjectPhases(root) {
        const typeSel  = root.querySelector('[data-pp-role="np-type"]');
        const stateSel = root.querySelector('[data-pp-role="np-state"]');
        if (!typeSel || !stateSel) {
            return;
        }
        const apply = function () {
            fillStateSelect(stateSel, phasesForType(typeSel.value), null);
        };
        typeSel.addEventListener('change', apply);
        apply(); // ja abre coerente com o tipo pre-selecionado
    }


    // ------------------------------------------------------------------
    // Busca em dropdowns (rodada 31/07/2026).
    //
    // Transforma um <select class="pp-search"> num combobox com campo de
    // filtro. PROGRESSIVO: o select nativo continua no DOM (escondido),
    // e o valor do formulario, e dispara 'change' normal -- nenhum
    // consumidor muda. A lista e lida AO VIVO das <option> a cada
    // abertura, entao um select cujas opcoes sao refeitas por outro
    // codigo continua funcionando. Filtro ignora acentos.
    function enhanceSearchSelects(root) {
        (root || document).querySelectorAll('select.pp-search').forEach(function (sel) {
            if (sel.dataset.ppSearch) { return; }
            sel.dataset.ppSearch = '1';

            const wrap = document.createElement('div');
            wrap.className = 'pp-ss';
            sel.parentNode.insertBefore(wrap, sel);
            wrap.appendChild(sel);

            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'pp-ss__input';
            input.autocomplete = 'off';
            input.setAttribute('role', 'combobox');
            input.placeholder = __('Digite para buscar…');
            const list = document.createElement('div');
            list.className = 'pp-ss__list';
            list.hidden = true;
            wrap.appendChild(input);
            wrap.appendChild(list);

            function norm(t) {
                return String(t).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }
            function labelOf(opt) {
                return opt ? opt.textContent.replace(/\u00a0/g, ' ').trim() : '';
            }
            function currentLabel() {
                return labelOf(sel.options[sel.selectedIndex]);
            }
            function closeList() {
                list.hidden = true;
                input.value = currentLabel();
            }
            function pick(value, label) {
                sel.value = value;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
                input.value = label;
                list.hidden = true;
            }
            function rebuild(filter) {
                list.innerHTML = '';
                const f = norm(filter || '');
                let shown = 0;
                Array.prototype.forEach.call(sel.options, function (o) {
                    const label = labelOf(o);
                    if (f && norm(label).indexOf(f) === -1) { return; }
                    const item = document.createElement('div');
                    item.className = 'pp-ss__item' + (o.value === sel.value ? ' pp-ss__item--sel' : '');
                    item.textContent = label || '—';
                    item.dataset.value = o.value;
                    // mousedown: dispara ANTES do blur do input (click nao dispararia)
                    item.addEventListener('mousedown', function (ev) {
                        ev.preventDefault();
                        pick(o.value, label);
                    });
                    list.appendChild(item);
                    shown++;
                });
                if (shown === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'pp-ss__empty';
                    empty.textContent = __('Nenhum resultado');
                    list.appendChild(empty);
                }
                list.hidden = false;
            }

            input.value = currentLabel();
            input.addEventListener('focus', function () { input.select(); rebuild(''); });
            input.addEventListener('input', function () { rebuild(input.value); });
            input.addEventListener('blur', function () { closeList(); });
            input.addEventListener('keydown', function (ev) {
                const items = list.querySelectorAll('.pp-ss__item');
                let idx = -1;
                items.forEach(function (it, i) { if (it.classList.contains('pp-ss__item--act')) { idx = i; } });
                if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                    ev.preventDefault();
                    if (list.hidden) { rebuild(input.value); return; }
                    const next = ev.key === 'ArrowDown'
                        ? Math.min(idx + 1, items.length - 1) : Math.max(idx - 1, 0);
                    items.forEach(function (it) { it.classList.remove('pp-ss__item--act'); });
                    if (items[next]) {
                        items[next].classList.add('pp-ss__item--act');
                        items[next].scrollIntoView({ block: 'nearest' });
                    }
                } else if (ev.key === 'Enter') {
                    if (!list.hidden) {
                        const alvo = (idx >= 0 && items[idx]) ? items[idx]
                            : (items.length === 1 ? items[0] : null);
                        if (alvo) {
                            ev.preventDefault();
                            pick(alvo.dataset.value, alvo.textContent);
                        }
                    }
                } else if (ev.key === 'Escape') {
                    closeList();
                }
            });
            // mudanca programatica do select (reset de form etc.) atualiza o rotulo
            sel.addEventListener('change', function () {
                if (list.hidden) { input.value = currentLabel(); }
            });
        });
    }
    ProjectPlus.enhanceSearchSelects = enhanceSearchSelects;

    ProjectPlus.initDashboard = function () {
        const root = document.getElementById('projectplus-dashboard');
        if (!root) {
            return;
        }
        const ajaxUrl = root.dataset.ajaxUrl;

        initStatusChart();
        initTasksChart();
        initPhaseChart();
        initTaskStateChart();
        initExpandButtons(root, ajaxUrl);
        initTaskExpand(root, ajaxUrl);
        initModals();
        enhanceSearchSelects(document); // busca nos dropdowns do modal
        initTaskPanels(root);
        initNewProjectPhases(root); // Etapa 9: Estado segue o Tipo no modal
        initOpenTaskPanels(root);  // 💬/🔗 em "Tarefas em andamento" (Bloco 4)
        initTableSearch(root);     // busca nas tabelas (Bloco 4)
        initBell(root); // depois de initTaskPanels (que inicializa o ppCsrf)
        initFocusProject(root); // Bloco A: ?project=ID abre a tela do projeto
    };

    // ------------------------------------------------------------------
    // Foco em um projeto (Bloco A, 28/09/2026)
    //
    // Com ?project=ID o PHP já reduziu a lista a esse projeto. Aqui só
    // abrimos o painel de tarefas e destacamos a linha; com &task=ID
    // (cartao do Kanban de tarefas) destacamos também a tarefa, esperando
    // o fetch do painel terminar.
    // ------------------------------------------------------------------
    function initFocusProject(root) {
        const projectId = root.dataset.focusProject;
        if (!projectId || projectId === '0') {
            return;
        }

        const row = root.querySelector('tr.projectplus-row-item[data-project-id="' + projectId + '"]');
        if (row) {
            row.classList.add('pp-focus-row');
        }

        const btn = root.querySelector('[data-tasks-project="' + projectId + '"]');
        if (!btn) {
            return; // sem direito de Tarefas: fica só o destaque da linha
        }
        btn.click();

        const taskId = root.dataset.focusTask;
        if (!taskId || taskId === '0') {
            return;
        }

        // O painel chega por fetch; tenta por ~6s e desiste em silêncio.
        let tries = 0;
        const timer = setInterval(function () {
            const panel = root.querySelector('.projectplus-taskspanel');
            const tr = panel
                ? panel.querySelector('tr[data-task-id="' + taskId + '"]')
                : null;
            if (tr) {
                clearInterval(timer);
                // A-2 (29/09/2026): subtarefa nasce RECOLHIDA sob a mãe, então
                // o cartão do Kanban abria o projeto com a tarefa escondida.
                // Abre a cadeia de mães antes de destacar.
                expandAncestors(panel, tr);
                tr.classList.add('pp-focus-task');
                tr.scrollIntoView({ block: 'center' });
            } else if (++tries > 40) {
                clearInterval(timer);
            }
        }, 150);
    }

    /**
     * Expande as tarefas-mãe até a linha alvo ficar visível (Bloco A-2).
     *
     * As linhas estão em ordem de árvore com data-depth; a mãe é a primeira
     * linha ACIMA com profundidade menor. Clicar no +/− (.pp-subexp) usa o
     * mesmo caminho do usuário — o listener delegado registra a tarefa no
     * conjunto de abertas e recalcula a visibilidade do topo.
     */
    function expandAncestors(panel, tr) {
        let depth = parseInt(tr.dataset.depth, 10) || 0;
        let node = tr.previousElementSibling;

        while (node && depth > 0) {
            const id = node.dataset.taskId;
            const d = id ? (parseInt(node.dataset.depth, 10) || 0) : null;
            if (id && d < depth) {
                const btn = node.querySelector('.pp-subexp');
                if (btn && btn.textContent.trim() === '+') {
                    btn.click();
                }
                depth = d;
            }
            node = node.previousElementSibling;
        }
    }

    // ------------------------------------------------------------------
    // Busca nas tabelas "Projetos em andamento" e "Tarefas em andamento"
    // (Etapa 3, Bloco 4). Filtra as linhas de topo pelo texto; linhas
    // filhas e painéis expansíveis (💬/🔗/tarefas) seguem a visibilidade
    // da linha de topo à qual pertencem.
    // ------------------------------------------------------------------

    function initTableSearch(root) {
        root.querySelectorAll('.pp-tablesearch').forEach(function (inp) {
            const card = inp.closest('.projectplus-card');
            if (!card) { return; }
            const apply = function () {
                const q = inp.value.trim().toLowerCase();
                let show = true;
                card.querySelectorAll('table.projectplus-table > tbody > tr').forEach(function (row) {
                    const isTop = row.classList.contains('projectplus-row-item') ||
                        row.dataset.depth === '0';
                    if (isTop) {
                        show = q === '' || row.textContent.toLowerCase().indexOf(q) !== -1;
                    }
                    row.hidden = !show;
                });
            };
            inp.addEventListener('input', apply);
            inp.addEventListener('search', apply); // botão × do campo
        });
    }

    // 💬 e 🔗 nas linhas server-rendered de "Tarefas em andamento"
    function initOpenTaskPanels(root) {
        root.querySelectorAll('#tasks-table tr[data-task-id]').forEach(function (row) {
            bindOpenTaskRow(row);
        });
    }

    function bindOpenTaskRow(row) {
        const taskId = row.dataset.taskId;
        const cmt = row.querySelector('.pp-cmt-btn');
        if (cmt) {
            cmt.addEventListener('click', function () {
                toggleCommentPanel(row, taskId);
            });
        }
        const dep = row.querySelector('.pp-dep-btn');
        if (dep) {
            dep.addEventListener('click', function () {
                toggleDepPanel(row, taskId);
            });
        }
    }

    // ------------------------------------------------------------------
    // Expansão de subtarefas em "Tarefas em andamento" (Fix 2) — mesmo
    // comportamento da relação pai/filho de "Projetos em andamento",
    // com expansão recursiva (subtarefas de subtarefas).
    // ------------------------------------------------------------------

    function initTaskExpand(root, ajaxUrl) {
        root.querySelectorAll('.pp-taskexp').forEach(function (btn) {
            bindTaskExpand(btn, ajaxUrl);
        });
    }

    function bindTaskExpand(btn, ajaxUrl) {
        btn.addEventListener('click', function () {
            const row   = btn.closest('tr');
            const depth = parseInt(row.dataset.depth, 10) || 0;

            // Já expandido? Recolhe (remove tudo que estiver mais fundo,
            // inclusive painéis 💬/🔗 abertos nas linhas descendentes).
            if (btn.dataset.expanded === '1') {
                let next = row.nextElementSibling;
                while (next && ((parseInt(next.dataset.depth, 10) || 0) > depth ||
                       next.classList.contains('pp-cmt-row') || next.classList.contains('pp-dep-row'))) {
                    const rm = next;
                    next = next.nextElementSibling;
                    rm.remove();
                }
                btn.dataset.expanded = '0';
                btn.textContent = '+';
                return;
            }

            btn.disabled = true;
            // Fecha painéis 💬/🔗 desta linha antes de inserir as filhas
            // (mantém a ordem linha → filhas no DOM)
            let adj = row.nextElementSibling;
            while (adj && (adj.classList.contains('pp-cmt-row') || adj.classList.contains('pp-dep-row'))) {
                const rm = adj;
                adj = adj.nextElementSibling;
                rm.remove();
            }
            fetch(ajaxUrl + '?action=taskchildren&id=' + encodeURIComponent(btn.dataset.taskId), {
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (children) {
                    insertTaskChildren(row, children, depth + 1, ajaxUrl);
                    btn.dataset.expanded = '1';
                    btn.textContent = '−';
                })
                .catch(function () { /* silencioso; próxima tentativa recarrega */ })
                .finally(function () { btn.disabled = false; });
        });
    }

    function insertTaskChildren(parentRow, children, depth, ajaxUrl) {
        let anchor = parentRow;
        children.forEach(function (t) {
            const pct = parseInt(t.percent, 10) || 0;
            const tr  = document.createElement('tr');
            tr.className = 'projectplus-row--child' + (pct >= 100 ? ' pp-task-done' : '');
            tr.dataset.depth = String(depth);
            tr.dataset.taskId = String(t.id);

            tr.innerHTML =
                '<td class="projectplus-expand">' + (t.children > 0
                    ? '<button type="button" class="projectplus-expand__btn pp-taskexp"' +
                      ' title="' + escapeHtml(_n('%d subtarefa', '%d subtarefas', t.children, t.children)) +
                      '" data-task-id="' + t.id + '">+</button>'
                    : '') + '</td>' +
                '<td style="padding-left:' + (8 + depth * 18) + 'px">' +
                    '<span class="pp-task-branch">└</span> ' +
                    (t.blocked
                        ? '<span class="pp-dep-lock" title="' +
                          escapeHtml(__('Bloqueada por outra(s) tarefa(s) — veja 🔗')) + '">🔒</span> '
                        : '') +
                    '<a href="' + escapeHtml(t.url) + '" target="_blank">' + escapeHtml(t.name) + '</a>' +
                    (pct >= 100
                        ? ' <span class="pp-task-donemark" title="' + escapeHtml(__('Concluída')) + '">✓ ' + escapeHtml(__('Concluída')) + '</span>'
                        : '') + '</td>' +
                '<td>' + escapeHtml(t.project || '—') + '</td>' +
                '<td>' + (t.team && t.team.length
                    ? escapeHtml(t.team.join(', '))
                    : '<span class="projectplus-muted">—</span>') + '</td>' +
                '<td><div class="projectplus-progress">' +
                    '<div class="projectplus-progress__bar" style="width:' + pct + '%"></div></div>' +
                    '<span class="projectplus-progress__pct">' + pct + '%</span></td>' +
                '<td class="pp-phase-cell">' + phaseChip(t.state_name, t.state_color) + '</td>' +
                '<td class="pp-deadline-cell">' + deadlineCell(t.deadline) + '</td>' +
                '<td>' + (t.is_overdue
                    ? '<span class="projectplus-badge projectplus-badge--overdue">' + escapeHtml(t.end || '—') + '</span>'
                    : (t.end ? escapeHtml(t.end) : '—')) + '</td>' +
                '<td class="pp-dep-cell">' + depBtnHtml(t) + '</td>' +
                '<td class="pp-cmt-cell">' + commentBtnHtml(t) + '</td>';

            anchor.insertAdjacentElement('afterend', tr);

            const childBtn = tr.querySelector('.pp-taskexp');
            if (childBtn) {
                bindTaskExpand(childBtn, ajaxUrl);
            }
            bindOpenTaskRow(tr); // 💬/🔗 também nas subtarefas (Bloco 4)
            anchor = tr;
        });
    }

    // ------------------------------------------------------------------
    // "Minhas tarefas" (Etapa 3, Bloco 1)
    // ------------------------------------------------------------------

    ProjectPlus.initMyTasks = function () {
        const root = document.getElementById('projectplus-mytasks');
        if (!root) {
            return;
        }
        ppCsrf = root.dataset.csrf || null;
        ppDataUrl = root.dataset.ajaxUrl || null;
        ppCommentUrl = root.dataset.commentUrl || null;
        ppDepUrl = root.dataset.depUrl || null;
        const dataEl = document.getElementById('pp-data');
        if (dataEl) {
            try { ppData = JSON.parse(dataEl.textContent); } catch (e) { /* mantém default */ }
        }

        const ajaxUrl    = root.dataset.ajaxUrl;
        const taskUrl    = root.dataset.taskUrl;
        const groupsEl   = document.getElementById('pp-mt-groups');
        const doneToggle = document.getElementById('pp-mt-done');
        const projSel    = document.getElementById('pp-mt-project');
        const typeSel    = document.getElementById('pp-mt-type');
        let lastData     = null;
        // Bloco F-1b: ?project=ID[&task=ID] vindo de um link de projeto/tarefa
        // (quem não tem o Painel). Aplicado só na PRIMEIRA carga.
        let pendingProject = String(parseInt(root.dataset.initialProject, 10) || '');
        let pendingTask    = String(parseInt(root.dataset.initialTask, 10) || '');

        // Tipo escolhido: null = todos; 0 = "Sem tipo"
        function typeFilter() {
            if (!typeSel || typeSel.value === '') { return null; }
            const n = parseInt(typeSel.value, 10);
            return isNaN(n) ? null : n;
        }
        function projectsOfType(projects) {
            const tf = typeFilter();
            if (tf === null) { return projects; }
            return projects.filter(function (p) { return Number(p.type_id) === tf; });
        }

        function setKpi(id, value) {
            const el = document.getElementById(id);
            if (el) { el.textContent = String(value); }
        }

        // Filtro de projeto (Bloco C): a lista de opções vem do payload e é
        // refeita a cada carga; a escolha sobrevive ao "Mostrar concluídas".
        // Opções = projetos que o usuário enxerga (project_options, C-3)
        // UNIDOS aos projetos onde ele tem tarefa (projects) — assim nenhum
        // projeto com tarefa dele fica de fora. Ordem alfabética.
        function projectOptions(data) {
            const seen = {};
            const out = [];
            const add = function (p) {
                const id = Number(p.id);
                if (!id || seen[id]) { return; }
                seen[id] = true;
                out.push({ id: id, name: p.name, type_id: Number(p.type_id) || 0 });
            };
            (Array.isArray(data.project_options) ? data.project_options : []).forEach(add);
            (Array.isArray(data.projects) ? data.projects : []).forEach(add);
            out.sort(function (a, b) { return String(a.name).localeCompare(String(b.name), undefined, { sensitivity: 'base' }); });
            return out;
        }

        function fillProjectSelect(projects) {
            if (!projSel) { return; }
            projects = projectsOfType(projects); // projeto obedece ao tipo
            const keep = pendingProject || projSel.value || '0';
            pendingProject = '';
            const first = projSel.options[0]; // "Todos os projetos" (Twig)
            projSel.innerHTML = '';
            if (first) { projSel.appendChild(first); }
            projects.forEach(function (p) {
                const o = document.createElement('option');
                o.value = String(p.id);
                o.textContent = p.name || ('#' + p.id);
                projSel.appendChild(o);
            });
            const exists = Array.prototype.some.call(projSel.options, function (o) { return o.value === keep; });
            projSel.value = exists ? keep : '0';
            const input = projSel.parentNode && projSel.parentNode.querySelector('.pp-ss__input');
            if (input && document.activeElement !== input) {
                const opt = projSel.options[projSel.selectedIndex];
                input.value = opt ? opt.textContent.trim() : '';
            }
        }

        function refresh() {
            const done = (doneToggle && doneToggle.checked) ? '&done=1' : '';
            fetch(ajaxUrl + '?action=mytasks' + done, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(load)
                .catch(function () {
                    groupsEl.innerHTML = '<div class="projectplus-card">' +
                        '<p class="projectplus-muted">' +
                        escapeHtml(__('Erro ao carregar suas tarefas.')) + '</p></div>';
                });
        }

        function load(data) {
            lastData = data || {};
            fillProjectSelect(projectOptions(lastData));
            render();
        }

        function render() {
            const data = lastData || {};
            const pid = projSel ? parseInt(projSel.value, 10) || 0 : 0;
            const projects = Array.isArray(data.projects) ? data.projects : [];
            const proj = pid ? projects.filter(function (p) { return Number(p.id) === pid; })[0] : null;
            const tf = typeFilter();
            const ofType = projectsOfType(projects);

            // KPIs seguem o filtro (lição 129: todo consumidor do recorte):
            // projeto > soma dos projetos do tipo > total
            let kpis = data.kpis || {};
            if (pid) {
                // projeto sem tarefa minha (C-3): KPIs zerados
                kpis = (proj && proj.kpis) || { open: 0, overdue: 0, nodates: 0, done: 0 };
            } else if (tf !== null) {
                kpis = { open: 0, overdue: 0, nodates: 0, done: 0 };
                ofType.forEach(function (p) {
                    Object.keys(kpis).forEach(function (k) { kpis[k] += Number((p.kpis || {})[k]) || 0; });
                });
            }
            setKpi('pp-mt-kpi-open',    kpis.open    || 0);
            setKpi('pp-mt-kpi-overdue', kpis.overdue || 0);
            setKpi('pp-mt-kpi-nodates', kpis.nodates || 0);
            setKpi('pp-mt-kpi-done',    kpis.done    || 0);

            let groups = Array.isArray(data.groups) ? data.groups : [];
            if (pid) {
                groups = groups.filter(function (g) { return Number(g.project_id) === pid; });
            } else if (tf !== null) {
                groups = groups.filter(function (g) { return Number(g.project_type_id) === tf; });
            }
            if (groups.length === 0) {
                groupsEl.innerHTML = '<div class="projectplus-card">' +
                    '<p class="projectplus-muted">' + escapeHtml((doneToggle && doneToggle.checked)
                        ? __('Nenhuma tarefa atribuída a você.')
                        : __('Nenhuma tarefa atribuída a você em aberto.')) + '</p></div>';
                return;
            }

            groupsEl.innerHTML = groups.map(function (g) {
                const n = g.tasks.length;
                return '<div class="projectplus-card pp-mt-group">' +
                    '<div class="pp-report-projhead">' +
                        '<h3><a href="' + escapeHtml(g.project_url) + '">' + escapeHtml(g.project_name) + '</a></h3>' +
                        '<span class="projectplus-muted">' +
                            escapeHtml(_n('%d tarefa', '%d tarefas', n, n)) + '</span>' +
                    '</div>' +
                    taskTableHtml(g.tasks) +
                    '</div>';
            }).join('');

            groupsEl.querySelectorAll('.pp-mt-group').forEach(function (card) {
                bindTaskRows(card, taskUrl, refresh);
            });

            // Bloco F-1b: destaca a tarefa do link (mesma classe do painel)
            if (pendingTask) {
                const tr = groupsEl.querySelector('tr[data-task-id="' + pendingTask + '"]');
                pendingTask = '';
                if (tr) {
                    tr.classList.add('pp-focus-task');
                    if (tr.scrollIntoView) { tr.scrollIntoView({ block: 'center' }); }
                }
            }
        }

        if (doneToggle) {
            doneToggle.addEventListener('change', refresh);
        }
        if (projSel) {
            enhanceSearchSelects(projSel.parentNode);
            // trocar de projeto só recorta o que já veio — sem nova requisição
            projSel.addEventListener('change', function () {
                if (lastData) { render(); }
            });
        }
        if (typeSel) {
            // trocar o tipo refaz a lista de projetos (projeto fora do tipo
            // volta para "Todos os projetos") e recorta — sem requisição
            typeSel.addEventListener('change', function () {
                if (!lastData) { return; }
                fillProjectSelect(projectOptions(lastData));
                render();
            });
        }
        refresh();

        // Sino de alertas (mesma estrutura da Visão geral; o endpoint já
        // filtra por destinatário — só alertas do usuário logado)
        initBell(root);
    };

    // ------------------------------------------------------------------
    // Modelos de projeto — LISTA (Etapa 4)
    //
    // Listagem e formulários renderizados no servidor (POST tradicional,
    // sem AJAX própria). O JS só cuida dos modais (abrir/fechar via
    // initModals()) e de levar o id/nome do modelo clicado para dentro
    // do modal "Criar projeto a partir do modelo".
    // ------------------------------------------------------------------

    ProjectPlus.initTemplates = function () {
        const root = document.getElementById('projectplus-templates');
        if (!root) {
            return;
        }
        initModals();

        const idField   = document.getElementById('pp-inst-template-id');
        const nameField = document.getElementById('pp-inst-template-name');

        root.querySelectorAll('.pp-tpl-instantiate-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (idField)   { idField.value = btn.dataset.tplId || ''; }
                if (nameField) { nameField.textContent = btn.dataset.tplName || ''; }
            });
        });
    };

    // ------------------------------------------------------------------
    // Modelos de projeto — EDITOR VISUAL (Etapa 4, itens 1 e 2)
    //
    // Monta a árvore de tarefas e subprojetos no cliente. O DOM é a
    // fonte da verdade: adicionar/remover mexe direto nos nós; no submit
    // a árvore é serializada para JSON no formato de Templates/
    // TemplateCloner ({tasks:[...], subprojects:[...]}) e enviada em um
    // campo hidden. Offsets são em dias a partir do início do projeto.
    // ------------------------------------------------------------------

    // Listas dos dropdowns (estados, tipos de projeto/tarefa, usuários),
    // carregadas de #pp-tpl-refdata no init.
    let ppTplRef = { states: [], ptypes: [], ttypes: [], users: [], phases_by_type: {} };

    function tplOptions(list, selectedId) {
        let html = '<option value="0">—</option>';
        (Array.isArray(list) ? list : []).forEach(function (o) {
            html += '<option value="' + o.id + '"' + (o.id === selectedId ? ' selected' : '') +
                '>' + escapeHtml(o.name) + '</option>';
        });
        return html;
    }

    /**
     * Bloco D-1 (30/09/2026): campo de usuario (Gestor/Responsavel) do editor
     * de Modelos com BUSCA. E <div>, nao <label>: o combobox poe um input ao
     * lado do select escondido, e o label mandaria o clique para o select
     * oculto (mesma decisao dos filtros de Minhas tarefas). A classe do select
     * (pp-pm-user / pp-tpl-user) segue igual — o serialize nao muda.
     */
    function tplUserField(label, cls, selectedId) {
        return '<div class="pp-tpl-field pp-tpl-field--user"><span>' + escapeHtml(label) + '</span>' +
            '<select class="' + cls + ' pp-search">' + tplOptions(ppTplRef.users, selectedId) + '</select></div>';
    }

    /**
     * Bloco D-2b (01/10/2026): responsáveis da TAREFA do modelo em chips
     * (vários). Modelo antigo, com `users_id` único, abre como 1 chip. Id que
     * não está na lista de usuários ativos (ppTplRef.users) não vira chip —
     * mesmo comportamento do select de antes, que caía em "—".
     */
    function tplTaskUserIds(data) {
        let ids = Array.isArray(data.users_ids) ? data.users_ids : [];
        if (!ids.length && parseInt(data.users_id, 10) > 0) { ids = [data.users_id]; }
        const seen = {};
        return ids.map(function (x) { return parseInt(x, 10) || 0; }).filter(function (x) {
            if (x <= 0 || seen[x]) { return false; }
            seen[x] = true;
            return true;
        });
    }

    function tplTeamField(ids) {
        const byId = {};
        (ppTplRef.users || []).forEach(function (u) { byId[u.id] = u.name; });
        return '<div class="pp-tpl-field pp-tpl-field--user"><span>' + escapeHtml(__('Responsáveis')) + '</span>' +
            '<div class="pp-team pp-tpl-team">' +
            ids.filter(function (id) { return byId[id] !== undefined; }).map(function (id) {
                return teamChipHtml(id, byId[id]);
            }).join('') +
            teamAddBtnHtml() + '</div></div>';
    }

    /**
     * Editor de Modelos, Etapa 9 — de que TIPO de projeto e este campo
     * "Estado"?
     *
     * O tipo e o do projeto que ENVOLVE o campo: para um no de subprojeto (e
     * para as tarefas dentro dele) vale o Tipo do proprio subprojeto; fora de
     * qualquer subprojeto vale o Tipo do projeto raiz. `closest` resolve o
     * aninhamento sozinho, inclusive subprojeto dentro de subprojeto.
     */
    function tplTypeForStateSelect(sel) {
        const sub = sel.closest('.pp-tpl-node--project');
        if (sub) {
            const own = sub.querySelector(':scope > .pp-tpl-meta .pp-tpl-ptype');
            if (own) {
                return own.value;
            }
        }
        const meta = document.getElementById('pp-tpl-projectmeta');
        const rootType = meta ? meta.querySelector('.pp-pm-ptype') : null;
        return rootType ? rootType.value : 0;
    }

    /** Fases do conjunto de um tipo, no formato dos dropdowns do editor. */
    function tplPhasesForType(typeId) {
        const map = ppTplRef.phases_by_type || {};
        const own = map[String(parseInt(typeId, 10) || 0)];
        if (Array.isArray(own) && own.length) {
            return own;
        }
        const def = map['0'];
        if (Array.isArray(def) && def.length) {
            return def;
        }
        return Array.isArray(ppTplRef.states) ? ppTplRef.states : [];
    }

    function tplRefreshStateSelect(sel) {
        fillStateSelect(sel, tplPhasesForType(tplTypeForStateSelect(sel)), null);
    }

    function tplRefreshAllStateSelects(root) {
        root.querySelectorAll('.pp-pm-state, .pp-tpl-state').forEach(tplRefreshStateSelect);
    }

    /**
     * Liga o filtro "Estado segue o Tipo" no editor de Modelos.
     *
     * Os dois listeners sao DELEGADOS no elemento raiz de proposito: os nos
     * do editor (tarefa, subtarefa, subprojeto) sao criados dinamicamente em
     * varios pontos, e delegar dispensa lembrar de chamar o filtro em cada um
     * deles — que e exatamente o tipo de ponto que se esquece.
     *  - `change` num select de Tipo: refaz TODOS os selects de Estado;
     *  - `focusin` num select de Estado: refaz aquele select, garantindo que
     *    um no recem-criado tambem abra ja filtrado.
     */
    function initTplPhaseFilter(root) {
        root.addEventListener('change', function (ev) {
            const t = ev.target;
            if (t && t.classList &&
                (t.classList.contains('pp-pm-ptype') || t.classList.contains('pp-tpl-ptype'))) {
                tplRefreshAllStateSelects(root);
            }
        });
        root.addEventListener('focusin', function (ev) {
            const t = ev.target;
            if (t && t.classList &&
                (t.classList.contains('pp-pm-state') || t.classList.contains('pp-tpl-state'))) {
                tplRefreshStateSelect(t);
            }
        });
    }

    ProjectPlus.initTemplateEditor = function () {
        const root = document.getElementById('projectplus-tpleditor');
        if (!root) {
            return;
        }

        const treeEl      = document.getElementById('pp-tpl-tree');
        const rootTasksEl = document.getElementById('pp-tpl-roottasks');
        const rootSubsEl  = document.getElementById('pp-tpl-rootsubs');
        const projMetaEl  = document.getElementById('pp-tpl-projectmeta');
        const form        = document.getElementById('pp-tpl-form');
        const out         = document.getElementById('pp-tpl-structure-out');
        const nameInput   = document.getElementById('pp-tpl-name');

        // Listas dos dropdowns
        const refEl = document.getElementById('pp-tpl-refdata');
        if (refEl) {
            try {
                const rd = JSON.parse(refEl.textContent);
                if (rd && typeof rd === 'object') {
                    ppTplRef = {
                        states: Array.isArray(rd.states) ? rd.states : [],
                        ptypes: Array.isArray(rd.ptypes) ? rd.ptypes : [],
                        ttypes: Array.isArray(rd.ttypes) ? rd.ttypes : [],
                        users:  Array.isArray(rd.users)  ? rd.users  : [],
                        // Etapa 9: conjunto de fases por tipo de projeto
                        phases_by_type: (rd.phases_by_type && typeof rd.phases_by_type === 'object')
                            ? rd.phases_by_type : {}
                    };
                }
            } catch (e) { /* mantém vazio */ }
        }

        // Estrutura inicial (vazia no modo "criar")
        let structure = { project: {}, tasks: [], subprojects: [] };
        const dataEl = document.getElementById('pp-tpl-structure');
        if (dataEl) {
            try {
                const parsed = JSON.parse(dataEl.textContent);
                if (parsed && typeof parsed === 'object') {
                    structure.project = (parsed.project && typeof parsed.project === 'object') ? parsed.project : {};
                    structure.tasks = Array.isArray(parsed.tasks) ? parsed.tasks : [];
                    structure.subprojects = Array.isArray(parsed.subprojects) ? parsed.subprojects : [];
                }
            } catch (e) { /* mantém vazia */ }
        }

        buildProjectMeta(projMetaEl, structure.project);
        structure.tasks.forEach(function (t) { rootTasksEl.appendChild(buildTplTask(t)); });
        structure.subprojects.forEach(function (p) { rootSubsEl.appendChild(buildTplProject(p)); });

        // Etapa 9: "Estado" passa a listar só as fases do conjunto do Tipo.
        // Liga os listeners delegados e já filtra o que acabou de ser montado.
        initTplPhaseFilter(root);
        tplRefreshAllStateSelects(root);
        tplRefreshMoves(treeEl); // Bloco E

        // Botões de topo (raiz)
        const addRootTask = root.querySelector('[data-add-roottask]');
        const addRootSub  = root.querySelector('[data-add-rootsub]');
        if (addRootTask) {
            addRootTask.addEventListener('click', function () {
                rootTasksEl.appendChild(buildTplTask(null));
                tplRefreshMoves(treeEl);
            });
        }
        if (addRootSub) {
            addRootSub.addEventListener('click', function () {
                rootSubsEl.appendChild(buildTplProject(null));
                tplRefreshMoves(treeEl);
            });
        }

        // Delegação para os botões dinâmicos dentro da árvore
        treeEl.addEventListener('click', function (e) {
            // Bloco D-2b: chips de responsáveis da tarefa (só no cliente;
            // grava no submit, junto com o resto da estrutura)
            const tx = e.target.closest('.pp-tpl-team .pp-team__x');
            if (tx) {
                const chip = tx.closest('.pp-team__chip');
                if (chip) { chip.remove(); }
                return;
            }
            const tadd = e.target.closest('.pp-tpl-team .pp-team__add');
            if (tadd) {
                const box = tadd.closest('.pp-tpl-team');
                openTeamPicker(tadd, teamIds(box), function (uid, name) {
                    tadd.insertAdjacentHTML('beforebegin', teamChipHtml(uid, name));
                }, null, ppTplRef.users);
                return;
            }
            const btn = e.target.closest('button[data-act]');
            if (!btn) { return; }
            const act = btn.dataset.act;
            if (act === 'move-up' || act === 'move-down' || act === 'promote' || act === 'demote') {
                const mnode = btn.closest('.pp-tpl-node');
                if (mnode && tplMoveNode(mnode, act)) {
                    tplRefreshMoves(treeEl);
                    tplFlash(mnode);
                    // o botão clicado pode ter ficado desabilitado na posição nova
                    const again = mnode.querySelector(':scope > .pp-tpl-row > .pp-tpl-moves [data-act="' + act + '"]');
                    if (again && !again.disabled) { again.focus(); }
                }
                return;
            }
            if (act === 'remove') {
                const node = btn.closest('.pp-tpl-node');
                if (node) { node.remove(); }
            } else if (act === 'add-subtask') {
                const node = btn.closest('.pp-tpl-node--task');
                node.querySelector(':scope > .pp-tpl-children').appendChild(buildTplTask(null));
            } else if (act === 'add-ptask') {
                const node = btn.closest('.pp-tpl-node--project');
                node.querySelector(':scope > .pp-tpl-psection > .pp-tpl-ptasks').appendChild(buildTplTask(null));
            } else if (act === 'add-psub') {
                const node = btn.closest('.pp-tpl-node--project');
                node.querySelector(':scope > .pp-tpl-psection > .pp-tpl-psubs').appendChild(buildTplProject(null));
            }
            tplRefreshMoves(treeEl); // Bloco E: estado dos ↑↓/promover/rebaixar
        });

        form.addEventListener('submit', function (e) {
            const name = (nameInput.value || '').trim();
            if (!name) {
                e.preventDefault();
                alert(__('Informe o nome do modelo.'));
                nameInput.focus();
                return;
            }
            const s = {
                project: serializeProjectMeta(projMetaEl),
                tasks: serializeTplTasks(rootTasksEl),
                subprojects: serializeTplProjects(rootSubsEl)
            };
            if (s.tasks.length === 0 && s.subprojects.length === 0) {
                e.preventDefault();
                alert(__('Adicione ao menos uma tarefa ou subprojeto.'));
                return;
            }
            out.value = JSON.stringify(s);
            // deixa o form seguir (POST tradicional)
        });
    };

    // Bloco de atributos do projeto raiz (aplicados ao clonar)
    function buildProjectMeta(container, data) {
        data = data || {};
        const offset  = parseInt(data.offset_start_days, 10) || 0;
        const dur     = (data.duration_days != null) ? Math.max(1, data.duration_days) : 1;
        const stateId = parseInt(data.projectstates_id, 10) || 0;
        const ptypeId = parseInt(data.projecttypes_id, 10) || 0;
        const userId  = parseInt(data.users_id, 10) || 0;
        const budget  = parseFloat(data.budget) || 0;
        const auto    = !!data.auto_percent_done;

        container.innerHTML =
            '<div class="pp-tpl-meta">' +
                '<label class="pp-tpl-num" title="' + escapeHtml(__('Dias após a data de início escolhida ao criar')) + '">' + escapeHtml(__('início (d)')) + '<input type="number" class="pp-pm-offset" min="0" step="1" value="' + offset + '"></label>' +
                '<label class="pp-tpl-num" title="' + escapeHtml(__('Duração do projeto em dias (define a data de fim)')) + '">' + escapeHtml(__('duração (d)')) + '<input type="number" class="pp-pm-dur" min="1" step="1" value="' + dur + '"></label>' +
                '<label class="pp-tpl-field">' + escapeHtml(__('Fase')) + '<select class="pp-pm-state">' + tplOptions(ppTplRef.states, stateId) + '</select></label>' +
                '<label class="pp-tpl-field">' + escapeHtml(__('Tipo')) + '<select class="pp-pm-ptype">' + tplOptions(ppTplRef.ptypes, ptypeId) + '</select></label>' +
                tplUserField(__('Gestor'), 'pp-pm-user', userId) +
                '<label class="pp-tpl-field">' + escapeHtml(__('Orçamento (R$)')) + '<input type="number" class="pp-pm-budget" min="0" step="0.01" value="' + budget + '"></label>' +
                '<label class="pp-tpl-check"><input type="checkbox" class="pp-pm-auto"' + (auto ? ' checked' : '') + '> ' + escapeHtml(__('calcular automaticamente o %')) + '</label>' +
            '</div>' +
            '<textarea class="pp-pm-content" rows="2" placeholder="' + escapeHtml(__('Descrição do projeto (opcional)')) + '"></textarea>';
        container.querySelector('.pp-pm-content').value = data.content || '';
        enhanceSearchSelects(container); // D-1: busca no Gestor
    }

    function serializeProjectMeta(container) {
        return {
            offset_start_days: Math.max(0, parseInt(container.querySelector('.pp-pm-offset').value, 10) || 0),
            duration_days:     Math.max(1, parseInt(container.querySelector('.pp-pm-dur').value, 10) || 1),
            projectstates_id: parseInt(container.querySelector('.pp-pm-state').value, 10) || 0,
            projecttypes_id:  parseInt(container.querySelector('.pp-pm-ptype').value, 10) || 0,
            users_id:         parseInt(container.querySelector('.pp-pm-user').value, 10) || 0,
            budget:           Math.max(0, parseFloat(container.querySelector('.pp-pm-budget').value) || 0),
            auto_percent_done: container.querySelector('.pp-pm-auto').checked ? 1 : 0,
            content:          container.querySelector('.pp-pm-content').value || ''
        };
    }

    // ------------------------------------------------------------------
    // Bloco E (05/10/2026) — reorganizar a árvore do modelo sem refazer:
    // mover ↑↓ entre irmãs, Promover (sobe um nível, logo abaixo da antiga
    // mãe) e Rebaixar (vira a última filha da irmã de cima). O nó leva as
    // filhas junto. Tudo no cliente: as datas do modelo são relativas ao
    // início do PROJETO RAIZ em qualquer profundidade, então mudar o nó de
    // nível não altera datas. Cada lista da árvore só contém nós de um tipo
    // (tarefas OU subprojetos), então "irmã" = elemento vizinho na lista.
    // ------------------------------------------------------------------
    function tplMovesHtml() {
        return '<span class="pp-tpl-moves">' +
            '<button type="button" class="pp-tpl-mv" data-act="move-up" title="' + escapeHtml(__('Mover para cima')) + '"><i class="ti ti-arrow-up"></i></button>' +
            '<button type="button" class="pp-tpl-mv" data-act="move-down" title="' + escapeHtml(__('Mover para baixo')) + '"><i class="ti ti-arrow-down"></i></button>' +
            '<button type="button" class="pp-tpl-mv" data-act="promote" title="' + escapeHtml(__('Promover (subir um nível)')) + '"><i class="ti ti-indent-decrease"></i></button>' +
            '<button type="button" class="pp-tpl-mv" data-act="demote" title="' + escapeHtml(__('Rebaixar (para dentro do item de cima)')) + '"><i class="ti ti-indent-increase"></i></button>' +
            '</span>';
    }

    function tplIsTask(node) { return node.classList.contains('pp-tpl-node--task'); }

    function tplSibling(node, dir) {
        const kind = tplIsTask(node) ? 'pp-tpl-node--task' : 'pp-tpl-node--project';
        let el = dir < 0 ? node.previousElementSibling : node.nextElementSibling;
        while (el && !el.classList.contains(kind)) {
            el = dir < 0 ? el.previousElementSibling : el.nextElementSibling;
        }
        return el;
    }

    /** Nó "mãe" de onde se promove, ou null quando já está no nível mais alto possível. */
    function tplPromoteParent(node) {
        const list = node.parentElement;
        if (!list) { return null; }
        if (tplIsTask(node)) {
            // só subtarefa sobe: a lista é .pp-tpl-children de uma tarefa
            return list.classList.contains('pp-tpl-children')
                ? list.closest('.pp-tpl-node--task') : null;
        }
        // só subprojeto aninhado sobe: a lista é .pp-tpl-psubs de um subprojeto
        return list.classList.contains('pp-tpl-psubs')
            ? list.closest('.pp-tpl-node--project') : null;
    }

    /** Lista de filhas do nó (destino do Rebaixar). */
    function tplChildList(node) {
        return tplIsTask(node)
            ? node.querySelector(':scope > .pp-tpl-children')
            : node.querySelector(':scope > .pp-tpl-psection > .pp-tpl-psubs');
    }

    function tplNodeName(node) {
        const inp = node.querySelector(':scope > .pp-tpl-row > .pp-tpl-name');
        return inp ? (inp.value || '').trim() : '';
    }

    /**
     * Nome repetido na lista de destino. Ao criar o projeto, o TemplateCloner
     * descarta EM SILÊNCIO o segundo item com o mesmo nome sob o mesmo pai
     * (anti-duplicação) — então a mudança de nível é recusada aqui, com aviso.
     */
    function tplNameClash(node, destList) {
        const name = tplNodeName(node).toLowerCase();
        if (!name || !destList) { return ''; }
        const kind = tplIsTask(node) ? 'pp-tpl-node--task' : 'pp-tpl-node--project';
        let clash = '';
        Array.prototype.forEach.call(destList.children, function (el) {
            if (el !== node && el.classList.contains(kind) && tplNodeName(el).toLowerCase() === name) {
                clash = tplNodeName(node);
            }
        });
        return clash;
    }

    function tplFlash(node) {
        node.classList.remove('pp-tpl-node--moved');
        void node.offsetWidth; // reinicia a animação
        node.classList.add('pp-tpl-node--moved');
        setTimeout(function () { node.classList.remove('pp-tpl-node--moved'); }, 1300);
    }

    /** Aplica o movimento; devolve true quando a árvore mudou. */
    function tplMoveNode(node, act) {
        if (act === 'move-up' || act === 'move-down') {
            const sib = tplSibling(node, act === 'move-up' ? -1 : 1);
            if (!sib) { return false; }
            if (act === 'move-up') { sib.before(node); } else { sib.after(node); }
            return true;
        }
        let dest = null;
        let place = null;
        if (act === 'promote') {
            const mother = tplPromoteParent(node);
            if (!mother) { return false; }
            dest = mother.parentElement;
            place = function () { mother.after(node); };
        } else if (act === 'demote') {
            const above = tplSibling(node, -1);
            if (!above) { return false; }
            dest = tplChildList(above);
            place = function () { dest.appendChild(node); };
        } else {
            return false;
        }
        const clash = tplNameClash(node, dest);
        if (clash) {
            alert(__('Já existe um item com o nome %s nesse nível. Renomeie antes de mover — senão ele seria ignorado ao criar o projeto.', clash));
            return false;
        }
        place();
        return true;
    }

    /** Liga/desliga os botões conforme a posição de cada nó. */
    function tplRefreshMoves(root) {
        root.querySelectorAll('.pp-tpl-node').forEach(function (node) {
            const box = node.querySelector(':scope > .pp-tpl-row > .pp-tpl-moves');
            if (!box) { return; }
            const up = !!tplSibling(node, -1);
            box.querySelector('[data-act="move-up"]').disabled   = !up;
            box.querySelector('[data-act="move-down"]').disabled = !tplSibling(node, 1);
            box.querySelector('[data-act="promote"]').disabled   = !tplPromoteParent(node);
            box.querySelector('[data-act="demote"]').disabled    = !up;
        });
    }

    function buildTplTask(data) {
        data = data || {};
        const stateId     = parseInt(data.projectstates_id, 10) || 0;
        const ttypeId     = parseInt(data.projecttasktypes_id, 10) || 0;
        const hasChildren = Array.isArray(data.children) && data.children.length > 0;
        const auto        = (data.auto_percent_done != null) ? !!data.auto_percent_done : hasChildren;

        const el = document.createElement('div');
        el.className = 'pp-tpl-node pp-tpl-node--task';
        el.innerHTML =
            '<div class="pp-tpl-row">' +
                '<span class="pp-tpl-handle" title="' + escapeHtml(__('Tarefa')) + '"><i class="ti ti-list-check"></i></span>' +
                '<input type="text" class="pp-tpl-name" placeholder="' + escapeHtml(__('Nome da tarefa')) + '" maxlength="255">' +
                '<label class="pp-tpl-num">' + escapeHtml(__('início (d)')) + '<input type="number" class="pp-tpl-offset" min="0" step="1" value="0"></label>' +
                '<label class="pp-tpl-num">' + escapeHtml(__('duração (d)')) + '<input type="number" class="pp-tpl-dur" min="1" step="1" value="1"></label>' +
                tplMovesHtml() +
                '<button type="button" class="pp-tpl-mini" data-act="add-subtask">' + escapeHtml(__('+ subtarefa')) + '</button>' +
                '<button type="button" class="pp-tpl-mini pp-tpl-mini--danger" data-act="remove" title="' + escapeHtml(__('Remover')) + '">&times;</button>' +
            '</div>' +
            '<div class="pp-tpl-meta">' +
                '<label class="pp-tpl-field">' + escapeHtml(__('Fase')) + '<select class="pp-tpl-state">' + tplOptions(ppTplRef.states, stateId) + '</select></label>' +
                '<label class="pp-tpl-field">' + escapeHtml(__('Tipo')) + '<select class="pp-tpl-ttype">' + tplOptions(ppTplRef.ttypes, ttypeId) + '</select></label>' +
                tplTeamField(tplTaskUserIds(data)) +
                '<label class="pp-tpl-check"><input type="checkbox" class="pp-tpl-auto"' + (auto ? ' checked' : '') + '> ' + escapeHtml(__('calcular automaticamente o %')) + '</label>' +
            '</div>' +
            '<textarea class="pp-tpl-content" rows="1" placeholder="' + escapeHtml(__('Descrição (opcional)')) + '"></textarea>' +
            '<div class="pp-tpl-children"></div>';

        el.querySelector(':scope > .pp-tpl-row > .pp-tpl-name').value = data.name || '';
        el.querySelector(':scope > .pp-tpl-row .pp-tpl-offset').value =
            (data.offset_start_days != null) ? data.offset_start_days : 0;
        el.querySelector(':scope > .pp-tpl-row .pp-tpl-dur').value =
            (data.duration_days != null) ? Math.max(1, data.duration_days) : 1;
        el.querySelector(':scope > .pp-tpl-content').value = data.content || '';
        if (data.planned_duration != null) { el.dataset.plannedDuration = data.planned_duration; }

        const childrenC = el.querySelector(':scope > .pp-tpl-children');
        (Array.isArray(data.children) ? data.children : []).forEach(function (c) {
            childrenC.appendChild(buildTplTask(c));
        });
        enhanceSearchSelects(el); // D-1: busca no Responsavel (filhas ja vem prontas)
        return el;
    }

    function buildTplProject(data) {
        data = data || {};
        const stateId     = parseInt(data.projectstates_id, 10) || 0;
        const ptypeId     = parseInt(data.projecttypes_id, 10) || 0;
        const userId      = parseInt(data.users_id, 10) || 0;
        const budget      = parseFloat(data.budget) || 0;
        const hasChildren = (Array.isArray(data.tasks) && data.tasks.length > 0) ||
                            (Array.isArray(data.subprojects) && data.subprojects.length > 0);
        const auto        = (data.auto_percent_done != null) ? !!data.auto_percent_done : hasChildren;

        const el = document.createElement('div');
        el.className = 'pp-tpl-node pp-tpl-node--project';
        el.innerHTML =
            '<div class="pp-tpl-row">' +
                '<span class="pp-tpl-handle" title="' + escapeHtml(__('Subprojeto')) + '"><i class="ti ti-folder"></i></span>' +
                '<input type="text" class="pp-tpl-name" placeholder="' + escapeHtml(__('Nome do subprojeto')) + '" maxlength="255">' +
                '<label class="pp-tpl-num">' + escapeHtml(__('início (d)')) + '<input type="number" class="pp-tpl-offset" min="0" step="1" value="0"></label>' +
                '<label class="pp-tpl-num">' + escapeHtml(__('duração (d)')) + '<input type="number" class="pp-tpl-dur" min="1" step="1" value="1"></label>' +
                tplMovesHtml() +
                '<button type="button" class="pp-tpl-mini" data-act="add-ptask">' + escapeHtml(__('+ tarefa')) + '</button>' +
                '<button type="button" class="pp-tpl-mini" data-act="add-psub">' + escapeHtml(__('+ subprojeto')) + '</button>' +
                '<button type="button" class="pp-tpl-mini pp-tpl-mini--danger" data-act="remove" title="' + escapeHtml(__('Remover')) + '">&times;</button>' +
            '</div>' +
            '<div class="pp-tpl-meta">' +
                '<label class="pp-tpl-field">' + escapeHtml(__('Fase')) + '<select class="pp-tpl-state">' + tplOptions(ppTplRef.states, stateId) + '</select></label>' +
                '<label class="pp-tpl-field">' + escapeHtml(__('Tipo')) + '<select class="pp-tpl-ptype">' + tplOptions(ppTplRef.ptypes, ptypeId) + '</select></label>' +
                tplUserField(__('Gestor'), 'pp-tpl-user', userId) +
                '<label class="pp-tpl-field">' + escapeHtml(__('Orçamento (R$)')) + '<input type="number" class="pp-tpl-budget" min="0" step="0.01" value="' + budget + '"></label>' +
                '<label class="pp-tpl-check"><input type="checkbox" class="pp-tpl-auto"' + (auto ? ' checked' : '') + '> ' + escapeHtml(__('calcular automaticamente o %')) + '</label>' +
            '</div>' +
            '<textarea class="pp-tpl-content" rows="1" placeholder="' + escapeHtml(__('Descrição (opcional)')) + '"></textarea>' +
            '<div class="pp-tpl-psection"><div class="pp-tpl-plabel">' + escapeHtml(__('Tarefas')) + '</div><div class="pp-tpl-ptasks pp-tpl-list"></div></div>' +
            '<div class="pp-tpl-psection"><div class="pp-tpl-plabel">' + escapeHtml(__('Subprojetos')) + '</div><div class="pp-tpl-psubs pp-tpl-list"></div></div>';

        el.querySelector(':scope > .pp-tpl-row > .pp-tpl-name').value = data.name || '';
        el.querySelector(':scope > .pp-tpl-row .pp-tpl-offset').value =
            (data.offset_start_days != null) ? data.offset_start_days : 0;
        el.querySelector(':scope > .pp-tpl-row .pp-tpl-dur').value =
            (data.duration_days != null) ? Math.max(1, data.duration_days) : 1;
        el.querySelector(':scope > .pp-tpl-content').value = data.content || '';

        const ptasks = el.querySelector(':scope > .pp-tpl-psection > .pp-tpl-ptasks');
        (Array.isArray(data.tasks) ? data.tasks : []).forEach(function (t) {
            ptasks.appendChild(buildTplTask(t));
        });
        const psubs = el.querySelector(':scope > .pp-tpl-psection > .pp-tpl-psubs');
        (Array.isArray(data.subprojects) ? data.subprojects : []).forEach(function (p) {
            psubs.appendChild(buildTplProject(p));
        });
        enhanceSearchSelects(el); // D-1: busca no Gestor do subprojeto
        return el;
    }

    function serializeTplTasks(container) {
        const out = [];
        Array.prototype.forEach.call(container.children, function (el) {
            if (!el.classList || !el.classList.contains('pp-tpl-node--task')) { return; }
            const row  = el.querySelector(':scope > .pp-tpl-row');
            const meta = el.querySelector(':scope > .pp-tpl-meta');
            const name = (row.querySelector('.pp-tpl-name').value || '').trim();
            if (!name) { return; } // ignora nós sem nome
            const t = {
                name: name,
                content: el.querySelector(':scope > .pp-tpl-content').value || '',
                offset_start_days: Math.max(0, parseInt(row.querySelector('.pp-tpl-offset').value, 10) || 0),
                duration_days: Math.max(1, parseInt(row.querySelector('.pp-tpl-dur').value, 10) || 1),
                projectstates_id: parseInt(meta.querySelector('.pp-tpl-state').value, 10) || 0,
                projecttasktypes_id: parseInt(meta.querySelector('.pp-tpl-ttype').value, 10) || 0,
                // Bloco D-2b: vários responsáveis; users_id = o primeiro, para
                // quem ainda lê o formato antigo
                users_ids: teamIds(meta.querySelector('.pp-tpl-team')),
                users_id: teamIds(meta.querySelector('.pp-tpl-team'))[0] || 0,
                auto_percent_done: meta.querySelector('.pp-tpl-auto').checked ? 1 : 0
            };
            if (el.dataset.plannedDuration) {
                t.planned_duration = parseInt(el.dataset.plannedDuration, 10) || 0;
            }
            const ch = serializeTplTasks(el.querySelector(':scope > .pp-tpl-children'));
            if (ch.length) { t.children = ch; }
            out.push(t);
        });
        return out;
    }

    function serializeTplProjects(container) {
        const out = [];
        Array.prototype.forEach.call(container.children, function (el) {
            if (!el.classList || !el.classList.contains('pp-tpl-node--project')) { return; }
            const row  = el.querySelector(':scope > .pp-tpl-row');
            const meta = el.querySelector(':scope > .pp-tpl-meta');
            const name = (row.querySelector('.pp-tpl-name').value || '').trim();
            if (!name) { return; }
            const p = {
                name: name,
                content: el.querySelector(':scope > .pp-tpl-content').value || '',
                offset_start_days: Math.max(0, parseInt(row.querySelector('.pp-tpl-offset').value, 10) || 0),
                duration_days: Math.max(1, parseInt(row.querySelector('.pp-tpl-dur').value, 10) || 1),
                projectstates_id: parseInt(meta.querySelector('.pp-tpl-state').value, 10) || 0,
                projecttypes_id: parseInt(meta.querySelector('.pp-tpl-ptype').value, 10) || 0,
                users_id: parseInt(meta.querySelector('.pp-tpl-user').value, 10) || 0,
                budget: Math.max(0, parseFloat(meta.querySelector('.pp-tpl-budget').value) || 0),
                auto_percent_done: meta.querySelector('.pp-tpl-auto').checked ? 1 : 0,
                tasks: serializeTplTasks(el.querySelector(':scope > .pp-tpl-psection > .pp-tpl-ptasks')),
                subprojects: serializeTplProjects(el.querySelector(':scope > .pp-tpl-psection > .pp-tpl-psubs'))
            };
            out.push(p);
        });
        return out;
    }

    // ------------------------------------------------------------------
    // Sino de alertas (consome ajax/alerts.php)
    // ------------------------------------------------------------------

    function initBell(root) {
        const bell = document.getElementById('pp-bell');
        const panel = document.getElementById('pp-bell-panel');
        if (!bell || !panel) { return; }

        const alertsUrl = root.dataset.alertsUrl;
        const badge = bell.querySelector('.pp-bell__badge');
        const list = panel.querySelector('.pp-bell__list');

        function refresh() {
            fetch(alertsUrl + '?action=list', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(render)
                .catch(function () { /* silencioso; próxima abertura tenta de novo */ });
        }

        function bellItem(a, isRead) {
            return '<li data-alert-id="' + a.id + '"' + (isRead ? ' class="pp-bell__item--read"' : '') + '>' +
                '<div class="pp-bell__body">' +
                    '<span class="pp-bell__msg">' + escapeHtml(a.message || '') + '</span>' +
                    '<em>' + escapeHtml(formatDateTime(a.date_creation)) + '</em>' +
                '</div>' +
                (isRead ? ''
                    : '<button type="button" class="pp-bell__read" title="' +
                        escapeHtml(__('Marcar como lida')) + '">✓</button>') +
                '</li>';
        }

        function render(data) {
            const unread = (data && Array.isArray(data.unread)) ? data.unread : [];
            const read   = (data && Array.isArray(data.read))   ? data.read   : [];
            const n = unread.length;

            badge.hidden = n === 0;
            badge.textContent = n > 99 ? '99+' : String(n);

            let html = '';
            if (n === 0) {
                html += '<li class="pp-bell__empty">' +
                    escapeHtml(__('Nenhum alerta não lido')) + '</li>';
            } else {
                html += unread.map(function (a) { return bellItem(a, false); }).join('');
            }
            if (read.length > 0) {
                html += '<li class="pp-bell__section">' + escapeHtml(__('Lidas recentemente')) + '</li>' +
                    read.map(function (a) { return bellItem(a, true); }).join('');
            }
            list.innerHTML = html;

            list.querySelectorAll('.pp-bell__read').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    const li = btn.closest('li');
                    taskPost(alertsUrl, { action: 'read', id: li.dataset.alertId },
                        function () { refresh(); });
                });
            });
        }

        bell.addEventListener('click', function () {
            panel.hidden = !panel.hidden;
            if (!panel.hidden) { refresh(); }
        });

        const readAll = panel.querySelector('.pp-bell__readall');
        if (readAll) {
            readAll.addEventListener('click', function () {
                readAll.disabled = true;
                taskPost(alertsUrl, { action: 'read_all' }, function () {
                    readAll.disabled = false;
                    refresh();
                });
            });
        }

        // Fecha ao clicar fora
        document.addEventListener('click', function (e) {
            if (!panel.hidden && !panel.contains(e.target) && !bell.contains(e.target)) {
                panel.hidden = true;
            }
        });

        refresh(); // contador já carrega com a página
    }

    // ------------------------------------------------------------------
    // Painel de tarefas por projeto (Bloco 3)
    // ------------------------------------------------------------------

    let ppCsrf = null;
    let ppData = { states: [], users: [], current_user_id: 0, phases_by_type: {} };
    let ppDataUrl = null;      // ajax/dashboard_data.php (leituras)
    let ppCommentUrl = null;   // ajax/comment.php (Etapa 3, Bloco 2)
    let ppDepUrl = null;       // ajax/taskdep.php (Etapa 3, Bloco 3)
    let ppProjectUrl = null;   // ajax/project.php (Bloco D-3a, faixa "Projeto")

    function initTaskPanels(root) {
        ppCsrf = root.dataset.csrf || null;
        ppDataUrl = root.dataset.ajaxUrl || null;
        ppCommentUrl = root.dataset.commentUrl || null;
        ppDepUrl = root.dataset.depUrl || null;
        ppProjectUrl = root.dataset.projectUrl || null;
        const dataEl = document.getElementById('pp-data');
        if (dataEl) {
            try { ppData = JSON.parse(dataEl.textContent); } catch (e) { /* mantém default */ }
        }
        const taskUrl = root.dataset.taskUrl;
        const ajaxUrl = root.dataset.ajaxUrl;

        root.querySelectorAll('[data-tasks-project]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const projectId = btn.dataset.tasksProject;
                const row = btn.closest('tr');
                const existing = row.nextElementSibling;

                if (existing && existing.classList.contains('projectplus-taskspanel-row')) {
                    existing.remove(); // toggle: fecha
                    return;
                }
                openTaskPanel(row, projectId, ajaxUrl, taskUrl);
            });
        });
    }

    function openTaskPanel(projectRow, projectId, ajaxUrl, taskUrl) {
        const tr = document.createElement('tr');
        tr.className = 'projectplus-taskspanel-row';
        const td = document.createElement('td');
        // Bloco 4: o número de colunas da tabela de projetos varia com o
        // direito de Custos — usa a própria linha como referência.
        td.colSpan = projectRow.children.length || 9;
        td.innerHTML = '<div class="projectplus-taskspanel">' +
            escapeHtml(__('Carregando tarefas…')) + '</div>';
        tr.appendChild(td);
        projectRow.insertAdjacentElement('afterend', tr);

        loadTasks(td, projectId, ajaxUrl, taskUrl);
    }

    function loadTasks(container, projectId, ajaxUrl, taskUrl) {
        const getJson = function (url) {
            return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
        };
        // Bloco D-3a: faixa "Projeto" só para quem edita projeto; falha na
        // meta não derruba o painel (a faixa só não aparece).
        const metaP = (ppData.can_edit_project && ppProjectUrl)
            ? getJson(ajaxUrl + '?action=projectmeta&id=' + encodeURIComponent(projectId))
                .then(function (r) { return (r && r.ok) ? r.meta : null; })
                .catch(function () { return null; })
            : Promise.resolve(null);
        Promise.all([getJson(ajaxUrl + '?action=tasks&id=' + encodeURIComponent(projectId) + scopeQs()), metaP])
            .then(function (res) {
                const tasks = res[0];
                const meta  = res[1];
                container.innerHTML = renderTaskPanel(projectId, tasks, meta);
                bindTaskPanel(container, projectId, ajaxUrl, taskUrl, tasks);
                bindProjectStrip(container.querySelector('.pp-projstrip'));
                enhanceSearchSelects(container); // busca nos dropdowns da linha de criacao
            })
            .catch(function () {
                container.innerHTML = '<div class="projectplus-taskspanel">' +
                    escapeHtml(__('Erro ao carregar tarefas.')) + '</div>';
            });
    }

    // Bloco F-1 (05/10/2026): as leituras do painel repassam o "Ver só os
    // meus" da tela; sem isso o endpoint usaria o escopo PADRÃO do perfil.
    function scopeQs() {
        return (ppData && ppData.scope_mine) ? '&scope=mine' : '';
    }

    function renderTaskPanel(projectId, tasks, meta) {
        let html = '<div class="projectplus-taskspanel">';

        // Bloco D-3a: faixa "Projeto" (equipe, datas, fase, %, auto)
        if (meta) {
            html += '<div class="pp-projstrip" data-project-id="' + parseInt(meta.id, 10) + '">' +
                projectStripInner(meta) + '</div>';
        }

        // Formulário de nova tarefa
        html += '<div class="projectplus-newtask">' +
            '<input type="text" class="pp-nt-name" placeholder="' + escapeHtml(__('Nova tarefa…')) + '" maxlength="255">' +
            '<select class="pp-nt-parent pp-search"><option value="0">' + escapeHtml(__('Sem tarefa pai')) + '</option>' +
            tasks.filter(function (t) { return !t.context; }).map(function (t) {
                return '<option value="' + t.id + '">' + '&nbsp;'.repeat(t.depth * 2) + escapeHtml(t.name) + '</option>';
            }).join('') +
            '</select>' +
            // Bloco D-2a: vários responsáveis já na criação (chips + busca);
            // nasce com o usuário logado, como o select único fazia.
            newTaskTeamHtml() +
            '<input type="date" class="pp-nt-start" title="' + escapeHtml(__('Início planejado')) + '">' +
            '<input type="date" class="pp-nt-end" title="' + escapeHtml(__('Fim planejado')) + '">' +
            '<button type="button" class="projectplus-btn pp-nt-create">' + escapeHtml(__('Criar tarefa')) + '</button>' +
            '</div>';

        if (tasks.length === 0) {
            // Bloco F-1: fora do "Ver todos" a lista é só das MINHAS tarefas
            html += '<p class="projectplus-muted">' +
                escapeHtml(ppData.tasks_mine
                    ? __('Nenhuma tarefa atribuída a você.')
                    : __('Nenhuma tarefa neste projeto ainda.')) + '</p></div>';
            return html;
        }

        html += taskTableHtml(tasks, true) + '</div>';
        return html;
    }

    // Tabela de tarefas com edição inline — compartilhada entre o painel
    // por projeto e a tela "Minhas tarefas" (Etapa 3, Bloco 1).
    // Conta as subtarefas DIRETAS da tarefa na posição idx. A lista chega
    // achatada em ordem de árvore (depth-first), então os filhos diretos são
    // as linhas seguintes com depth == atual+1, até voltar a depth <= atual.
    function directChildren(tasks, idx) {
        const base = tasks[idx].depth;
        let n = 0;
        for (let i = idx + 1; i < tasks.length; i++) {
            if (tasks[i].depth <= base) { break; }
            if (tasks[i].depth === base + 1) { n++; }
        }
        return n;
    }

    // collapsible=true (painel do projeto na Visão geral): tarefa com
    // subtarefas ganha o botão +/− e nasce RECOLHIDA — mesmo padrão da
    // tabela "Tarefas em andamento" (pedido de 31/07/2026). "Minhas
    // tarefas" chama sem o flag e segue com a árvore sempre aberta.
    function taskTableHtml(tasks, collapsible) {
        let html = '<table class="projectplus-tasktable"><thead><tr>' +
            '<th>' + escapeHtml(__('Tarefa')) + '</th>' +
            '<th>' + escapeHtml(__('Responsáveis')) + '</th>' +
            '<th>' + escapeHtml(__('Início')) + '</th>' +
            '<th>' + escapeHtml(__('Fim')) + '</th>' +
            '<th>%</th>' +
            '<th>' + escapeHtml(__('Fase')) + '</th>' +
            '<th>' + escapeHtml(__('Prazo')) + '</th><th></th><th></th><th></th><th></th>' +
            '</tr></thead><tbody>';

        tasks.forEach(function (t, idx) {
            const stateOpts = ppData.states.map(function (s) {
                return '<option value="' + s.id + '"' + (s.id === t.state_id ? ' selected' : '') + '>' +
                    escapeHtml(s.name) + '</option>';
            }).join('');
            const kids = collapsible ? directChildren(tasks, idx) : 0;
            if (t.context) {
                // Bloco F-1: mãe que não é do usuário — só o nome, sem link
                // nem ações. Mesmas 11 células da linha normal (lição 36).
                html += '<tr class="pp-task-context" data-task-id="' + t.id + '" data-depth="' + t.depth + '" data-context="1">' +
                    '<td style="padding-left:' + (10 + t.depth * 22) + 'px">' +
                        (kids > 0
                            ? '<button type="button" class="projectplus-expand__btn pp-subexp" title="' +
                              escapeHtml(_n('%d subtarefa', '%d subtarefas', kids, kids)) + '">+</button> '
                            : '') +
                        (t.depth > 0 ? '<span class="pp-task-branch">└</span> ' : '') +
                        '<span class="pp-task-ctxname" title="' + escapeHtml(__('Tarefa mãe')) + '">' +
                        escapeHtml(t.name) + '</span></td>' +
                    '<td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>' +
                    '</tr>';
                return;
            }
            html += '<tr data-task-id="' + t.id + '" data-depth="' + t.depth + '" class="' + (t.percent >= 100 ? 'pp-task-done' : '') + '">' +
                '<td style="padding-left:' + (10 + t.depth * 22) + 'px">' +
                    (kids > 0
                        ? '<button type="button" class="projectplus-expand__btn pp-subexp" title="' +
                          escapeHtml(_n('%d subtarefa', '%d subtarefas', kids, kids)) + '">+</button> '
                        : '') +
                    (t.depth > 0 ? '<span class="pp-task-branch">└</span> ' : '') +
                    (t.depth === 0 && t.parent_name
                        ? '<span class="pp-task-parent" title="' + escapeHtml(__('Tarefa mãe')) + '">' + escapeHtml(t.parent_name) + ' › </span>'
                        : '') +
                    (t.blocked
                        ? '<span class="pp-dep-lock" title="' +
                          escapeHtml(__('Bloqueada por outra(s) tarefa(s) — veja 🔗')) + '">🔒</span> '
                        : '') +
                    '<a href="' + escapeHtml(t.url) + '" target="_blank">' + escapeHtml(t.name) + '</a></td>' +
                '<td class="pp-team-cell">' + teamCellHtml(t) + '</td>' +
                '<td><input type="date" class="pp-task-start" value="' + (t.start_iso || '') + '"></td>' +
                '<td><input type="date" class="pp-task-end" value="' + (t.end_iso || '') + '"></td>' +
                '<td><input type="number" class="pp-task-percent" min="0" max="100" value="' + t.percent + '"' +
                    (t.auto_percent
                        ? ' disabled title="' + escapeHtml(__('Cálculo automático a partir das subtarefas')) + '"'
                        : '') + '></td>' +
                '<td class="pp-state-cell"><span class="pp-phase-dot" style="background:' + stateColor(t.state_id) + '"></span>' +
                    '<select class="pp-task-state"><option value="0">—</option>' + stateOpts + '</select></td>' +
                '<td class="pp-deadline-cell">' + deadlineCell(t.deadline) + '</td>' +
                '<td class="pp-auto-cell">' + autoSwitchHtml(t) + '</td>' +
                '<td class="pp-dep-cell">' + depBtnHtml(t) + '</td>' +
                '<td class="pp-cmt-cell">' + commentBtnHtml(t) + '</td>' +
                '<td>' + (t.percent >= 100
                    ? '<span class="pp-task-donemark" title="' + escapeHtml(__('Concluída')) + '">✓ ' + escapeHtml(__('Concluída')) + '</span>'
                    : (!t.auto_percent
                        ? '<button type="button" class="pp-task-complete" title="' + escapeHtml(__('Concluir')) + '">✓</button>'
                        : '')) + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        return html;
    }

    // ------------------------------------------------------------------
    // Responsáveis em chips (Bloco D-2a, 30/09/2026)
    //
    // Equipe da tarefa = tabela nativa glpi_projecttaskteams (só USUÁRIOS
    // aparecem; grupo cadastrado na ficha nativa não é mostrado nem tocado).
    // Editar é só do gestor (ppData.can_team, conferido de novo no servidor
    // por tarefa). Sem o direito, a célula segue só texto, como antes.
    // ------------------------------------------------------------------
    // removeLabel/addLabel: Bloco D-3a reaproveita os chips na Equipe do
    // projeto ("Remover da equipe"/"Adicionar à equipe"); sem rótulo, são
    // os textos de responsável de tarefa.
    function teamChipHtml(id, name, removeLabel) {
        const lbl = removeLabel || __('Remover responsável');
        return '<span class="pp-team__chip" data-uid="' + parseInt(id, 10) + '">' + escapeHtml(name) +
            '<button type="button" class="pp-team__x" data-uid="' + parseInt(id, 10) + '" title="' +
            escapeHtml(lbl) + '" aria-label="' + escapeHtml(lbl) + '">&times;</button></span>';
    }

    function teamAddBtnHtml(addLabel) {
        const lbl = addLabel || __('Adicionar responsável');
        return '<button type="button" class="pp-team__add" title="' + escapeHtml(lbl) +
            '" aria-label="' + escapeHtml(lbl) + '">+</button>';
    }

    function teamEmptyHtml(hidden) {
        return '<span class="pp-team__empty projectplus-muted"' + (hidden ? ' hidden' : '') + '>' +
            escapeHtml(__('Sem responsável')) + '</span>';
    }

    function teamCellHtml(t) {
        const list = Array.isArray(t.team_users) ? t.team_users : null;
        if (!ppData.can_team || !list) {
            const names = Array.isArray(t.team) ? t.team : [];
            return names.length ? escapeHtml(names.join(', ')) : '<span class="projectplus-muted">—</span>';
        }
        return '<div class="pp-team pp-team--edit">' +
            list.map(function (u) { return teamChipHtml(u.id, u.name); }).join('') +
            teamAddBtnHtml() + '</div>';
    }

    function newTaskTeamHtml() {
        const me = (Array.isArray(ppData.users) ? ppData.users : []).filter(function (u) {
            return u.id === ppData.current_user_id;
        })[0];
        return '<div class="pp-team pp-nt-team" title="' + escapeHtml(__('Responsáveis')) + '">' +
            teamEmptyHtml(!!me) +
            (me ? teamChipHtml(me.id, me.name) : '') +
            teamAddBtnHtml() + '</div>';
    }

    function teamIds(box) {
        if (!box) { return []; }
        return Array.prototype.map.call(box.querySelectorAll('.pp-team__chip'), function (c) {
            return parseInt(c.dataset.uid, 10);
        }).filter(function (n) { return n > 0; });
    }

    function refreshTeamEmpty(box) {
        const empty = box.querySelector('.pp-team__empty');
        if (empty) { empty.hidden = teamIds(box).length > 0; }
    }

    // Abre, no lugar do "+", um combobox de busca (pp-search) com os
    // usuários que ainda NÃO estão na equipe. Escolher chama onPick(id,
    // nome); sair sem escolher (blur/Esc) devolve o "+".
    function openTeamPicker(addBtn, excludeIds, onPick, placeholder, userList) {
        if (addBtn.hidden || addBtn.disabled) { return; }
        const skip = {};
        (excludeIds || []).forEach(function (id) { skip[id] = true; });

        const sel = document.createElement('select');
        sel.className = 'pp-search';
        sel.innerHTML = '<option value="">' + escapeHtml(placeholder || __('Adicionar responsável')) + '</option>' +
            (Array.isArray(userList) ? userList : (Array.isArray(ppData.users) ? ppData.users : [])).filter(function (u) {
                return !skip[u.id];
            }).map(function (u) {
                return '<option value="' + u.id + '">' + escapeHtml(u.name) + '</option>';
            }).join('');

        const box = document.createElement('span');
        box.className = 'pp-team__picker';
        box.appendChild(sel);
        addBtn.hidden = true;
        addBtn.insertAdjacentElement('afterend', box);
        enhanceSearchSelects(box);

        let done = false;
        const close = function () {
            if (box.parentNode) { box.parentNode.removeChild(box); }
            addBtn.hidden = false;
        };
        const input = box.querySelector('.pp-ss__input');
        sel.addEventListener('change', function () {
            const uid = parseInt(sel.value, 10);
            if (done || !(uid > 0)) { return; }
            done = true;
            const label = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].textContent : '';
            close();
            onPick(uid, label);
        });
        if (input) {
            input.addEventListener('blur', function () {
                setTimeout(function () { if (!done) { done = true; close(); } }, 0);
            });
            input.addEventListener('keydown', function (ev) {
                if (ev.key === 'Escape') { done = true; close(); }
            });
            input.value = '';
            input.focus();
        }
    }

    // Interruptor "Calcular automaticamente" (auto_percent_done nativo),
    // o mesmo da ficha do GLPI: ligado, o % vira a média das subtarefas e
    // o campo trava; desligado, volta a ser editável. Só aparece em tarefa
    // com subtarefa — ou já ligada, para poder desligar.
    function autoSwitchHtml(t) {
        if (!t.has_children && !t.auto_percent) { return ''; }
        const tip = t.auto_percent
            ? __('Cálculo automático a partir das subtarefas')
            : __('Calcular automaticamente a partir das subtarefas');
        return '<label class="pp-auto-switch" title="' + escapeHtml(tip) + '">' +
            '<input type="checkbox" class="pp-task-auto"' + (t.auto_percent ? ' checked' : '') + '>' +
            '<span class="pp-auto-switch__track"></span>' +
            '<span class="pp-auto-switch__txt">auto</span></label>';
    }

    function bindTaskPanel(container, projectId, ajaxUrl, taskUrl, tasks) {
        const reload = function () { loadTasks(container, projectId, ajaxUrl, taskUrl); };

        // Criar tarefa
        const createBtn = container.querySelector('.pp-nt-create');
        if (createBtn) {
            createBtn.addEventListener('click', function () {
                const name = container.querySelector('.pp-nt-name').value.trim();
                if (!name) { return; }
                const parentId = container.querySelector('.pp-nt-parent').value;
                createBtn.disabled = true;
                taskPost(taskUrl, {
                    action: 'create',
                    name: name,
                    projects_id: projectId,
                    projecttasks_id: parentId,
                    users_ids: teamIds(container.querySelector('.pp-nt-team')).join(','),
                    plan_start_date: container.querySelector('.pp-nt-start').value,
                    plan_end_date: container.querySelector('.pp-nt-end').value
                }, function () {
                    // Subtarefa recém-criada tem que aparecer: expande a mãe
                    // antes do reload (o conjunto vive no container e o
                    // redesenho o reaplica).
                    if (parentId !== '0') {
                        openSubtasksSet(container).add(String(parentId));
                    }
                    reload();
                });
            });
        }

        // Responsáveis da tarefa nova (Bloco D-2a) — só no cliente até criar
        const ntTeam = container.querySelector('.pp-nt-team');
        if (ntTeam) {
            ntTeam.addEventListener('click', function (ev) {
                const x = ev.target.closest('.pp-team__x');
                if (x) {
                    const chip = x.closest('.pp-team__chip');
                    if (chip) { chip.remove(); }
                    refreshTeamEmpty(ntTeam);
                    return;
                }
                const add = ev.target.closest('.pp-team__add');
                if (add) {
                    openTeamPicker(add, teamIds(ntTeam), function (uid, name) {
                        add.insertAdjacentHTML('beforebegin', teamChipHtml(uid, name));
                        refreshTeamEmpty(ntTeam);
                    });
                }
            });
        }

        // Edição inline
        bindTaskRows(container, taskUrl, reload);

        // Subtarefas recolhidas por padrão (+/− na tarefa mãe)
        bindSubtaskCollapse(container);
    }

    // Conjunto de tarefas com subtárvore ABERTA neste painel. Vive no
    // elemento do painel (sobrevive aos reloads da edição inline); fechar
    // e reabrir o painel volta ao padrão recolhido.
    function openSubtasksSet(container) {
        if (!container._ppOpenSubs) { container._ppOpenSubs = new Set(); }
        return container._ppOpenSubs;
    }

    // Recolher/expandir subtarefas no painel do projeto (31/07/2026).
    // A visibilidade é recalculada do topo: uma pilha guarda a profundidade
    // de cada ancestral recolhido; linha com depth maior que o topo da pilha
    // fica oculta. Linhas-painel (💬 pp-cmt-row / 🔗 pp-dep-row) não têm
    // data-task-id e acompanham a visibilidade da tarefa dona (a linha
    // imediatamente acima).
    function bindSubtaskCollapse(container) {
        const table = container.querySelector('.projectplus-tasktable');
        if (!table) { return; }
        const open = openSubtasksSet(container);

        function apply() {
            const stack = [];
            let prevTaskHidden = false;
            table.querySelectorAll('tbody > tr').forEach(function (row) {
                const id = row.dataset.taskId;
                if (!id) {
                    row.style.display = prevTaskHidden ? 'none' : '';
                    return;
                }
                const d = parseInt(row.dataset.depth, 10) || 0;
                while (stack.length && d <= stack[stack.length - 1]) { stack.pop(); }
                const hidden = stack.length > 0;
                row.style.display = hidden ? 'none' : '';
                prevTaskHidden = hidden;
                const btn = row.querySelector('.pp-subexp');
                if (btn) {
                    const isOpen = open.has(String(id));
                    btn.textContent = isOpen ? '−' : '+';
                    if (!isOpen) { stack.push(d); }
                }
            });
        }

        // Listener DELEGADO na tabela, com guarda: chamar bindSubtaskCollapse
        // de novo sobre a mesma tabela não duplica o clique (toggle duplo
        // seria um no-op silencioso — pego pelo harness jsdom).
        if (!table.dataset.ppSubBound) {
            table.dataset.ppSubBound = '1';
            table.addEventListener('click', function (ev) {
                const btn = ev.target && ev.target.closest ? ev.target.closest('.pp-subexp') : null;
                if (!btn || !table.contains(btn)) { return; }
                const row = btn.closest('tr[data-task-id]');
                if (!row) { return; }
                const id = String(row.dataset.taskId);
                if (open.has(id)) { open.delete(id); } else { open.add(id); }
                apply();
            });
        }

        apply();
    }

    // Liga a edição inline das linhas de tarefa (percent, datas, estado,
    // concluir) — compartilhada com "Minhas tarefas" (Etapa 3, Bloco 1).
    function bindTaskRows(container, taskUrl, reload) {
        container.querySelectorAll('tr[data-task-id]').forEach(function (row) {
            const taskId = row.dataset.taskId;

            const pct = row.querySelector('.pp-task-percent');
            if (pct) {
                pct.addEventListener('change', function () {
                    taskPost(taskUrl, { action: 'percent', task_id: taskId, percent: pct.value }, function (resp) {
                        // Recarrega sempre: atualiza o % automático da tarefa
                        // mãe e a barra de prazo/risco da linha alterada
                        if (resp.ok) { reload(); }
                    });
                });
            }

            const auto = row.querySelector('.pp-task-auto');
            if (auto) {
                auto.addEventListener('change', function () {
                    const on = auto.checked;
                    auto.disabled = true;
                    taskPost(taskUrl, { action: 'auto_percent', task_id: taskId, value: on ? 1 : 0 }, function (resp) {
                        auto.disabled = false;
                        // Ligando, o core recalcula o % (e o das mães/projeto):
                        // recarrega para mostrar o valor novo, a trava do campo
                        // e o ✓ escondido. Recusado, o interruptor volta.
                        if (resp.ok) { reload(); } else { auto.checked = !on; }
                    });
                });
            }

            const ds = row.querySelector('.pp-task-start');
            const de = row.querySelector('.pp-task-end');
            [ds, de].forEach(function (inp) {
                if (!inp) { return; }
                inp.addEventListener('change', function () {
                    taskPost(taskUrl, {
                        action: 'dates',
                        task_id: taskId,
                        plan_start_date: ds ? ds.value : '',
                        plan_end_date: de ? de.value : ''
                    }, function (resp) {
                        // A-3 (29/09/2026): a barra/rótulo de Prazo vem
                        // calculada do servidor (Deadline::compute). Sem o
                        // reload, mudar a data gravava no banco mas a coluna
                        // Prazo continuava mostrando o cálculo antigo —
                        // mesmo tratamento que o campo % já fazia.
                        if (resp.ok) { reload(); }
                    });
                });
            });

            const st = row.querySelector('.pp-task-state');
            if (st) {
                st.addEventListener('change', function () {
                    const dot = row.querySelector('.pp-phase-dot');
                    if (dot) { dot.style.background = stateColor(st.value); }
                    taskPost(taskUrl, { action: 'state', task_id: taskId, projectstates_id: st.value }, null);
                });
            }

            // Responsáveis (Bloco D-2a) — só existe com ppData.can_team (gestor)
            const team = row.querySelector('.pp-team--edit');
            if (team) {
                team.addEventListener('click', function (ev) {
                    const x = ev.target.closest('.pp-team__x');
                    if (x) {
                        x.disabled = true;
                        taskPost(taskUrl, { action: 'team_remove', task_id: taskId, users_id: x.dataset.uid }, function (resp) {
                            if (resp.ok) { reload(); } else { x.disabled = false; }
                        });
                        return;
                    }
                    const add = ev.target.closest('.pp-team__add');
                    if (add) {
                        openTeamPicker(add, teamIds(team), function (uid) {
                            add.disabled = true;
                            taskPost(taskUrl, { action: 'team_add', task_id: taskId, users_id: uid }, function (resp) {
                                if (resp.ok) { reload(); } else { add.disabled = false; }
                            });
                        });
                    }
                });
            }

            const done = row.querySelector('.pp-task-complete');
            if (done) {
                done.addEventListener('click', function () {
                    taskPost(taskUrl, { action: 'complete', task_id: taskId }, function () { reload(); });
                });
            }

            // Comentários (Etapa 3, Bloco 2)
            const cmt = row.querySelector('.pp-cmt-btn');
            if (cmt) {
                cmt.addEventListener('click', function () {
                    toggleCommentPanel(row, taskId);
                });
            }

            // Dependências (Etapa 3, Bloco 3)
            const dep = row.querySelector('.pp-dep-btn');
            if (dep) {
                dep.addEventListener('click', function () {
                    toggleDepPanel(row, taskId);
                });
            }
        });
    }

    // ------------------------------------------------------------------
    // Comentários por tarefa (Etapa 3, Bloco 2)
    //
    // Balão com contador na coluna própria (classe pp-cmt-* com escopo
    // exclusivo — lição do Bloco 1: seletores compartilhados entre
    // tabelas precisam de escopo). Painel expansível logo abaixo da
    // linha, no mesmo padrão da expansão de subtarefas.
    // ------------------------------------------------------------------

    function commentBtnHtml(t) {
        const n = parseInt(t.comments, 10) || 0;
        return '<button type="button" class="pp-cmt-btn' + (n > 0 ? ' pp-cmt-btn--has' : '') +
            '" title="' + escapeHtml(__('Comentários')) + '">💬' +
            (n > 0 ? '<span class="pp-cmt-count">' + n + '</span>' : '') +
            '</button>';
    }

    // Painéis expansíveis podem coexistir na mesma linha (💬 e 🔗):
    // procura o painel da classe pedida atravessando os adjacentes.
    function findPanelRow(row, cls) {
        let next = row.nextElementSibling;
        while (next && (next.classList.contains('pp-cmt-row') || next.classList.contains('pp-dep-row'))) {
            if (next.classList.contains(cls)) { return next; }
            next = next.nextElementSibling;
        }
        return null;
    }

    function toggleCommentPanel(row, taskId) {
        const existing = findPanelRow(row, 'pp-cmt-row');
        if (existing) {
            existing.remove(); // toggle: fecha
            return;
        }
        const tr = document.createElement('tr');
        tr.className = 'pp-cmt-row';
        const td = document.createElement('td');
        td.colSpan = row.children.length;
        td.innerHTML = '<div class="pp-cmt-panel"><span class="projectplus-muted">' +
            escapeHtml(__('Carregando comentários…')) + '</span></div>';
        tr.appendChild(td);
        row.insertAdjacentElement('afterend', tr);
        loadComments(td, row, taskId);
    }

    // kind = 'task' (padrão) ou 'project' (Bloco D-3b: 💬 da faixa
    // "Projeto"). Mesmo painel, mesma tabela; muda só a leitura e a chave
    // enviada no add (task_id x project_id).
    function loadComments(container, row, taskId, kind) {
        const action = (kind === 'project') ? 'projectcomments' : 'taskcomments';
        fetch(ppDataUrl + '?action=' + action + '&id=' + encodeURIComponent(taskId), {
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (comments) { renderComments(container, row, taskId, comments, kind); })
            .catch(function () {
                container.innerHTML = '<div class="pp-cmt-panel">' +
                    '<span class="projectplus-muted">' +
                    escapeHtml(__('Erro ao carregar comentários.')) + '</span></div>';
            });
    }

    function renderComments(container, row, taskId, comments, kind) {
        let html = '<div class="pp-cmt-panel">';

        if (!comments.length) {
            html += '<p class="projectplus-muted" style="margin:2px 0">' +
                escapeHtml(__('Nenhum comentário ainda.')) + '</p>';
        } else {
            html += comments.map(function (c) {
                return '<div class="pp-cmt-item" data-comment-id="' + c.id + '">' +
                    '<div class="pp-cmt-item__head">' +
                        '<strong>' + escapeHtml(c.author) + '</strong>' +
                        '<span class="projectplus-muted">' + escapeHtml(c.date) +
                            (c.edited ? ' · ' + escapeHtml(__('editado')) : '') + '</span>' +
                        (c.can_edit
                            ? '<span class="pp-cmt-item__actions">' +
                              '<button type="button" class="pp-cmt-edit" title="' + escapeHtml(__('Editar')) + '">✎</button>' +
                              '<button type="button" class="pp-cmt-del" title="' + escapeHtml(__('Excluir')) + '">×</button>' +
                              '</span>'
                            : '') +
                    '</div>' +
                    '<div class="pp-cmt-item__body">' +
                        escapeHtml(c.content).replace(/\n/g, '<br>') + '</div>' +
                    cmtFilesHtml(c.files) +
                    '</div>';
            }).join('');
        }

        html += '<div class="pp-cmt-new">' +
            '<textarea class="pp-cmt-text" rows="2" maxlength="4000" ' +
                'placeholder="' + escapeHtml(__('Escreva um comentário… (Ctrl+Enter envia)')) + '"></textarea>' +
            '<label class="pp-cmt-attach" title="' + escapeHtml(__('Anexar arquivo')) + '">📎' +
                '<input type="file" class="pp-cmt-files" multiple hidden ' +
                    'accept=".png,.jpg,.jpeg,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx">' +
            '</label>' +
            '<span class="pp-cmt-attach-count projectplus-muted"></span>' +
            '<button type="button" class="projectplus-btn pp-cmt-send">' + escapeHtml(__('Comentar')) + '</button>' +
            '</div></div>';

        container.innerHTML = html;
        bindCommentPanel(container, row, taskId, comments, kind);
    }

    function bindCommentPanel(container, row, taskId, comments, kind) {
        const reload = function () { loadComments(container, row, taskId, kind); };
        const addData = function (content) {
            const d = { action: 'add', content: content };
            d[kind === 'project' ? 'project_id' : 'task_id'] = taskId;
            return d;
        };

        // Novo comentário
        const send  = container.querySelector('.pp-cmt-send');
        const text  = container.querySelector('.pp-cmt-text');
        const fpick = container.querySelector('.pp-cmt-files');
        const fcnt  = container.querySelector('.pp-cmt-attach-count');
        if (fpick && fcnt) {
            fpick.addEventListener('change', function () {
                const n = fpick.files.length;
                fcnt.textContent = n
                    ? _n('%d arquivo selecionado', '%d arquivos selecionados', n, n)
                    : '';
            });
        }
        if (send && text) {
            const submit = function () {
                const content  = text.value.trim();
                const hasFiles = fpick && fpick.files.length > 0;
                if (!content && !hasFiles) { return; }
                send.disabled = true;
                const done = function (resp) {
                    send.disabled = false;
                    if (resp.ok) {
                        if (resp.files_errors && resp.files_errors.length) {
                            alert(resp.files_errors.join('\n'));
                        }
                        setCommentCount(row, resp.count);
                        reload();
                    }
                };
                if (hasFiles) {
                    taskPostFiles(ppCommentUrl, addData(content), fpick.files, done);
                } else {
                    taskPost(ppCommentUrl, addData(content), done);
                }
            };
            send.addEventListener('click', submit);
            text.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { submit(); }
            });
            text.focus();
        }

        // Editar / excluir (só aparecem para o autor — o servidor revalida)
        container.querySelectorAll('.pp-cmt-item').forEach(function (item) {
            const cid = item.dataset.commentId;

            const del = item.querySelector('.pp-cmt-del');
            if (del) {
                del.addEventListener('click', function () {
                    if (!window.confirm(__('Excluir este comentário?'))) { return; }
                    taskPost(ppCommentUrl, { action: 'delete', id: cid }, function (resp) {
                        if (resp.ok) {
                            setCommentCount(row, resp.count);
                            reload();
                        }
                    });
                });
            }

            const edit = item.querySelector('.pp-cmt-edit');
            if (edit) {
                edit.addEventListener('click', function () {
                    const body = item.querySelector('.pp-cmt-item__body');
                    let original = '';
                    for (let i = 0; i < comments.length; i++) {
                        if (String(comments[i].id) === String(cid)) {
                            original = comments[i].content;
                            break;
                        }
                    }
                    body.innerHTML = '<div class="pp-cmt-new">' +
                        '<textarea class="pp-cmt-text" rows="2" maxlength="4000"></textarea>' +
                        '<button type="button" class="projectplus-btn pp-cmt-save">' + escapeHtml(__('Salvar')) + '</button>' +
                        '<button type="button" class="projectplus-btn projectplus-btn--ghost pp-cmt-cancel">' + escapeHtml(__('Cancelar')) + '</button>' +
                        '</div>';
                    const ta = body.querySelector('textarea');
                    ta.value = original;
                    ta.focus();
                    body.querySelector('.pp-cmt-cancel').addEventListener('click', reload);
                    body.querySelector('.pp-cmt-save').addEventListener('click', function () {
                        const content = ta.value.trim();
                        if (!content) { return; }
                        taskPost(ppCommentUrl, { action: 'update', id: cid, content: content }, function (resp) {
                            if (resp.ok) { reload(); }
                        });
                    });
                });
            }
        });
    }

    // Atualiza o badge 💬 da linha sem recarregar a tabela
    function setCommentCount(row, count) {
        const btn = row.querySelector('.pp-cmt-btn');
        if (!btn || typeof count === 'undefined') { return; }
        const n = parseInt(count, 10) || 0;
        btn.classList.toggle('pp-cmt-btn--has', n > 0);
        let span = btn.querySelector('.pp-cmt-count');
        if (n > 0) {
            if (!span) {
                span = document.createElement('span');
                span.className = 'pp-cmt-count';
                btn.appendChild(span);
            }
            span.textContent = String(n);
        } else if (span) {
            span.remove();
        }
    }

    // ------------------------------------------------------------------
    // Dependências entre tarefas (Etapa 3, Bloco 3)
    //
    // Botão 🔗 com contador em coluna própria (classes pp-dep-* com
    // escopo exclusivo, lição do Bloco 1). Painel expansível no mesmo
    // padrão dos comentários; grava na tabela NATIVA
    // glpi_projecttasklinks via ajax/taskdep.php.
    // ------------------------------------------------------------------

    function depBtnHtml(t) {
        const n = parseInt(t.deps, 10) || 0;
        return '<button type="button" class="pp-dep-btn' + (n > 0 ? ' pp-dep-btn--has' : '') +
            (t.blocked ? ' pp-dep-btn--blocked' : '') +
            '" title="' + escapeHtml(__('Dependências')) + '">🔗' +
            (n > 0 ? '<span class="pp-dep-count">' + n + '</span>' : '') +
            '</button>';
    }

    function toggleDepPanel(row, taskId) {
        const existing = findPanelRow(row, 'pp-dep-row');
        if (existing) {
            existing.remove(); // toggle: fecha
            return;
        }
        const tr = document.createElement('tr');
        tr.className = 'pp-dep-row';
        const td = document.createElement('td');
        td.colSpan = row.children.length;
        td.innerHTML = '<div class="pp-dep-panel"><span class="projectplus-muted">' +
            escapeHtml(__('Carregando dependências…')) + '</span></div>';
        tr.appendChild(td);
        row.insertAdjacentElement('afterend', tr);
        loadDeps(td, row, taskId);
    }

    function loadDeps(container, row, taskId) {
        fetch(ppDataUrl + '?action=taskdeps&id=' + encodeURIComponent(taskId), {
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) { renderDeps(container, row, taskId, data); })
            .catch(function () {
                container.innerHTML = '<div class="pp-dep-panel">' +
                    '<span class="projectplus-muted">' +
                    escapeHtml(__('Erro ao carregar as dependências.')) + '</span></div>';
            });
    }

    function depItemHtml(d, canEdit) {
        return '<div class="pp-dep-item">' +
            '<span class="pp-dep-item__status ' + (d.open ? 'pp-dep-item__status--open' : 'pp-dep-item__status--done') + '"' +
                ' title="' + escapeHtml(d.open ? __('aberta') + ' (' + d.percent + '%)' : __('concluída')) + '"></span>' +
            '<a href="' + escapeHtml(d.url) + '" target="_blank">' + escapeHtml(d.name) + '</a>' +
            (d.implicit
                ? '<span class="pp-dep-tag" title="' + escapeHtml(__('Regra geral: subtarefa aberta bloqueia a mãe')) + '">' +
                  escapeHtml(__('subtarefa')) + '</span>'
                : '') +
            '<span class="projectplus-muted"> — ' + (d.open ? d.percent + '%' : escapeHtml(__('concluída'))) + '</span>' +
            (canEdit && !d.implicit
                ? '<button type="button" class="pp-dep-del" data-link-id="' + d.link_id + '" title="' +
                  escapeHtml(__('Remover vínculo')) + '">×</button>'
                : '') +
            '</div>';
    }

    function renderDeps(container, row, taskId, data) {
        const canEdit    = !!data.can_edit;
        const blockers   = data.blockers || [];
        const blocked    = data.blocked || [];
        const candidates = data.candidates || [];

        let html = '<div class="pp-dep-panel">';

        html += '<div class="pp-dep-group"><div class="pp-dep-group__title">⛔ ' +
            escapeHtml(__('Bloqueada por')) + ' ' +
            '<span class="projectplus-muted">' + escapeHtml(__('(precisam terminar antes)')) + '</span></div>';
        html += blockers.length
            ? blockers.map(function (d) { return depItemHtml(d, canEdit); }).join('')
            : '<div class="projectplus-muted">' + escapeHtml(__('Nenhuma')) + '</div>';
        html += '</div>';

        html += '<div class="pp-dep-group"><div class="pp-dep-group__title">⏩ ' +
            escapeHtml(__('Bloqueia')) + ' ' +
            '<span class="projectplus-muted">' + escapeHtml(__('(só concluem depois desta)')) + '</span></div>';
        html += blocked.length
            ? blocked.map(function (d) { return depItemHtml(d, canEdit); }).join('')
            : '<div class="projectplus-muted">' + escapeHtml(__('Nenhuma')) + '</div>';
        html += '</div>';

        if (canEdit) {
            html += '<div class="pp-dep-new">' +
                '<select class="pp-dep-dir">' +
                    '<option value="blocked_by">' + escapeHtml(__('É bloqueada por')) + '</option>' +
                    '<option value="blocks">' + escapeHtml(__('Bloqueia')) + '</option>' +
                '</select>' +
                '<select class="pp-dep-other">' +
                (candidates.length
                    ? candidates.map(function (c) {
                        return '<option value="' + c.id + '">' + escapeHtml(c.name) + ' (' + c.percent + '%)</option>';
                    }).join('')
                    : '<option value="0">' + escapeHtml(__('— sem outras tarefas neste projeto —')) + '</option>') +
                '</select>' +
                '<button type="button" class="projectplus-btn pp-dep-add"' +
                    (candidates.length ? '' : ' disabled') + '>' + escapeHtml(__('Adicionar')) + '</button>' +
                '</div>';
        }

        html += '</div>';
        container.innerHTML = html;
        bindDepPanel(container, row, taskId);

        // Sincroniza badge 🔗 e cadeado 🔒 da linha (sem recarregar a tabela)
        syncDepRow(row, blockers, blocked);
    }

    function bindDepPanel(container, row, taskId) {
        const reload = function () { loadDeps(container, row, taskId); };

        const add = container.querySelector('.pp-dep-add');
        if (add) {
            add.addEventListener('click', function () {
                const other = container.querySelector('.pp-dep-other').value;
                const dir   = container.querySelector('.pp-dep-dir').value;
                if (!other || other === '0') { return; }
                add.disabled = true;
                taskPost(ppDepUrl, { action: 'add', task_id: taskId, other_id: other, dir: dir }, function () {
                    reload();
                });
            });
        }

        container.querySelectorAll('.pp-dep-del').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!window.confirm(__('Remover este vínculo de dependência?'))) { return; }
                taskPost(ppDepUrl, { action: 'delete', link_id: btn.dataset.linkId }, function () {
                    reload();
                });
            });
        });
    }

    // Atualiza contador do 🔗 e o 🔒 do nome sem recarregar a tabela
    // (o contador conta só vínculos EXPLÍCITOS; o 🔒 considera também
    // as subtarefas abertas — regra geral)
    function syncDepRow(row, blockers, blocked) {
        const explicit = function (d) { return !d.implicit; };
        const n = blockers.filter(explicit).length + blocked.filter(explicit).length;
        const isBlocked = blockers.some(function (d) { return d.open; });

        const btn = row.querySelector('.pp-dep-btn');
        if (btn) {
            btn.classList.toggle('pp-dep-btn--has', n > 0);
            btn.classList.toggle('pp-dep-btn--blocked', isBlocked);
            let span = btn.querySelector('.pp-dep-count');
            if (n > 0) {
                if (!span) {
                    span = document.createElement('span');
                    span.className = 'pp-dep-count';
                    btn.appendChild(span);
                }
                span.textContent = String(n);
            } else if (span) {
                span.remove();
            }
        }

        let lock = row.querySelector('.pp-dep-lock');
        if (isBlocked && !lock) {
            const link = row.querySelector('td a');
            if (link) {
                lock = document.createElement('span');
                lock.className = 'pp-dep-lock';
                lock.title = __('Bloqueada por outra(s) tarefa(s) — veja 🔗');
                lock.textContent = '🔒';
                link.parentNode.insertBefore(lock, link);
                link.parentNode.insertBefore(document.createTextNode(' '), link);
            }
        } else if (!isBlocked && lock) {
            lock.remove();
        }
    }

    // ------------------------------------------------------------------
    // Chips de fase (Etapa 2.5, Bloco 3)
    // ------------------------------------------------------------------

    function stateColor(stateId) {
        const id = parseInt(stateId, 10) || 0;
        for (let i = 0; i < ppData.states.length; i++) {
            if (ppData.states[i].id === id) {
                return ppData.states[i].color || '#8a97a5';
            }
        }
        return '#8a97a5';
    }

    function phaseChip(name, color) {
        if (!name) {
            return '<span class="projectplus-muted">—</span>';
        }
        return '<span class="pp-phase" style="--pp-phase-color:' + escapeHtml(color || '#8a97a5') + '">' +
            escapeHtml(name) + '</span>';
    }

    // ------------------------------------------------------------------
    // Barra de prazo (Bloco 4-revisado)
    //
    // dl = { state, percent, display, label } calculado em src/Deadline.php.
    // Estados: green (começo real) | blue (planejado) | yellow 50% |
    // orange 75% | red 90% | dark 100%+ | none (sem datas) | done.
    // ------------------------------------------------------------------
    function deadlineCell(dl) {
        if (!dl || dl.state === 'done') {
            return '<span class="projectplus-muted">—</span>';
        }
        if (dl.state === 'none') {
            return '<div class="pp-deadline pp-deadline--none" ' +
                'title="' + escapeHtml(__('Sem datas planejadas — corrija o planejamento')) + '">' +
                '<div class="pp-deadline__fill" style="width:0%"></div></div>' +
                '<span class="projectplus-muted">—</span>';
        }
        return '<div class="pp-deadline"><div class="pp-deadline__fill pp-deadline__fill--' +
            dl.state + '" style="width:' + (parseInt(dl.display, 10) || 0) + '%"></div></div>' +
            '<span class="pp-deadline__label pp-deadline__label--' + dl.state + '">' +
            escapeHtml(dl.label || '') + '</span>';
    }

    // POST com rotação de token CSRF (tokens do GLPI são de uso único)
    // Anexos de um comentário (Rodada 3, Bloco 4)
    function cmtFilesHtml(files) {
        if (!files || !files.length) { return ''; }
        return '<div class="pp-cmt-files-list">' + files.map(function (f) {
            const url = escapeHtml(f.url);
            if (f.is_image) {
                return '<a class="pp-cmt-thumbwrap" href="' + url + '" target="_blank" rel="noopener">' +
                    '<img class="pp-cmt-thumb" src="' + url + '" alt="' + escapeHtml(f.name) + '"></a>';
            }
            return '<a class="pp-cmt-file" href="' + url + '" target="_blank" rel="noopener">📄 ' +
                escapeHtml(f.name) +
                ' <span class="projectplus-muted">(' + escapeHtml(f.size_h) + ')</span></a>';
        }).join('') + '</div>';
    }

    // POST multipart (comentário com anexos) — mesma rotação de CSRF do taskPost.
    function taskPostFiles(url, data, files, onDone) {
        const fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        fd.append('_glpi_csrf_token', ppCsrf);
        for (let i = 0; i < files.length; i++) { fd.append('files[]', files[i]); }
        fetch(url, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                resp = resp || {};
                if (resp.csrf) { ppCsrf = resp.csrf; }
                if (resp.ok === false && resp.message) { alert(resp.message); }
                if (onDone) { onDone(resp); }
            })
            .catch(function () { if (onDone) { onDone({ ok: false }); } });
    }

    function taskPost(url, data, onDone) {
        data._glpi_csrf_token = ppCsrf;
        postForm(url, data, function (resp) {
            if (resp && resp.csrf) { ppCsrf = resp.csrf; }
            if (resp && resp.ok === false && resp.message) { alert(resp.message); }
            if (onDone) { onDone(resp || {}); }
        });
    }

    // Modais (Bloco 2)
    function initModals() {
        document.querySelectorAll('[data-pp-modal-open]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const modal = document.getElementById(btn.dataset.ppModalOpen);
                if (modal) {
                    modal.hidden = false;
                    const first = modal.querySelector('input[autofocus], input, select, textarea');
                    if (first) { first.focus(); }
                }
            });
        });
        document.querySelectorAll('[data-pp-modal-close]').forEach(function (el) {
            el.addEventListener('click', function () {
                const modal = el.closest('.projectplus-modal');
                if (modal) { modal.hidden = true; }
            });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.projectplus-modal:not([hidden])').forEach(function (m) {
                    m.hidden = true;
                });
            }
        });
    }

    function initStatusChart() {
        const el = document.getElementById('projectplus-status-chart');
        if (!el) { return; }
        drawDonut(el, [
            { label: __('Concluído'),    value: parseInt(el.dataset.done, 10) || 0,       color: '#4caf7d' },
            { label: __('Em andamento'), value: parseInt(el.dataset.inprogress, 10) || 0, color: '#4a9fd4' },
            { label: __('Planejado'),    value: parseInt(el.dataset.planned, 10) || 0,    color: '#e8a33d' },
            { label: __('Atrasado'),     value: parseInt(el.dataset.overdue, 10) || 0,    color: '#d9534f' }
        ]);
    }

    // Donut "Projetos por fase" (Etapa 2.5, Bloco 3) — fatias dinâmicas
    // com a cor de cada estado, vindas do PHP em data-phases (JSON).
    function initPhaseChart() {
        const el = document.getElementById('projectplus-phase-chart');
        if (!el) { return; }
        let phases = [];
        try { phases = JSON.parse(el.dataset.phases || '[]'); } catch (e) { /* vazio */ }
        if (!Array.isArray(phases)) { phases = []; } // defesa: "null"/objeto não derruba o init
        drawDonut(el, phases.map(function (p) {
            return { label: p.name, value: parseInt(p.count, 10) || 0, color: p.color || '#8a97a5' };
        }));
    }

    // Donut "Tarefas por fase" (Etapa 3, Bloco 4) — mesmas fatias
    // dinâmicas do donut de fases, agrupando as tarefas por estado.
    function initTaskStateChart() {
        const el = document.getElementById('projectplus-taskstate-chart');
        if (!el) { return; }
        let phases = [];
        try { phases = JSON.parse(el.dataset.phases || '[]'); } catch (e) { /* vazio */ }
        if (!Array.isArray(phases)) { phases = []; } // defesa: "null"/objeto não derruba o init
        drawDonut(el, phases.map(function (p) {
            return { label: p.name, value: parseInt(p.count, 10) || 0, color: p.color || '#8a97a5' };
        }));
    }

    function initTasksChart() {
        const el = document.getElementById('projectplus-tasks-chart');
        if (!el) { return; }
        drawDonut(el, [
            { label: __('Concluídas'),   value: parseInt(el.dataset.done, 10) || 0,       color: '#4caf7d' },
            { label: __('Em andamento'), value: parseInt(el.dataset.inprogress, 10) || 0, color: '#4a9fd4' },
            { label: __('Pendentes'),    value: parseInt(el.dataset.pending, 10) || 0,    color: '#e8a33d' },
            { label: __('Atrasadas'),    value: parseInt(el.dataset.overdue, 10) || 0,    color: '#d9534f' }
        ]);
    }

    function drawDonut(el, data) {
        const total = data.reduce(function (s, d) { return s + d.value; }, 0);

        if (total === 0) {
            el.innerHTML = '<p class="projectplus-donut__empty">' +
                escapeHtml(__('Sem dados para exibir')) + '</p>';
            return;
        }

        // Donut em SVG puro — sem dependência externa
        const cx = 90, cy = 90, r = 72, ir = 44;
        let angle = -Math.PI / 2;
        let paths = '';

        data.forEach(function (d) {
            if (d.value === 0) { return; }
            const frac = d.value / total;
            // fatia única (100%): desenha um anel completo
            if (frac >= 0.999) {
                paths += '<circle cx="' + cx + '" cy="' + cy + '" r="' + ((r + ir) / 2) +
                    '" fill="none" stroke="' + d.color + '" stroke-width="' + (r - ir) + '"/>';
                return;
            }
            const a1 = angle + frac * Math.PI * 2;
            const large = (a1 - angle) > Math.PI ? 1 : 0;
            const x0 = cx + r * Math.cos(angle),  y0 = cy + r * Math.sin(angle);
            const x1 = cx + r * Math.cos(a1),     y1 = cy + r * Math.sin(a1);
            const xi1 = cx + ir * Math.cos(a1),   yi1 = cy + ir * Math.sin(a1);
            const xi0 = cx + ir * Math.cos(angle), yi0 = cy + ir * Math.sin(angle);
            paths += '<path d="M' + x0 + ' ' + y0 +
                ' A' + r + ' ' + r + ' 0 ' + large + ' 1 ' + x1 + ' ' + y1 +
                ' L' + xi1 + ' ' + yi1 +
                ' A' + ir + ' ' + ir + ' 0 ' + large + ' 0 ' + xi0 + ' ' + yi0 +
                ' Z" fill="' + d.color + '"><title>' + escapeHtml(d.label) + ': ' + d.value + '</title></path>';
            angle = a1;
        });

        let legend = '<ul class="projectplus-donut__legend">';
        data.forEach(function (d) {
            legend += '<li><span class="projectplus-donut__swatch" style="background:' +
                d.color + '"></span>' + escapeHtml(d.label) + ' (' + d.value + ')</li>';
        });
        legend += '</ul>';

        el.innerHTML =
            '<div class="projectplus-donut__wrap">' +
            '<svg viewBox="0 0 180 180" width="180" height="180" role="img" aria-label="' +
            escapeHtml(__('Distribuição por status')) + '">' +
            paths + '</svg>' + legend + '</div>';
    }

    // Requisito 2: subprojetos só aparecem ao expandir o pai
    // (:not(.pp-taskexp) — os botões de subtarefas têm handler próprio)
    function initExpandButtons(root, ajaxUrl) {
        root.querySelectorAll('.projectplus-expand__btn:not(.pp-taskexp)').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const projectId = btn.dataset.projectId;
                const row = btn.closest('tr');

                // Já expandido? Então recolhe.
                if (btn.dataset.expanded === '1') {
                    collapseChildren(row);
                    btn.dataset.expanded = '0';
                    btn.textContent = '+';
                    return;
                }

                btn.disabled = true;
                fetch(ajaxUrl + '?action=children&id=' + encodeURIComponent(projectId) + scopeQs(), {
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (children) {
                        insertChildren(row, children);
                        btn.dataset.expanded = '1';
                        btn.textContent = '−';
                    })
                    .catch(function () { /* silencioso; próxima tentativa recarrega */ })
                    .finally(function () { btn.disabled = false; });
            });
        });
    }

    function insertChildren(parentRow, children) {
        const rootEl = document.getElementById('projectplus-dashboard');
        // Etapa 8, Bloco 4: a linha de subprojeto tem que ter EXATAMENTE as
        // mesmas colunas do <thead> — que agora dependem do direito do
        // perfil (coluna Orçamento só com o direito de Custos; botão
        // "Tarefas" só com o direito de Tarefas). As flags vêm do PHP nos
        // data-attributes da raiz; ausente = ligado (compatibilidade).
        const showBudget = !rootEl || rootEl.dataset.ppCosts !== '0';
        const showTasks  = !rootEl || rootEl.dataset.ppTasks !== '0';
        let anchor = parentRow;
        children.forEach(function (child) {
            const tr = document.createElement('tr');
            tr.className = 'projectplus-row--child';
            tr.dataset.parentId = parentRow.dataset.projectId;
            tr.dataset.projectId = child.id;

            const cells = projectRowCells(child, showBudget);
            tr.innerHTML =
                '<td></td>' +
                '<td>' + projectLockHtml(child.blocked) +
                '<a href="' + escapeHtml(child.url) + '">' + escapeHtml(child.name) + '</a>' +
                    (showTasks
                        ? ' <button type="button" class="projectplus-tasksbtn" data-tasks-project="' + child.id + '">' +
                          escapeHtml(__('Tarefas')) + '</button>'
                        : '') + '</td>' +
                cells.map(function (c) { return '<td' + (c.cls ? ' class="' + c.cls + '"' : '') + '>' + c.html + '</td>'; }).join('');
            anchor.insertAdjacentElement('afterend', tr);

            // Botão Tarefas do subprojeto
            const btn = tr.querySelector('[data-tasks-project]');
            if (btn && rootEl) {
                btn.addEventListener('click', function () {
                    const existing = tr.nextElementSibling;
                    if (existing && existing.classList.contains('projectplus-taskspanel-row')) {
                        existing.remove();
                        return;
                    }
                    openTaskPanel(tr, child.id, rootEl.dataset.ajaxUrl, rootEl.dataset.taskUrl);
                });
            }
            anchor = tr;
        });
    }

    function projectLockHtml(blocked) {
        return blocked
            ? '<span class="pp-dep-lock" title="' +
              escapeHtml(__('Projeto com tarefas/subprojetos abertos — não pode ir para fase concluída')) +
              '">🔒</span> '
            : '';
    }

    // Células da linha de projeto a partir da coluna Fase — MESMA ordem do
    // <thead> (Orçamento só com o direito de Custos). Usada pelo subprojeto
    // (insertChildren) e pelo redesenho depois de editar na faixa (D-3a).
    function projectRowCells(p, showBudget) {
        let badge;
        if (p.is_overdue) {
            badge = '<span class="projectplus-badge projectplus-badge--overdue">' + escapeHtml(__('Atrasado')) + '</span>';
        } else if (p.is_stalled) {
            badge = '<span class="projectplus-badge projectplus-badge--stalled">' + escapeHtml(__('Parado')) + '</span>';
        } else {
            badge = '<span class="projectplus-badge projectplus-badge--ok">' + escapeHtml(__('No prazo')) + '</span>';
        }
        let budget = '<span class="projectplus-muted">—</span>';
        if (p.budget) {
            const w = Math.min(100, p.budget.percent);
            budget = '<div class="projectplus-budgetbar"><div class="projectplus-budgetbar__fill ' +
                'projectplus-budgetbar__fill--' + p.budget.state + '" style="width:' + w + '%"></div></div> ' +
                p.budget.percent + '% <span class="projectplus-muted">(' +
                escapeHtml(p.budget.spent_fmt) + ' / ' + escapeHtml(p.budget.planned_fmt) + ')</span>';
        }
        const pct = parseInt(p.percent_done, 10) || 0;
        const cells = [
            { cls: 'pp-phase-cell', html: phaseChip(p.state_name, p.state_color) },
            { cls: '', html: '<div class="projectplus-progress"><div class="projectplus-progress__bar" style="width:' +
                pct + '%"></div></div><span class="projectplus-progress__pct">' + pct + '%</span>' },
            { cls: '', html: (p.last_activity || '—') },
            { cls: '', html: badge }
        ];
        if (showBudget) { cells.push({ cls: 'projectplus-budget-cell', html: budget }); }
        cells.push({ cls: 'pp-deadline-cell', html: deadlineCell(p.deadline) });
        cells.push({ cls: '', html: p.plan_end_date ? formatDate(p.plan_end_date) : '—' });
        return cells;
    }

    // Bloco D-3a: depois de editar pela faixa, redesenha as linhas da tabela
    // de projetos (o próprio e os ancestrais — o % automático sobe) sem
    // recarregar a página. Mantém as duas primeiras células (expandir, nome
    // + botão Tarefas, com seus listeners); só o cadeado é refeito.
    function refreshProjectRows(rows) {
        const rootEl = document.getElementById('projectplus-dashboard');
        if (!rootEl || !Array.isArray(rows)) { return; }
        const showBudget = rootEl.dataset.ppCosts !== '0';
        rows.forEach(function (p) {
            const id  = parseInt(p.id, 10);
            const sel = 'tr.projectplus-row-item[data-project-id="' + id + '"],' +
                'tr.projectplus-row--child[data-project-id="' + id + '"]';
            rootEl.querySelectorAll(sel).forEach(function (tr) {
                const cells = projectRowCells(p, showBudget);
                if (tr.children.length !== cells.length + 2) { return; } // layout inesperado: não mexe
                cells.forEach(function (c, i) { tr.children[i + 2].innerHTML = c.html; });
                const nameTd = tr.children[1];
                const lock = nameTd.querySelector('.pp-dep-lock');
                if (lock) {
                    if (lock.nextSibling && lock.nextSibling.nodeType === 3) { lock.nextSibling.remove(); }
                    lock.remove();
                }
                if (p.blocked) { nameTd.insertAdjacentHTML('afterbegin', projectLockHtml(true)); }
            });
        });
    }

    // ------------------------------------------------------------------
    // Faixa "Projeto" no topo do painel de tarefas (Bloco D-3a, 01/10/2026)
    //
    // Mesmas funções da linha da tarefa, aplicadas ao PROJETO: Equipe do
    // projeto (glpi_projectteams — quem entra passa a enxergar o projeto),
    // início/fim, fase (conjunto do tipo), % e auto. Só para quem edita
    // projeto (ppData.can_edit_project; o servidor confere por projeto).
    // Cada gravação devolve meta + linhas: a faixa e as linhas da tabela se
    // redesenham sem recarregar a página.
    // ------------------------------------------------------------------
    function projectStripInner(m) {
        const phases = phasesForType(m.type_id).slice();
        if (m.state_id && !phases.some(function (x) { return Number(x.id) === Number(m.state_id); })) {
            const cur = (ppData.states || []).filter(function (x) { return Number(x.id) === Number(m.state_id); })[0];
            if (cur) { phases.push(cur); }
        }
        const stateOpts = '<option value="0">—</option>' + phases.map(function (x) {
            return '<option value="' + parseInt(x.id, 10) + '"' + (Number(x.id) === Number(m.state_id) ? ' selected' : '') + '>' +
                escapeHtml(x.name) + '</option>';
        }).join('');
        const autoTip = m.auto_percent
            ? __('Cálculo automático a partir das tarefas e subprojetos')
            : __('Calcular automaticamente a partir das tarefas e subprojetos');
        const team = Array.isArray(m.team_users) ? m.team_users : [];
        return '<span class="pp-projstrip__title">' + escapeHtml(__('Projeto')) + '</span>' +
            (m.manager
                ? '<span class="pp-projstrip__mgr">' + escapeHtml(__('Gestor')) + ': ' + escapeHtml(m.manager) + '</span>'
                : '') +
            '<div class="pp-team pp-projstrip__team">' +
                '<span class="pp-projstrip__lbl">' + escapeHtml(__('Equipe do projeto')) + '</span>' +
                team.map(function (u) { return teamChipHtml(u.id, u.name, __('Remover da equipe')); }).join('') +
                teamAddBtnHtml(__('Adicionar à equipe')) +
            '</div>' +
            '<input type="date" class="pp-ps-start" value="' + escapeHtml(m.start_iso || '') + '" title="' + escapeHtml(__('Início planejado')) + '">' +
            '<input type="date" class="pp-ps-end" value="' + escapeHtml(m.end_iso || '') + '" title="' + escapeHtml(__('Fim planejado')) + '">' +
            '<span class="pp-state-cell"><span class="pp-phase-dot" style="background:' + stateColor(m.state_id) + '"></span>' +
                '<select class="pp-ps-state" title="' + escapeHtml(__('Fase')) + '">' + stateOpts + '</select></span>' +
            '<input type="number" class="pp-ps-percent" min="0" max="100" value="' + (parseInt(m.percent, 10) || 0) + '" title="' +
                escapeHtml(m.auto_percent ? autoTip : __('Progresso')) + '"' + (m.auto_percent ? ' disabled' : '') + '>' +
            '<label class="pp-auto-switch" title="' + escapeHtml(autoTip) + '">' +
                '<input type="checkbox" class="pp-ps-auto"' + (m.auto_percent ? ' checked' : '') + '>' +
                '<span class="pp-auto-switch__track"></span><span class="pp-auto-switch__txt">auto</span></label>' +
            // Bloco D-3b: 💬 do projeto (painel abre logo abaixo da faixa)
            commentBtnHtml({ comments: m.comments });
    }

    // Painel de comentários do projeto: IRMÃO da faixa (não dentro), para
    // sobreviver ao redesenho da faixa depois de cada edição.
    function toggleProjectComments(strip) {
        const next = strip.nextElementSibling;
        if (next && next.classList.contains('pp-projcmt')) {
            next.remove();
            return;
        }
        const box = document.createElement('div');
        box.className = 'pp-projcmt';
        box.innerHTML = '<div class="pp-cmt-panel"><span class="projectplus-muted">' +
            escapeHtml(__('Carregando comentários…')) + '</span></div>';
        strip.insertAdjacentElement('afterend', box);
        loadComments(box, strip, strip.dataset.projectId, 'project');
    }

    function bindProjectStrip(strip) {
        if (!strip || strip.dataset.ppBound) { return; }
        strip.dataset.ppBound = '1';
        const pid = strip.dataset.projectId;

        const post = function (data, ctl) {
            if (ctl) { ctl.disabled = true; }
            data.project_id = pid;
            taskPost(ppProjectUrl, data, function (resp) {
                // Sucesso OU recusa: a resposta traz o estado real do banco
                // — a faixa volta ao que está gravado (fase recusada pela
                // trava, por exemplo, retorna sozinha, com a mensagem).
                if (resp && resp.meta) {
                    strip.innerHTML = projectStripInner(resp.meta);
                } else if (ctl) {
                    ctl.disabled = false;
                }
                if (resp && resp.rows) { refreshProjectRows(resp.rows); }
            });
        };

        strip.addEventListener('change', function (ev) {
            const t = ev.target;
            if (!t || !t.classList) { return; }
            if (t.classList.contains('pp-ps-start') || t.classList.contains('pp-ps-end')) {
                const st = strip.querySelector('.pp-ps-start');
                const en = strip.querySelector('.pp-ps-end');
                post({ action: 'dates', plan_start_date: st ? st.value : '', plan_end_date: en ? en.value : '' }, t);
            } else if (t.classList.contains('pp-ps-state')) {
                const dot = strip.querySelector('.pp-phase-dot');
                if (dot) { dot.style.background = stateColor(t.value); }
                post({ action: 'state', projectstates_id: t.value }, t);
            } else if (t.classList.contains('pp-ps-percent')) {
                post({ action: 'percent', percent: t.value }, t);
            } else if (t.classList.contains('pp-ps-auto')) {
                post({ action: 'auto_percent', value: t.checked ? 1 : 0 }, t);
            }
        });

        strip.addEventListener('click', function (ev) {
            if (ev.target.closest('.pp-cmt-btn')) {
                toggleProjectComments(strip);
                return;
            }
            const x = ev.target.closest('.pp-team__x');
            if (x) {
                post({ action: 'team_remove', users_id: x.dataset.uid }, x);
                return;
            }
            const add = ev.target.closest('.pp-team__add');
            if (add) {
                const box = add.closest('.pp-team');
                openTeamPicker(add, teamIds(box), function (uid) {
                    post({ action: 'team_add', users_id: uid }, add);
                }, __('Adicionar à equipe'));
            }
        });
    }

    function collapseChildren(parentRow) {
        const parentId = parentRow.dataset.projectId;
        let next = parentRow.nextElementSibling;
        while (next && next.dataset.parentId === parentId) {
            const toRemove = next;
            next = next.nextElementSibling;
            toRemove.remove();
        }
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    function postForm(url, data, onDone) {
        const body = new URLSearchParams();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });

        fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (json) { if (onDone) { onDone(json); } })
            .catch(function () { if (onDone) { onDone({ ok: false }); } });
    }

    function pad(n) { return String(n).padStart(2, '0'); }

    // Data no formato preferido do usuario (Bloco 4c): a mascara vem do
    // servidor junto do dicionario de traducao. Antes era dd/mm/aaaa fixo.
    //
    // Passou a NAO usar mais `new Date(...)`: para uma data seca
    // ('2026-07-26') o construtor interpreta como UTC e, num fuso a oeste
    // como o do Brasil, devolvia o DIA ANTERIOR. O parse agora e textual.
    function formatDate(iso) {
        if (window.ProjectPlusI18n && window.ProjectPlusI18n.fmtDate) {
            return window.ProjectPlusI18n.fmtDate(iso, '');
        }
        const p = String(iso || '').split('-');
        return p.length === 3 ? p[2] + '-' + p[1] + '-' + p[0].substring(0, 4) : String(iso || '');
    }

    function formatDateTime(sql) {
        if (!sql) { return ''; }
        if (window.ProjectPlusI18n && window.ProjectPlusI18n.fmtDateTime) {
            return window.ProjectPlusI18n.fmtDateTime(sql, '');
        }
        return String(sql);
    }

    // Escapa também as aspas: boa parte das chamadas alimenta ATRIBUTOS
    // (title=", placeholder=", aria-label=") montados por concatenação, e a
    // serialização de um textNode não escapa " nem ' — uma tarefa chamada
    // 'Trocar "switch" do rack' partia o atributo ao meio. Mesma
    // implementação de public/js/timeline.js.
    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ------------------------------------------------------------------
    // Relatórios: Burndown (Etapa 5, Bloco 2)
    //
    // O PHP (ajax/reports_data.php -> Reports::burndownData) devolve só
    // dados BRUTOS: total de tarefas do escopo + uma lista de datas de
    // conclusão (uma por tarefa, aproximada por date_mod). Toda a
    // agregação por semana/dia e o desenho do gráfico acontecem aqui —
    // trocar o toggle Semana/Dia só reprocessa os dados já carregados,
    // sem nova requisição ao servidor.
    // ------------------------------------------------------------------

    ProjectPlus.initReports = function () {
        const section = document.getElementById('pp-burndown');
        if (!section) {
            return;
        }
        const ajaxUrl  = section.dataset.ajaxUrl;
        const select   = document.getElementById('pp-burndown-project');
        const buttons  = section.querySelectorAll('.pp-seg__btn');
        const chartEl  = document.getElementById('pp-burndown-chart');
        const kpiEl    = document.getElementById('pp-burndown-kpis');

        let granularity = 'week';
        let currentData = null;

        function parseISODate(s) {
            const p = String(s).split('-');
            return new Date(Date.UTC(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10)));
        }

        function addDays(d, n) {
            const r = new Date(d.getTime());
            r.setUTCDate(r.getUTCDate() + n);
            return r;
        }

        // Avança n meses preservando o dia; se o mês destino for mais
        // curto (ex.: 31/01 -> fev), fixa no último dia do mês destino.
        function addMonths(d, n) {
            const day = d.getUTCDate();
            const r = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + n, 1));
            const lastDay = new Date(Date.UTC(r.getUTCFullYear(), r.getUTCMonth() + 1, 0)).getUTCDate();
            r.setUTCDate(Math.min(day, lastDay));
            return r;
        }

        function isoOf(d) {
            return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
        }

        // Rotulo curto do eixo X do burndown. Sem ano, mas a ORDEM tem de
        // seguir a preferencia: para quem usa m-d-Y (ou o ISO Y-m-d, que
        // tambem poe o mes antes do dia), '07-06' precisa ser julho/dia 6.
        function shortLabel(d) {
            const dd = pad(d.getUTCDate());
            const mm = pad(d.getUTCMonth() + 1);
            const fmt = (window.ProjectPlusI18n && window.ProjectPlusI18n.dateFormat)
                ? window.ProjectPlusI18n.dateFormat() : 'd-m-Y';
            return fmt === 'd-m-Y' ? dd + '-' + mm : mm + '-' + dd;
        }

        // Datas dos "cortes" do eixo X: sempre inclui o início e o fim do
        // eixo; entre eles, um ponto a cada dia, a cada 7 dias ou a cada
        // mês (passo de calendário, não fixo em 30 dias).
        function buildBucketDates(startStr, endStr, gran) {
            const start = parseISODate(startStr);
            let end = parseISODate(endStr);
            if (end.getTime() < start.getTime()) {
                end = start;
            }
            const dates = [start];
            if (gran === 'month') {
                // Ancora cada tick em "início + k meses" (não encadeado),
                // para que um mês curto não "prenda" os ticks seguintes no
                // dia reduzido (ex.: 31/01, 28/02, 31/03, 30/04…).
                let k = 1;
                let cur = addMonths(start, k);
                while (cur.getTime() < end.getTime()) {
                    dates.push(cur);
                    k++;
                    cur = addMonths(start, k);
                }
                // Fecha o eixo no fim exato, sem duplicar quando início==fim.
                if (end.getTime() > dates[dates.length - 1].getTime()) {
                    dates.push(end);
                }
                return dates;
            }
            const stepDays = gran === 'day' ? 1 : 7;
            let cur = start;
            while (cur.getTime() < end.getTime()) {
                cur = addDays(cur, stepDays);
                if (cur.getTime() > end.getTime()) {
                    cur = end;
                }
                dates.push(cur);
            }
            return dates;
        }

        // Quantas tarefas já estavam concluídas até (e incluindo) a data
        // informada — completions[] vem ordenada (ISO, comparação lexical
        // funciona direto).
        function completedBy(sortedCompletions, dateObj) {
            const iso = isoOf(dateObj);
            let count = 0;
            for (let i = 0; i < sortedCompletions.length; i++) {
                if (sortedCompletions[i] <= iso) {
                    count++;
                } else {
                    break;
                }
            }
            return count;
        }

        // Linha "ideal": queda linear de total -> 0 entre idealStart e
        // idealEnd. Se o projeto já passou do fim planejado, fica em 0
        // (mostra visualmente que "já deveria estar pronto").
        function idealAt(dateObj, idealStart, idealEnd, total) {
            if (idealEnd.getTime() <= idealStart.getTime()) {
                return dateObj.getTime() >= idealEnd.getTime() ? 0 : total;
            }
            let frac = (dateObj.getTime() - idealStart.getTime()) / (idealEnd.getTime() - idealStart.getTime());
            frac = Math.max(0, Math.min(1, frac));
            return total * (1 - frac);
        }

        function drawChart(dates, realSeries, idealSeries, total) {
            const W = 640, H = 260, padL = 34, padR = 12, padT = 14, padB = 30;
            const innerW = W - padL - padR, innerH = H - padT - padB;
            const n = dates.length;
            const maxY = Math.max(total, 1);

            function xAt(i) { return n <= 1 ? padL : padL + (innerW * i / (n - 1)); }
            function yAt(v) { return padT + innerH - (innerH * v / maxY); }

            function pathFor(series) {
                return series.map(function (v, i) {
                    return (i === 0 ? 'M' : 'L') + xAt(i).toFixed(1) + ' ' + yAt(v).toFixed(1);
                }).join(' ');
            }

            let gridLines = '';
            [0, 0.5, 1].forEach(function (f) {
                const y = padT + innerH * (1 - f);
                gridLines += '<line x1="' + padL + '" y1="' + y + '" x2="' + (W - padR) + '" y2="' + y +
                    '" stroke="#e7ebf0" stroke-width="1"/>';
                gridLines += '<text x="' + (padL - 6) + '" y="' + (y + 4) +
                    '" font-size="10" fill="#8a97a5" text-anchor="end">' + Math.round(maxY * f) + '</text>';
            });

            const labelStep = Math.max(1, Math.ceil(n / 8));
            let xLabels = '';
            dates.forEach(function (d, i) {
                if (i % labelStep !== 0 && i !== n - 1) {
                    return;
                }
                xLabels += '<text x="' + xAt(i).toFixed(1) + '" y="' + (H - padB + 16) +
                    '" font-size="10" fill="#8a97a5" text-anchor="middle">' + shortLabel(d) + '</text>';
            });

            const realDots = realSeries.map(function (v, i) {
                const rest = Math.round(v);
                return '<circle cx="' + xAt(i).toFixed(1) + '" cy="' + yAt(v).toFixed(1) +
                    '" r="2.6" fill="#4a9fd4"><title>' + shortLabel(dates[i]) + ': ' +
                    escapeHtml(_n('%d restante', '%d restantes', rest, rest)) + '</title></circle>';
            }).join('');

            chartEl.innerHTML =
                '<svg viewBox="0 0 ' + W + ' ' + H + '" width="100%" height="' + H +
                '" role="img" aria-label="' + escapeHtml(__('Gráfico de burndown')) + '">' +
                gridLines +
                '<path d="' + pathFor(idealSeries) + '" fill="none" stroke="#c3ccd4" stroke-width="2" stroke-dasharray="5 4"/>' +
                '<path d="' + pathFor(realSeries) + '" fill="none" stroke="#4a9fd4" stroke-width="2.5"/>' +
                realDots + xLabels +
                '</svg>' +
                '<ul class="pp-burndown-legend">' +
                '<li><span class="pp-burndown-legend__swatch pp-burndown-legend__swatch--ideal"></span>' +
                escapeHtml(__('Ideal')) + '</li>' +
                '<li><span class="pp-burndown-legend__swatch pp-burndown-legend__swatch--real"></span>' +
                escapeHtml(__('Real (tarefas restantes)')) + '</li>' +
                '</ul>';
        }

        function render() {
            if (!currentData) {
                return;
            }
            if (!currentData.total_tasks) {
                chartEl.innerHTML = '<p class="projectplus-muted">' +
                    escapeHtml(__('Este projeto (e seus subprojetos) não tem tarefas.')) + '</p>';
                kpiEl.innerHTML = '';
                return;
            }

            const dates = buildBucketDates(currentData.axis_start, currentData.axis_end, granularity);
            const idealStart = currentData.planned_start ? parseISODate(currentData.planned_start) : parseISODate(currentData.axis_start);
            const idealEnd   = currentData.planned_end ? parseISODate(currentData.planned_end) : parseISODate(currentData.axis_end);
            const completions = (currentData.completions || []).slice().sort();
            const total = currentData.total_tasks;

            const realSeries  = dates.map(function (d) { return total - completedBy(completions, d); });
            const idealSeries = dates.map(function (d) { return idealAt(d, idealStart, idealEnd, total); });

            drawChart(dates, realSeries, idealSeries, total);

            const doneNow = completedBy(completions, parseISODate(currentData.axis_end));
            const pct = total > 0 ? Math.round((doneNow / total) * 100) : 0;
            kpiEl.innerHTML =
                '<span><strong>' + total + '</strong> ' + escapeHtml(__('tarefas')) + '</span>' +
                '<span><strong>' + doneNow + '</strong> ' + escapeHtml(__('concluídas')) + '</span>' +
                '<span><strong>' + (total - doneNow) + '</strong> ' + escapeHtml(__('restantes')) + '</span>' +
                '<span><strong>' + pct + '%</strong> ' + escapeHtml(__('concluído')) + '</span>';
        }

        function loadProject(id) {
            if (!id || id === '0') {
                currentData = null;
                chartEl.innerHTML = '<p class="projectplus-muted">' +
                    escapeHtml(__('Selecione um projeto para ver o burndown.')) + '</p>';
                kpiEl.innerHTML = '';
                return;
            }
            chartEl.innerHTML = '<p class="projectplus-muted">' + escapeHtml(__('Carregando…')) + '</p>';
            kpiEl.innerHTML = '';
            fetch(ajaxUrl + '?action=burndown&project=' + encodeURIComponent(id), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data) {
                        currentData = null;
                        chartEl.innerHTML = '<p class="projectplus-muted">' +
                            escapeHtml(__('Projeto não encontrado.')) + '</p>';
                        return;
                    }
                    currentData = data;
                    render();
                })
                .catch(function () {
                    currentData = null;
                    chartEl.innerHTML = '<p class="projectplus-muted">' +
                        escapeHtml(__('Não foi possível carregar o burndown.')) + '</p>';
                });
        }

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (btn.classList.contains('pp-seg__btn--active')) {
                    return;
                }
                buttons.forEach(function (b) { b.classList.remove('pp-seg__btn--active'); });
                btn.classList.add('pp-seg__btn--active');
                granularity = btn.dataset.granularity;
                render();
            });
        });

        if (select) {
            select.addEventListener('change', function () { loadProject(select.value); });
            loadProject(select.value);
        }
    };

    // Exposto só para os testes isolados (jsdom) — mesmo padrão de
    // ProjectPlusKanban._test (Etapa 7, Bloco 2a).
    ProjectPlus._test = {
        taskTableHtml: function (tasks, collapsible) { return taskTableHtml(tasks, collapsible); },
        bindSubtaskCollapse: bindSubtaskCollapse,
        openSubtasksSet: openSubtasksSet,
        expandAncestors: expandAncestors,
        bindTaskRows: bindTaskRows,
        // Bloco D-2a: painel completo (linha de criação + chips) no harness
        setData: function (d) { ppData = d; },
        renderTaskPanel: renderTaskPanel,
        bindTaskPanel: bindTaskPanel,
        // Bloco D-3a
        setProjectUrl: function (u) { ppProjectUrl = u; },
        initTaskPanels: initTaskPanels,
        bindProjectStrip: bindProjectStrip,
        refreshProjectRows: refreshProjectRows,
        insertChildren: function (row, children) { insertChildren(row, children); },
    };

    window.ProjectPlus = ProjectPlus;
})();

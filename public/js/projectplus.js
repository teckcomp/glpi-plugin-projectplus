/**
 * ProjectPlus — front-end (HTML/JS puro + SVG).
 *
 * - initDashboard(): gráficos, expansão de subprojetos e painel de tarefas
 * - Barra de prazo (Bloco 4-revisado): % do período planejado consumido,
 *   calculada no PHP (src/Deadline.php) e apenas renderizada aqui
 */
(function () {
    'use strict';

    const ProjectPlus = {};

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    ProjectPlus.initDashboard = function () {
        const root = document.getElementById('projectplus-dashboard');
        if (!root) {
            return;
        }
        const ajaxUrl = root.dataset.ajaxUrl;

        initStatusChart();
        initTasksChart();
        initPhaseChart();
        initExpandButtons(root, ajaxUrl);
        initTaskExpand(root, ajaxUrl);
        initModals();
        initTaskPanels(root);
        initBell(root); // depois de initTaskPanels (que inicializa o ppCsrf)
    };

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

            // Já expandido? Recolhe (remove tudo que estiver mais fundo).
            if (btn.dataset.expanded === '1') {
                let next = row.nextElementSibling;
                while (next && (parseInt(next.dataset.depth, 10) || 0) > depth) {
                    const rm = next;
                    next = next.nextElementSibling;
                    rm.remove();
                }
                btn.dataset.expanded = '0';
                btn.textContent = '+';
                return;
            }

            btn.disabled = true;
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

            tr.innerHTML =
                '<td class="projectplus-expand">' + (t.children > 0
                    ? '<button type="button" class="projectplus-expand__btn pp-taskexp"' +
                      ' title="' + t.children + ' subtarefa(s)" data-task-id="' + t.id + '">+</button>'
                    : '') + '</td>' +
                '<td style="padding-left:' + (8 + depth * 18) + 'px">' +
                    '<span class="pp-task-branch">└</span> ' +
                    '<a href="' + escapeHtml(t.url) + '" target="_blank">' + escapeHtml(t.name) + '</a></td>' +
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
                    : (t.end ? escapeHtml(t.end) : '—')) + '</td>';

            anchor.insertAdjacentElement('afterend', tr);

            const childBtn = tr.querySelector('.pp-taskexp');
            if (childBtn) {
                bindTaskExpand(childBtn, ajaxUrl);
            }
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

        function setKpi(id, value) {
            const el = document.getElementById(id);
            if (el) { el.textContent = String(value); }
        }

        function refresh() {
            const done = (doneToggle && doneToggle.checked) ? '&done=1' : '';
            fetch(ajaxUrl + '?action=mytasks' + done, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(render)
                .catch(function () {
                    groupsEl.innerHTML = '<div class="projectplus-card">' +
                        '<p class="projectplus-muted">Erro ao carregar suas tarefas.</p></div>';
                });
        }

        function render(data) {
            const kpis = (data && data.kpis) || {};
            setKpi('pp-mt-kpi-open',    kpis.open    || 0);
            setKpi('pp-mt-kpi-overdue', kpis.overdue || 0);
            setKpi('pp-mt-kpi-nodates', kpis.nodates || 0);
            setKpi('pp-mt-kpi-done',    kpis.done    || 0);

            const groups = (data && Array.isArray(data.groups)) ? data.groups : [];
            if (groups.length === 0) {
                groupsEl.innerHTML = '<div class="projectplus-card">' +
                    '<p class="projectplus-muted">Nenhuma tarefa atribuída a você' +
                    ((doneToggle && doneToggle.checked) ? '' : ' em aberto') + '.</p></div>';
                return;
            }

            groupsEl.innerHTML = groups.map(function (g) {
                const n = g.tasks.length;
                return '<div class="projectplus-card pp-mt-group">' +
                    '<div class="pp-report-projhead">' +
                        '<h3><a href="' + escapeHtml(g.project_url) + '">' + escapeHtml(g.project_name) + '</a></h3>' +
                        '<span class="projectplus-muted">' + n + (n === 1 ? ' tarefa' : ' tarefas') + '</span>' +
                    '</div>' +
                    taskTableHtml(g.tasks) +
                    '</div>';
            }).join('');

            groupsEl.querySelectorAll('.pp-mt-group').forEach(function (card) {
                bindTaskRows(card, taskUrl, refresh);
            });
        }

        if (doneToggle) {
            doneToggle.addEventListener('change', refresh);
        }
        refresh();

        // Sino de alertas (mesma estrutura da Visão geral; o endpoint já
        // filtra por destinatário — só alertas do usuário logado)
        initBell(root);
    };

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
                    : '<button type="button" class="pp-bell__read" title="Marcar como lida">✓</button>') +
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
                html += '<li class="pp-bell__empty">Nenhum alerta não lido</li>';
            } else {
                html += unread.map(function (a) { return bellItem(a, false); }).join('');
            }
            if (read.length > 0) {
                html += '<li class="pp-bell__section">Lidas recentemente</li>' +
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
    let ppData = { states: [], users: [], current_user_id: 0 };
    let ppDataUrl = null;      // ajax/dashboard_data.php (leituras)
    let ppCommentUrl = null;   // ajax/comment.php (Etapa 3, Bloco 2)
    let ppDepUrl = null;       // ajax/taskdep.php (Etapa 3, Bloco 3)

    function initTaskPanels(root) {
        ppCsrf = root.dataset.csrf || null;
        ppDataUrl = root.dataset.ajaxUrl || null;
        ppCommentUrl = root.dataset.commentUrl || null;
        ppDepUrl = root.dataset.depUrl || null;
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
        td.colSpan = 9;
        td.innerHTML = '<div class="projectplus-taskspanel">Carregando tarefas…</div>';
        tr.appendChild(td);
        projectRow.insertAdjacentElement('afterend', tr);

        loadTasks(td, projectId, ajaxUrl, taskUrl);
    }

    function loadTasks(container, projectId, ajaxUrl, taskUrl) {
        fetch(ajaxUrl + '?action=tasks&id=' + encodeURIComponent(projectId), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (tasks) {
                container.innerHTML = renderTaskPanel(projectId, tasks);
                bindTaskPanel(container, projectId, ajaxUrl, taskUrl, tasks);
            })
            .catch(function () {
                container.innerHTML = '<div class="projectplus-taskspanel">Erro ao carregar tarefas.</div>';
            });
    }

    function renderTaskPanel(projectId, tasks) {
        let html = '<div class="projectplus-taskspanel">';

        // Formulário de nova tarefa
        html += '<div class="projectplus-newtask">' +
            '<input type="text" class="pp-nt-name" placeholder="Nova tarefa…" maxlength="255">' +
            '<select class="pp-nt-parent"><option value="0">Sem tarefa pai</option>' +
            tasks.map(function (t) {
                return '<option value="' + t.id + '">' + '&nbsp;'.repeat(t.depth * 2) + escapeHtml(t.name) + '</option>';
            }).join('') +
            '</select>' +
            '<select class="pp-nt-user"><option value="0">Sem responsável</option>' +
            ppData.users.map(function (u) {
                const sel = (u.id === ppData.current_user_id) ? ' selected' : '';
                return '<option value="' + u.id + '"' + sel + '>' + escapeHtml(u.name) + '</option>';
            }).join('') +
            '</select>' +
            '<input type="date" class="pp-nt-start" title="Início planejado">' +
            '<input type="date" class="pp-nt-end" title="Fim planejado">' +
            '<button type="button" class="projectplus-btn pp-nt-create">Criar tarefa</button>' +
            '</div>';

        if (tasks.length === 0) {
            html += '<p class="projectplus-muted">Nenhuma tarefa neste projeto ainda.</p></div>';
            return html;
        }

        html += taskTableHtml(tasks) + '</div>';
        return html;
    }

    // Tabela de tarefas com edição inline — compartilhada entre o painel
    // por projeto e a tela "Minhas tarefas" (Etapa 3, Bloco 1).
    function taskTableHtml(tasks) {
        let html = '<table class="projectplus-tasktable"><thead><tr>' +
            '<th>Tarefa</th><th>Responsáveis</th><th>Início</th><th>Fim</th>' +
            '<th>%</th><th>Estado</th><th>Prazo</th><th></th><th></th><th></th>' +
            '</tr></thead><tbody>';

        tasks.forEach(function (t) {
            const stateOpts = ppData.states.map(function (s) {
                return '<option value="' + s.id + '"' + (s.id === t.state_id ? ' selected' : '') + '>' +
                    escapeHtml(s.name) + '</option>';
            }).join('');
            html += '<tr data-task-id="' + t.id + '" class="' + (t.percent >= 100 ? 'pp-task-done' : '') + '">' +
                '<td style="padding-left:' + (10 + t.depth * 22) + 'px">' +
                    (t.depth > 0 ? '<span class="pp-task-branch">└</span> ' : '') +
                    (t.depth === 0 && t.parent_name
                        ? '<span class="pp-task-parent" title="Tarefa mãe">' + escapeHtml(t.parent_name) + ' › </span>'
                        : '') +
                    (t.blocked
                        ? '<span class="pp-dep-lock" title="Bloqueada por outra(s) tarefa(s) — veja 🔗">🔒</span> '
                        : '') +
                    '<a href="' + escapeHtml(t.url) + '" target="_blank">' + escapeHtml(t.name) + '</a></td>' +
                '<td>' + (t.team.length ? escapeHtml(t.team.join(', ')) : '<span class="projectplus-muted">—</span>') + '</td>' +
                '<td><input type="date" class="pp-task-start" value="' + (t.start_iso || '') + '"></td>' +
                '<td><input type="date" class="pp-task-end" value="' + (t.end_iso || '') + '"></td>' +
                '<td><input type="number" class="pp-task-percent" min="0" max="100" value="' + t.percent + '"' +
                    (t.auto_percent
                        ? ' disabled title="Cálculo automático a partir das subtarefas"'
                        : '') + '></td>' +
                '<td class="pp-state-cell"><span class="pp-phase-dot" style="background:' + stateColor(t.state_id) + '"></span>' +
                    '<select class="pp-task-state"><option value="0">—</option>' + stateOpts + '</select></td>' +
                '<td class="pp-deadline-cell">' + deadlineCell(t.deadline) + '</td>' +
                '<td class="pp-dep-cell">' + depBtnHtml(t) + '</td>' +
                '<td class="pp-cmt-cell">' + commentBtnHtml(t) + '</td>' +
                '<td>' + (t.percent < 100 && !t.auto_percent
                    ? '<button type="button" class="pp-task-complete" title="Concluir">✓</button>'
                    : '') + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        return html;
    }

    function bindTaskPanel(container, projectId, ajaxUrl, taskUrl, tasks) {
        const reload = function () { loadTasks(container, projectId, ajaxUrl, taskUrl); };

        // Criar tarefa
        const createBtn = container.querySelector('.pp-nt-create');
        if (createBtn) {
            createBtn.addEventListener('click', function () {
                const name = container.querySelector('.pp-nt-name').value.trim();
                if (!name) { return; }
                createBtn.disabled = true;
                taskPost(taskUrl, {
                    action: 'create',
                    name: name,
                    projects_id: projectId,
                    projecttasks_id: container.querySelector('.pp-nt-parent').value,
                    users_id: container.querySelector('.pp-nt-user').value,
                    plan_start_date: container.querySelector('.pp-nt-start').value,
                    plan_end_date: container.querySelector('.pp-nt-end').value
                }, function () { reload(); });
            });
        }

        // Edição inline
        bindTaskRows(container, taskUrl, reload);
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
                    }, null);
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
            '" title="Comentários">💬' +
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
        td.innerHTML = '<div class="pp-cmt-panel"><span class="projectplus-muted">Carregando comentários…</span></div>';
        tr.appendChild(td);
        row.insertAdjacentElement('afterend', tr);
        loadComments(td, row, taskId);
    }

    function loadComments(container, row, taskId) {
        fetch(ppDataUrl + '?action=taskcomments&id=' + encodeURIComponent(taskId), {
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (comments) { renderComments(container, row, taskId, comments); })
            .catch(function () {
                container.innerHTML = '<div class="pp-cmt-panel">' +
                    '<span class="projectplus-muted">Erro ao carregar comentários.</span></div>';
            });
    }

    function renderComments(container, row, taskId, comments) {
        let html = '<div class="pp-cmt-panel">';

        if (!comments.length) {
            html += '<p class="projectplus-muted" style="margin:2px 0">Nenhum comentário ainda.</p>';
        } else {
            html += comments.map(function (c) {
                return '<div class="pp-cmt-item" data-comment-id="' + c.id + '">' +
                    '<div class="pp-cmt-item__head">' +
                        '<strong>' + escapeHtml(c.author) + '</strong>' +
                        '<span class="projectplus-muted">' + escapeHtml(c.date) +
                            (c.edited ? ' · editado' : '') + '</span>' +
                        (c.can_edit
                            ? '<span class="pp-cmt-item__actions">' +
                              '<button type="button" class="pp-cmt-edit" title="Editar">✎</button>' +
                              '<button type="button" class="pp-cmt-del" title="Excluir">×</button>' +
                              '</span>'
                            : '') +
                    '</div>' +
                    '<div class="pp-cmt-item__body">' +
                        escapeHtml(c.content).replace(/\n/g, '<br>') + '</div>' +
                    '</div>';
            }).join('');
        }

        html += '<div class="pp-cmt-new">' +
            '<textarea class="pp-cmt-text" rows="2" maxlength="4000" ' +
                'placeholder="Escreva um comentário… (Ctrl+Enter envia)"></textarea>' +
            '<button type="button" class="projectplus-btn pp-cmt-send">Comentar</button>' +
            '</div></div>';

        container.innerHTML = html;
        bindCommentPanel(container, row, taskId, comments);
    }

    function bindCommentPanel(container, row, taskId, comments) {
        const reload = function () { loadComments(container, row, taskId); };

        // Novo comentário
        const send = container.querySelector('.pp-cmt-send');
        const text = container.querySelector('.pp-cmt-text');
        if (send && text) {
            const submit = function () {
                const content = text.value.trim();
                if (!content) { return; }
                send.disabled = true;
                taskPost(ppCommentUrl, { action: 'add', task_id: taskId, content: content }, function (resp) {
                    send.disabled = false;
                    if (resp.ok) {
                        setCommentCount(row, resp.count);
                        reload();
                    }
                });
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
                    if (!window.confirm('Excluir este comentário?')) { return; }
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
                        '<button type="button" class="projectplus-btn pp-cmt-save">Salvar</button>' +
                        '<button type="button" class="projectplus-btn projectplus-btn--ghost pp-cmt-cancel">Cancelar</button>' +
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
            '" title="Dependências">🔗' +
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
        td.innerHTML = '<div class="pp-dep-panel"><span class="projectplus-muted">Carregando dependências…</span></div>';
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
                    '<span class="projectplus-muted">Erro ao carregar as dependências.</span></div>';
            });
    }

    function depItemHtml(d, canEdit) {
        return '<div class="pp-dep-item">' +
            '<span class="pp-dep-item__status ' + (d.open ? 'pp-dep-item__status--open' : 'pp-dep-item__status--done') + '"' +
                ' title="' + (d.open ? 'aberta (' + d.percent + '%)' : 'concluída') + '"></span>' +
            '<a href="' + escapeHtml(d.url) + '" target="_blank">' + escapeHtml(d.name) + '</a>' +
            (d.implicit ? '<span class="pp-dep-tag" title="Regra geral: subtarefa aberta bloqueia a mãe">subtarefa</span>' : '') +
            '<span class="projectplus-muted"> — ' + (d.open ? d.percent + '%' : 'concluída') + '</span>' +
            (canEdit && !d.implicit
                ? '<button type="button" class="pp-dep-del" data-link-id="' + d.link_id + '" title="Remover vínculo">×</button>'
                : '') +
            '</div>';
    }

    function renderDeps(container, row, taskId, data) {
        const canEdit    = !!data.can_edit;
        const blockers   = data.blockers || [];
        const blocked    = data.blocked || [];
        const candidates = data.candidates || [];

        let html = '<div class="pp-dep-panel">';

        html += '<div class="pp-dep-group"><div class="pp-dep-group__title">⛔ Bloqueada por ' +
            '<span class="projectplus-muted">(precisam terminar antes)</span></div>';
        html += blockers.length
            ? blockers.map(function (d) { return depItemHtml(d, canEdit); }).join('')
            : '<div class="projectplus-muted">Nenhuma</div>';
        html += '</div>';

        html += '<div class="pp-dep-group"><div class="pp-dep-group__title">⏩ Bloqueia ' +
            '<span class="projectplus-muted">(só concluem depois desta)</span></div>';
        html += blocked.length
            ? blocked.map(function (d) { return depItemHtml(d, canEdit); }).join('')
            : '<div class="projectplus-muted">Nenhuma</div>';
        html += '</div>';

        if (canEdit) {
            html += '<div class="pp-dep-new">' +
                '<select class="pp-dep-dir">' +
                    '<option value="blocked_by">É bloqueada por</option>' +
                    '<option value="blocks">Bloqueia</option>' +
                '</select>' +
                '<select class="pp-dep-other">' +
                (candidates.length
                    ? candidates.map(function (c) {
                        return '<option value="' + c.id + '">' + escapeHtml(c.name) + ' (' + c.percent + '%)</option>';
                    }).join('')
                    : '<option value="0">— sem outras tarefas neste projeto —</option>') +
                '</select>' +
                '<button type="button" class="projectplus-btn pp-dep-add"' +
                    (candidates.length ? '' : ' disabled') + '>Adicionar</button>' +
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
                if (!window.confirm('Remover este vínculo de dependência?')) { return; }
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
                lock.title = 'Bloqueada por outra(s) tarefa(s) — veja 🔗';
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
                'title="Sem datas planejadas — corrija o planejamento">' +
                '<div class="pp-deadline__fill" style="width:0%"></div></div>' +
                '<span class="projectplus-muted">—</span>';
        }
        return '<div class="pp-deadline"><div class="pp-deadline__fill pp-deadline__fill--' +
            dl.state + '" style="width:' + (parseInt(dl.display, 10) || 0) + '%"></div></div>' +
            '<span class="pp-deadline__label pp-deadline__label--' + dl.state + '">' +
            escapeHtml(dl.label || '') + '</span>';
    }

    // POST com rotação de token CSRF (tokens do GLPI são de uso único)
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
            { label: 'Concluído',    value: parseInt(el.dataset.done, 10) || 0,       color: '#4caf7d' },
            { label: 'Em andamento', value: parseInt(el.dataset.inprogress, 10) || 0, color: '#4a9fd4' },
            { label: 'Planejado',    value: parseInt(el.dataset.planned, 10) || 0,    color: '#e8a33d' },
            { label: 'Atrasado',     value: parseInt(el.dataset.overdue, 10) || 0,    color: '#d9534f' }
        ]);
    }

    // Donut "Projetos por fase" (Etapa 2.5, Bloco 3) — fatias dinâmicas
    // com a cor de cada estado, vindas do PHP em data-phases (JSON).
    function initPhaseChart() {
        const el = document.getElementById('projectplus-phase-chart');
        if (!el) { return; }
        let phases = [];
        try { phases = JSON.parse(el.dataset.phases || '[]'); } catch (e) { /* vazio */ }
        drawDonut(el, phases.map(function (p) {
            return { label: p.name, value: parseInt(p.count, 10) || 0, color: p.color || '#8a97a5' };
        }));
    }

    function initTasksChart() {
        const el = document.getElementById('projectplus-tasks-chart');
        if (!el) { return; }
        drawDonut(el, [
            { label: 'Concluídas',   value: parseInt(el.dataset.done, 10) || 0,       color: '#4caf7d' },
            { label: 'Em andamento', value: parseInt(el.dataset.inprogress, 10) || 0, color: '#4a9fd4' },
            { label: 'Pendentes',    value: parseInt(el.dataset.pending, 10) || 0,    color: '#e8a33d' },
            { label: 'Atrasadas',    value: parseInt(el.dataset.overdue, 10) || 0,    color: '#d9534f' }
        ]);
    }

    function drawDonut(el, data) {
        const total = data.reduce(function (s, d) { return s + d.value; }, 0);

        if (total === 0) {
            el.innerHTML = '<p class="projectplus-donut__empty">Sem dados para exibir</p>';
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
                ' Z" fill="' + d.color + '"><title>' + d.label + ': ' + d.value + '</title></path>';
            angle = a1;
        });

        let legend = '<ul class="projectplus-donut__legend">';
        data.forEach(function (d) {
            legend += '<li><span class="projectplus-donut__swatch" style="background:' +
                d.color + '"></span>' + d.label + ' (' + d.value + ')</li>';
        });
        legend += '</ul>';

        el.innerHTML =
            '<div class="projectplus-donut__wrap">' +
            '<svg viewBox="0 0 180 180" width="180" height="180" role="img" aria-label="Distribuição por status">' +
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
                fetch(ajaxUrl + '?action=children&id=' + encodeURIComponent(projectId), {
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
        let anchor = parentRow;
        children.forEach(function (child) {
            const tr = document.createElement('tr');
            tr.className = 'projectplus-row--child';
            tr.dataset.parentId = parentRow.dataset.projectId;
            tr.dataset.projectId = child.id;

            let badge;
            if (child.is_overdue) {
                badge = '<span class="projectplus-badge projectplus-badge--overdue">Atrasado</span>';
            } else if (child.is_stalled) {
                badge = '<span class="projectplus-badge projectplus-badge--stalled">Parado</span>';
            } else {
                badge = '<span class="projectplus-badge projectplus-badge--ok">No prazo</span>';
            }

            let budget = '<span class="projectplus-muted">—</span>';
            if (child.budget) {
                const w = Math.min(100, child.budget.percent);
                budget = '<div class="projectplus-budgetbar"><div class="projectplus-budgetbar__fill ' +
                    'projectplus-budgetbar__fill--' + child.budget.state + '" style="width:' + w + '%"></div></div> ' +
                    child.budget.percent + '% <span class="projectplus-muted">(' +
                    escapeHtml(child.budget.spent_fmt) + ' / ' + escapeHtml(child.budget.planned_fmt) + ')</span>';
            }

            tr.innerHTML =
                '<td></td>' +
                '<td>' + (child.blocked
                    ? '<span class="pp-dep-lock" title="Projeto com tarefas/subprojetos abertos — não pode ir para fase concluída">🔒</span> '
                    : '') +
                '<a href="' + escapeHtml(child.url) + '">' + escapeHtml(child.name) + '</a>' +
                    ' <button type="button" class="projectplus-tasksbtn" data-tasks-project="' + child.id + '">Tarefas</button></td>' +
                '<td class="pp-phase-cell">' + phaseChip(child.state_name, child.state_color) + '</td>' +
                '<td>' +
                    '<div class="projectplus-progress">' +
                        '<div class="projectplus-progress__bar" style="width:' +
                        (parseInt(child.percent_done, 10) || 0) + '%"></div>' +
                    '</div>' +
                    '<span class="projectplus-progress__pct">' +
                    (parseInt(child.percent_done, 10) || 0) + '%</span>' +
                '</td>' +
                '<td>' + (child.last_activity || '—') + '</td>' +
                '<td>' + badge + '</td>' +
                '<td class="projectplus-budget-cell">' + budget + '</td>' +
                '<td class="pp-deadline-cell">' + deadlineCell(child.deadline) + '</td>' +
                '<td>' + (child.plan_end_date ? formatDate(child.plan_end_date) : '—') + '</td>';
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

    function formatDate(iso) {
        const d = new Date(iso);
        return isNaN(d) ? iso : pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear();
    }

    function formatDateTime(sql) {
        if (!sql) { return ''; }
        const d = new Date(String(sql).replace(' ', 'T'));
        return isNaN(d) ? String(sql)
            : pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() +
              ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    window.ProjectPlus = ProjectPlus;
})();

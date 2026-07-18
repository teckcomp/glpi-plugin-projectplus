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
        initExpandButtons(root, ajaxUrl);
        initModals();
        initTaskPanels(root);
        initBell(root); // depois de initTaskPanels (que inicializa o ppCsrf)
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

    function initTaskPanels(root) {
        ppCsrf = root.dataset.csrf || null;
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
        td.colSpan = 8;
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

        html += '<table class="projectplus-tasktable"><thead><tr>' +
            '<th>Tarefa</th><th>Responsáveis</th><th>Início</th><th>Fim</th>' +
            '<th>%</th><th>Estado</th><th>Prazo</th><th></th>' +
            '</tr></thead><tbody>';

        tasks.forEach(function (t) {
            const stateOpts = ppData.states.map(function (s) {
                return '<option value="' + s.id + '"' + (s.id === t.state_id ? ' selected' : '') + '>' +
                    escapeHtml(s.name) + '</option>';
            }).join('');
            html += '<tr data-task-id="' + t.id + '" class="' + (t.percent >= 100 ? 'pp-task-done' : '') + '">' +
                '<td style="padding-left:' + (10 + t.depth * 22) + 'px">' +
                    (t.depth > 0 ? '<span class="pp-task-branch">└</span> ' : '') +
                    '<a href="' + escapeHtml(t.url) + '" target="_blank">' + escapeHtml(t.name) + '</a></td>' +
                '<td>' + (t.team.length ? escapeHtml(t.team.join(', ')) : '<span class="projectplus-muted">—</span>') + '</td>' +
                '<td><input type="date" class="pp-task-start" value="' + (t.start_iso || '') + '"></td>' +
                '<td><input type="date" class="pp-task-end" value="' + (t.end_iso || '') + '"></td>' +
                '<td><input type="number" class="pp-task-percent" min="0" max="100" value="' + t.percent + '"></td>' +
                '<td><select class="pp-task-state"><option value="0">—</option>' + stateOpts + '</select></td>' +
                '<td class="pp-deadline-cell">' + deadlineCell(t.deadline) + '</td>' +
                '<td>' + (t.percent < 100
                    ? '<button type="button" class="pp-task-complete" title="Concluir">✓</button>'
                    : '') + '</td>' +
                '</tr>';
        });

        html += '</tbody></table></div>';
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
        container.querySelectorAll('tr[data-task-id]').forEach(function (row) {
            const taskId = row.dataset.taskId;

            const pct = row.querySelector('.pp-task-percent');
            if (pct) {
                pct.addEventListener('change', function () {
                    taskPost(taskUrl, { action: 'percent', task_id: taskId, percent: pct.value }, function (resp) {
                        if (resp.ok && parseInt(pct.value, 10) >= 100) { reload(); }
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
                    taskPost(taskUrl, { action: 'state', task_id: taskId, projectstates_id: st.value }, null);
                });
            }

            const done = row.querySelector('.pp-task-complete');
            if (done) {
                done.addEventListener('click', function () {
                    taskPost(taskUrl, { action: 'complete', task_id: taskId }, function () { reload(); });
                });
            }
        });
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
    function initExpandButtons(root, ajaxUrl) {
        root.querySelectorAll('.projectplus-expand__btn').forEach(function (btn) {
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
                '<td><a href="' + escapeHtml(child.url) + '">' + escapeHtml(child.name) + '</a>' +
                    ' <button type="button" class="projectplus-tasksbtn" data-tasks-project="' + child.id + '">Tarefas</button></td>' +
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

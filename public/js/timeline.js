/**
 * ProjectPlus — Timeline (Etapa 3, bloco final).
 *
 * Gantt somente-leitura em HTML/JS puro. Os dados chegam embutidos na
 * página (script#pp-tl-data), sem AJAX. Interações: zoom (dia/semana/mês),
 * botão "Hoje", busca, toggle de concluídas e recolher/expandir projeto.
 * Clique na barra ou no nome abre a ficha nativa em nova aba.
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

    // Lista traduzida "a|b|c" (meses do cabecalho).
    function _list(msgid, expected) {
        var i = window.ProjectPlusI18n;
        return i ? i.tlist(msgid, expected) : String(msgid).split('|');
    }

    const MS_DAY  = 86400000;
    const LABEL_W = 280; // largura da coluna de nomes (sincronizada no CSS)

    const ZOOMS = { day: 28, week: 12, month: 4 };

    // Meses abreviados do cabecalho. Sao resolvidos na PRIMEIRA chamada, e
    // nao no carregamento do arquivo: o dicionario so existe no DOM depois
    // que a pagina monta.
    let monthsCache = null;
    function monthNames() {
        if (monthsCache === null) {
            monthsCache = _list('jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez', 12);
        }
        return monthsCache;
    }

    const state = {
        zoom: 'week',
        query: '',
        showDone: false,
        collapsed: {},   // project_id -> true
        data: null,
    };

    const ProjectPlusTimeline = {};

    ProjectPlusTimeline.init = function () {
        const holder = document.getElementById('pp-tl');
        const dataEl = document.getElementById('pp-tl-data');
        if (!holder || !dataEl) {
            return;
        }

        let data = null;
        try {
            data = JSON.parse(dataEl.textContent);
        } catch (e) {
            data = null;
        }
        // Defesa da lição nº 9: payload ausente/errado não pode derrubar a tela
        if (!data || !Array.isArray(data.groups) || !data.range) {
            holder.innerHTML = '<p class="projectplus-muted">' +
                escapeHtml(__('Não foi possível carregar os dados da timeline.')) + '</p>';
            return;
        }
        state.data = data;

        document.querySelectorAll('.pp-tl-zoom__btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                state.zoom = btn.dataset.ppZoom || 'week';
                document.querySelectorAll('.pp-tl-zoom__btn').forEach(function (b) {
                    b.classList.toggle('pp-tl-zoom__btn--active', b === btn);
                });
                render(true);
            });
        });

        const search = document.getElementById('pp-tl-search');
        if (search) {
            const apply = function () {
                state.query = search.value.trim().toLowerCase();
                render(false);
            };
            search.addEventListener('input', apply);
            search.addEventListener('search', apply);
        }

        const done = document.getElementById('pp-tl-done');
        if (done) {
            done.addEventListener('change', function () {
                state.showDone = done.checked;
                render(false);
            });
        }

        const todayBtn = document.getElementById('pp-tl-today');
        if (todayBtn) {
            todayBtn.addEventListener('click', scrollToToday);
        }

        render(true);
    };

    // ------------------------------------------------------------------
    // Datas
    // ------------------------------------------------------------------

    function toDate(iso) {
        return new Date(iso + 'T00:00:00');
    }

    function dayIndex(iso) {
        return Math.round((toDate(iso) - toDate(state.data.range.min)) / MS_DAY);
    }

    function totalDays() {
        return dayIndex(state.data.range.max) + 1;
    }

    // Data no formato preferido do usuario (Bloco 4c). Antes chamava-se
    // fmtBr e fixava dd/mm/aaaa; a mascara agora vem do servidor, junto do
    // dicionario de traducao. O fallback existe para o caso de o
    // #pp-i18n nao estar na pagina — usa o mesmo separador do GLPI.
    // Rotulo curto do eixo (dia e mes, sem ano). Nao ha preferencia de
    // formato curto no GLPI, mas a ORDEM tem de seguir a do usuario: para
    // quem usa m-d-Y — ou o ISO Y-m-d, que tambem poe o mes antes — '07-06'
    // precisa ser julho, dia 6.
    function shortTick(d) {
        const dd  = pad(d.getDate());
        const mm  = pad(d.getMonth() + 1);
        const fmt = (window.ProjectPlusI18n && window.ProjectPlusI18n.dateFormat)
            ? window.ProjectPlusI18n.dateFormat() : 'd-m-Y';
        return fmt === 'd-m-Y' ? dd + '-' + mm : mm + '-' + dd;
    }

    function fmtDate(iso) {
        if (window.ProjectPlusI18n && window.ProjectPlusI18n.fmtDate) {
            return window.ProjectPlusI18n.fmtDate(iso);
        }
        if (!iso) { return '—'; }
        const p = String(iso).split('-');
        return p.length === 3 ? p[2] + '-' + p[1] + '-' + p[0] : String(iso);
    }

    // ------------------------------------------------------------------
    // Render
    // ------------------------------------------------------------------

    function render(resetScroll) {
        const holder = document.getElementById('pp-tl');
        const ppd    = ZOOMS[state.zoom] || ZOOMS.week;
        const days   = totalDays();
        const trackW = days * ppd;

        let html = '<div class="pp-tl-canvas" style="width:' + (LABEL_W + trackW) + 'px">';
        html += headHtml(ppd, trackW);
        html += '<div class="pp-tl-body" style="width:' + (LABEL_W + trackW) + 'px">';
        html += gridHtml(ppd);

        let visibleRows = 0;
        state.data.groups.forEach(function (g) {
            const gHtml = groupHtml(g, ppd, trackW);
            if (gHtml !== '') {
                html += gHtml;
                visibleRows++;
            }
        });

        html += '</div></div>';

        if (visibleRows === 0) {
            html = '<p class="projectplus-muted pp-tl-empty">' +
                escapeHtml(__('Nenhum projeto ou tarefa encontrado.')) + '</p>';
        }

        holder.innerHTML = html;
        bindRows(holder);

        if (resetScroll) {
            scrollToToday();
        }
    }

    // Cabeçalho: linha de meses + linha de dias/semanas conforme o zoom
    function headHtml(ppd, trackW) {
        const min = toDate(state.data.range.min);
        const max = toDate(state.data.range.max);

        let months = '';
        let cursor = new Date(min);
        while (cursor <= max) {
            const monthEnd = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0);
            const end      = monthEnd < max ? monthEnd : max;
            const daysIn   = Math.round((end - cursor) / MS_DAY) + 1;
            months += '<div class="pp-tl-month" style="width:' + (daysIn * ppd) + 'px">' +
                monthNames()[cursor.getMonth()] + ' ' + cursor.getFullYear() + '</div>';
            cursor = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1);
        }

        let ticks = '';
        if (state.zoom === 'day') {
            let d = new Date(min);
            while (d <= max) {
                const wd = d.getDay();
                ticks += '<div class="pp-tl-tick' + ((wd === 0 || wd === 6) ? ' pp-tl-tick--we' : '') +
                    '" style="width:' + ppd + 'px">' + d.getDate() + '</div>';
                d = new Date(d.getTime() + MS_DAY);
            }
        } else if (state.zoom === 'week') {
            // Um marcador por segunda-feira (dia e mes, na ordem da
            // preferencia do usuario — ver shortTick abaixo)
            let d = new Date(min);
            while (d.getDay() !== 1) {
                d = new Date(d.getTime() + MS_DAY);
            }
            while (d <= max) {
                const left = Math.round((d - min) / MS_DAY) * ppd;
                ticks += '<div class="pp-tl-wtick" style="left:' + left + 'px">' +
                    shortTick(d) + '</div>';
                d = new Date(d.getTime() + (7 * MS_DAY));
            }
        }

        return '<div class="pp-tl-head">' +
            '<div class="pp-tl-head__label">' + escapeHtml(__('Projeto / tarefa')) + '</div>' +
            '<div class="pp-tl-head__track" style="width:' + trackW + 'px">' +
            '<div class="pp-tl-months">' + months + '</div>' +
            '<div class="pp-tl-ticks' + (state.zoom === 'month' ? ' pp-tl-ticks--empty' : '') + '">' + ticks + '</div>' +
            '</div></div>';
    }

    // Camada de grade: linhas de mês + linha "hoje" (altura total do corpo)
    function gridHtml(ppd) {
        const min = toDate(state.data.range.min);
        const max = toDate(state.data.range.max);

        let html = '';
        let cursor = new Date(min.getFullYear(), min.getMonth() + 1, 1);
        while (cursor <= max) {
            const left = LABEL_W + Math.round((cursor - min) / MS_DAY) * ppd;
            html += '<div class="pp-tl-gline" style="left:' + left + 'px"></div>';
            cursor = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1);
        }

        const todayLeft = LABEL_W + dayIndex(state.data.range.today) * ppd + Math.round(ppd / 2);
        html += '<div class="pp-tl-todayline" style="left:' + todayLeft + 'px"></div>';

        return html;
    }

    // Um projeto (linha própria + linhas das tarefas). Devolve '' quando a
    // busca não casa com o projeto nem com nenhuma tarefa dele.
    function groupHtml(g, ppd, trackW) {
        const q          = state.query;
        const projMatch  = q === '' || String(g.name).toLowerCase().indexOf(q) !== -1;
        const tasks      = Array.isArray(g.tasks) ? g.tasks : [];

        const visibleTasks = tasks.filter(function (t) {
            if (!state.showDone && t.is_done) {
                return false;
            }
            if (projMatch) {
                return true;
            }
            return String(t.name).toLowerCase().indexOf(q) !== -1;
        });

        if (!projMatch && visibleTasks.length === 0) {
            return '';
        }

        const collapsed = !!state.collapsed[g.id];

        let html = rowHtml({
            kind: 'project', item: g, ppd: ppd, trackW: trackW,
            collapsed: collapsed, hasTasks: visibleTasks.length > 0,
        });

        if (!collapsed) {
            visibleTasks.forEach(function (t) {
                html += rowHtml({ kind: 'task', item: t, ppd: ppd, trackW: trackW });
            });
        }

        return html;
    }

    function rowHtml(opts) {
        const it     = opts.item;
        const isProj = opts.kind === 'project';
        const depth  = (it.depth || 0) + (isProj ? 0 : 1);
        const indent = 10 + (depth * 16) + (isProj ? 0 : 8);

        let caret = '';
        if (isProj) {
            caret = '<button type="button" class="pp-tl-caret" data-pp-collapse="' + it.id + '"' +
                (opts.hasTasks ? '' : ' disabled') + '>' +
                (opts.collapsed ? '▸' : '▾') + '</button>';
        }

        const lock  = it.blocked
            ? '<span class="pp-dep-lock" title="' + escapeHtml(__('Bloqueada — veja as dependências')) + '">🔒</span> '
            : '';
        const chip  = it.state_name
            ? '<span class="pp-phase pp-tl-chip" style="--pp-phase-color:' + escapeHtml(it.state_color) + '">' + escapeHtml(it.state_name) + '</span>'
            : '';

        let label = '<div class="pp-tl-row__label" style="padding-left:' + indent + 'px">' +
            caret + lock +
            '<a href="' + escapeHtml(it.url) + '" target="_blank" title="' + escapeHtml(it.name) + '">' +
            escapeHtml(it.name) + '</a>' + chip + '</div>';

        return '<div class="pp-tl-row' + (isProj ? ' pp-tl-row--project' : '') +
            (it.is_done ? ' pp-tl-row--done' : '') + '">' +
            label +
            '<div class="pp-tl-row__track" style="width:' + opts.trackW + 'px">' +
            barHtml(it, opts.ppd, isProj) +
            '</div></div>';
    }

    function barHtml(it, ppd, isProj) {
        const tip = escapeHtml(it.name) + ' — ' + fmtDate(it.start) + ' a ' + fmtDate(it.end) +
            ' — ' + (it.percent || 0) + '%';

        if (it.start && it.end) {
            const left  = dayIndex(it.start) * ppd;
            const width = Math.max((dayIndex(it.end) - dayIndex(it.start) + 1) * ppd, 6);
            return '<a class="pp-tl-bar' + (isProj ? ' pp-tl-bar--project' : '') +
                (it.is_overdue ? ' pp-tl-bar--overdue' : '') +
                '" style="left:' + left + 'px;width:' + width + 'px;' +
                '--pp-tl-color:' + escapeHtml(it.state_color || '#8a97a5') + '"' +
                ' href="' + escapeHtml(it.url) + '" target="_blank" title="' + tip + '">' +
                '<span class="pp-tl-bar__fill" style="width:' + Math.min(it.percent || 0, 100) + '%"></span>' +
                '</a>';
        }

        if (it.start || it.end) {
            const iso  = it.start || it.end;
            const left = dayIndex(iso) * ppd + Math.round(ppd / 2);
            return '<a class="pp-tl-ms' + (it.is_overdue ? ' pp-tl-ms--overdue' : '') +
                '" style="left:' + left + 'px;--pp-tl-color:' + escapeHtml(it.state_color || '#8a97a5') + '"' +
                ' href="' + escapeHtml(it.url) + '" target="_blank" title="' + tip + '"></a>';
        }

        const ndLeft = dayIndex(state.data.range.today) * ppd + Math.round(ppd / 2) + 10;
        return '<span class="pp-tl-nodates" style="left:' + ndLeft + 'px"' +
            ' title="' + escapeHtml(__('Sem datas planejadas — corrija o planejamento')) + '">' +
            escapeHtml(__('sem datas')) + '</span>';
    }

    function bindRows(holder) {
        holder.querySelectorAll('[data-pp-collapse]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const id = btn.dataset.ppCollapse;
                state.collapsed[id] = !state.collapsed[id];
                render(false);
            });
        });
    }

    function scrollToToday() {
        const scroll = document.getElementById('pp-tl');
        if (!scroll || !state.data) {
            return;
        }
        const ppd  = ZOOMS[state.zoom] || ZOOMS.week;
        const left = LABEL_W + dayIndex(state.data.range.today) * ppd;
        scroll.scrollLeft = Math.max(left - Math.round(scroll.clientWidth / 2), 0);
    }

    // ------------------------------------------------------------------
    // Utilitários
    // ------------------------------------------------------------------

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    // Escapa também aspas: os valores entram em atributos (title, href, style)
    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    window.ProjectPlusTimeline = ProjectPlusTimeline;
})();

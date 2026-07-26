<?php

namespace GlpiPlugin\Projectplus;

use CommonGLPI;
use Project;
use ProjectTask;

/**
 * Dashboard do ProjectPlus (Etapa 3, Bloco 3.2 — layout do esboço).
 *
 * - Filtro de período opcional (from/until, Y-m-d): projetos/tarefas cujo
 *   período planejado sobrepõe o intervalo (itens sem datas sempre entram);
 * - 6 KPIs, donuts, barras de progresso, tabela global de tarefas e
 *   feed de atividades (alimentado pelos alertas do plugin).
 */
class Dashboard extends CommonGLPI
{
    public static $rightname = 'plugin_projectplus_dashboard';

    public static function getTypeName($nb = 0)
    {
        return __('ProjectPlus', 'projectplus');
    }

    public static function getMenuName()
    {
        return __('Gestor de Projetos', 'projectplus');
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getMenuName(),
            'page'  => Url::to('front/dashboard.php'),
            'icon'  => 'ti ti-layout-dashboard',
        ];
    }

    // ------------------------------------------------------------------
    // Filtro de período
    // ------------------------------------------------------------------

    /**
     * Critérios de sobreposição do período planejado com [from, until].
     * Itens sem datas planejadas sempre entram.
     */
    /**
     * Pública (Etapa 5, Bloco 1.2): reaproveitada por Reports.php para o
     * filtro "Período" da tela Relatórios — mesma semântica da Visão
     * geral (sobreposição de intervalo, datas em aberto sempre entram).
     */
    public static function periodCriteria(?string $from, ?string $until, string $prefix): array
    {
        $crit = [];
        if ($until) {
            $crit[] = [
                'OR' => [
                    [$prefix . 'plan_start_date' => null],
                    [$prefix . 'plan_start_date' => ['<=', $until . ' 23:59:59']],
                ],
            ];
        }
        if ($from) {
            $crit[] = [
                'OR' => [
                    [$prefix . 'plan_end_date' => null],
                    [$prefix . 'plan_end_date' => ['>=', $from . ' 00:00:00']],
                ],
            ];
        }
        return $crit;
    }

    // ------------------------------------------------------------------
    // Estados/fases (Etapa 2.5, Bloco 3)
    // ------------------------------------------------------------------

    /** Cor padrão para itens sem fase definida. */
    public const PHASE_DEFAULT_COLOR = '#8a97a5';

    /**
     * Mapa id => ['name' => ..., 'color' => ...] de glpi_projectstates.
     * Cor sempre preenchida (fallback cinza) para uso direto nos chips.
     */
    public static function getStatesMap(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        foreach ($DB->request(['FROM' => 'glpi_projectstates', 'ORDER' => 'name']) as $s) {
            $color = trim((string) ($s['color'] ?? ''));
            $map[(int) $s['id']] = [
                'name'  => $s['name'],
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : self::PHASE_DEFAULT_COLOR,
            ];
        }
        return $map;
    }

    // ------------------------------------------------------------------
    // Dados do painel
    // ------------------------------------------------------------------

    public static function getData(
        ?string $from = null,
        ?string $until = null,
        ?array $projectIds = null,
        ?array $myTaskIds = null,
        ?array $taskProjectIds = null
    ): array {
        /** @var \DBmysql $DB */
        global $DB;

        $now = time();

        // --- Projetos PAI apenas (requisito 2) ---
        $where = [
            'glpi_projects.projects_id' => 0,
            'glpi_projects.is_deleted'  => 0,
            'glpi_projects.is_template' => 0,
        ] + getEntitiesRestrictCriteria('glpi_projects');

        foreach (self::periodCriteria($from, $until, 'glpi_projects.') as $c) {
            $where[] = $c;
        }

        // Escopo (Bloco 3): projetos EXATOS do escopo (cada um por si — raiz
        // ou subprojeto). null = sem filtro (modo "todos", só raízes);
        // lista vazia vira [0] = nada. Ao filtrar por id exato, remove-se a
        // restrição de "só projetos-pai" para o subprojeto aparecer sozinho.
        if ($projectIds !== null) {
            unset($where['glpi_projects.projects_id']);
            $where['glpi_projects.id'] = Scope::inList($projectIds);
        }

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_projects.id', 'glpi_projects.name',
                'glpi_projects.percent_done', 'glpi_projects.plan_end_date',
                'glpi_projects.plan_start_date', 'glpi_projects.real_start_date',
                'glpi_projects.date_mod', 'glpi_projects.priority',
                'glpi_projects.projectstates_id',
            ],
            'FROM'  => 'glpi_projects',
            'WHERE' => $where,
            'ORDER' => 'glpi_projects.date_mod DESC',
        ]);

        $projects  = [];
        $kpis      = [
            'active' => 0, 'avg_progress' => 0, 'open_tasks' => 0,
            'tasks_overdue' => 0, 'overdue' => 0, 'done_month' => 0, 'on_time' => 0,
        ];
        $chart     = ['done' => 0, 'in_progress' => 0, 'planned' => 0, 'overdue' => 0];
        $prioChart = ['high' => 0, 'medium' => 0, 'low' => 0];
        $pctSum    = 0;

        $states     = self::getStatesMap();
        $phaseCount = []; // states_id => quantidade (0 = sem fase)

        foreach ($iterator as $row) {
            $pct       = (int) $row['percent_done'];
            $isOverdue = !empty($row['plan_end_date'])
                && strtotime($row['plan_end_date']) < $now
                && $pct < 100;

            $tracking = ProjectTracking::getForProject((int) $row['id']);
            $children = self::countChildren((int) $row['id']);

            $budget     = Budget::getForProject((int) $row['id']);
            $budgetInfo = null;
            if ($budget['planned'] > 0) {
                $budgetInfo = [
                    'planned_fmt' => number_format($budget['planned'], 2, ',', '.'),
                    'spent_fmt'   => number_format($budget['spent_total'], 2, ',', '.'),
                    'percent'     => $budget['percent'],
                    'state'       => $budget['percent'] > 100 ? 'over'
                        : ($budget['percent'] >= 80 ? 'warn' : 'ok'),
                ];
            }

            $stateId = (int) $row['projectstates_id'];
            $phaseCount[$stateId] = ($phaseCount[$stateId] ?? 0) + 1;

            $projects[] = [
                'id'            => (int) $row['id'],
                'name'          => $row['name'],
                'state_name'    => $states[$stateId]['name'] ?? null,
                'state_color'   => $states[$stateId]['color'] ?? self::PHASE_DEFAULT_COLOR,
                'percent_done'  => $pct,
                'plan_end_date' => $row['plan_end_date'],
                'last_activity' => $tracking['last_activity'] ?? $row['date_mod'],
                'is_stalled'    => (bool) ($tracking['is_stalled'] ?? false),
                'is_overdue'    => $isOverdue,
                // No escopo pessoal a lista é PLANA: sem expandir para
                // subprojetos que o usuário não participa (Bloco 3).
                'children'      => ($myTaskIds !== null) ? 0 : $children,
                'budget'        => $budgetInfo,
                'deadline'      => Deadline::compute(
                    $row['plan_start_date'],
                    $row['real_start_date'],
                    $row['plan_end_date'],
                    $pct
                ),
                'url'           => Project::getFormURLWithID((int) $row['id']),
            ];

            $kpis['active']++;
            $pctSum += $pct;

            if ($isOverdue) {
                $kpis['overdue']++;
                $chart['overdue']++;
            } elseif ($pct >= 100) {
                $chart['done']++;
            } elseif ($pct > 0) {
                $chart['in_progress']++;
            } else {
                $chart['planned']++;
            }

            $prio = (int) $row['priority'];
            if ($prio >= 4) {
                $prioChart['high']++;
            } elseif ($prio <= 2) {
                $prioChart['low']++;
            } else {
                $prioChart['medium']++;
            }
        }

        // Regra geral (Etapa 3, Bloco 3 / Fix 1): projeto com filhos
        // abertos fica 🔒 — consulta única para todos os projetos
        $blockedProjects = TaskDep::blockedProjects(array_column($projects, 'id'));
        foreach ($projects as &$p) {
            $p['blocked'] = $blockedProjects[$p['id']] ?? false;
        }
        unset($p);

        if ($kpis['active'] > 0) {
            $kpis['avg_progress'] = (int) round($pctSum / $kpis['active']);
            $kpis['on_time']      = (int) round(
                (($kpis['active'] - $kpis['overdue']) / $kpis['active']) * 100
            );
        }

        // --- Tarefas (KPIs + gráfico), com filtro de período ---
        $taskWhere = [];
        foreach (self::periodCriteria($from, $until, '') as $c) {
            $taskWhere[] = $c;
        }
        // Escopo (Bloco 3): personal = só as MINHAS tarefas; managed = todas
        // as tarefas dos meus projetos (raízes + descendentes).
        if ($myTaskIds !== null) {
            $taskWhere['id'] = Scope::inList($myTaskIds);
        } elseif ($taskProjectIds !== null) {
            $taskWhere['projects_id'] = Scope::inList($taskProjectIds);
        }

        $tasksChart     = ['done' => 0, 'in_progress' => 0, 'pending' => 0, 'overdue' => 0];
        $taskStateCount = []; // projectstates_id => quantidade (donut "Tarefas por Estado")
        foreach (
            $DB->request([
                'SELECT' => ['percent_done', 'plan_end_date', 'projectstates_id'],
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => $taskWhere,
            ]) as $t
        ) {
            $p = (int) $t['percent_done'];
            if ($p >= 100) {
                $tasksChart['done']++;
            } elseif (!empty($t['plan_end_date']) && strtotime($t['plan_end_date']) < $now) {
                $tasksChart['overdue']++;
            } elseif ($p > 0) {
                $tasksChart['in_progress']++;
            } else {
                $tasksChart['pending']++;
            }
            $tsid = (int) $t['projectstates_id'];
            $taskStateCount[$tsid] = ($taskStateCount[$tsid] ?? 0) + 1;
        }
        $kpis['open_tasks']    = $tasksChart['in_progress'] + $tasksChart['pending'] + $tasksChart['overdue'];
        $kpis['tasks_overdue'] = $tasksChart['overdue'];

        // --- Concluídos este mês (percent 100 com modificação no mês corrente) ---
        $doneMonthWhere = [
            'is_deleted'   => 0,
            'is_template'  => 0,
            'percent_done' => ['>=', 100],
            'date_mod'     => ['>=', date('Y-m-01 00:00:00')],
        ];
        // Escopo (Bloco 3): conta só os projetos do escopo.
        if ($projectIds !== null) {
            $doneMonthWhere['id'] = Scope::inList($projectIds);
        }
        $doneMonth = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_projects',
            'WHERE' => $doneMonthWhere,
        ])->current();
        $kpis['done_month'] = (int) ($doneMonth['cpt'] ?? 0);

        // --- Donut "Projetos por fase" (Etapa 2.5, Bloco 3) ---
        // Segue a ordem alfabética dos estados (prefixo numérico garante a
        // sequência 1..5); "Sem fase" entra por último, em cinza.
        $phaseChart = [];
        foreach ($states as $sid => $s) {
            if (!empty($phaseCount[$sid])) {
                $phaseChart[] = [
                    'name'  => $s['name'],
                    'color' => $s['color'],
                    'count' => $phaseCount[$sid],
                ];
            }
        }
        if (!empty($phaseCount[0])) {
            $phaseChart[] = [
                'name'  => __('Sem fase', 'projectplus'),
                'color' => self::PHASE_DEFAULT_COLOR,
                'count' => $phaseCount[0],
            ];
        }

        // --- Donut "Tarefas por Estado" (Etapa 3, Bloco 4) — mesmo formato
        // do phase_chart, agrupando as tarefas por glpi_projectstates ---
        $taskStateChart = [];
        foreach ($states as $sid => $s) {
            if (!empty($taskStateCount[$sid])) {
                $taskStateChart[] = [
                    'name'  => $s['name'],
                    'color' => $s['color'],
                    'count' => $taskStateCount[$sid],
                ];
            }
        }
        if (!empty($taskStateCount[0])) {
            $taskStateChart[] = [
                'name'  => __('Sem estado', 'projectplus'),
                'color' => self::PHASE_DEFAULT_COLOR,
                'count' => $taskStateCount[0],
            ];
        }

        return [
            'kpis'             => $kpis,
            'status_chart'     => $chart,
            'priority_chart'   => $prioChart,
            'tasks_chart'      => $tasksChart,
            'phase_chart'      => $phaseChart,
            'task_state_chart' => $taskStateChart,
            'projects'         => $projects,
            'open_tasks'       => self::getOpenTasks($from, $until, 15, $myTaskIds, $taskProjectIds),
        ];
    }

    /**
     * Tarefas em andamento (globais), para a tabela da linha de tarefas.
     * Fix 2: lista apenas tarefas-RAIZ (como "Projetos em andamento");
     * as subtarefas aparecem ao expandir via getOpenTaskChildren().
     */
    public static function getOpenTasks(
        ?string $from,
        ?string $until,
        int $limit = 15,
        ?array $myTaskIds = null,
        ?array $taskProjectIds = null
    ): array {
        /** @var \DBmysql $DB */
        global $DB;

        $where = [
            'glpi_projecttasks.percent_done' => ['<', 100],
        ];
        // Escopo (Bloco 3):
        if ($myTaskIds !== null) {
            // personal: só as MINHAS tarefas, planas (sem restrição de raiz,
            // para que uma subtarefa minha também apareça).
            $where['glpi_projecttasks.id'] = Scope::inList($myTaskIds);
        } else {
            // managed/todos: tarefas-raiz + expansão (comportamento original);
            // managed ainda restringe aos projetos do escopo.
            $where['glpi_projecttasks.projecttasks_id'] = 0;
            if ($taskProjectIds !== null) {
                $where['glpi_projecttasks.projects_id'] = Scope::inList($taskProjectIds);
            }
        }
        foreach (self::periodCriteria($from, $until, 'glpi_projecttasks.') as $c) {
            $where[] = $c;
        }

        $states = self::getStatesMap();

        $tasks = [];
        $now   = time();
        foreach (
            $DB->request([
                'SELECT'    => [
                    'glpi_projecttasks.id', 'glpi_projecttasks.name',
                    'glpi_projecttasks.percent_done', 'glpi_projecttasks.plan_end_date',
                    'glpi_projecttasks.plan_start_date', 'glpi_projecttasks.real_start_date',
                    'glpi_projecttasks.projectstates_id',
                    'glpi_projects.name AS project_name',
                ],
                'FROM'      => 'glpi_projecttasks',
                'LEFT JOIN' => [
                    'glpi_projects' => [
                        'ON' => [
                            'glpi_projecttasks' => 'projects_id',
                            'glpi_projects'     => 'id',
                        ],
                    ],
                ],
                'WHERE' => $where,
                'ORDER' => 'glpi_projecttasks.plan_end_date ASC',
                'LIMIT' => $limit,
            ]) as $row
        ) {
            $tasks[] = [
                'id'         => (int) $row['id'],
                'name'       => $row['name'],
                'url'        => ProjectTask::getFormURLWithID((int) $row['id']),
                'project'    => $row['project_name'] ?? '—',
                'team'       => [],
                'children'   => 0,
                'percent'     => (int) $row['percent_done'],
                'state_name'  => $states[(int) $row['projectstates_id']]['name'] ?? null,
                'state_color' => $states[(int) $row['projectstates_id']]['color'] ?? self::PHASE_DEFAULT_COLOR,
                'end'        => $row['plan_end_date'] ? DateFmt::date($row['plan_end_date']) : null,
                'is_overdue' => !empty($row['plan_end_date'])
                    && strtotime($row['plan_end_date']) < $now,
                'deadline'   => Deadline::compute(
                    $row['plan_start_date'],
                    $row['real_start_date'],
                    $row['plan_end_date'],
                    (int) $row['percent_done']
                ),
            ];
        }

        self::attachTeamAndChildren($tasks);

        // No escopo pessoal a lista é PLANA: some o botão de expandir, para
        // não revelar subtarefas de outros responsáveis (Bloco 3).
        if ($myTaskIds !== null) {
            foreach ($tasks as &$t) {
                $t['children'] = 0;
            }
            unset($t);
        }

        return $tasks;
    }

    /**
     * Subtarefas DIRETAS de uma tarefa (expansão na tabela "Tarefas em
     * andamento" — Fix 2). Inclui concluídas, como nos subprojetos; cada
     * filha traz sua própria contagem para expansão recursiva.
     */
    public static function getOpenTaskChildren(int $parentId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $states = self::getStatesMap();
        $now    = time();
        $tasks  = [];

        foreach (
            $DB->request([
                'SELECT'    => [
                    'glpi_projecttasks.id', 'glpi_projecttasks.name',
                    'glpi_projecttasks.percent_done', 'glpi_projecttasks.plan_end_date',
                    'glpi_projecttasks.plan_start_date', 'glpi_projecttasks.real_start_date',
                    'glpi_projecttasks.projectstates_id',
                    'glpi_projects.name AS project_name',
                ],
                'FROM'      => 'glpi_projecttasks',
                'LEFT JOIN' => [
                    'glpi_projects' => [
                        'ON' => [
                            'glpi_projecttasks' => 'projects_id',
                            'glpi_projects'     => 'id',
                        ],
                    ],
                ],
                'WHERE' => ['glpi_projecttasks.projecttasks_id' => $parentId],
                'ORDER' => 'glpi_projecttasks.plan_end_date ASC',
            ]) as $row
        ) {
            $pct = (int) $row['percent_done'];

            $tasks[] = [
                'id'          => (int) $row['id'],
                'name'        => $row['name'],
                'url'         => ProjectTask::getFormURLWithID((int) $row['id']),
                'project'     => $row['project_name'] ?? '—',
                'team'        => [],
                'children'    => 0,
                'percent'     => $pct,
                'state_name'  => $states[(int) $row['projectstates_id']]['name'] ?? null,
                'state_color' => $states[(int) $row['projectstates_id']]['color'] ?? self::PHASE_DEFAULT_COLOR,
                'end'         => $row['plan_end_date'] ? DateFmt::date($row['plan_end_date']) : null,
                'is_overdue'  => !empty($row['plan_end_date'])
                    && strtotime($row['plan_end_date']) < $now
                    && $pct < 100,
                'deadline'    => Deadline::compute(
                    $row['plan_start_date'],
                    $row['real_start_date'],
                    $row['plan_end_date'],
                    $pct
                ),
            ];
        }

        self::attachTeamAndChildren($tasks);

        return $tasks;
    }

    /**
     * Anexa, em consultas únicas, os responsáveis (equipe User) e a
     * contagem de subtarefas diretas às tarefas listadas.
     */
    private static function attachTeamAndChildren(array &$tasks): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (empty($tasks)) {
            return;
        }
        $ids = array_column($tasks, 'id');

        $byTid = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    'glpi_projecttaskteams.projecttasks_id',
                    'glpi_users.realname', 'glpi_users.firstname',
                    'glpi_users.name AS login',
                ],
                'FROM'      => 'glpi_projecttaskteams',
                'LEFT JOIN' => [
                    'glpi_users' => [
                        'ON' => [
                            'glpi_projecttaskteams' => 'items_id',
                            'glpi_users'            => 'id',
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_projecttaskteams.itemtype'        => 'User',
                    'glpi_projecttaskteams.projecttasks_id' => $ids,
                ],
            ]) as $row
        ) {
            $label = trim(($row['realname'] ?? '') . ' ' . ($row['firstname'] ?? ''));
            $byTid[(int) $row['projecttasks_id']][] = $label !== '' ? $label : ($row['login'] ?? '?');
        }

        // Contagem em PHP: o iterator do GLPI 11 descarta os campos do
        // SELECT quando COUNT+GROUPBY são usados juntos (linhas voltavam
        // sem projecttasks_id) — contamos aqui, tabela é pequena.
        $counts = [];
        foreach (
            $DB->request([
                'SELECT' => 'projecttasks_id',
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => ['projecttasks_id' => $ids],
            ]) as $row
        ) {
            $pid          = (int) $row['projecttasks_id'];
            $counts[$pid] = ($counts[$pid] ?? 0) + 1;
        }

        // Comentários e dependências (Etapa 3, Bloco 4) — consultas únicas,
        // para os botões 💬/🔗 na tabela "Tarefas em andamento"
        $comments = TaskComment::countForTasks($ids);
        $deps     = TaskDep::countForTasks($ids);

        foreach ($tasks as &$t) {
            $t['team']     = $byTid[$t['id']] ?? [];
            $t['children'] = $counts[$t['id']] ?? 0;
            $t['comments'] = $comments[$t['id']] ?? 0;
            $t['deps']     = $deps[$t['id']]['deps'] ?? 0;
            $t['blocked']  = $deps[$t['id']]['blocked'] ?? false;
        }
        unset($t);
    }

    /**
     * "Minhas tarefas" (Etapa 3, Bloco 1) — tarefas em que o usuário está
     * na equipe (itemtype User), agrupadas por projeto, com KPIs pessoais.
     *
     * As tarefas usam o MESMO formato de getTasks() para reaproveitar a
     * renderização e a edição inline do JS (taskTableHtml/bindTaskRows).
     *
     * @param int  $userId      usuário logado
     * @param bool $includeDone inclui tarefas 100% concluídas na listagem
     *                          (o KPI "done" é contado sempre)
     */
    public static function getMyTasks(int $userId, bool $includeDone = false): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $states = self::getStatesMap();
        $now    = time();

        $kpis = ['open' => 0, 'overdue' => 0, 'nodates' => 0, 'done' => 0];

        $groups  = []; // project_id => ['project_id','project_name','project_url','tasks']
        $taskIds = [];

        foreach (
            $DB->request([
                'SELECT'     => [
                    'glpi_projecttasks.id', 'glpi_projecttasks.name',
                    'glpi_projecttasks.percent_done',
                    'glpi_projecttasks.plan_start_date', 'glpi_projecttasks.plan_end_date',
                    'glpi_projecttasks.real_start_date',
                    'glpi_projecttasks.projectstates_id',
                    'glpi_projecttasks.projecttasks_id',
                    'glpi_projecttasks.auto_percent_done',
                    'parent_task.name AS parent_name',
                    'glpi_projects.id AS project_id',
                    'glpi_projects.name AS project_name',
                ],
                'FROM'       => 'glpi_projecttaskteams',
                'INNER JOIN' => [
                    'glpi_projecttasks' => [
                        'ON' => [
                            'glpi_projecttaskteams' => 'projecttasks_id',
                            'glpi_projecttasks'     => 'id',
                        ],
                    ],
                    'glpi_projects' => [
                        'ON' => [
                            'glpi_projecttasks' => 'projects_id',
                            'glpi_projects'     => 'id',
                        ],
                    ],
                ],
                'LEFT JOIN'  => [
                    'glpi_projecttasks AS parent_task' => [
                        'ON' => [
                            'glpi_projecttasks' => 'projecttasks_id',
                            'parent_task'       => 'id',
                        ],
                    ],
                ],
                'WHERE'      => [
                    'glpi_projecttaskteams.itemtype' => 'User',
                    'glpi_projecttaskteams.items_id' => $userId,
                    'glpi_projects.is_deleted'       => 0,
                    'glpi_projects.is_template'      => 0,
                ] + getEntitiesRestrictCriteria('glpi_projects'),
                'ORDER'      => ['glpi_projects.name', 'glpi_projecttasks.plan_end_date'],
            ]) as $row
        ) {
            $pct      = (int) $row['percent_done'];
            $deadline = Deadline::compute(
                $row['plan_start_date'],
                $row['real_start_date'],
                $row['plan_end_date'],
                $pct
            );

            // KPIs pessoais (contados sobre TODAS as tarefas do usuário)
            if ($pct >= 100) {
                $kpis['done']++;
                if (!$includeDone) {
                    continue;
                }
            } else {
                $kpis['open']++;
                if (!empty($row['plan_end_date']) && strtotime($row['plan_end_date']) < $now) {
                    $kpis['overdue']++;
                }
                if ($deadline['state'] === 'none') {
                    $kpis['nodates']++;
                }
            }

            $pid = (int) $row['project_id'];
            if (!isset($groups[$pid])) {
                $groups[$pid] = [
                    'project_id'   => $pid,
                    'project_name' => $row['project_name'],
                    'project_url'  => Project::getFormURLWithID($pid),
                    'tasks'        => [],
                ];
            }

            $id        = (int) $row['id'];
            $taskIds[] = $id;
            $stateId   = (int) $row['projectstates_id'];

            $groups[$pid]['tasks'][] = [
                'id'           => $id,
                'name'         => $row['name'],
                'url'          => ProjectTask::getFormURLWithID($id),
                'depth'        => 0,
                'parent_id'    => (int) $row['projecttasks_id'],
                'parent_name'  => $row['parent_name'],
                'auto_percent' => (bool) $row['auto_percent_done'],
                'percent'      => $pct,
                'start'       => $row['plan_start_date'] ? DateFmt::date($row['plan_start_date']) : null,
                'end'         => $row['plan_end_date'] ? DateFmt::date($row['plan_end_date']) : null,
                'start_iso'   => $row['plan_start_date'] ? substr($row['plan_start_date'], 0, 10) : '',
                'end_iso'     => $row['plan_end_date'] ? substr($row['plan_end_date'], 0, 10) : '',
                'state_id'    => $stateId,
                'state_name'  => $states[$stateId]['name'] ?? '—',
                'state_color' => $states[$stateId]['color'] ?? self::PHASE_DEFAULT_COLOR,
                'team'        => [],
                'deadline'    => $deadline,
            ];
        }

        // Árvore dentro de cada projeto: filha aninhada sob a mãe quando a
        // mãe também está na lista do usuário; caso contrário fica na raiz
        // (o JS mostra "Mãe › " como contexto via parent_name).
        foreach ($groups as &$g) {
            $inList = [];
            foreach ($g['tasks'] as $t) {
                $inList[$t['id']] = true;
            }
            $byParent = [];
            foreach ($g['tasks'] as $t) {
                $key = isset($inList[$t['parent_id']]) ? $t['parent_id'] : 0;
                if ($key !== 0) {
                    $t['parent_name'] = null; // aninhada: dispensa o contexto textual
                }
                $byParent[$key][] = $t;
            }
            $ordered = [];
            $walk = function (int $parentId, int $depth) use (&$walk, &$ordered, $byParent) {
                foreach ($byParent[$parentId] ?? [] as $t) {
                    $t['depth'] = $depth;
                    $ordered[]  = $t;
                    $walk($t['id'], $depth + 1);
                }
            };
            $walk(0, 0);
            $g['tasks'] = $ordered;
        }
        unset($g);

        // Equipe completa (User) das tarefas listadas, em consulta única
        if (!empty($taskIds)) {
            $byTid = [];
            foreach (
                $DB->request([
                    'SELECT'    => [
                        'glpi_projecttaskteams.projecttasks_id',
                        'glpi_users.realname', 'glpi_users.firstname',
                        'glpi_users.name AS login',
                    ],
                    'FROM'      => 'glpi_projecttaskteams',
                    'LEFT JOIN' => [
                        'glpi_users' => [
                            'ON' => [
                                'glpi_projecttaskteams' => 'items_id',
                                'glpi_users'            => 'id',
                            ],
                        ],
                    ],
                    'WHERE' => [
                        'glpi_projecttaskteams.itemtype'        => 'User',
                        'glpi_projecttaskteams.projecttasks_id' => $taskIds,
                    ],
                ]) as $row
            ) {
                $label = trim(($row['realname'] ?? '') . ' ' . ($row['firstname'] ?? ''));
                $byTid[(int) $row['projecttasks_id']][] = $label !== '' ? $label : ($row['login'] ?? '?');
            }
            // Contador de comentários (Etapa 3, Bloco 2) — consulta única
            $comments = TaskComment::countForTasks($taskIds);
            // Dependências (Etapa 3, Bloco 3) — consulta única
            $deps = TaskDep::countForTasks($taskIds);

            foreach ($groups as &$g) {
                foreach ($g['tasks'] as &$t) {
                    $t['team']     = $byTid[$t['id']] ?? [];
                    $t['comments'] = $comments[$t['id']] ?? 0;
                    $t['deps']     = $deps[$t['id']]['deps'] ?? 0;
                    $t['blocked']  = $deps[$t['id']]['blocked'] ?? false;
                }
                unset($t);
            }
            unset($g);
        }

        return [
            'kpis'   => $kpis,
            'groups' => array_values($groups),
        ];
    }

    /**
     * Feed "Atividades recentes" — alimentado pelos alertas do plugin.
     */
    public static function getActivities(int $limit = 8): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $icons = [
            'completed'        => ['icon' => '✓', 'class' => 'ok'],
            'pending'          => ['icon' => '⏱', 'class' => 'warn'],
            'overdue'          => ['icon' => '!', 'class' => 'over'],
            'stalled'          => ['icon' => '⏸', 'class' => 'warn'],
            'budget_warn'      => ['icon' => '$', 'class' => 'warn'],
            'budget_over'      => ['icon' => '$', 'class' => 'over'],
            'deadline_50'      => ['icon' => '%', 'class' => 'warn'],
            'deadline_75'      => ['icon' => '%', 'class' => 'warn'],
            'deadline_90'      => ['icon' => '%', 'class' => 'over'],
            'deadline_over'    => ['icon' => '!', 'class' => 'over'],
            'deadline_nodates' => ['icon' => '?', 'class' => 'warn'],
            'comment'          => ['icon' => '💬', 'class' => 'ok'],
        ];

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => 'glpi_plugin_projectplus_alerts',
                'ORDER' => 'date_creation DESC',
                'LIMIT' => $limit,
            ]) as $row
        ) {
            $meta  = $icons[$row['kind']] ?? ['icon' => '•', 'class' => 'ok'];
            $out[] = [
                'icon'    => $meta['icon'],
                'class'   => $meta['class'],
                'message' => $row['message'],
                'date'    => $row['date_creation']
                    ? DateFmt::dateTime($row['date_creation']) : '',
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Subprojetos e tarefas por projeto (Blocos 3 / 3.1 — inalterados)
    // ------------------------------------------------------------------

    public static function getChildren(int $parentId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'SELECT' => [
                'id', 'name', 'percent_done', 'plan_end_date',
                'plan_start_date', 'real_start_date', 'date_mod',
                'projectstates_id',
            ],
            'FROM'   => 'glpi_projects',
            'WHERE'  => [
                'projects_id' => $parentId,
                'is_deleted'  => 0,
                'is_template' => 0,
            ] + getEntitiesRestrictCriteria('glpi_projects'),
            'ORDER'  => 'name',
        ]);

        $children = [];
        $now      = time();
        $states   = self::getStatesMap();
        foreach ($iterator as $row) {
            $childId  = (int) $row['id'];
            $tracking = ProjectTracking::getForProject($childId);

            $isOverdue = !empty($row['plan_end_date'])
                && strtotime($row['plan_end_date']) < $now
                && (int) $row['percent_done'] < 100;

            $budget     = Budget::getForProject($childId);
            $budgetInfo = null;
            if ($budget['planned'] > 0) {
                $budgetInfo = [
                    'planned_fmt' => number_format($budget['planned'], 2, ',', '.'),
                    'spent_fmt'   => number_format($budget['spent_total'], 2, ',', '.'),
                    'percent'     => $budget['percent'],
                    'state'       => $budget['percent'] > 100 ? 'over'
                        : ($budget['percent'] >= 80 ? 'warn' : 'ok'),
                ];
            }

            $lastActivity = $tracking['last_activity'] ?? $row['date_mod'];

            $childState = (int) $row['projectstates_id'];

            $children[] = [
                'id'            => $childId,
                'name'          => $row['name'],
                'state_name'    => $states[$childState]['name'] ?? null,
                'state_color'   => $states[$childState]['color'] ?? self::PHASE_DEFAULT_COLOR,
                'percent_done'  => (int) $row['percent_done'],
                'plan_end_date' => $row['plan_end_date'],
                'last_activity' => $lastActivity ? DateFmt::dateTime($lastActivity) : null,
                'is_stalled'    => (bool) ($tracking['is_stalled'] ?? false),
                'is_overdue'    => $isOverdue,
                'budget'        => $budgetInfo,
                'deadline'      => Deadline::compute(
                    $row['plan_start_date'],
                    $row['real_start_date'],
                    $row['plan_end_date'],
                    (int) $row['percent_done']
                ),
                'url'           => Project::getFormURLWithID($childId),
            ];
        }

        // Regra geral (Etapa 3, Bloco 3 / Fix 1): subprojeto com filhos
        // abertos também mostra 🔒
        $blockedProjects = TaskDep::blockedProjects(array_column($children, 'id'));
        foreach ($children as &$c) {
            $c['blocked'] = $blockedProjects[$c['id']] ?? false;
        }
        unset($c);

        return $children;
    }

    private static function countChildren(int $parentId): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_projects',
            'WHERE' => [
                'projects_id' => $parentId,
                'is_deleted'  => 0,
                'is_template' => 0,
            ],
        ])->current();

        return (int) ($row['cpt'] ?? 0);
    }

    public static function getTasks(int $projectId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $states = self::getStatesMap();

        $byParent = [];
        foreach (
            $DB->request([
                'SELECT' => [
                    'id', 'name', 'projecttasks_id', 'percent_done',
                    'plan_start_date', 'plan_end_date', 'real_start_date',
                    'projectstates_id', 'auto_percent_done',
                ],
                'FROM'  => 'glpi_projecttasks',
                'WHERE' => ['projects_id' => $projectId],
                'ORDER' => ['projecttasks_id', 'id'],
            ]) as $row
        ) {
            $byParent[(int) $row['projecttasks_id']][] = $row;
        }

        if (empty($byParent)) {
            return [];
        }

        $teams = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    'glpi_projecttaskteams.projecttasks_id',
                    'glpi_users.realname',
                    'glpi_users.firstname',
                    'glpi_users.name AS login',
                ],
                'FROM'      => 'glpi_projecttaskteams',
                'LEFT JOIN' => [
                    'glpi_users' => [
                        'ON' => [
                            'glpi_projecttaskteams' => 'items_id',
                            'glpi_users'            => 'id',
                        ],
                    ],
                ],
                'WHERE' => ['glpi_projecttaskteams.itemtype' => 'User'],
            ]) as $row
        ) {
            $label = trim(($row['realname'] ?? '') . ' ' . ($row['firstname'] ?? ''));
            $teams[(int) $row['projecttasks_id']][] = $label !== '' ? $label : ($row['login'] ?? '?');
        }

        $out  = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $byParent, $states, $teams) {
            foreach ($byParent[$parentId] ?? [] as $t) {
                $id    = (int) $t['id'];
                $out[] = [
                    'id'           => $id,
                    'name'         => $t['name'],
                    'url'          => ProjectTask::getFormURLWithID($id),
                    'depth'        => $depth,
                    'auto_percent' => (bool) $t['auto_percent_done'],
                    'percent'      => (int) $t['percent_done'],
                    'start'      => $t['plan_start_date'] ? DateFmt::date($t['plan_start_date']) : null,
                    'end'        => $t['plan_end_date'] ? DateFmt::date($t['plan_end_date']) : null,
                    'start_iso'  => $t['plan_start_date'] ? substr($t['plan_start_date'], 0, 10) : '',
                    'end_iso'    => $t['plan_end_date'] ? substr($t['plan_end_date'], 0, 10) : '',
                    'state_id'    => (int) $t['projectstates_id'],
                    'state_name'  => $states[(int) $t['projectstates_id']]['name'] ?? '—',
                    'state_color' => $states[(int) $t['projectstates_id']]['color'] ?? self::PHASE_DEFAULT_COLOR,
                    'team'       => $teams[$id] ?? [],
                    'deadline'   => Deadline::compute(
                        $t['plan_start_date'],
                        $t['real_start_date'],
                        $t['plan_end_date'],
                        (int) $t['percent_done']
                    ),
                ];
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);

        // Contador de comentários (Etapa 3, Bloco 2) — consulta única
        $comments = TaskComment::countForTasks(array_column($out, 'id'));
        // Dependências (Etapa 3, Bloco 3) — consulta única
        $deps = TaskDep::countForTasks(array_column($out, 'id'));
        foreach ($out as &$t) {
            $t['comments'] = $comments[$t['id']] ?? 0;
            $t['deps']     = $deps[$t['id']]['deps'] ?? 0;
            $t['blocked']  = $deps[$t['id']]['blocked'] ?? false;
        }
        unset($t);

        return $out;
    }
}

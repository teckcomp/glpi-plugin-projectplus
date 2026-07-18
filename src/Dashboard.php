<?php

namespace GlpiPlugin\Projectplus;

use CommonGLPI;
use Plugin;
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
        return __('Painel de Projetos', 'projectplus');
    }

    public static function getMenuContent()
    {
        return [
            'title' => self::getMenuName(),
            'page'  => Plugin::getWebDir('projectplus') . '/front/dashboard.php',
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
    private static function periodCriteria(?string $from, ?string $until, string $prefix): array
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
    // Dados do painel
    // ------------------------------------------------------------------

    public static function getData(?string $from = null, ?string $until = null): array
    {
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

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_projects.id', 'glpi_projects.name',
                'glpi_projects.percent_done', 'glpi_projects.plan_end_date',
                'glpi_projects.plan_start_date', 'glpi_projects.real_start_date',
                'glpi_projects.date_mod', 'glpi_projects.priority',
            ],
            'FROM'  => 'glpi_projects',
            'WHERE' => $where,
            'ORDER' => 'glpi_projects.date_mod DESC',
        ]);

        $projects  = [];
        $progress  = [];
        $kpis      = [
            'active' => 0, 'avg_progress' => 0, 'open_tasks' => 0,
            'resources' => 0, 'overdue' => 0, 'done_month' => 0, 'on_time' => 0,
        ];
        $chart     = ['done' => 0, 'in_progress' => 0, 'planned' => 0, 'overdue' => 0];
        $prioChart = ['high' => 0, 'medium' => 0, 'low' => 0];
        $pctSum    = 0;

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

            $projects[] = [
                'id'            => (int) $row['id'],
                'name'          => $row['name'],
                'percent_done'  => $pct,
                'plan_end_date' => $row['plan_end_date'],
                'last_activity' => $tracking['last_activity'] ?? $row['date_mod'],
                'is_stalled'    => (bool) ($tracking['is_stalled'] ?? false),
                'is_overdue'    => $isOverdue,
                'children'      => $children,
                'budget'        => $budgetInfo,
                'deadline'      => Deadline::compute(
                    $row['plan_start_date'],
                    $row['real_start_date'],
                    $row['plan_end_date'],
                    $pct
                ),
                'url'           => Project::getFormURLWithID((int) $row['id']),
            ];

            if ($pct < 100) {
                $progress[] = ['name' => $row['name'], 'percent' => $pct];
            }

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

        $tasksChart = ['done' => 0, 'in_progress' => 0, 'pending' => 0, 'overdue' => 0];
        foreach (
            $DB->request([
                'SELECT' => ['percent_done', 'plan_end_date'],
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
        }
        $kpis['open_tasks'] = $tasksChart['in_progress'] + $tasksChart['pending'] + $tasksChart['overdue'];

        // --- Recursos alocados: pessoas distintas em tarefas abertas ---
        $res = $DB->request([
            'SELECT'    => ['COUNT DISTINCT' => 'glpi_projecttaskteams.items_id AS cpt'],
            'FROM'      => 'glpi_projecttaskteams',
            'LEFT JOIN' => [
                'glpi_projecttasks' => [
                    'ON' => [
                        'glpi_projecttaskteams' => 'projecttasks_id',
                        'glpi_projecttasks'     => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_projecttaskteams.itemtype'  => 'User',
                'glpi_projecttasks.percent_done'  => ['<', 100],
            ],
        ])->current();
        $kpis['resources'] = (int) ($res['cpt'] ?? 0);

        // --- Concluídos este mês (percent 100 com modificação no mês corrente) ---
        $doneMonth = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_projects',
            'WHERE' => [
                'is_deleted'   => 0,
                'is_template'  => 0,
                'percent_done' => ['>=', 100],
                'date_mod'     => ['>=', date('Y-m-01 00:00:00')],
            ],
        ])->current();
        $kpis['done_month'] = (int) ($doneMonth['cpt'] ?? 0);

        return [
            'kpis'           => $kpis,
            'status_chart'   => $chart,
            'priority_chart' => $prioChart,
            'tasks_chart'    => $tasksChart,
            'projects'       => $projects,
            'progress'       => array_slice($progress, 0, 8),
            'open_tasks'     => self::getOpenTasks($from, $until, 15),
            'activities'     => self::getActivities(8),
        ];
    }

    /**
     * Tarefas em andamento (globais), para a tabela da linha de tarefas.
     */
    public static function getOpenTasks(?string $from, ?string $until, int $limit = 15): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = ['glpi_projecttasks.percent_done' => ['<', 100]];
        foreach (self::periodCriteria($from, $until, 'glpi_projecttasks.') as $c) {
            $where[] = $c;
        }

        $states = [];
        foreach ($DB->request(['FROM' => 'glpi_projectstates']) as $s) {
            $states[(int) $s['id']] = $s['name'];
        }

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
                'percent'    => (int) $row['percent_done'],
                'state_name' => $states[(int) $row['projectstates_id']] ?? '—',
                'end'        => $row['plan_end_date'] ? date('d/m/Y', strtotime($row['plan_end_date'])) : null,
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
        return $tasks;
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
                    ? date('d/m/Y H:i', strtotime($row['date_creation'])) : '',
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

            $children[] = [
                'id'            => $childId,
                'name'          => $row['name'],
                'percent_done'  => (int) $row['percent_done'],
                'plan_end_date' => $row['plan_end_date'],
                'last_activity' => $lastActivity ? date('d/m/Y H:i', strtotime($lastActivity)) : null,
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

        $states = [];
        foreach ($DB->request(['FROM' => 'glpi_projectstates']) as $s) {
            $states[(int) $s['id']] = $s['name'];
        }

        $byParent = [];
        foreach (
            $DB->request([
                'SELECT' => [
                    'id', 'name', 'projecttasks_id', 'percent_done',
                    'plan_start_date', 'plan_end_date', 'real_start_date',
                    'projectstates_id',
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
                    'id'         => $id,
                    'name'       => $t['name'],
                    'url'        => ProjectTask::getFormURLWithID($id),
                    'depth'      => $depth,
                    'percent'    => (int) $t['percent_done'],
                    'start'      => $t['plan_start_date'] ? date('d/m/Y', strtotime($t['plan_start_date'])) : null,
                    'end'        => $t['plan_end_date'] ? date('d/m/Y', strtotime($t['plan_end_date'])) : null,
                    'start_iso'  => $t['plan_start_date'] ? substr($t['plan_start_date'], 0, 10) : '',
                    'end_iso'    => $t['plan_end_date'] ? substr($t['plan_end_date'], 0, 10) : '',
                    'state_id'   => (int) $t['projectstates_id'],
                    'state_name' => $states[(int) $t['projectstates_id']] ?? '—',
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

        return $out;
    }
}

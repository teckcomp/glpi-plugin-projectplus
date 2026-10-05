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
            // Bloco F-1b: sem Painel, o menu abre em Minhas tarefas/Kanban/...
            'page'  => Url::to(Access::homePath() ?? 'front/dashboard.php'),
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
     * Mapa id => ['name' => ..., 'color' => ..., 'is_finished' => ...] das
     * fases. Cor sempre preenchida (fallback cinza) para uso direto nos chips.
     *
     * GARGALO ÚNICO DE FASES (lição 60): Kanban de tarefas, Kanban de
     * projetos, Timeline, donuts da Visão geral, filtro de Relatórios e 3
     * `front/` passam por aqui. A Etapa 9 acrescentou o parâmetro `$typeId`
     * — e é por isso que "fases por tipo" se propaga sozinha.
     *
     * @param ?int $typeId null = TODAS as fases da instância (é o que se usa
     *                     para RESOLVER nome/cor de um chip: a fase de um
     *                     item tem de aparecer mesmo que não pertença ao
     *                     conjunto do tipo dele). Um id de tipo devolve o
     *                     CONJUNTO ORDENADO daquele tipo — é o que monta
     *                     COLUNAS e listas de opção.
     *
     * @return array<int, array{name:string,color:string,is_finished:bool}>
     */
    public static function getStatesMap(?int $typeId = null): array
    {
        return TypePhase::statesFor($typeId);
    }

    // ------------------------------------------------------------------
    // Dados do painel
    // ------------------------------------------------------------------

    /**
     * @param ?int $typeId Etapa 9 — tipo de projeto selecionado no filtro.
     *                     null = todos os tipos (o donut de fase vira
     *                     "Projetos por tipo").
     */
    public static function getData(
        ?string $from = null,
        ?string $until = null,
        ?array $projectIds = null,
        ?array $myTaskIds = null,
        ?array $taskProjectIds = null,
        ?int $typeId = null,
        ?array $focusProjectIds = null
    ): array {
        /** @var \DBmysql $DB */
        global $DB;

        $now = time();

        // Bloco F-1: $myTaskIds passou a vir preenchido também no managed
        // (tarefas = só as minhas). A lista PLANA de projetos continua sendo
        // só do personal — o gestor segue expandindo os subprojetos dos
        // projetos que gerencia. personal = minhas tarefas sem lista de
        // projetos por tarefa; managed = as duas; all = nenhuma.
        $flatProjects = ($myTaskIds !== null && $taskProjectIds === null);
        $childAllowed = null;
        if ($taskProjectIds !== null) {
            $childAllowed = [];
            foreach (array_merge((array) $projectIds, $taskProjectIds) as $id) {
                $childAllowed[(int) $id] = true;
            }
        }

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

        // Etapa 9 — filtro por TIPO de projeto. Com tipo selecionado o painel
        // fala de um vocabulário só: os projetos são do tipo, as tarefas são
        // dos projetos do tipo e o donut de fase usa o conjunto do tipo.
        $typeProjectIds = null;
        if ($typeId !== null) {
            $where['glpi_projects.projecttypes_id'] = $typeId;
            $typeProjectIds = self::projectIdsOfType($typeId);
        }

        // Bloco A-2 (29/09/2026) — FOCO em um projeto (?project=ID). A lista
        // de projetos já vinha restrita por $projectIds, mas "Tarefas em
        // andamento", os KPIs e os donuts continuavam falando do escopo
        // inteiro: era o que mostrava tarefa de outro projeto na tela de um.
        // O foco entra pelo MESMO canal do filtro de tipo — a lista de
        // projetos que restringe as consultas que partem de
        // glpi_projecttasks — e por interseção, então nunca AMPLIA o escopo.
        if ($focusProjectIds !== null) {
            $typeProjectIds = self::intersectIds($typeProjectIds, $focusProjectIds);
        }

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_projects.id', 'glpi_projects.name',
                'glpi_projects.percent_done', 'glpi_projects.plan_end_date',
                'glpi_projects.plan_start_date', 'glpi_projects.real_start_date',
                'glpi_projects.date_mod', 'glpi_projects.priority',
                'glpi_projects.projectstates_id', 'glpi_projects.projecttypes_id',
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

        // Mapa COMPLETO de fases: resolve nome/cor do chip de cada projeto,
        // inclusive de fase que não pertence ao conjunto do tipo (Etapa 9 —
        // o chip nunca deve ficar sem nome).
        $states     = self::getStatesMap();
        $phaseCount = []; // states_id => quantidade (0 = sem fase)
        $typeCount  = []; // projecttypes_id => quantidade (0 = sem tipo)

        foreach ($iterator as $row) {
            $pct       = (int) $row['percent_done'];
            $isOverdue = !empty($row['plan_end_date'])
                && strtotime($row['plan_end_date']) < $now
                && $pct < 100;

            $tracking = ProjectTracking::getForProject((int) $row['id']);
            $children = $flatProjects ? 0 : self::countChildren((int) $row['id'], $childAllowed);

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

            $ptypeId = (int) ($row['projecttypes_id'] ?? 0);
            $typeCount[$ptypeId] = ($typeCount[$ptypeId] ?? 0) + 1;

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
                'children'      => $children,
                'budget'        => $budgetInfo,
                'deadline'      => Deadline::compute(
                    $row['plan_start_date'],
                    $row['real_start_date'],
                    $row['plan_end_date'],
                    $pct
                ),
                'url'           => Url::project((int) $row['id']),
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
        // Etapa 9: com tipo selecionado as tarefas vêm só dos projetos daquele
        // tipo. Lição 35 — duas restrições sobre a MESMA chave do WHERE se
        // sobrescrevem, então aqui é INTERSEÇÃO (o tipo nunca amplia o escopo).
        if ($typeProjectIds !== null) {
            $taskWhere['projects_id'] = Scope::inList(
                ($myTaskIds !== null)
                    ? $typeProjectIds
                    : self::intersectIds($taskProjectIds, $typeProjectIds)
            );
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
        if ($typeProjectIds !== null) {
            // Interseção, mesma razão do bloco de tarefas acima (lição 35).
            $doneMonthWhere['id'] = Scope::inList(
                self::intersectIds($projectIds, $typeProjectIds)
            );
        }
        $doneMonth = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_projects',
            'WHERE' => $doneMonthWhere,
        ])->current();
        $kpis['done_month'] = (int) ($doneMonth['cpt'] ?? 0);

        // --- Donut "Projetos por fase" (Etapa 2.5, Bloco 3) ---
        // Etapa 9: com tipo selecionado a ORDEM é a do conjunto do tipo
        // (coluna `ordem`), não mais a alfabética. Fase que tem projeto mas
        // está FORA do conjunto entra depois, para nada desaparecer do donut.
        $phaseOrder = ($typeId !== null) ? self::getStatesMap($typeId) : $states;

        $phaseChart = [];
        $inOrder    = [];
        foreach ($phaseOrder as $sid => $s) {
            $inOrder[$sid] = true;
            if (!empty($phaseCount[$sid])) {
                $phaseChart[] = [
                    'name'  => $s['name'],
                    'color' => $s['color'],
                    'count' => $phaseCount[$sid],
                ];
            }
        }
        foreach ($states as $sid => $s) {
            if (!isset($inOrder[$sid]) && !empty($phaseCount[$sid])) {
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
        // do phase_chart, agrupando as tarefas por glpi_projectstates.
        //
        // Etapa 9 (correção): segue o MESMO critério do donut de projetos —
        // com tipo selecionado, a ordem é a do conjunto do tipo, e não a
        // alfabética. Sem isto as CONTAGENS respeitavam o filtro (as tarefas
        // já vêm só dos projetos daquele tipo), mas a lista continuava vindo
        // do vocabulário inteiro da instância: filtrando por Infraestrutura
        // ainda aparecia fatia de fase de outro setor, que é exatamente a
        // mistura que esta etapa existe para acabar. ---
        $taskStateChart = [];
        foreach ($phaseOrder as $sid => $s) {
            if (!empty($taskStateCount[$sid])) {
                $taskStateChart[] = [
                    'name'  => $s['name'],
                    'color' => $s['color'],
                    'count' => $taskStateCount[$sid],
                ];
            }
        }
        // Fase com tarefa mas FORA do conjunto entra depois — some da ordem,
        // não do gráfico (mesma regra da coluna "Sem fase" no Kanban).
        foreach ($states as $sid => $s) {
            if (!isset($inOrder[$sid]) && !empty($taskStateCount[$sid])) {
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

        // --- Donut ADAPTATIVO (Etapa 9): sem tipo selecionado, "Projetos por
        // fase" daria um gráfico com os vocabulários de todos os setores
        // misturados. Nesse caso a Visão geral mostra "Projetos por tipo",
        // que é a leitura que faz sentido cruzando departamentos. ---
        $typeChart = [];
        foreach (TypePhase::projectTypes() as $tid => $tname) {
            if (!empty($typeCount[$tid])) {
                $typeChart[] = [
                    'name'  => $tname,
                    'color' => TypePhase::typeColor((int) $tid),
                    'count' => $typeCount[$tid],
                ];
            }
        }
        if (!empty($typeCount[0])) {
            $typeChart[] = [
                'name'  => __('Sem tipo', 'projectplus'),
                'color' => self::PHASE_DEFAULT_COLOR,
                'count' => $typeCount[0],
            ];
        }

        return [
            'kpis'             => $kpis,
            'status_chart'     => $chart,
            'priority_chart'   => $prioChart,
            'tasks_chart'      => $tasksChart,
            'phase_chart'      => $phaseChart,
            'type_chart'       => $typeChart,
            'task_state_chart' => $taskStateChart,
            'projects'         => $projects,
            'open_tasks'       => self::getOpenTasks(
                $from,
                $until,
                15,
                $myTaskIds,
                $taskProjectIds,
                $typeProjectIds
            ),
        ];
    }

    /**
     * Ids dos projetos de um tipo (não excluídos, não modelo, na entidade).
     * Etapa 9 — usado para levar o filtro de tipo às consultas que partem de
     * `glpi_projecttasks`, que não tem a coluna do tipo.
     *
     * @return array<int, int>
     */
    private static function projectIdsOfType(int $typeId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => ['id'],
                'FROM'   => 'glpi_projects',
                'WHERE'  => [
                    'projecttypes_id' => $typeId,
                    'is_deleted'      => 0,
                    'is_template'     => 0,
                ] + getEntitiesRestrictCriteria('glpi_projects'),
            ]) as $row
        ) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * Interseção de duas listas de ids, com null = "sem restrição".
     * Mesma semântica de Reports::combineIds (lição 35): o segundo filtro
     * nunca AMPLIA o primeiro.
     */
    private static function intersectIds(?array $a, ?array $b): ?array
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return array_values(array_intersect(array_map('intval', $a), array_map('intval', $b)));
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
        ?array $taskProjectIds = null,
        ?array $typeProjectIds = null
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
            // Rodada 3 — tipo selecionado restringe também o pessoal aos
            // projetos DAQUELE tipo (o filtro nunca amplia, só corta).
            if ($typeProjectIds !== null) {
                $where['glpi_projecttasks.projects_id'] = Scope::inList($typeProjectIds);
            }
        } else {
            // managed/todos: tarefas-raiz + expansão (comportamento original);
            // managed ainda restringe aos projetos do escopo.
            $where['glpi_projecttasks.projecttasks_id'] = 0;
            // Rodada 3 — INTERSEÇÃO escopo × tipo (lição 35: duas restrições
            // sobre a mesma chave do WHERE se sobrescrevem). Antes desta
            // correção o getData() já passava $typeProjectIds como 6º
            // argumento, mas a assinatura tinha só 5 parâmetros e o PHP
            // DESCARTAVA o filtro em silêncio: "Tarefas em andamento"
            // ignorava o tipo escolhido na Visão geral.
            $effectiveIds = self::intersectIds($taskProjectIds, $typeProjectIds);
            if ($effectiveIds !== null) {
                $where['glpi_projecttasks.projects_id'] = Scope::inList($effectiveIds);
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
                    'glpi_projecttasks.projects_id',
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
                'url'        => Url::project((int) $row['projects_id'], (int) $row['id']),
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
                    'glpi_projecttasks.projects_id',
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
                'url'         => Url::project((int) $row['projects_id'], (int) $row['id']),
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
            // Respeita a ordem de nome configurada no GLPI (config ou
            // preferência da sessão), em vez de fixar "Sobrenome Nome".
            $label = \formatUserName(0, $row['login'] ?? '', $row['realname'] ?? '', $row['firstname'] ?? '');
            $byTid[(int) $row['projecttasks_id']][] = $label !== '' ? $label : '?';
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

        // Bloco C (30/09/2026): os MESMOS KPIs recortados por projeto, para o
        // filtro de projeto da tela. Inclui projeto que só tem tarefa
        // concluída (some de `groups` com "Mostrar concluídas" desligado,
        // mas segue no filtro e no KPI "Concluídas" dele).
        $byProject = []; // project_id => ['id','name','kpis']

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
                    'glpi_projects.projecttypes_id AS project_type_id',
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

            $pid = (int) $row['project_id'];
            if (!isset($byProject[$pid])) {
                $byProject[$pid] = [
                    'id'   => $pid,
                    'name' => $row['project_name'],
                    // tipo do projeto (0 = sem tipo) — filtro de tipo do Bloco C
                    'type_id' => (int) ($row['project_type_id'] ?? 0),
                    'kpis' => ['open' => 0, 'overdue' => 0, 'nodates' => 0, 'done' => 0],
                ];
            }

            // KPIs pessoais (contados sobre TODAS as tarefas do usuário) —
            // no total e no recorte do projeto (filtro do Bloco C)
            $hits = self::myTaskKpiHits($pct, $row['plan_end_date'], $deadline['state'], $now);
            foreach ($hits as $k) {
                $kpis[$k]++;
                $byProject[$pid]['kpis'][$k]++;
            }
            if ($pct >= 100 && !$includeDone) {
                continue;
            }

            if (!isset($groups[$pid])) {
                $groups[$pid] = [
                    'project_id'   => $pid,
                    'project_name' => $row['project_name'],
                    'project_url'  => Url::project($pid),
                    'project_type_id' => (int) ($row['project_type_id'] ?? 0),
                    'tasks'        => [],
                ];
            }

            $id        = (int) $row['id'];
            $taskIds[] = $id;
            $stateId   = (int) $row['projectstates_id'];

            $groups[$pid]['tasks'][] = [
                'id'           => $id,
                'name'         => $row['name'],
                'url'          => Url::project($pid, $id),
                'depth'        => 0,
                'parent_id'    => (int) $row['projecttasks_id'],
                'parent_name'  => $row['parent_name'],
                'auto_percent' => (bool) $row['auto_percent_done'],
                'has_children' => false, // preenchido abaixo (consulta única)
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

        // Quais das tarefas listadas têm subtarefa (mesmo fora da lista do
        // usuário) — decide se o interruptor "Calcular automaticamente"
        // aparece na linha. Linhas trazidas e contadas em PHP (lição do
        // COUNT + GROUPBY do Iterator do GLPI 11).
        $withKids = self::tasksWithChildren($taskIds);
        foreach ($groups as &$g) {
            foreach ($g['tasks'] as &$t) {
                $t['has_children'] = isset($withKids[$t['id']]);
            }
            unset($t);
        }
        unset($g);

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
            // Bloco D-2a: id + nome (os chips editáveis precisam do id)
            $teamUsers = self::teamUsers($taskIds);
            // Contador de comentários (Etapa 3, Bloco 2) — consulta única
            $comments = TaskComment::countForTasks($taskIds);
            // Dependências (Etapa 3, Bloco 3) — consulta única
            $deps = TaskDep::countForTasks($taskIds);

            foreach ($groups as &$g) {
                foreach ($g['tasks'] as &$t) {
                    $t['team_users'] = $teamUsers[$t['id']] ?? [];
                    $t['team']       = array_column($t['team_users'], 'name');
                    $t['comments'] = $comments[$t['id']] ?? 0;
                    $t['deps']     = $deps[$t['id']]['deps'] ?? 0;
                    $t['blocked']  = $deps[$t['id']]['blocked'] ?? false;
                }
                unset($t);
            }
            unset($g);
        }

        return [
            'kpis'     => $kpis,
            'groups'   => array_values($groups),
            // Ordem do SQL (nome do projeto) — mesma ordem dos grupos
            'projects' => array_values($byProject),
        ];
    }

    /**
     * Opções do filtro "Projeto" de "Minhas tarefas" (Bloco C-3, 30/09/2026).
     *
     * Decisão do Claudio: Tipo → Projeto lista os projetos DO TIPO que o
     * usuário enxerga (mesmo sem tarefa dele); a lista de tarefas continua
     * sendo só as dele. "Enxerga" = o mesmo escopo das outras telas
     * (Scope::projectIds() + descendentes do managed via taskProjectIds()).
     * `$projectIds === null` = modo "todos" (sem filtro de projeto).
     * Lista vazia nunca vira "sem filtro" (Scope::inList → [0]).
     *
     * @return array<int, array{id:int, name:string, type_id:int}>
     */
    public static function myTasksProjectOptions(?array $projectIds, ?array $taskProjectIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = [
            'glpi_projects.is_deleted'  => 0,
            'glpi_projects.is_template' => 0,
        ] + getEntitiesRestrictCriteria('glpi_projects');

        if ($projectIds !== null) {
            $ids = [];
            foreach (array_merge($projectIds, (array) $taskProjectIds) as $id) {
                $ids[(int) $id] = true;
            }
            $where['glpi_projects.id'] = Scope::inList(array_keys($ids));
        }

        $out = [];
        foreach (
            $DB->request([
                'SELECT' => ['glpi_projects.id', 'glpi_projects.name', 'glpi_projects.projecttypes_id'],
                'FROM'   => 'glpi_projects',
                'WHERE'  => $where,
                'ORDER'  => 'glpi_projects.name',
            ]) as $row
        ) {
            $out[] = [
                'id'      => (int) $row['id'],
                'name'    => (string) $row['name'],
                'type_id' => (int) ($row['projecttypes_id'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Quais KPIs de "Minhas tarefas" uma tarefa soma (Bloco C, 30/09/2026).
     *
     * Regra única para o total e para o recorte por projeto — extraída do
     * laço de getMyTasks() sem mudar o critério: concluída = 100%; atrasada
     * = aberta com prazo final vencido; sem datas = aberta com Deadline
     * "none".
     *
     * @return string[] subconjunto de ['open','overdue','nodates','done']
     */
    public static function myTaskKpiHits(int $pct, ?string $planEnd, string $deadlineState, int $now): array
    {
        if ($pct >= 100) {
            return ['done'];
        }
        $hits = ['open'];
        if (!empty($planEnd) && strtotime($planEnd) < $now) {
            $hits[] = 'overdue';
        }
        if ($deadlineState === 'none') {
            $hits[] = 'nodates';
        }
        return $hits;
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

    /**
     * @param array<int, true>|null $allowed Bloco F-1: subprojetos visíveis
     *        no escopo (Scope::visibleProjectMap); null = todos.
     */
    public static function getChildren(int $parentId, ?array $allowed = null): array
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
            if ($allowed !== null && !isset($allowed[(int) $row['id']])) {
                continue;
            }
            $children[] = self::projectRowData($row, $states, $now);
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

    /**
     * Linha da tabela de projetos (MESMO formato do `children`): fase,
     * progresso, última atividade, situação, orçamento, prazo. Extraída do
     * getChildren no Bloco D-3a para servir também à faixa "Projeto", que
     * redesenha a linha depois de editar.
     *
     * @param array $row linha de glpi_projects com id, name, percent_done,
     *                   plan_*_date, real_start_date, date_mod, projectstates_id
     */
    private static function projectRowData(array $row, array $states, int $now): array
    {
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

        return [
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
            'url'           => Url::project($childId),
        ];
    }

    /**
     * Bloco D-3a — linhas (formato `children`, com `blocked`) dos projetos
     * pedidos, na entidade do usuário. Usado depois de editar pela faixa:
     * o projeto e os ANCESTRAIS (o % automático sobe para eles).
     *
     * @param int[] $ids
     */
    public static function getProjectRows(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }
        $out    = [];
        $now    = time();
        $states = self::getStatesMap();
        foreach (
            $DB->request([
                'SELECT' => [
                    'id', 'name', 'percent_done', 'plan_end_date',
                    'plan_start_date', 'real_start_date', 'date_mod',
                    'projectstates_id',
                ],
                'FROM'   => 'glpi_projects',
                'WHERE'  => ['id' => $ids, 'is_deleted' => 0]
                    + getEntitiesRestrictCriteria('glpi_projects'),
            ]) as $row
        ) {
            $out[] = self::projectRowData($row, $states, $now);
        }
        $blocked = TaskDep::blockedProjects(array_column($out, 'id'));
        foreach ($out as &$r) {
            $r['blocked'] = $blocked[$r['id']] ?? false;
        }
        unset($r);
        return $out;
    }

    /**
     * Bloco D-3a — ids dos projetos ACIMA deste (pai, avô...), do mais
     * próximo para o mais distante. Para em ciclo ou profundidade 20.
     *
     * @return int[]
     */
    public static function projectAncestors(int $projectId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out  = [];
        $seen = [$projectId => true];
        $cur  = $projectId;
        for ($i = 0; $i < 20; $i++) {
            $row = $DB->request([
                'SELECT' => ['projects_id'],
                'FROM'   => 'glpi_projects',
                'WHERE'  => ['id' => $cur],
            ])->current();
            $parent = (int) ($row['projects_id'] ?? 0);
            if ($parent <= 0 || isset($seen[$parent])) {
                break;
            }
            $out[]         = $parent;
            $seen[$parent] = true;
            $cur           = $parent;
        }
        return $out;
    }

    /**
     * Bloco D-3a — o que a faixa "Projeto" do painel edita: datas, fase,
     * %, auto, tipo (para a lista de fases do conjunto), gestor (só exibe)
     * e a EQUIPE DO PROJETO (glpi_projectteams, só usuários — grupo da
     * equipe não aparece nem é tocado). Null = projeto inexistente, apagado
     * ou fora da entidade.
     */
    public static function getProjectMeta(int $projectId): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $p = $DB->request([
            'SELECT' => [
                'id', 'name', 'plan_start_date', 'plan_end_date', 'projectstates_id',
                'projecttypes_id', 'percent_done', 'auto_percent_done', 'users_id',
            ],
            'FROM'   => 'glpi_projects',
            'WHERE'  => ['id' => $projectId, 'is_deleted' => 0]
                + getEntitiesRestrictCriteria('glpi_projects'),
        ])->current();
        if (!$p) {
            return null;
        }

        $team = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    'glpi_projectteams.items_id',
                    'glpi_users.realname',
                    'glpi_users.firstname',
                    'glpi_users.name AS login',
                ],
                'FROM'      => 'glpi_projectteams',
                'LEFT JOIN' => [
                    'glpi_users' => [
                        'ON' => [
                            'glpi_projectteams' => 'items_id',
                            'glpi_users'        => 'id',
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_projectteams.projects_id' => $projectId,
                    'glpi_projectteams.itemtype'    => 'User',
                ],
                'ORDER' => ['glpi_projectteams.id'],
            ]) as $row
        ) {
            $label  = \formatUserName(0, $row['login'] ?? '', $row['realname'] ?? '', $row['firstname'] ?? '');
            $team[] = ['id' => (int) $row['items_id'], 'name' => $label !== '' ? $label : '?'];
        }

        $manager = '';
        if ((int) $p['users_id'] > 0) {
            $u = $DB->request([
                'SELECT' => ['name', 'realname', 'firstname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => (int) $p['users_id']],
            ])->current();
            if ($u) {
                $manager = \formatUserName(0, $u['name'] ?? '', $u['realname'] ?? '', $u['firstname'] ?? '');
            }
        }

        return [
            'id'           => (int) $p['id'],
            'name'         => $p['name'],
            'start_iso'    => $p['plan_start_date'] ? substr($p['plan_start_date'], 0, 10) : '',
            'end_iso'      => $p['plan_end_date'] ? substr($p['plan_end_date'], 0, 10) : '',
            'state_id'     => (int) $p['projectstates_id'],
            'type_id'      => (int) $p['projecttypes_id'],
            'percent'      => (int) $p['percent_done'],
            'auto_percent' => (bool) $p['auto_percent_done'],
            'manager_id'   => (int) $p['users_id'],
            'manager'      => $manager,
            'team_users'   => $team,
            // Bloco D-3b: contador do 💬 da faixa
            'comments'     => TaskComment::countForProject((int) $p['id']),
        ];
    }

    /**
     * @param array<int, true>|null $allowed Bloco F-1: só conta os filhos
     *        que o usuário enxerga (null = todos). Com filtro as linhas vêm
     *        e são contadas em PHP.
     */
    private static function countChildren(int $parentId, ?array $allowed = null): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = [
            'projects_id' => $parentId,
            'is_deleted'  => 0,
            'is_template' => 0,
        ];

        if ($allowed !== null) {
            $n = 0;
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_projects', 'WHERE' => $where]) as $r) {
                if (isset($allowed[(int) $r['id']])) {
                    $n++;
                }
            }
            return $n;
        }

        $row = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_projects',
            'WHERE' => $where,
        ])->current();

        return (int) ($row['cpt'] ?? 0);
    }

    /**
     * Das tarefas informadas, quais têm ao menos uma subtarefa direta.
     *
     * @param int[] $taskIds
     * @return array<int, true> id da tarefa-mãe => true
     */
    public static function tasksWithChildren(array $taskIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
        if (empty($taskIds)) {
            return [];
        }

        $out = [];
        foreach (
            $DB->request([
                'SELECT' => ['projecttasks_id'],
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => ['projecttasks_id' => $taskIds],
            ]) as $row
        ) {
            $out[(int) $row['projecttasks_id']] = true;
        }
        return $out;
    }

    /**
     * Bloco D-2a — responsáveis (equipe, só USUÁRIOS) de cada tarefa:
     * [taskId => [['id' => uid, 'name' => rótulo], ...]] na ordem em que
     * entraram na equipe. Rótulo por formatUserName (names_format).
     * Grupos da equipe ficam de fora — não aparecem nem são editados aqui.
     *
     * @param int[] $taskIds
     * @return array<int, array<int, array{id:int,name:string}>>
     */
    public static function teamUsers(array $taskIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if (empty($taskIds)) {
            return [];
        }

        $out = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    'glpi_projecttaskteams.projecttasks_id',
                    'glpi_projecttaskteams.items_id',
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
                'WHERE' => [
                    'glpi_projecttaskteams.itemtype'        => 'User',
                    'glpi_projecttaskteams.projecttasks_id' => $taskIds,
                ],
                'ORDER' => ['glpi_projecttaskteams.projecttasks_id', 'glpi_projecttaskteams.id'],
            ]) as $row
        ) {
            $label = \formatUserName(0, $row['login'] ?? '', $row['realname'] ?? '', $row['firstname'] ?? '');
            $out[(int) $row['projecttasks_id']][] = [
                'id'   => (int) $row['items_id'],
                'name' => $label !== '' ? $label : '?',
            ];
        }
        return $out;
    }

    /**
     * Bloco D-2a — usuários ativos para escolher responsável (antes montado
     * dentro de front/dashboard.php; agora também em Minhas tarefas).
     * Rótulo por formatUserName, ordem pelo rótulo com Collator.
     *
     * @return array<int, array{id:int,name:string}>
     */
    public static function userOptions(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $users = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'realname', 'firstname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
                'LIMIT'  => 300,
            ]) as $row
        ) {
            $label   = \formatUserName(
                0,
                (string) ($row['name'] ?? ''),
                (string) ($row['realname'] ?? ''),
                (string) ($row['firstname'] ?? '')
            );
            $users[] = [
                'id'   => (int) $row['id'],
                'name' => $label !== '' ? $label : (string) $row['name'],
            ];
        }
        if (class_exists('\\Collator')) {
            $coll = new \Collator(str_replace('_', '-', $_SESSION['glpilanguage'] ?? 'pt_BR'));
            usort($users, static fn ($a, $b) => $coll->compare($a['name'], $b['name']));
        } else {
            usort($users, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        }
        return $users;
    }

    /**
     * Árvore de tarefas do painel do projeto.
     *
     * @param int[]|null $myTaskIds Bloco F-1 (05/10/2026): com lista, só as
     *        MINHAS tarefas aparecem; a mãe que não é minha entra como
     *        CONTEXTO (`context` = true), só com o nome, para a subtarefa não
     *        ficar solta. Irmãs e ramos sem tarefa minha somem. null = todas.
     */
    public static function getTasks(int $projectId, ?array $myTaskIds = null): array
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

        // Bloco F-1: quais linhas entram. $keep = minhas; $context = mães
        // (de qualquer nível) de uma tarefa minha que não são minhas.
        $keep    = null;
        $context = [];
        if ($myTaskIds !== null) {
            $parentOf = [];
            foreach ($byParent as $pid => $rows) {
                foreach ($rows as $r) {
                    $parentOf[(int) $r['id']] = (int) $pid;
                }
            }
            $keep = [];
            foreach ($myTaskIds as $mid) {
                $mid = (int) $mid;
                if (isset($parentOf[$mid])) {
                    $keep[$mid] = true;
                }
            }
            foreach (array_keys($keep) as $mid) {
                $up = $parentOf[$mid];
                while ($up > 0 && isset($parentOf[$up]) && !isset($keep[$up]) && !isset($context[$up])) {
                    $context[$up] = true;
                    $up = $parentOf[$up];
                }
            }
            if ($keep === []) {
                return [];
            }
        }

        // Bloco D-2a: equipe só das tarefas DESTE projeto (antes a consulta
        // lia glpi_projecttaskteams inteira), com id + nome.
        $allIds = [];
        foreach ($byParent as $rows) {
            foreach ($rows as $r) {
                if ($keep === null || isset($keep[(int) $r['id']])) {
                    $allIds[] = (int) $r['id'];
                }
            }
        }
        $teams = self::teamUsers($allIds);

        $out  = [];
        $walk = function (int $parentId, int $depth) use (&$walk, &$out, $byParent, $states, $teams, $projectId, $keep, $context) {
            foreach ($byParent[$parentId] ?? [] as $t) {
                $id = (int) $t['id'];
                if ($keep !== null && !isset($keep[$id])) {
                    if (isset($context[$id])) {
                        // Só o nome (decisão do Claudio): sem link, %,
                        // fase, prazo, responsáveis nem ações.
                        $out[] = [
                            'id'           => $id,
                            'name'         => $t['name'],
                            'depth'        => $depth,
                            'context'      => true,
                            'has_children' => true,
                        ];
                        $walk($id, $depth + 1);
                    }
                    continue;
                }
                $out[] = [
                    'id'           => $id,
                    'name'         => $t['name'],
                    'url'          => Url::project($projectId, $id),
                    'depth'        => $depth,
                    'auto_percent' => (bool) $t['auto_percent_done'],
                    'has_children' => !empty($byParent[$id]),
                    'percent'      => (int) $t['percent_done'],
                    'start'      => $t['plan_start_date'] ? DateFmt::date($t['plan_start_date']) : null,
                    'end'        => $t['plan_end_date'] ? DateFmt::date($t['plan_end_date']) : null,
                    'start_iso'  => $t['plan_start_date'] ? substr($t['plan_start_date'], 0, 10) : '',
                    'end_iso'    => $t['plan_end_date'] ? substr($t['plan_end_date'], 0, 10) : '',
                    'state_id'    => (int) $t['projectstates_id'],
                    'state_name'  => $states[(int) $t['projectstates_id']]['name'] ?? '—',
                    'state_color' => $states[(int) $t['projectstates_id']]['color'] ?? self::PHASE_DEFAULT_COLOR,
                    'team'       => array_column($teams[$id] ?? [], 'name'),
                    'team_users' => $teams[$id] ?? [],
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
        $comments = TaskComment::countForTasks($allIds);
        // Dependências (Etapa 3, Bloco 3) — consulta única
        $deps = TaskDep::countForTasks($allIds);
        foreach ($out as &$t) {
            if (!empty($t['context'])) {
                continue;
            }
            $t['comments'] = $comments[$t['id']] ?? 0;
            $t['deps']     = $deps[$t['id']]['deps'] ?? 0;
            $t['blocked']  = $deps[$t['id']]['blocked'] ?? false;
        }
        unset($t);

        return $out;
    }
}

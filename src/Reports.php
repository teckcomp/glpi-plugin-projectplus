<?php

namespace GlpiPlugin\Projectplus;

use Session;

/**
 * Relatórios (Etapa 5, Bloco 1).
 *
 * Tela "Relatórios" com exportação CSV de três conjuntos de dados:
 * Projetos, Tarefas e Custos. Mesmo filtro por projeto raiz usado na
 * tela "Orçamento" (front/costs.php): sem filtro = todos os projetos
 * raiz da entidade; com filtro = o projeto escolhido + descendentes.
 *
 * ACESSO: mesmo direito da Visão geral (plugin_projectplus_dashboard
 * READ) — não é uma área administrativa como "Modelos".
 *
 * Cada método de dados devolve ['header' => [...], 'rows' => [[...]]],
 * já pronto tanto para a tabela em tela quanto para o CSV (front/
 * reports_export.php só serializa 'header'+'rows' com fputcsv).
 */
class Reports
{
    public static function canAccess(): bool
    {
        return Session::haveRight('plugin_projectplus_dashboard', READ);
    }

    /**
     * Projetos raiz para o seletor de filtro (mesmo critério de costs.php).
     */
    public static function getRootProjectsForFilter(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_projects',
                'WHERE'  => [
                    'projects_id' => 0,
                    'is_deleted'  => 0,
                    'is_template' => 0,
                ] + getEntitiesRestrictCriteria('glpi_projects'),
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $out[] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }
        return $out;
    }

    /**
     * IDs de projetos dentro do escopo do filtro: sem filtro = todos os
     * projetos (raiz + descendentes) da entidade; com filtro = o projeto
     * escolhido + todos os descendentes (reaproveita Budget::getDescendantIds).
     */
    private static function scopeProjectIds(int $filterId): ?array
    {
        if ($filterId <= 0) {
            return null; // sem restrição de IDs (todos)
        }
        $ids = [$filterId];
        return array_merge($ids, Budget::getDescendantIds($filterId));
    }

    private static function userLabel(?string $realname, ?string $firstname, ?string $login): ?string
    {
        $label = trim((string) $realname . ' ' . (string) $firstname);
        if ($label !== '') {
            return $label;
        }
        return $login !== '' ? $login : null;
    }

    // ------------------------------------------------------------------
    // Listas para os filtros (Bloco 1.1)
    // ------------------------------------------------------------------

    /**
     * Usuários que aparecem como gestor de algum projeto OU responsável de
     * alguma tarefa — lista mais curta e relevante que todos os usuários
     * ativos. Um único filtro serve para "Gestor" (Projetos) e
     * "Responsável" (Tarefas).
     */
    public static function getFilterUsers(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => 'users_id',
                'FROM'   => 'glpi_projects',
                'WHERE'  => ['users_id' => ['>', 0]],
            ]) as $r
        ) {
            $ids[(int) $r['users_id']] = true;
        }
        foreach (
            $DB->request([
                'SELECT' => 'items_id',
                'FROM'   => 'glpi_projecttaskteams',
                'WHERE'  => ['itemtype' => 'User'],
            ]) as $r
        ) {
            $ids[(int) $r['items_id']] = true;
        }

        if (empty($ids)) {
            return [];
        }

        $out = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'realname', 'firstname', 'name'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => array_keys($ids)],
            ]) as $r
        ) {
            $out[] = [
                'id'   => (int) $r['id'],
                'name' => self::userLabel($r['realname'], $r['firstname'], $r['name']) ?? $r['name'],
            ];
        }
        usort($out, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    public static function getProjectTypesForFilter(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_projecttypes', 'ORDER' => 'name']) as $r) {
            $out[] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        return $out;
    }

    public static function getTaskTypesForFilter(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_projecttasktypes', 'ORDER' => 'name']) as $r
        ) {
            $out[] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Projetos
    // ------------------------------------------------------------------

    /**
     * @param array{user?: int, state?: int, project_type?: int, from?: string, until?: string} $filters
     */
    public static function projectsData(int $filterId, array $filters = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $header = [
            __('ID', 'projectplus'), __('Projeto', 'projectplus'), __('Projeto pai', 'projectplus'),
            __('Fase', 'projectplus'), __('Tipo', 'projectplus'), __('Gestor', 'projectplus'),
            __('% concluído', 'projectplus'), __('Início planejado', 'projectplus'),
            __('Fim planejado', 'projectplus'), __('Atrasado', 'projectplus'),
            __('Orçamento previsto', 'projectplus'), __('Orçamento gasto', 'projectplus'),
            __('Saldo', 'projectplus'), __('% orçamento consumido', 'projectplus'),
        ];

        // Nomes de coluna QUALIFICADOS com a tabela: esta consulta tem
        // LEFT JOIN com glpi_users, que também tem is_deleted/id — sem o
        // prefixo o MySQL devolve "Column ... is ambiguous" (1052).
        $where = [
            'glpi_projects.is_deleted'  => 0,
            'glpi_projects.is_template' => 0,
        ] + getEntitiesRestrictCriteria('glpi_projects');

        $scopeIds = self::scopeProjectIds($filterId);
        if ($scopeIds !== null) {
            $where['glpi_projects.id'] = $scopeIds;
        }

        // Filtros extras (Bloco 1.1): Gestor, Fase, Tipo de projeto
        if (!empty($filters['user'])) {
            $where['glpi_projects.users_id'] = (int) $filters['user'];
        }
        if (!empty($filters['state'])) {
            $where['glpi_projects.projectstates_id'] = (int) $filters['state'];
        }
        if (!empty($filters['project_type'])) {
            $where['glpi_projects.projecttypes_id'] = (int) $filters['project_type'];
        }
        // Período (Bloco 1.2): mesma semântica da Visão geral — período
        // planejado sobrepõe o intervalo; itens sem data sempre entram.
        foreach (Dashboard::periodCriteria($filters['from'] ?? null, $filters['until'] ?? null, 'glpi_projects.') as $c) {
            $where[] = $c;
        }

        $states = Dashboard::getStatesMap();

        $types = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_projecttypes']) as $r) {
            $types[(int) $r['id']] = $r['name'];
        }

        $rows      = [];
        $names     = []; // id => nome (para resolver "Projeto pai")
        $now       = time();
        $iterator  = $DB->request([
            'SELECT'    => [
                'glpi_projects.id', 'glpi_projects.name', 'glpi_projects.projects_id',
                'glpi_projects.percent_done', 'glpi_projects.plan_start_date',
                'glpi_projects.plan_end_date', 'glpi_projects.projectstates_id',
                'glpi_projects.projecttypes_id',
                'glpi_users.realname AS mgr_realname', 'glpi_users.firstname AS mgr_firstname',
                'glpi_users.name AS mgr_login',
            ],
            'FROM'      => 'glpi_projects',
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => ['glpi_projects' => 'users_id', 'glpi_users' => 'id'],
                ],
            ],
            'WHERE'     => $where,
            'ORDER'     => 'glpi_projects.name',
        ]);

        $data = [];
        foreach ($iterator as $row) {
            $data[(int) $row['id']] = $row;
            $names[(int) $row['id']] = $row['name'];
        }

        // Nomes de pais fora do escopo (ex.: filtro por subprojeto cujo pai
        // não entrou na lista) — busca avulsa dos que faltarem.
        $missingParents = [];
        foreach ($data as $row) {
            $pid = (int) $row['projects_id'];
            if ($pid > 0 && !isset($names[$pid])) {
                $missingParents[$pid] = true;
            }
        }
        if (!empty($missingParents)) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name'],
                    'FROM'   => 'glpi_projects',
                    'WHERE'  => ['id' => array_keys($missingParents)],
                ]) as $r
            ) {
                $names[(int) $r['id']] = $r['name'];
            }
        }

        foreach ($data as $row) {
            $pct       = (int) $row['percent_done'];
            $isOverdue = !empty($row['plan_end_date'])
                && strtotime($row['plan_end_date']) < $now
                && $pct < 100;

            $budget  = Budget::getForProject((int) $row['id']);
            $stateId = (int) $row['projectstates_id'];
            $typeId  = (int) $row['projecttypes_id'];
            $parentId = (int) $row['projects_id'];

            $rows[] = [
                (int) $row['id'],
                $row['name'],
                $parentId > 0 ? ($names[$parentId] ?? ('#' . $parentId)) : '',
                $states[$stateId]['name'] ?? '',
                $types[$typeId] ?? '',
                self::userLabel($row['mgr_realname'], $row['mgr_firstname'], $row['mgr_login']) ?? '',
                $pct,
                !empty($row['plan_start_date']) ? date('d/m/Y', strtotime($row['plan_start_date'])) : '',
                !empty($row['plan_end_date']) ? date('d/m/Y', strtotime($row['plan_end_date'])) : '',
                $isOverdue ? __('Sim', 'projectplus') : __('Não', 'projectplus'),
                $budget['planned'] > 0 ? number_format($budget['planned'], 2, ',', '.') : '',
                $budget['planned'] > 0 ? number_format($budget['spent_total'], 2, ',', '.') : '',
                $budget['balance'] !== null ? number_format($budget['balance'], 2, ',', '.') : '',
                $budget['percent'] !== null ? $budget['percent'] : '',
            ];
        }

        return ['header' => $header, 'rows' => $rows];
    }

    // ------------------------------------------------------------------
    // Tarefas
    // ------------------------------------------------------------------

    /**
     * @param array{user?: int, state?: int, task_type?: int, task_search?: string, from?: string, until?: string} $filters
     */
    public static function tasksData(int $filterId, array $filters = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $header = [
            __('ID', 'projectplus'), __('Projeto', 'projectplus'), __('Tarefa', 'projectplus'),
            __('Tarefa mãe', 'projectplus'), __('Fase', 'projectplus'), __('Tipo', 'projectplus'),
            __('Responsável(is)', 'projectplus'), __('% concluído', 'projectplus'),
            __('Início planejado', 'projectplus'), __('Fim planejado', 'projectplus'),
            __('Atrasada', 'projectplus'), __('Bloqueada', 'projectplus'),
        ];

        $where = [];
        $scopeIds = self::scopeProjectIds($filterId);
        if ($scopeIds !== null) {
            $where['glpi_projecttasks.projects_id'] = $scopeIds;
        } else {
            $where[] = ['glpi_projects.is_deleted' => 0, 'glpi_projects.is_template' => 0]
                + getEntitiesRestrictCriteria('glpi_projects');
        }

        // Filtros extras (Bloco 1.1): Fase, Tipo de tarefa, busca por nome
        if (!empty($filters['state'])) {
            $where['glpi_projecttasks.projectstates_id'] = (int) $filters['state'];
        }
        if (!empty($filters['task_type'])) {
            $where['glpi_projecttasks.projecttasktypes_id'] = (int) $filters['task_type'];
        }
        if (!empty($filters['task_search'])) {
            $where['glpi_projecttasks.name'] = ['LIKE', '%' . $filters['task_search'] . '%'];
        }
        // Período (Bloco 1.2): mesma semântica da Visão geral.
        foreach (Dashboard::periodCriteria($filters['from'] ?? null, $filters['until'] ?? null, 'glpi_projecttasks.') as $c) {
            $where[] = $c;
        }

        // Filtro de Responsável: mesmo campo "Gestor/Responsável" da tela,
        // aqui aplicado via equipe (glpi_projecttaskteams) — não é coluna
        // direta da tarefa (ver lição aprendida (15) do contexto).
        if (!empty($filters['user'])) {
            $userTaskIds = [];
            foreach (
                $DB->request([
                    'SELECT' => 'projecttasks_id',
                    'FROM'   => 'glpi_projecttaskteams',
                    'WHERE'  => ['itemtype' => 'User', 'items_id' => (int) $filters['user']],
                ]) as $r
            ) {
                $userTaskIds[] = (int) $r['projecttasks_id'];
            }
            // Lista vazia = nenhuma tarefa deste responsável; ID 0 nunca
            // existe, garante zero resultados em vez de ignorar o filtro.
            $where['glpi_projecttasks.id'] = !empty($userTaskIds) ? $userTaskIds : [0];
        }

        $states = Dashboard::getStatesMap();

        $ttypes = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_projecttasktypes']) as $r) {
            $ttypes[(int) $r['id']] = $r['name'];
        }

        $iterator = $DB->request([
            'SELECT'    => [
                'glpi_projecttasks.id', 'glpi_projecttasks.name',
                'glpi_projecttasks.projecttasks_id', 'glpi_projecttasks.percent_done',
                'glpi_projecttasks.plan_start_date', 'glpi_projecttasks.plan_end_date',
                'glpi_projecttasks.projectstates_id', 'glpi_projecttasks.projecttasktypes_id',
                'glpi_projects.name AS project_name',
            ],
            'FROM'      => 'glpi_projecttasks',
            'LEFT JOIN' => [
                'glpi_projects' => [
                    'ON' => ['glpi_projecttasks' => 'projects_id', 'glpi_projects' => 'id'],
                ],
            ],
            'WHERE'     => $where,
            'ORDER'     => ['glpi_projects.name', 'glpi_projecttasks.name'],
        ]);

        $tasks   = [];
        $taskIds = [];
        $parentIds = [];
        $now     = time();
        foreach ($iterator as $row) {
            $tid = (int) $row['id'];
            $tasks[$tid] = $row;
            $taskIds[]   = $tid;
            $ptid        = (int) $row['projecttasks_id'];
            if ($ptid > 0) {
                $parentIds[$ptid] = true;
            }
        }

        // Nomes das tarefas mãe (podem estar fora do escopo filtrado)
        $parentNames = [];
        if (!empty($parentIds)) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name'],
                    'FROM'   => 'glpi_projecttasks',
                    'WHERE'  => ['id' => array_keys($parentIds)],
                ]) as $r
            ) {
                $parentNames[(int) $r['id']] = $r['name'];
            }
        }

        // Equipe (responsáveis), uma consulta só — mesmo padrão de
        // Dashboard::attachTeamAndChildren (evita COUNT+GROUP BY)
        $team = [];
        if (!empty($taskIds)) {
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
                            'ON' => ['glpi_projecttaskteams' => 'items_id', 'glpi_users' => 'id'],
                        ],
                    ],
                    'WHERE'     => [
                        'glpi_projecttaskteams.itemtype'        => 'User',
                        'glpi_projecttaskteams.projecttasks_id' => $taskIds,
                    ],
                ]) as $row
            ) {
                $label = self::userLabel($row['realname'], $row['firstname'], $row['login']);
                if ($label !== null) {
                    $team[(int) $row['projecttasks_id']][] = $label;
                }
            }
        }

        $deps = TaskDep::countForTasks($taskIds);

        $rows = [];
        foreach ($tasks as $tid => $row) {
            $pct       = (int) $row['percent_done'];
            $isOverdue = !empty($row['plan_end_date'])
                && strtotime($row['plan_end_date']) < $now
                && $pct < 100;
            $stateId  = (int) $row['projectstates_id'];
            $typeId   = (int) $row['projecttasktypes_id'];
            $parentId = (int) $row['projecttasks_id'];

            $rows[] = [
                $tid,
                $row['project_name'] ?? '',
                $row['name'],
                $parentId > 0 ? ($parentNames[$parentId] ?? ('#' . $parentId)) : '',
                $states[$stateId]['name'] ?? '',
                $ttypes[$typeId] ?? '',
                implode('; ', $team[$tid] ?? []),
                $pct,
                !empty($row['plan_start_date']) ? date('d/m/Y', strtotime($row['plan_start_date'])) : '',
                !empty($row['plan_end_date']) ? date('d/m/Y', strtotime($row['plan_end_date'])) : '',
                $isOverdue ? __('Sim', 'projectplus') : __('Não', 'projectplus'),
                !empty($deps[$tid]['blocked']) ? __('Sim', 'projectplus') : __('Não', 'projectplus'),
            ];
        }

        return ['header' => $header, 'rows' => $rows];
    }

    // ------------------------------------------------------------------
    // Custos
    // ------------------------------------------------------------------

    public static function costsData(int $filterId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $header = [
            __('Projeto raiz', 'projectplus'), __('Origem', 'projectplus'),
            __('Descrição', 'projectplus'), __('Data', 'projectplus'),
            __('Autor', 'projectplus'), __('Valor', 'projectplus'),
        ];

        $where = [
            'projects_id' => 0,
            'is_deleted'  => 0,
            'is_template' => 0,
        ] + getEntitiesRestrictCriteria('glpi_projects');
        if ($filterId > 0) {
            $where = ['id' => $filterId] + getEntitiesRestrictCriteria('glpi_projects');
        }

        $rows = [];
        foreach (
            $DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_projects', 'WHERE' => $where, 'ORDER' => 'name']) as $root
        ) {
            $rootId = (int) $root['id'];

            $entries = Budget::getEntriesForProject($rootId, __('Projeto', 'projectplus'));
            $visited = [];
            foreach (Budget::getDescendantIds($rootId, $visited) as $childId) {
                $childRow = $DB->request([
                    'SELECT' => ['name'],
                    'FROM'   => 'glpi_projects',
                    'WHERE'  => ['id' => $childId],
                ])->current();
                $entries = array_merge($entries, Budget::getEntriesForProject(
                    $childId,
                    sprintf(__('Subprojeto "%s"', 'projectplus'), $childRow['name'] ?? '—')
                ));
            }

            foreach ($entries as $e) {
                $rows[] = [
                    $root['name'],
                    $e['origin'],
                    $e['name'],
                    !empty($e['date']) ? date('d/m/Y', strtotime($e['date'])) : '',
                    $e['author'] ?? '',
                    number_format((float) $e['cost'], 2, ',', '.'),
                ];
            }
        }

        return ['header' => $header, 'rows' => $rows];
    }

    /**
     * Ponto único usado tanto pela tela (preview) quanto pelo export CSV.
     *
     * Custos NÃO recebe os filtros extras (Gestor/Fase/Tipo): a tabela de
     * custos é uma lista de lançamentos, sem essas colunas próprias —
     * continua só com o filtro de Projeto, como na tela Orçamento.
     */
    public static function dataFor(string $type, int $filterId, array $filters = []): ?array
    {
        return match ($type) {
            'projects' => self::projectsData($filterId, $filters),
            'tasks'    => self::tasksData($filterId, $filters),
            'costs'    => self::costsData($filterId),
            default    => null,
        };
    }
}

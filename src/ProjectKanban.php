<?php

namespace GlpiPlugin\Projectplus;

use Project;

/**
 * ProjectPlus — Kanban de PROJETOS (Etapa 8, Bloco 4).
 *
 * Board voltado ao papel CLIENTE (direito `plugin_projectplus_projectkanban`):
 * as COLUNAS continuam sendo as fases (glpi_projectstates, mesma ordem de
 * Dashboard::getStatesMap), mas os CARTÕES são PROJETOS e SUBPROJETOS — não
 * tarefas. É SOMENTE LEITURA: não há arrastar-e-soltar (o Cliente não altera
 * nada), então não existe endpoint AJAX nem token neste board.
 *
 * Escopo: quem chama passa a lista de projetos visíveis (src/Scope.php —
 * para o Cliente, os projetos em cuja EQUIPE ele está). `null` = sem
 * restrição (perfil com "ver todos").
 *
 * Sem swimlanes: seguindo a decisão da Etapa 7 ("menos regra = menos bug"),
 * TODO projeto é um cartão comum na coluna da sua fase; o subprojeto só
 * ganha a tag "Subprojeto de: <pai>" — o mesmo tratamento dado à subtarefa
 * no Kanban de tarefas.
 *
 * Classe estática, sem extends — autoload PSR-4.
 */
class ProjectKanban
{
    /** id usado para a coluna "sem fase". */
    public const UNSET_ID = 0;

    /** O perfil pode ver ALGUM kanban de projetos? */
    public static function canAccess(): bool
    {
        return Access::can('projectkanban') || Access::can('kanban');
    }

    /**
     * Dados completos do board de projetos.
     *
     * @param ?array $projectIds ids exatos a exibir (escopo do usuário);
     *                           null = todos os projetos da entidade
     * @param ?int   $typeId     Etapa 9 — tipo de projeto do board: as
     *                           COLUNAS passam a ser o conjunto de fases do
     *                           tipo e os cartões, só os projetos daquele
     *                           tipo. null = todas as fases (comportamento
     *                           anterior à Etapa 9).
     *
     * @return array{
     *   columns: array<int, array{id:int, name:string, color:string}>,
     *   cards: array<int, array<string, mixed>>
     * }
     */
    public static function getBoardData(?array $projectIds = null, ?int $typeId = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Etapa 9: o conjunto de fases do TIPO define as colunas e a ordem
        // delas. O cartão não mostra o nome da fase (a coluna já é a fase),
        // então não é preciso o mapa completo de estados aqui.
        $states = Dashboard::getStatesMap($typeId);

        // Lição 16: com LEFT JOIN, TODA chave do WHERE precisa vir
        // qualificada com a tabela (glpi_users também tem id/is_deleted).
        $where = [
            'glpi_projects.is_deleted'  => 0,
            'glpi_projects.is_template' => 0,
        ] + getEntitiesRestrictCriteria('glpi_projects');

        if ($projectIds !== null) {
            $where['glpi_projects.id'] = Scope::inList($projectIds);
        }

        // Etapa 9 — só os projetos do tipo selecionado. Restrição por COLUNA
        // (não por lista de ids), então conviver com o filtro de escopo na
        // mesma consulta é seguro: são chaves diferentes do WHERE.
        if ($typeId !== null) {
            $where['glpi_projects.projecttypes_id'] = $typeId;
        }

        $rows      = [];
        $ids       = [];
        $parentIds = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    'glpi_projects.id',
                    'glpi_projects.name',
                    'glpi_projects.projects_id',
                    'glpi_projects.percent_done',
                    'glpi_projects.plan_start_date',
                    'glpi_projects.plan_end_date',
                    'glpi_projects.real_start_date',
                    'glpi_projects.projectstates_id',
                    'glpi_users.realname AS mgr_realname',
                    'glpi_users.firstname AS mgr_firstname',
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
            ]) as $row
        ) {
            $rows[] = $row;
            $ids[]  = (int) $row['id'];
            $pid    = (int) $row['projects_id'];
            if ($pid > 0) {
                $parentIds[$pid] = true;
            }
        }

        if (empty($rows)) {
            return ['columns' => self::columnsFrom($states), 'cards' => []];
        }

        // Nome do projeto pai (pode estar FORA do escopo — só o nome é
        // exposto, o cartão do pai continua não aparecendo).
        $parentNames = [];
        if (!empty($parentIds)) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name'],
                    'FROM'   => 'glpi_projects',
                    'WHERE'  => ['id' => array_keys($parentIds)],
                ]) as $r
            ) {
                $parentNames[(int) $r['id']] = $r['name'];
            }
        }

        // Contagem de tarefas por projeto (total e concluídas) numa única
        // consulta — sem COUNT+GROUP BY junto (lição 1: o iterator do GLPI
        // 11 DESCARTA os campos do SELECT quando os dois vêm juntos).
        $taskTotal = [];
        $taskDone  = [];
        foreach (
            $DB->request([
                'SELECT' => ['projects_id', 'percent_done'],
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => ['projects_id' => $ids],
            ]) as $r
        ) {
            $pid             = (int) $r['projects_id'];
            $taskTotal[$pid] = ($taskTotal[$pid] ?? 0) + 1;
            if ((int) $r['percent_done'] >= 100) {
                $taskDone[$pid] = ($taskDone[$pid] ?? 0) + 1;
            }
        }

        $cards         = [];
        $sawExtraState = false;
        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $stateId = (int) $row['projectstates_id'];
            if (!isset($states[$stateId])) {
                // Sem fase, fase excluída — ou (Etapa 9) fase fora do conjunto
                // do tipo: vai para a coluna sintética "Sem fase", senão o
                // cartão apontaria para uma coluna que não existe.
                $sawExtraState = true;
                $stateId       = self::UNSET_ID;
            }
            $parentId = (int) $row['projects_id'];
            // Respeita a ordem de nome configurada no GLPI (config ou
            // preferência da sessão), em vez de fixar "Sobrenome Nome".
            $manager = \formatUserName(
                0,
                (string) ($row['mgr_login'] ?? ''),
                (string) ($row['mgr_realname'] ?? ''),
                (string) ($row['mgr_firstname'] ?? '')
            );

            $cards[] = [
                'id'          => $id,
                'name'        => $row['name'],
                'url'         => Project::getFormURLWithID($id),
                'parent_id'   => $parentId,
                'parent_name' => $parentId > 0 ? ($parentNames[$parentId] ?? '—') : '',
                'state_id'    => $stateId,
                'percent'     => (int) $row['percent_done'],
                'is_done'     => (int) $row['percent_done'] >= 100,
                'manager'     => $manager !== '' ? $manager : __('Sem gestor', 'projectplus'),
                'tasks_total' => $taskTotal[$id] ?? 0,
                'tasks_done'  => $taskDone[$id] ?? 0,
                'deadline'    => Deadline::compute(
                    $row['plan_start_date'],
                    $row['real_start_date'],
                    $row['plan_end_date'],
                    (int) $row['percent_done']
                ),
            ];
        }

        return [
            'columns' => self::columnsFrom($states, $sawExtraState),
            'cards'   => $cards,
        ];
    }

    /**
     * @param array<int, array{name:string, color:string}> $states
     */
    private static function columnsFrom(array $states, bool $withUnset = false): array
    {
        $columns = [];
        foreach ($states as $id => $s) {
            $columns[] = ['id' => $id, 'name' => $s['name'], 'color' => $s['color']];
        }
        if ($withUnset || empty($states)) {
            $columns[] = [
                'id'    => self::UNSET_ID,
                'name'  => __('Sem fase', 'projectplus'),
                'color' => Dashboard::PHASE_DEFAULT_COLOR,
            ];
        }
        return $columns;
    }
}

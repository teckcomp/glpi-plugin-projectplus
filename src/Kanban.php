<?php

namespace GlpiPlugin\Projectplus;

use Project;
use ProjectTask;
use Session;

/**
 * ProjectPlus — Kanban avançado (Etapa 7, Bloco 1 + 1.1 + 1.2 + 1.3).
 *
 * Board próprio do plugin: colunas por fase (glpi_projectstates, mesma
 * ordem de Dashboard::getStatesMap — nomes prefixados "1. ", "2. " etc.
 * já ordenam certo por nome, mesmo truque usado na Timeline/Dashboard) e
 * swimlanes ALTERNÁVEIS no cliente (Projeto / Responsável): o servidor
 * devolve um único payload com as duas chaves de agrupamento já prontas
 * em cada cartão, e o JS troca a visão sem nova requisição — mesmo padrão
 * do toggle Semana/Dia/Mês do burndown (Etapa 5, Bloco 2, lição 21).
 *
 * Este bloco é SOMENTE LEITURA: arrastar-e-soltar com persistência de
 * fase (e a validação de bloqueio por dependência, reaproveitando
 * TaskDep — mesma regra da Etapa 3, Bloco 3) fica para o Bloco 2.
 *
 * Bloco 1.1 (ajustes pós-validação, 19/07/2026):
 * - getBoardData() aceita $projectId opcional (raiz + descendentes) para
 *   alimentar a aba "Kanban (ProjectPlus)" da ficha nativa do projeto
 *   (ver KanbanTab.php), que substitui a aba "Kanban" nativa.
 *
 * Bloco 1.2 (ajustes pós-validação, 19/07/2026): a swimlane "Projeto" NÃO
 * mistura mais as tarefas do subprojeto dentro da lane do pai — por
 * padrão só aparecem as tarefas ATRIBUÍDAS DIRETAMENTE ao projeto da
 * lane; subprojeto e subtarefa (tarefa mãe/filha) viram estruturas
 * expansíveis (botão "+"), no mesmo espírito do "+"/"−" de subprojeto já
 * usado na Visão geral (Dashboard::getTasks / initExpandButtons):
 * - cada cartão carrega `task_parent_id` (glpi_projecttasks.projecttasks_id);
 *   só tarefas de TOPO (task_parent_id = 0) viram cartão na grade — as
 *   filhas ficam disponíveis no payload e o JS as aninha dentro do
 *   cartão-mãe, reveladas por um "+N subtarefas";
 * - a árvore de projetos (`projects`, mapa id => {name, parent_id}) é
 *   montada só com os projetos que têm alguma tarefa no resultado (+ seus
 *   ancestrais, até o "teto" do escopo — ver $ceilingId), permitindo ao
 *   JS montar as lanes por projeto raiz e expandir subprojeto por
 *   subprojeto sob demanda, sem nova requisição.
 *
 * Escopo: por decisão do usuário (19/07/2026), TODAS as tarefas (visão
 * global) quando $projectId é null, sem filtro por usuário logado. A
 * Etapa 8 trará a permissão "ver todos os projetos" e poderá restringir
 * a visão aqui, no mesmo espírito do parâmetro $onlyUserId de
 * Timeline::getData().
 */
class Kanban
{
    /** id usado para a coluna/swimlane "sem fase" / "sem responsável". */
    public const UNSET_ID = 0;

    public static function canAccess(): bool
    {
        return Session::haveRight('plugin_projectplus_dashboard', READ);
    }

    /**
     * Total de tarefas no escopo (projeto + descendentes) — usado só para
     * o contador da aba nativa (KanbanTab), sem montar o board inteiro.
     */
    public static function countTasksForProject(int $projectId): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = array_merge([$projectId], Budget::getDescendantIds($projectId));
        $row = $DB->request([
            'SELECT' => ['COUNT' => 'id AS cpt'],
            'FROM'   => 'glpi_projecttasks',
            'WHERE'  => ['projects_id' => $ids],
        ])->current();

        return (int) ($row['cpt'] ?? 0);
    }

    /**
     * Dados completos do board.
     *
     * @param ?int $projectId quando informado, restringe a este projeto +
     *                        seus descendentes (aba na ficha nativa) — E
     *                        vira o "teto" da árvore de projetos (não
     *                        expõe ancestrais de fora do escopo); null =
     *                        visão global (árvore sobe até a raiz real)
     *
     * @return array{
     *   columns: array<int, array{id:int, name:string, color:string}>,
     *   cards: array<int, array<string, mixed>>,
     *   projects: array<int, array{name:string, parent_id:int}>,
     *   lanes: array{responsible: array<int, array{id:int, name:string}>}
     * }
     */
    public static function getBoardData(?int $projectId = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $states = Dashboard::getStatesMap();

        // ---------- Mapa de TODOS os projetos: pai + nome — usado para
        // montar a árvore de swimlanes (Projeto raiz -> subprojetos) ----------
        $parentOf = [];
        $nameOf   = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'projects_id', 'name'],
                'FROM'   => 'glpi_projects',
                'WHERE'  => [
                    'is_deleted'  => 0,
                    'is_template' => 0,
                ] + getEntitiesRestrictCriteria('glpi_projects'),
            ]) as $row
        ) {
            $pid            = (int) $row['id'];
            $parentOf[$pid] = (int) $row['projects_id'];
            $nameOf[$pid]   = $row['name'];
        }

        // ---------- Tarefas (visão global OU escopo de um projeto) ----------
        // JOIN com glpi_projects só para nome do projeto + is_deleted/
        // is_template/entidade; lição nº 16: toda chave do WHERE precisa
        // vir qualificada com a tabela por causa do INNER JOIN.
        $where = [
            'glpi_projects.is_deleted'  => 0,
            'glpi_projects.is_template' => 0,
        ] + getEntitiesRestrictCriteria('glpi_projects');

        if ($projectId !== null) {
            $scopeIds = array_merge([$projectId], Budget::getDescendantIds($projectId));
            $where['glpi_projecttasks.projects_id'] = $scopeIds;
        }

        $rows       = [];
        $allTaskIds = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    'glpi_projecttasks.id',
                    'glpi_projecttasks.name',
                    'glpi_projecttasks.projects_id',
                    'glpi_projecttasks.projecttasks_id',
                    'glpi_projecttasks.percent_done',
                    'glpi_projecttasks.plan_start_date',
                    'glpi_projecttasks.plan_end_date',
                    'glpi_projecttasks.real_start_date',
                    'glpi_projecttasks.projectstates_id',
                    'glpi_projects.name AS project_name',
                ],
                'FROM'       => 'glpi_projecttasks',
                'INNER JOIN' => [
                    'glpi_projects' => [
                        'ON' => ['glpi_projecttasks' => 'projects_id', 'glpi_projects' => 'id'],
                    ],
                ],
                'WHERE'      => $where,
                'ORDER'      => ['glpi_projects.name', 'glpi_projecttasks.name'],
            ]) as $row
        ) {
            $rows[]       = $row;
            $allTaskIds[] = (int) $row['id'];
        }

        if (empty($rows)) {
            return [
                'columns'  => self::columnsFrom($states),
                'cards'    => [],
                'projects' => [],
                'lanes'    => ['responsible' => []],
            ];
        }

        // ---------- Responsável = primeiro membro da equipe da tarefa,
        // mesma convenção da captura de Modelos (Etapa 4, lição 15) ----------
        $teamFirst = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    'glpi_projecttaskteams.id',
                    'glpi_projecttaskteams.projecttasks_id',
                    'glpi_projecttaskteams.items_id',
                    'glpi_users.realname',
                    'glpi_users.firstname',
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
                    'glpi_projecttaskteams.projecttasks_id' => $allTaskIds,
                ],
                'ORDER'     => ['glpi_projecttaskteams.projecttasks_id', 'glpi_projecttaskteams.id'],
            ]) as $row
        ) {
            $tid = (int) $row['projecttasks_id'];
            if (isset($teamFirst[$tid])) {
                continue; // já temos o primeiro (ordenado por id da equipe)
            }
            $label           = trim(($row['realname'] ?? '') . ' ' . ($row['firstname'] ?? ''));
            $teamFirst[$tid] = [
                'id'   => (int) $row['items_id'],
                'name' => $label !== '' ? $label : ($row['login'] ?? '?'),
            ];
        }

        $deps     = TaskDep::countForTasks($allTaskIds);
        $comments = TaskComment::countForTasks($allTaskIds);

        // ---------- Monta os cartões (TODAS as tarefas, inclusive
        // filhas — o JS decide o que vira cartão de topo x subtarefa
        // aninhada a partir de task_parent_id) ----------
        $cards         = [];
        $usersSeen     = [];
        $sawExtraState = false;
        $directProjectIds = [];

        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $stateId = (int) $row['projectstates_id'];
            $pid     = (int) $row['projects_id'];

            if (!isset($states[$stateId])) {
                // Sem fase (0) ou referência de estado excluído: agrupa
                // na coluna "Sem fase" (id sintético UNSET_ID).
                $sawExtraState = true;
                $stateId       = self::UNSET_ID;
            }

            $resp     = $teamFirst[$id] ?? null;
            $respId   = $resp['id'] ?? self::UNSET_ID;
            $respName = $resp['name'] ?? __('Sem responsável', 'projectplus');

            $usersSeen[$respId]      = $respName;
            $directProjectIds[$pid]  = true;

            $cards[] = [
                'id'                => $id,
                'name'              => $row['name'],
                'url'               => ProjectTask::getFormURLWithID($id),
                'project_id'        => $pid,
                'project_name'      => $row['project_name'],
                'project_url'       => Project::getFormURLWithID($pid),
                'task_parent_id'    => (int) $row['projecttasks_id'],
                'responsible_id'    => $respId,
                'responsible_name'  => $respName,
                'state_id'          => $stateId,
                'percent'           => (int) $row['percent_done'],
                'is_done'           => (int) $row['percent_done'] >= 100,
                'blocked'           => $deps[$id]['blocked'] ?? false,
                'deps'              => $deps[$id]['deps'] ?? 0,
                'comments'          => $comments[$id] ?? 0,
                'deadline'          => Deadline::compute(
                    $row['plan_start_date'],
                    $row['real_start_date'],
                    $row['plan_end_date'],
                    (int) $row['percent_done']
                ),
            ];
        }

        // ---------- Colunas: fases (ordem de nome, "1. "…"5. ") + "Sem fase" ----------
        $columns = self::columnsFrom($states, $sawExtraState);

        // ---------- Árvore de projetos (Bloco 1.2 + 1.3): monta a árvore de
        // swimlanes por projeto. Bloco 1.3 (ponto 1 do usuário): agora
        // inclui a SUBÁRVORE COMPLETA de cada projeto raiz "envolvido"
        // (que tem tarefa em algum nível abaixo dele), não só os projetos
        // que têm tarefa direta — assim um subprojeto SEM tarefa direta
        // ainda aparece como lane expansível, em paridade com a Visão geral
        // (antes ele sumia do Kanban). O "teto" ($ceiling) continua sendo o
        // projeto aberto quando a chamada é escopada (aba nativa): ele vira
        // a raiz local (parent_id = 0) e não subimos/descemos além dele. ----------
        $ceiling = $projectId ?? 0;

        // pai efetivo respeitando o teto: o projeto do escopo é tratado
        // como raiz (parent_id 0), mesmo que tenha pai real fora do escopo.
        $parentAt = static function (int $pid) use ($parentOf, $ceiling): int {
            if ($ceiling !== 0 && $pid === $ceiling) {
                return 0;
            }
            return $parentOf[$pid] ?? 0;
        };

        // mapa de filhos (descendente) a partir de TODOS os projetos da
        // entidade, já respeitando o teto — usado para descer a subárvore.
        $childrenOf = [];
        foreach (array_keys($nameOf) as $pid) {
            $par = $parentAt($pid);
            if ($par !== 0) {
                $childrenOf[$par][] = $pid;
            }
        }

        // raízes envolvidas: sobe de cada projeto COM tarefa até a raiz
        // (ou até o teto do escopo), com trava anti-ciclo.
        $rootsInvolved = [];
        foreach (array_keys($directProjectIds) as $pid) {
            $cur   = $pid;
            $guard = 0;
            while (true) {
                $par = $parentAt($cur);
                if ($par === 0 || $guard++ > 1000) {
                    $rootsInvolved[$cur] = true;
                    break;
                }
                $cur = $par;
            }
        }

        // desce a subárvore completa de cada raiz envolvida (inclui
        // subprojetos sem tarefa direta), com trava anti-ciclo por visita.
        $projects = [];
        $stack    = array_keys($rootsInvolved);
        $guard    = 0;
        while (!empty($stack)) {
            if ($guard++ > 100000) {
                break;
            }
            $pid = (int) array_pop($stack);
            if (isset($projects[$pid])) {
                continue;
            }
            $projects[$pid] = [
                'name'      => $nameOf[$pid] ?? ('#' . $pid),
                'parent_id' => $parentAt($pid),
            ];
            foreach ($childrenOf[$pid] ?? [] as $child) {
                if (!isset($projects[$child])) {
                    $stack[] = $child;
                }
            }
        }

        // ---------- Swimlane "Responsável": ordenada por nome; "Sem
        // responsável" por último (sem hierarquia, ao contrário de Projeto) ----------
        $laneUsers = [];
        foreach ($usersSeen as $uid => $name) {
            if ($uid !== self::UNSET_ID) {
                $laneUsers[] = ['id' => $uid, 'name' => $name];
            }
        }
        usort($laneUsers, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        if (isset($usersSeen[self::UNSET_ID])) {
            $laneUsers[] = ['id' => self::UNSET_ID, 'name' => $usersSeen[self::UNSET_ID]];
        }

        return [
            'columns'  => $columns,
            'cards'    => $cards,
            'projects' => $projects,
            'lanes'    => [
                'responsible' => $laneUsers,
            ],
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

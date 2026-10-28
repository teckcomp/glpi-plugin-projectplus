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

    /**
     * Acesso ao board de TAREFAS (usado também pela aba na ficha do
     * projeto). Etapa 8, Bloco 4: passou a exigir o direito próprio
     * `plugin_projectplus_kanban` — antes era o direito do Painel, o que
     * dava a aba de Kanban de tarefas ao Cliente.
     */
    public static function canAccess(): bool
    {
        return Access::can('kanban');
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
            'WHERE'  => array_merge(['projects_id' => $ids], Trash::liveTasks()), // Bloco M-1
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
     * @param ?int $typeId    Etapa 9 — tipo de projeto do board: as COLUNAS
     *                        passam a ser o conjunto de fases do tipo e os
     *                        cartões, as tarefas dos projetos daquele tipo.
     *                        null = todas as fases (comportamento anterior).
     *                        Ignorado quando $projectId é informado: na aba
     *                        da ficha nativa o tipo vem do próprio projeto.
     *
     * @return array{
     *   columns: array<int, array{id:int, name:string, color:string}>,
     *   cards: array<int, array<string, mixed>>,
     *   projects: array<int, array{name:string, parent_id:int}>,
     *   lanes: array{responsible: array<int, array{id:int, name:string}>}
     * }
     */
    public static function getBoardData(
        ?int $projectId = null,
        ?array $myTaskIds = null,
        ?array $taskProjectIds = null,
        ?int $typeId = null
    ): array {
        /** @var \DBmysql $DB */
        global $DB;

        // Etapa 9 — a aba "Kanban (ProjectPlus)" da ficha nativa não tem (nem
        // precisa de) seletor: já existe um projeto único em contexto, então o
        // vocabulário de colunas é o do TIPO DESSE PROJETO.
        if ($projectId !== null) {
            $typeId = TypePhase::typeOfProject($projectId);
        }

        // Colunas: conjunto de fases do TIPO — é ele que define QUAIS colunas
        // existem e em que ordem (null = todas as fases, como antes da Etapa
        // 9). O cartão do Kanban não exibe o nome da fase (a COLUNA já é a
        // fase), então aqui não é preciso o mapa completo de estados.
        $columnStates = Dashboard::getStatesMap($typeId);

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
            // Aba do projeto (KanbanTab): escopo do projeto + descendentes.
            $scopeIds = array_merge([$projectId], Budget::getDescendantIds($projectId));
            $where['glpi_projecttasks.projects_id'] = $scopeIds;
        } elseif ($myTaskIds !== null) {
            // Board cheio, escopo PESSOAL: só as minhas tarefas.
            $where['glpi_projecttasks.id'] = Scope::inList($myTaskIds);
        } elseif ($taskProjectIds !== null) {
            // Board cheio, escopo GERÊNCIA: tarefas dos meus projetos.
            $where['glpi_projecttasks.projects_id'] = Scope::inList($taskProjectIds);
        }

        // Etapa 9 — board cheio com tipo selecionado: só as tarefas de
        // projetos daquele tipo. O JOIN com glpi_projects já está aqui, então
        // basta restringir a coluna do tipo (qualificada — lição 16). Não é
        // interseção de listas: a restrição é por coluna, e conviver com o
        // filtro de ids do escopo na MESMA consulta é seguro porque as chaves
        // do WHERE são diferentes.
        if ($projectId === null && $typeId !== null) {
            $where['glpi_projects.projecttypes_id'] = $typeId;
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
                    'glpi_projecttasks.date_mod', // O-2c: regra de "Parado"
                    'glpi_projecttasks.auto_percent_done', // O-2b: % travado no modal
                    'glpi_projecttasks.content',           // O-2e-2: trecho no cartão
                    'glpi_projects.name AS project_name',
                ],
                'FROM'       => 'glpi_projecttasks',
                'INNER JOIN' => [
                    'glpi_projects' => [
                        'ON' => ['glpi_projecttasks' => 'projects_id', 'glpi_projects' => 'id'],
                    ],
                ],
                'WHERE'      => array_merge($where, Trash::liveTasks()), // Bloco M-1
                'ORDER'      => ['glpi_projects.name', 'glpi_projecttasks.name'],
            ]) as $row
        ) {
            $rows[]       = $row;
            $allTaskIds[] = (int) $row['id'];
        }

        if (empty($rows)) {
            return [
                'columns'  => self::columnsFrom($columnStates),
                'cards'    => [],
                'projects' => [],
                'lanes'    => ['responsible' => []],
            ];
        }

        // ---------- Responsável = primeiro membro da equipe da tarefa,
        // mesma convenção da captura de Modelos (Etapa 4, lição 15) ----------
        $teamFirst = [];
        $teamAll   = []; // O-2a: todos os responsáveis, para o modal do cartão
        $teamPeople = []; // O-4: os mesmos, com id (avatares)
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
            // Respeita a ordem de nome configurada no GLPI (config ou
            // preferência da sessão), em vez de fixar "Sobrenome Nome".
            $label           = \formatUserName(
                (int) $row['items_id'],
                $row['login'] ?? '',
                $row['realname'] ?? '',
                $row['firstname'] ?? ''
            );
            $teamAll[$tid][] = $label !== '' ? $label : '?';
            // O-4: avatares no cartão (id + nome, na ordem da equipe)
            $teamPeople[$tid][] = ['id' => (int) $row['items_id'], 'name' => $label !== '' ? $label : '?'];
            if (isset($teamFirst[$tid])) {
                continue; // já temos o primeiro (ordenado por id da equipe)
            }
            $teamFirst[$tid] = [
                'id'   => (int) $row['items_id'],
                'name' => $label !== '' ? $label : '?',
            ];
        }

        $deps     = TaskDep::countForTasks($allTaskIds);
        $comments = TaskComment::countForTasks($allTaskIds);
        $preview  = TaskComment::previewFor('task', $allTaskIds); // O-2c
        // O-4: não lidos do usuário logado (a leitura é por pessoa)
        $me       = (int) Session::getLoginUserID();
        $unread   = CommentRead::unreadFor('task', $allTaskIds, $me);
        $now      = time();

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

            if (!isset($columnStates[$stateId])) {
                // Sem fase (0), estado excluído — ou (Etapa 9) fase que NÃO
                // pertence ao conjunto do tipo deste board: agrupa na coluna
                // "Sem fase" (id sintético UNSET_ID). Sem isto o cartão
                // apontaria para uma coluna inexistente e sumiria da tela.
                $sawExtraState = true;
                $stateId       = self::UNSET_ID;
            }

            $resp     = $teamFirst[$id] ?? null;
            $respId   = $resp['id'] ?? self::UNSET_ID;
            $respName = $resp['name'] ?? __('Sem responsável', 'projectplus');

            $deadline = Deadline::compute(
                $row['plan_start_date'],
                $row['real_start_date'],
                $row['plan_end_date'],
                (int) $row['percent_done']
            );

            $usersSeen[$respId]      = $respName;
            $directProjectIds[$pid]  = true;

            $cards[] = [
                'id'                => $id,
                'name'              => $row['name'],
                'url'               => Url::project($pid, $id),
                'project_id'        => $pid,
                'project_name'      => $row['project_name'],
                'project_url'       => Url::project($pid),
                'task_parent_id'    => (int) $row['projecttasks_id'],
                'responsible_id'    => $respId,
                'responsible_name'  => $respName,
                'team_names'        => $teamAll[$id] ?? [], // O-2a
                'team'              => $teamPeople[$id] ?? [], // O-4: avatares
                'state_id'          => $stateId,
                'percent'           => (int) $row['percent_done'],
                'auto_percent'      => !empty($row['auto_percent_done']), // O-2b
                'is_done'           => (int) $row['percent_done'] >= 100,
                'blocked'           => $deps[$id]['blocked'] ?? false,
                'deps'              => $deps[$id]['deps'] ?? 0,
                'comments'          => $comments[$id] ?? 0,
                'unread'            => $unread['count'][$id] ?? 0, // O-4
                // Bloco N-5: prazo final no rodapé do cartão (formato do usuário)
                'end_label'         => DateFmt::date($row['plan_end_date'], ''),
                'start_label'       => DateFmt::date($row['plan_start_date'], ''), // O-2a
                'deadline'          => $deadline,
                // O-2c: tag de status e prévia dos 2 últimos comentários
                'status'            => self::taskStatus($row, $deadline, $preview['latest'][$id] ?? null, $now),
                'last_comments'     => CommentRead::flagPreview($preview['items'][$id] ?? [], $unread['since'][$id] ?? PHP_INT_MAX, $me),
                // O-2e-2: descrição — 2 linhas abaixo do título (texto puro)
                'has_desc'          => Description::has($row['content'] ?? ''),
                'desc_excerpt'      => Description::excerpt($row['content'] ?? ''),
            ];
        }

        // ---------- Colunas: conjunto de fases do TIPO, na ordem gravada
        // (Etapa 9; sem tipo = todas as fases em ordem de nome) + "Sem fase" ----------
        $columns = self::columnsFrom($columnStates, $sawExtraState);

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
                // Bloco B: o nome na faixa da raia abre o projeto no painel
                // do plugin (regra do Bloco A — nunca a ficha nativa).
                'url'       => Url::project($pid),
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
            // O-3: regras do arrastar (comentário / novo prazo) e as fases
            // finalizadoras (lá o novo prazo não é pedido)
            'move'     => MoveRules::config() + ['finished' => TaskDep::finishedStateIds(), 'desc' => Description::required()],
        ];
    }

    /**
     * O-2b (27/10/2026) — UM cartão recalculado (tag, barras, prévia), para o
     * modal redesenhar o cartão depois de salvar sem recarregar a página.
     * Reaproveita getBoardData() inteiro com a lista de UM id, para o cartão
     * sair idêntico ao do board. Sem filtro de tipo: `state_id` é a fase
     * real (o JS encaixa na coluna do board). Quem chama já conferiu a
     * permissão sobre a tarefa.
     */
    public static function cardFor(int $taskId): ?array
    {
        if ($taskId <= 0) {
            return null;
        }
        $data = self::getBoardData(null, [$taskId]);
        foreach ($data['cards'] as $card) {
            if ((int) $card['id'] === $taskId) {
                return $card;
            }
        }
        return null;
    }

    /**
     * @param array<int, array{name:string, color:string}> $states
     */
    /** O-2c: tarefa iniciada sem nenhuma alteração há este tanto de dias = "Parado". */
    public const TASK_STALLED_DAYS = 3;

    /**
     * O-2c (27/10/2026) — tag de status do cartão da tarefa, nesta ordem:
     *   done     — 100% concluída;
     *   late     — fim planejado já passou (mesma regra do "Atrasado" do painel);
     *   stalled  — começou (% > 0) e nem a tarefa (date_mod) nem os comentários
     *              mudam há TASK_STALLED_DAYS dias;
     *   nodates  — sem datas suficientes para a barra de prazo;
     *   ok       — em dia.
     */
    public static function taskStatus(array $row, array $deadline, ?string $lastComment, int $now): string
    {
        $pct = (int) ($row['percent_done'] ?? 0);
        if ($pct >= 100) {
            return 'done';
        }
        if (!empty($row['plan_end_date']) && strtotime((string) $row['plan_end_date']) < $now) {
            return 'late';
        }
        if ($pct > 0) {
            $last = max(
                !empty($row['date_mod']) ? (int) strtotime((string) $row['date_mod']) : 0,
                !empty($lastComment) ? (int) strtotime($lastComment) : 0
            );
            if ($last > 0 && $last < $now - self::TASK_STALLED_DAYS * 86400) {
                return 'stalled';
            }
        }
        if (($deadline['state'] ?? '') === 'none') {
            return 'nodates';
        }
        return 'ok';
    }

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

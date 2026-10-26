<?php

/**
 * ProjectPlus — helper central de controle de acesso por MÓDULO (Etapa 8, Bloco 2).
 *
 * Camada de PERMISSÃO: traduz os direitos granulares criados no Bloco 1
 * (`plugin_projectplus_*`) em decisões simples de "pode ver este módulo?".
 * NÃO trata de ESCOPO (pessoal x gerência x todos) — isso é o Bloco 3.
 *
 * Uso típico:
 *   - nos front/*.php, para gatear a tela:   Access::require('costs');
 *   - nos templates, para esconder a sidebar: passa-se Access::sidebar() como 'nav'.
 *
 * Classe estática, sem estado e sem extends — autoloadeia via PSR-4
 * (namespace GlpiPlugin\Projectplus → src/), não precisa de registro no setup.php.
 */

namespace GlpiPlugin\Projectplus;

use Html;
use Session;

class Access
{
    /**
     * Mapa módulo → nome do direito em glpi_profilerights.
     * (Configuração continua no direito NATIVO `config`, fora daqui.)
     */
    public const RIGHTS = [
        'dashboard'     => 'plugin_projectplus_dashboard',
        'projects'      => 'plugin_projectplus_projects',
        'tasks'         => 'plugin_projectplus_tasks',
        'kanban'        => 'plugin_projectplus_kanban',
        'timeline'      => 'plugin_projectplus_timeline',
        'clientview'    => 'plugin_projectplus_clientview',
        'costs'         => 'plugin_projectplus_costs',
        'reports'       => 'plugin_projectplus_reports',
        'templates'     => 'plugin_projectplus_templates',
        'alerts'        => 'plugin_projectplus_alerts',
        // Escopo (Bloco 3) — não entra na sidebar; usado por src/Scope.php.
        // O-1a (23/10/2026): "ver projetos que gerencia" (`seemanaged`)
        // saiu — o gestor é reconhecido pelo VÍNCULO (tabela de gestores),
        // não por direito (R2/R8). A linha fica em Install::LEGACY_RIGHTS.
        'seeall'        => 'plugin_projectplus_seeall',
    ];

    /**
     * Super-Admin do plugin (R1): direito NATIVO "Configuração: Atualizar"
     * — o mesmo critério que já guarda a Configuração do plugin. Visão (e,
     * a partir do O-1b, ação) sobre todos os projetos.
     */
    public static function isSuperAdmin(): bool
    {
        return (bool) Session::haveRight('config', UPDATE);
    }

    /**
     * O perfil atual tem o direito $module no nível $right?
     * $right ausente = READ.
     */
    public static function can(string $module, ?int $right = null): bool
    {
        if (!isset(self::RIGHTS[$module])) {
            return false;
        }
        if ($right === null) {
            $right = READ;
        }
        // O-1e (26/10/2026) — Visão do cliente = SOMENTE LEITURA, travada no
        // código: nenhum "Interagir/Criar/Excluir" de módulo vale para o
        // cliente, mesmo marcado na matriz. Ver continua pela matriz.
        if ($right !== READ && !in_array($module, ['clientview', 'seeall'], true) && self::isClient()) {
            return false;
        }
        // O-1e: "Painel: Ver" + "Visão do cliente" já abre Kanban (os dois
        // boards) e Timeline, sem marcar esses módulos (decisão do Claudio).
        if ($right === READ && in_array($module, self::CLIENT_IMPLIED, true) && self::isClientPackage()) {
            return true;
        }
        return (bool) Session::haveRight(self::RIGHTS[$module], $right);
    }

    /** O-1e: telas que o pacote do cliente (Painel + Visão do cliente) abre. */
    public const CLIENT_IMPLIED = ['kanban', 'timeline'];

    /** O-1e: o perfil tem "Visão do cliente"? (direto na sessão, sem recursão) */
    public static function isClient(): bool
    {
        return (bool) Session::haveRight(self::RIGHTS['clientview'], READ);
    }

    /** O-1e: pacote do cliente ligado = "Visão do cliente" + "Painel: Ver". */
    public static function isClientPackage(): bool
    {
        return self::isClient() && (bool) Session::haveRight(self::RIGHTS['dashboard'], READ);
    }

    /**
     * O-1e: o Painel mostra TAREFAS (botão Tarefas da linha do projeto,
     * Tarefas em andamento, "Tarefas atrasadas")? Quem tem Tarefas: Ver, ou
     * o cliente com o pacote — que vê as tarefas atribuídas a ele no Painel
     * sem ganhar a tela "Minhas tarefas" (essa segue a matriz).
     */
    public static function canSeeTasksInPanel(): bool
    {
        return self::can('tasks') || self::isClientPackage();
    }

    /**
     * Gate de tela: interrompe com "sem permissão" se o perfil não puder ver o módulo.
     * Equivalente a Session::checkRight, mas resolvendo o nome do direito pelo mapa.
     */
    public static function require(string $module, ?int $right = null): void
    {
        if (!self::can($module, $right)) {
            Html::displayRightError();
        }
    }

    /**
     * Telas de entrada do plugin, na ordem em que o menu escolhe a primeira
     * que o perfil alcança (Bloco F-1b, 05/10/2026). O Painel (Visão geral)
     * deixou de ser a porta única: o técnico com só Tarefas/Kanban entra
     * direto em "Minhas tarefas". Alertas (sino) não tem tela própria.
     */
    private const ENTRY = [
        'dashboard' => 'front/dashboard.php',
        'tasks'     => 'front/mytasks.php',
        'kanban'    => 'front/kanban.php',
        'timeline'  => 'front/timeline.php',
        'reports'   => 'front/reports.php',
        'costs'     => 'front/costs.php',
        'templates' => 'front/projecttemplates.php',
    ];

    /** Caminho da primeira tela que o perfil alcança; null = nenhuma. */
    public static function homePath(): ?string
    {
        foreach (self::ENTRY as $module => $path) {
            $ok = self::can($module);
            if ($ok) {
                return $path;
            }
        }
        return null;
    }

    /** O perfil entra no plugin (menu, leituras comuns, comentários)? */
    public static function canEnter(): bool
    {
        return self::homePath() !== null;
    }

    /**
     * O item "Kanban" da sidebar aparece se o perfil puder ver ALGUM Kanban:
     * o de tarefas (comum) OU o de projetos (Cliente).
     */
    public static function canKanban(): bool
    {
        // Bloco F-2a: o direito "Kanban de projetos (Cliente)" foi aposentado;
        // os dois boards são liberados pelo Kanban.
        return self::can('kanban');
    }

    /**
     * Roteamento do menu "Kanban": true quando o perfil só tem o Kanban de
     * PROJETOS (Cliente) e NÃO o de tarefas — nesse caso o item deve levar ao
     * board de projetos (a ser construído no Bloco 4). Os demais vão ao Kanban
     * de tarefas atual.
     */
    public static function kanbanIsProjects(): bool
    {
        // Bloco F-2b: sempre falso. No F-2a a Visão do cliente redirecionava
        // para o board de projetos — e o botão "Kanban de tarefas" (que
        // aponta para kanban.php) caía de volta nele, num laço. Com o
        // direito do Cliente aposentado, não há mais quem só tenha o board
        // de projetos: o Kanban abre sempre no de tarefas.
        return false;
    }

    /**
     * Pode alterar tarefas NESTA TELA? (flag de página — liga os controles
     * de edição; a decisão final é por tarefa, em canUpdateTask()).
     *
     * O-1b (23/10/2026): Super-Admin, quem gerencia algum projeto (R2) ou
     * "Interagir" do módulo. O nativo `project` UPDATE deixou de valer.
     */
    public static function canUpdateTasks(string $module = 'tasks'): bool
    {
        if (self::isClient()) {
            return false; // O-1e: cliente nunca edita
        }
        return self::isSuperAdmin()
            || Scope::managesAny()
            || self::can($module, UPDATE);
    }

    /**
     * Pode alterar ESTA tarefa (fase, %, datas, concluir, auto, mover no
     * Kanban)? O-1b (23/10/2026) — regras R1/R2/R5 do Ciclo O:
     *
     *   1. Super-Admin (nativo "Configuração: Atualizar") → sim;
     *   2. gestor do projeto da tarefa ou de um projeto acima (vínculo, sem
     *      depender de direito do módulo) → sim;
     *   3. "Interagir" do módulo (Tarefas ou Kanban) ou o nativo
     *      `projecttask` UPDATEMY, E o usuário é ATOR da tarefa → sim.
     *
     * "Ver todos" (`seeall`) é só leitura e NÃO entra. O nativo `project`
     * UPDATE deixou de liberar tudo (correção de 22/09 mantida no item 3).
     * Sempre exige acesso à entidade da tarefa.
     *
     * @param \ProjectTask $task tarefa já carregada (getFromDB)
     */
    public static function canUpdateTask($task, string $module = 'tasks'): bool
    {
        if (self::isClient()) {
            return false; // O-1e: fecha também o nativo UPDATEMY
        }
        if (!Session::haveAccessToEntity((int) $task->getEntityID())) {
            return false;
        }
        if (self::isSuperAdmin() || self::managesTask($task)) {
            return true;
        }
        $viaPlugin = self::can($module, UPDATE);
        $viaCore   = (bool) Session::haveRight('projecttask', 1024); // ProjectTask::UPDATEMY
        if (!$viaPlugin && !$viaCore) {
            return false;
        }
        return self::isTaskActor($task);
    }

    /**
     * O-1b: o usuário logado é gestor do projeto desta tarefa, ou de um
     * projeto acima dele (árvore gerenciada, R2)?
     *
     * @param \ProjectTask $task
     */
    public static function managesTask($task): bool
    {
        return Scope::managesProject((int) ($task->fields['projects_id'] ?? 0));
    }

    /**
     * O-1b: pode CRIAR tarefa neste projeto? Super-Admin ou gestor da
     * árvore (R2). O técnico continua sem criar, como antes (o "Criar" do
     * nativo `projecttask` não existe no GLPI 11).
     */
    public static function canCreateTaskIn(int $projectId): bool
    {
        if (self::isClient()) {
            return false; // O-1e
        }
        $project = new \Project();
        if ($projectId <= 0 || !$project->getFromDB($projectId)
            || !empty($project->fields['is_deleted'])
            || !Session::haveAccessToEntity((int) $project->getEntityID())) {
            return false;
        }
        return self::isSuperAdmin() || Scope::managesProject($projectId);
    }

    /**
     * O-1b: pode mandar ESTA tarefa para a lixeira? Super-Admin ou gestor
     * da árvore (R2, sem depender de Excluir); os demais como antes
     * ("Interagir" de Tarefas + Excluir nativo) e só se forem ATORES da
     * tarefa (R5 — antes valia para qualquer tarefa da entidade).
     *
     * @param \ProjectTask $task
     */
    public static function canDeleteTask($task): bool
    {
        if (self::isClient()) {
            return false; // O-1e
        }
        if (!Session::haveAccessToEntity((int) $task->getEntityID())) {
            return false;
        }
        if (self::isSuperAdmin() || self::managesTask($task)) {
            return true;
        }
        return self::can('tasks', UPDATE)
            && (bool) Session::haveRight('projecttask', DELETE)
            && self::isTaskActor($task);
    }

    /** Flag de PÁGINA do 🗑 da tarefa; o servidor confere por tarefa. */
    public static function canDeleteTasks(): bool
    {
        if (self::isClient()) {
            return false; // O-1e
        }
        return self::isSuperAdmin()
            || Scope::managesAny()
            || (self::can('tasks', UPDATE) && (bool) Session::haveRight('projecttask', DELETE));
    }

    /**
     * Pode editar PROJETOS nesta tela? Flag de PÁGINA (liga a faixa
     * "Projeto" do painel e o arrastar no Kanban de projetos); a decisão
     * final é por projeto, em canEditProject().
     *
     * Bloco D-3a: módulo Projetos UPDATE + nativo `project` UPDATE.
     * O-1b-2a (26/10/2026): Super-Admin, quem gerencia algum projeto (R2)
     * ou "Interagir" de Projetos (vale nos projetos de cuja equipe faz
     * parte). O nativo `project` UPDATE deixou de valer.
     */
    public static function canEditProjects(): bool
    {
        if (self::isClient()) {
            return false; // O-1e
        }
        return self::isSuperAdmin()
            || Scope::managesAny()
            || self::can('projects', UPDATE);
    }

    /**
     * Pode editar ESTE projeto (faixa "Projeto": fase, datas, %, auto;
     * arrastar no Kanban de projetos)? O-1b-2a (26/10/2026):
     *
     *   1. Super-Admin (nativo "Configuração: Atualizar") → sim;
     *   2. gestor do projeto ou de um projeto acima (vínculo, R2) → sim;
     *   3. membro da EQUIPE do projeto (`glpi_projectteams`, usuário) com
     *      Projetos "Interagir" → sim (decisão do Claudio, 23/10).
     *
     * "Ver todos" (`seeall`) é só leitura. Sempre exige a entidade.
     *
     * @param \Project $project projeto já carregado (getFromDB)
     */
    public static function canEditProject($project): bool
    {
        if (self::isClient()) {
            return false; // O-1e
        }
        if (!Session::haveAccessToEntity((int) $project->getEntityID())) {
            return false;
        }
        $pid = (int) $project->getID();
        if (self::isSuperAdmin() || Scope::managesProject($pid)) {
            return true;
        }
        return self::can('projects', UPDATE) && self::isProjectTeamMember($pid);
    }

    /**
     * Pode adicionar/remover pessoas na EQUIPE deste projeto? O-1b-2a:
     * só Super-Admin ou gestor da árvore (decisão do Claudio, 26/10 — igual
     * à equipe da tarefa). Quem entra na equipe passa a ENXERGAR o
     * projeto, então o membro com "Interagir" edita mas não amplia a equipe.
     *
     * @param \Project $project
     */
    public static function canManageProjectTeam($project): bool
    {
        if (self::isClient()) {
            return false; // O-1e
        }
        if (!Session::haveAccessToEntity((int) $project->getEntityID())) {
            return false;
        }
        return self::isSuperAdmin() || Scope::managesProject((int) $project->getID());
    }

    /**
     * Pode mandar ESTE projeto (com os subprojetos) para a lixeira?
     * O-1b-2a: Super-Admin ou gestor da árvore, sem depender do Excluir
     * nativo (decisão do Claudio, 26/10). Antes: Projetos "Interagir" +
     * nativo `project` DELETE.
     *
     * @param \Project $project
     */
    public static function canDeleteProject($project): bool
    {
        if (self::isClient()) {
            return false; // O-1e
        }
        if (!Session::haveAccessToEntity((int) $project->getEntityID())) {
            return false;
        }
        return self::isSuperAdmin() || Scope::managesProject((int) $project->getID());
    }

    /** Flag de PÁGINA da lixeira do projeto; o servidor confere por projeto. */
    public static function canDeleteProjects(): bool
    {
        if (self::isClient()) {
            return false; // O-1e
        }
        return self::isSuperAdmin() || Scope::managesAny();
    }

    /**
     * O usuário logado está na EQUIPE deste projeto (linha de usuário em
     * `glpi_projectteams` — a equipe de projeto é só de usuários, P-0)?
     */
    public static function isProjectTeamMember(int $projectId): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0 || $projectId <= 0) {
            return false;
        }
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_projectteams',
            'WHERE'  => [
                'projects_id' => $projectId,
                'itemtype'    => 'User',
                'items_id'    => $uid,
            ],
            'LIMIT'  => 1,
        ])->current();
        return !empty($row);
    }

    /**
     * Permissões da faixa "Projeto" que mudam o desenho (O-1b-2a): vão
     * junto da meta para o JS esconder o "+"/"×" da equipe e a lixeira.
     *
     * @param \Project $project
     * @return array{can_team: bool, can_delete: bool}
     */
    public static function projectStripFlags($project): array
    {
        return [
            'can_team'   => self::canManageProjectTeam($project),
            'can_delete' => self::canDeleteProject($project),
        ];
    }

    /**
     * Pode trocar/adicionar RESPONSÁVEIS desta tarefa?
     *
     * Bloco D-2a: só o gestor. O-1b (23/10/2026): "gestor" passou a ser o
     * VÍNCULO (gestor do projeto da tarefa ou de um acima — R2) ou o
     * Super-Admin, não mais o nativo `project` UPDATE. O técnico ator
     * continua alterando %, fase e datas, mas NÃO mexe na equipe.
     *
     * @param \ProjectTask $task
     */
    public static function canManageTaskTeam($task): bool
    {
        if (self::isClient()) {
            return false; // O-1e
        }
        if (!Session::haveAccessToEntity((int) $task->getEntityID())) {
            return false;
        }
        return self::isSuperAdmin() || self::managesTask($task);
    }

    /** Flag de PÁGINA (mostra chips com × e o "+"); o servidor confere por tarefa. */
    public static function canManageTaskTeams(): bool
    {
        if (self::isClient()) {
            return false; // O-1e
        }
        return self::isSuperAdmin() || Scope::managesAny();
    }

    /**
     * O usuário logado é ator da tarefa? Responsável (`users_id`) ou membro
     * da equipe (`glpi_projecttaskteams`) — direto ou por um de seus grupos.
     *
     * @param \ProjectTask $task
     */
    public static function isTaskActor($task): bool
    {
        $uid = (int) Session::getLoginUserID();
        if ($uid <= 0) {
            return false;
        }
        if ((int) ($task->fields['users_id'] ?? 0) === $uid) {
            return true;
        }
        $team   = \ProjectTaskTeam::getTeamFor((int) $task->getID());
        foreach ($team['User'] ?? [] as $m) {
            if ((int) $m['items_id'] === $uid) {
                return true;
            }
        }
        $groups = array_map('intval', (array) ($_SESSION['glpigroups'] ?? []));
        foreach ($team['Group'] ?? [] as $m) {
            if (in_array((int) $m['items_id'], $groups, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Flags de visibilidade da sidebar, consumidas pelos templates como `nav.*`.
     * Retorna TODAS as chaves sempre (o Twig do GLPI é strict: acessar chave
     * inexistente em `nav` quebraria a tela — lição nº 9).
     */
    public static function sidebar(): array
    {
        return [
            'dashboard' => self::can('dashboard'),
            'tasks'     => self::can('tasks'),
            'kanban'    => self::canKanban(),
            'timeline'  => self::can('timeline'),
            'templates' => self::can('templates'),
            'costs'     => self::can('costs'),
            'reports'   => self::can('reports'),
            'alerts'    => self::can('alerts'),
            'config'    => (bool) Session::haveRight('config', UPDATE),
            // Bloco F-2a: Visão do cliente esconde controles nas telas
            'client'    => self::can('clientview'),
        ];
    }
}

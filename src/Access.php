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
        // Escopos (Bloco 3) — não entram na sidebar; usados por src/Scope.php
        // para decidir o alcance do botão "Ver tudo".
        'seemanaged'    => 'plugin_projectplus_seemanaged',
        'seeall'        => 'plugin_projectplus_seeall',
    ];

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
        return (bool) Session::haveRight(self::RIGHTS[$module], $right);
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
     * CORREÇÃO (22/09/2026): o código antigo testava
     * `Session::haveRight('projecttask', UPDATE)`, mas o GLPI 11 REMOVE o
     * bit UPDATE (2) do direito `projecttask` — só existem READMY (1) e
     * UPDATEMY (1024). O teste era sempre falso, e só quem tinha `project`
     * UPDATE (gestor) conseguia alterar tarefa; o "Interagir" do plugin
     * nunca era consultado.
     */
    public static function canUpdateTasks(string $module = 'tasks'): bool
    {
        return (bool) Session::haveRight('project', UPDATE)
            || self::can($module, UPDATE);
    }

    /**
     * Pode alterar ESTA tarefa (fase, %, datas, concluir, mover no Kanban)?
     *
     *   1. `project` UPDATE nativo (gestor) → sim — comportamento anterior
     *      preservado;
     *   2. direito do módulo em UPDATE ("Interagir" de Tarefas ou de
     *      Kanban) E o usuário é ATOR da tarefa: responsável (`users_id`)
     *      ou está na equipe da tarefa (usuário, ou um grupo dele);
     *   3. o nativo `projecttask` UPDATEMY + ator (mesma regra do core,
     *      ProjectTask::canUpdateItem) → sim.
     *
     * Sempre exige acesso à entidade da tarefa.
     *
     * @param \ProjectTask $task tarefa já carregada (getFromDB)
     */
    public static function canUpdateTask($task, string $module = 'tasks'): bool
    {
        if (!Session::haveAccessToEntity((int) $task->getEntityID())) {
            return false;
        }
        if (Session::haveRight('project', UPDATE)) {
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
     * Bloco D-3a (01/10/2026): pode editar o PROJETO pela faixa do painel
     * (equipe, datas, fase, %, auto)? Mesmo critério do arrastar no Kanban de
     * projetos (4b.2): módulo Projetos em UPDATE E direito nativo `project`
     * UPDATE. Flag de PÁGINA; por projeto o servidor ainda exige
     * canUpdateItem() (entidade).
     */
    public static function canEditProjects(): bool
    {
        return self::can('projects', UPDATE) && (bool) Session::haveRight('project', UPDATE);
    }

    /** @param \Project $project */
    public static function canEditProject($project): bool
    {
        return self::canEditProjects() && (bool) $project->canUpdateItem();
    }

    /**
     * Bloco D-2a (30/09/2026): pode trocar/adicionar RESPONSÁVEIS desta tarefa?
     *
     * Decisão do Claudio: só o GESTOR — direito nativo `project` UPDATE, na
     * entidade da tarefa. Técnico "Interagir" (ator da tarefa) continua
     * alterando %, fase e datas, mas NÃO mexe na equipe.
     *
     * @param \ProjectTask $task
     */
    public static function canManageTaskTeam($task): bool
    {
        if (!Session::haveAccessToEntity((int) $task->getEntityID())) {
            return false;
        }
        return (bool) Session::haveRight('project', UPDATE);
    }

    /** Flag de PÁGINA (mostra chips com × e o "+"); o servidor confere por tarefa. */
    public static function canManageTaskTeams(): bool
    {
        return (bool) Session::haveRight('project', UPDATE);
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

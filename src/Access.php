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
        'projectkanban' => 'plugin_projectplus_projectkanban',
        'costs'         => 'plugin_projectplus_costs',
        'reports'       => 'plugin_projectplus_reports',
        'templates'     => 'plugin_projectplus_templates',
        'alerts'        => 'plugin_projectplus_alerts',
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
     * O item "Kanban" da sidebar aparece se o perfil puder ver ALGUM Kanban:
     * o de tarefas (comum) OU o de projetos (Cliente).
     */
    public static function canKanban(): bool
    {
        return self::can('kanban') || self::can('projectkanban');
    }

    /**
     * Roteamento do menu "Kanban": true quando o perfil só tem o Kanban de
     * PROJETOS (Cliente) e NÃO o de tarefas — nesse caso o item deve levar ao
     * board de projetos (a ser construído no Bloco 4). Os demais vão ao Kanban
     * de tarefas atual.
     */
    public static function kanbanIsProjects(): bool
    {
        return self::can('projectkanban') && !self::can('kanban');
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
            'timeline'  => self::can('tasks'),
            'templates' => self::can('templates'),
            'costs'     => self::can('costs'),
            'reports'   => self::can('reports'),
            'alerts'    => self::can('alerts'),
            'config'    => (bool) Session::haveRight('config', UPDATE),
        ];
    }
}

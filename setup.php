<?php

/**
 * ProjectPlus — camada extra de gestão de projetos para GLPI 11
 *
 * Não substitui nem altera as tabelas nativas (glpi_projects,
 * glpi_projecttasks). Apenas adiciona tabelas e telas próprias.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Projectplus\Config;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\Notification;
use GlpiPlugin\Projectplus\ProjectCost;
use GlpiPlugin\Projectplus\ProjectTracking;
use GlpiPlugin\Projectplus\TaskCost;

define('PLUGIN_PROJECTPLUS_VERSION', '0.5.0-alpha');

// Versões mínima/máxima do GLPI suportadas
define('PLUGIN_PROJECTPLUS_MIN_GLPI', '11.0.0');
define('PLUGIN_PROJECTPLUS_MAX_GLPI', '11.0.99');

/**
 * Inicialização do plugin: hooks, abas, menus, CSS/JS.
 */
function plugin_init_projectplus(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['projectplus'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('projectplus')) {
        return;
    }

    // Aba "Indicadores (ProjectPlus)" dentro do Project nativo
    Plugin::registerClass(ProjectTracking::class, ['addtabon' => Project::class]);

    // Aba "Custos (ProjectPlus)" dentro da tarefa nativa do projeto
    Plugin::registerClass(TaskCost::class, ['addtabon' => ProjectTask::class]);

    // Aba "Custos (ProjectPlus)" dentro do projeto nativo
    // (a aba Custos NATIVA fica oculta via JS — fonte única de custos)
    Plugin::registerClass(ProjectCost::class, ['addtabon' => Project::class]);

    // Item de menu: Ferramentas > ProjectPlus (dashboard)
    if (Session::haveRight('plugin_projectplus_dashboard', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['projectplus'] = [
            'tools' => Dashboard::class,
        ];
    }

    // Página de configuração do plugin (Configurar > Plugins)
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['projectplus'] = 'front/config.form.php';
    }

    // Reordena o menu Ferramentas: Painel primeiro, Projetos nativo por último
    // (configurável em Config: menu_first)
    $PLUGIN_HOOKS['redefine_menus']['projectplus'] = 'plugin_projectplus_redefine_menus';

    // CSS / JS carregados em todas as páginas do GLPI
    // (o JS só age nas telas do plugin e no sino de alertas)
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['projectplus'] = 'css/projectplus.css';

    // hidenativecosts.js só entra se a opção estiver ativa (Configurações)
    $pluginJs = ['js/projectplus.js'];
    $ppConfig = Config::get();
    if (!empty($ppConfig['hide_native_costs'])) {
        $pluginJs[] = 'js/hidenativecosts.js';
    }
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['projectplus'] = $pluginJs;

    // Hook pós-atualização de tarefas: mantém indicadores e alertas coerentes
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['projectplus'] = [
        ProjectTask::class => [Notification::class, 'onTaskUpdate'],
        Project::class     => [ProjectTracking::class, 'onProjectUpdate'],
    ];
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['projectplus'] = [
        ProjectTask::class => [ProjectTracking::class, 'onTaskAdd'],
    ];
}

/**
 * Metadados do plugin.
 */
function plugin_version_projectplus(): array
{
    return [
        'name'         => 'ProjectPlus',
        'version'      => PLUGIN_PROJECTPLUS_VERSION,
        'author'       => 'Teckcomp I.T. Services',
        'license'      => 'GPL-2.0-or-later',
        'homepage'     => 'https://github.com/teckcomp/glpi-plugin-projectplus',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PROJECTPLUS_MIN_GLPI,
                'max' => PLUGIN_PROJECTPLUS_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.2',
            ],
        ],
    ];
}

/**
 * Pré-requisitos (chamado antes da instalação).
 */
function plugin_projectplus_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_PROJECTPLUS_MIN_GLPI, '<')) {
        echo sprintf(
            'Este plugin requer GLPI >= %s (versão atual: %s)',
            PLUGIN_PROJECTPLUS_MIN_GLPI,
            GLPI_VERSION
        );
        return false;
    }
    return true;
}

/**
 * Verificação de configuração (chamado na ativação).
 */
function plugin_projectplus_check_config($verbose = false): bool
{
    return true;
}

/**
 * Reordena o menu Ferramentas: Painel de Projetos primeiro,
 * Projetos nativo por último. Nada é removido.
 *
 * PONTO A VALIDAR em homologação: as chaves internas do array de menu
 * podem variar conforme a versão/tema do GLPI.
 */
function plugin_projectplus_redefine_menus(array $menus): array
{
    $config = \GlpiPlugin\Projectplus\Config::get();
    if (empty($config['menu_first'])) {
        return $menus;
    }

    if (!isset($menus['tools']['content']) || !is_array($menus['tools']['content'])) {
        return $menus;
    }

    $content = $menus['tools']['content'];

    // Localiza a entrada do plugin (a chave contém "projectplus")
    $pluginKey = null;
    foreach (array_keys($content) as $key) {
        if (is_string($key) && stripos($key, 'projectplus') !== false) {
            $pluginKey = $key;
            break;
        }
    }

    // Move o painel para o início
    if ($pluginKey !== null) {
        $entry = $content[$pluginKey];
        unset($content[$pluginKey]);
        $content = [$pluginKey => $entry] + $content;
    }

    // Move o Projetos nativo para o final
    if (isset($content['project'])) {
        $native = $content['project'];
        unset($content['project']);
        $content['project'] = $native;
    }

    $menus['tools']['content'] = $content;
    return $menus;
}

<?php

/**
 * ProjectPlus — página do painel (requisitos 1 e 2).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Dashboard;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('plugin_projectplus_dashboard', READ);

Html::header(
    Dashboard::getMenuName(),
    $_SERVER['PHP_SELF'],
    'tools',
    Dashboard::class
);

// PONTO A VALIDAR em homologação: o caminho do template base
// (layout/base.html.twig) pode variar conforme a versão do GLPI.
// Este Twig é autocontido (não estende layout) para reduzir o risco.
// Filtro de período (Bloco 3.2)
$from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : null;
$until = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['until'] ?? '') ? $_GET['until'] : null;

$data = Dashboard::getData($from, $until);

$ganttActive = false;
$plugin      = new Plugin();
if (method_exists($plugin, 'isActivated')) {
    $ganttActive = $plugin->isActivated('gantt');
}

// ---- Dados para o modal "Novo projeto" (Etapa 3, Bloco 2) ----
global $DB;

$states = [];
foreach (Dashboard::getStatesMap() as $sid => $s) {
    $states[] = ['id' => $sid, 'name' => $s['name'], 'color' => $s['color']];
}

$users = [];
foreach (
    $DB->request([
        'SELECT' => ['id', 'name', 'realname', 'firstname'],
        'FROM'   => 'glpi_users',
        'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
        'ORDER'  => 'realname',
        'LIMIT'  => 300,
    ]) as $row
) {
    $label   = trim(($row['realname'] ?? '') . ' ' . ($row['firstname'] ?? ''));
    $users[] = [
        'id'   => (int) $row['id'],
        'name' => $label !== '' ? $label : $row['name'],
    ];
}

$parents = [];
foreach (
    $DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => 'glpi_projects',
        'WHERE'  => ['is_deleted' => 0, 'is_template' => 0]
            + getEntitiesRestrictCriteria('glpi_projects'),
        'ORDER'  => 'name',
    ]) as $row
) {
    $parents[] = ['id' => (int) $row['id'], 'name' => $row['name']];
}

TemplateRenderer::getInstance()->display(
    '@projectplus/dashboard.html.twig',
    [
        'plugin_web_dir'  => Plugin::getWebDir('projectplus'),
        'glpi_root'       => $CFG_GLPI['root_doc'] ?? '',
        'gantt_active'    => $ganttActive,
        'kpis'             => $data['kpis'],
        'status_chart'     => $data['status_chart'],
        'tasks_chart'      => $data['tasks_chart'],
        'phase_chart'      => $data['phase_chart'],
        'task_state_chart' => $data['task_state_chart'],
        'priority_chart'   => $data['priority_chart'],
        'open_tasks'       => $data['open_tasks'],
        'filter_from'     => $from ?? '',
        'filter_until'    => $until ?? '',
        'projects'        => $data['projects'],
        'states'          => $states,
        'users'           => $users,
        'parents'         => $parents,
        'current_user_id' => (int) Session::getLoginUserID(),
        'csrf_token'      => Session::getNewCSRFToken(),
        'can_create'      => Session::haveRight('plugin_projectplus_projects', CREATE),
        'can_templates'   => Session::haveRight('config', UPDATE),
        'nav'             => Access::sidebar(),
    ]
);

Html::footer();

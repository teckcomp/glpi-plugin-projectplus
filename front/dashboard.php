<?php

/**
 * ProjectPlus — página do painel (requisitos 1 e 2).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\I18nJs;
use GlpiPlugin\Projectplus\Scope;
use GlpiPlugin\Projectplus\TypePhase;
use GlpiPlugin\Projectplus\Url;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('plugin_projectplus_dashboard', READ);

Html::header(
    Dashboard::getMenuName(),
    '', // \Html::header ignora o 2o argumento no GLPI 11 (Bloco 4a)
    'tools',
    Dashboard::class
);

// Dicionario de traducao do JavaScript (Etapa 6, Bloco 3b): imprime o
// <script type="application/json" id="pp-i18n"> que public/js/i18n.js le.
// Tem que vir DEPOIS do Html::header (o echo cai dentro do body) e ANTES
// dos <script> das telas.
I18nJs::render();

// Caminho do template base do GLPI 11, validado em homologação.
// (layout/base.html.twig) pode variar conforme a versão do GLPI.
// Este Twig é autocontido (não estende layout) para reduzir o risco.
// Filtro de período (Bloco 3.2)
$from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : null;
$until = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['until'] ?? '') ? $_GET['until'] : null;

// Escopo (Etapa 8, Bloco 3): a tela abre sempre no PESSOAL; quem tem
// direito de escopo pode ampliar via ?scope=all (sem memória em sessão).
$scopeMode           = Scope::mode();
$scopeProjectIds     = Scope::projectIds($scopeMode);      // projetos exatos (equipe/gerência)
$scopeMyTaskIds      = Scope::myTaskIds($scopeMode);       // personal: minhas tarefas
$scopeTaskProjectIds = Scope::taskProjectIds($scopeMode);  // managed: tarefas por projeto

// Tipo de projeto (Etapa 9). Aqui o tipo é OPCIONAL — ao contrário dos
// Kanbans, a Visão geral é justamente a tela que cruza departamentos: sem
// tipo escolhido ela mostra tudo e o donut de fase (que misturaria os
// vocabulários de todos os setores) vira "Projetos por tipo".
$typeId      = TypePhase::requestedTypeOrNull();
$typeOptions = TypePhase::selectorTypes();

$data = Dashboard::getData(
    $from,
    $until,
    $scopeProjectIds,
    $scopeMyTaskIds,
    $scopeTaskProjectIds,
    $typeId
);

// Botão "Ver tudo" / "Ver só os meus" — preserva o filtro de período na URL.
$scopeCanExpand  = Scope::canExpand();
$scopeIsExpanded = Scope::isExpanded();
$scopeToggle     = [];
if ($from) {
    $scopeToggle['from'] = $from;
}
if ($until) {
    $scopeToggle['until'] = $until;
}
if ($typeId !== null) {
    $scopeToggle['type'] = $typeId;
}
if ($scopeIsExpanded) {
    // Do escopo amplo (padrão), o botão oferece REDUZIR ao pessoal.
    $scopeToggle['scope'] = 'mine';
}
$scopeToggleUrl = Url::to('front/dashboard.php')
    . ($scopeToggle === [] ? '' : ('?' . http_build_query($scopeToggle)));

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

// Tipos de projeto para o modal "Novo projeto" (Etapa 9): escolher o tipo é
// o que faz o campo Estado listar só as fases DAQUELE conjunto.
$ptypes = [];
foreach (TypePhase::projectTypes() as $tid => $tname) {
    $ptypes[] = ['id' => (int) $tid, 'name' => $tname];
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
        'plugin_web_dir'  => Url::base(),
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
        // Etapa 9 — filtro de tipo e donut adaptativo
        'ptypes'          => $ptypes,
        'type_options'    => $typeOptions,
        'filter_type'     => $typeId,
        'type_chart'      => $data['type_chart'],
        'filter_scope'    => $scopeIsExpanded ? '' : 'mine',
        // Conjunto de fases por tipo, para o campo Estado do modal seguir o
        // tipo escolhido sem recarregar a página.
        'phases_by_type'  => TypePhase::phasesByType(),
        'current_user_id' => (int) Session::getLoginUserID(),
        'csrf_token'      => Session::getNewCSRFToken(),
        'can_create'      => Session::haveRight('plugin_projectplus_projects', CREATE),
        'can_templates'   => Session::haveRight('config', UPDATE),
        // Etapa 8, Bloco 4: o painel some com o que o perfil não pode ver —
        // coluna/campo de Orçamento sem o direito de Custos (Cliente e
        // Colaborador) e bloco de Tarefas sem o direito de Tarefas (Cliente).
        'can_costs'       => Access::can('costs'),
        'can_tasks'       => Access::can('tasks'),
        'nav'             => Access::sidebar(),
        'scope_can_expand'  => $scopeCanExpand,
        'scope_is_expanded' => $scopeIsExpanded,
        'scope_toggle_url'  => $scopeToggleUrl,
    ]
);

Html::footer();

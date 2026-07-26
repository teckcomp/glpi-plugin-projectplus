<?php

/**
 * ProjectPlus — tela "Relatórios" (Etapa 5, Bloco 1 + Bloco 1.1 — filtros).
 *
 * Prévia em tela + exportação CSV (via front/reports_export.php) de três
 * conjuntos de dados: Projetos, Tarefas e Custos. Filtro de Projeto (raiz
 * + descendentes) vale para os três blocos, igual à tela "Orçamento".
 * Filtros extras — Tarefa (busca por nome), Gestor/Responsável, Fase e
 * Tipo — valem só para Projetos e Tarefas (Custos é lista de lançamentos,
 * sem essas colunas próprias).
 *
 * O filtro "Tipo" é um único campo na tela, mas guarda o tipo de PROJETO
 * ou o tipo de TAREFA (listas diferentes) — codificado em ?typefilter=
 * como "p:<id>" (tipo de projeto) ou "t:<id>" (tipo de tarefa).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\I18nJs;
use GlpiPlugin\Projectplus\Reports;
use GlpiPlugin\Projectplus\Scope;
use GlpiPlugin\Projectplus\Url;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('plugin_projectplus_reports', READ);

Html::header(
    __('Relatórios', 'projectplus'),
    '', // \Html::header ignora o 2o argumento no GLPI 11 (Bloco 4a)
    'tools',
    Dashboard::class
);

// Dicionario de traducao do JavaScript (Etapa 6, Bloco 3b): imprime o
// <script type="application/json" id="pp-i18n"> que public/js/i18n.js le.
// Tem que vir DEPOIS do Html::header (o echo cai dentro do body) e ANTES
// dos <script> das telas.
I18nJs::render();

$filterId   = (int) ($_GET['project'] ?? 0);
$taskSearch = trim((string) ($_GET['task'] ?? ''));
$userId     = (int) ($_GET['user'] ?? 0);
$stateId    = (int) ($_GET['state'] ?? 0);
$typeRaw    = (string) ($_GET['typefilter'] ?? '');
// Mesma validação de front/dashboard.php (regex Y-m-d)
$from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : null;
$until = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['until'] ?? '') ? $_GET['until'] : null;

$projectType = 0;
$taskType    = 0;
if (preg_match('/^p:(\d+)$/', $typeRaw, $m)) {
    $projectType = (int) $m[1];
} elseif (preg_match('/^t:(\d+)$/', $typeRaw, $m)) {
    $taskType = (int) $m[1];
}

$filters = [
    'user'         => $userId,
    'state'        => $stateId,
    'project_type' => $projectType,
    'task_type'    => $taskType,
    'task_search'  => $taskSearch,
    'from'         => $from,
    'until'        => $until,
];

$projects = Reports::projectsData($filterId, $filters);
$tasks    = Reports::tasksData($filterId, $filters);
$costs    = Reports::costsData($filterId);

// Escopo (Etapa 8, Bloco 4): mesma regra das demais telas — o padrão é o
// MAIOR escopo do perfil e o botão REDUZ ao pessoal (?scope=mine). Todos os
// filtros da tela são preservados na URL do botão e nos links de export.
$scopeCanExpand  = Scope::canExpand();
$scopeIsExpanded = Scope::isExpanded();
$scopeQuery      = array_filter([
    'project'    => $filterId ?: null,
    'task'       => $taskSearch !== '' ? $taskSearch : null,
    'user'       => $userId ?: null,
    'state'      => $stateId ?: null,
    'typefilter' => $typeRaw !== '' ? $typeRaw : null,
    'from'       => $from,
    'until'      => $until,
], static fn ($v): bool => $v !== null);
if ($scopeIsExpanded) {
    $scopeQuery['scope'] = 'mine';
}
$scopeToggleUrl = Url::to('front/reports.php')
    . ($scopeQuery === [] ? '' : ('?' . http_build_query($scopeQuery)));

// Lista de fases (mesma tabela glpi_projectstates para Projetos e Tarefas)
$states = [];
foreach (Dashboard::getStatesMap() as $sid => $s) {
    $states[] = ['id' => $sid, 'name' => $s['name']];
}

TemplateRenderer::getInstance()->display(
    '@projectplus/reports.html.twig',
    [
        'plugin_web_dir'    => Url::base(),
        'glpi_root'         => $CFG_GLPI['root_doc'] ?? '',
        'projects_list'     => Reports::getRootProjectsForFilter(),
        'users_list'        => Reports::getFilterUsers(),
        'states_list'       => $states,
        'project_types_list' => Reports::getProjectTypesForFilter(),
        'task_types_list'    => Reports::getTaskTypesForFilter(),
        'filter_project'    => $filterId,
        'filter_task'       => $taskSearch,
        'filter_user'       => $userId,
        'filter_state'      => $stateId,
        'filter_typefilter' => $typeRaw,
        'filter_from'       => $from ?? '',
        'filter_until'      => $until ?? '',
        'generated_at'      => date('d/m/Y H:i'),
        'can_templates'     => Session::haveRight('config', UPDATE),
        'nav'             => Access::sidebar(),
        'filter_scope'      => $scopeIsExpanded ? '' : 'mine',
        'scope_can_expand'  => $scopeCanExpand,
        'scope_is_expanded' => $scopeIsExpanded,
        'scope_toggle_url'  => $scopeToggleUrl,
        'projects_report'   => $projects,
        'tasks_report'      => $tasks,
        'costs_report'      => $costs,
    ]
);

Html::footer();

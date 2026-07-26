<?php

/**
 * ProjectPlus — relatório de custos consolidados (item "Custos" da sidebar).
 *
 * Lista, por projeto raiz (ou um projeto escolhido no filtro), TODOS os
 * lançamentos: aba nativa "Custos" do projeto + custos por tarefa (plugin)
 * + os mesmos itens de todos os subprojetos, com totais e saldo do teto.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Budget;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\I18nJs;
use GlpiPlugin\Projectplus\Scope;
use GlpiPlugin\Projectplus\Url;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
/** @var \DBmysql $DB */
global $CFG_GLPI, $DB;

Session::checkRight('plugin_projectplus_costs', READ);

Html::header(
    __('Custos consolidados', 'projectplus'),
    '', // \Html::header ignora o 2o argumento no GLPI 11 (Bloco 4a)
    'tools',
    Dashboard::class
);

// Dicionario de traducao do JavaScript (Etapa 6, Bloco 3b): imprime o
// <script type="application/json" id="pp-i18n"> que public/js/i18n.js le.
// Tem que vir DEPOIS do Html::header (o echo cai dentro do body) e ANTES
// dos <script> das telas.
I18nJs::render();

$filterId = (int) ($_GET['project'] ?? 0);

// Escopo (Etapa 8, Bloco 4): mesma regra das demais telas — padrão é o
// MAIOR escopo do perfil e ?scope=mine reduz ao pessoal. Com escopo ativo
// a lista é PLANA (só os projetos que o usuário vê, sem descer para
// subprojetos de terceiros — mesma decisão do Bloco 3).
$scopeIds        = Scope::projectIds();   // null = sem restrição
$scopeFlat       = ($scopeIds !== null);
$scopeCanExpand  = Scope::canExpand();
$scopeIsExpanded = Scope::isExpanded();
$scopeQuery      = [];
if ($filterId > 0) {
    $scopeQuery['project'] = $filterId;
}
if ($scopeIsExpanded) {
    $scopeQuery['scope'] = 'mine';
}
$scopeToggleUrl = Url::to('front/costs.php')
    . ($scopeQuery === [] ? '' : ('?' . http_build_query($scopeQuery)));

// Projetos para o seletor do filtro: raízes (sem escopo) ou exatamente os
// projetos do escopo (que podem ser subprojetos).
$rootsWhere = [
    'is_deleted'  => 0,
    'is_template' => 0,
] + getEntitiesRestrictCriteria('glpi_projects');
if ($scopeFlat) {
    $rootsWhere['id'] = Scope::inList($scopeIds);
} else {
    $rootsWhere['projects_id'] = 0;
}

$allRoots = [];
foreach (
    $DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => 'glpi_projects',
        'WHERE'  => $rootsWhere,
        'ORDER'  => 'name',
    ]) as $row
) {
    $allRoots[] = ['id' => (int) $row['id'], 'name' => $row['name']];
}

// Projetos do relatório: todos os do escopo, ou apenas o filtrado
// (o filtro NUNCA amplia o escopo — interseção dos dois).
$where = [
    'is_deleted'  => 0,
    'is_template' => 0,
] + getEntitiesRestrictCriteria('glpi_projects');
if ($filterId > 0) {
    $where['id'] = ($scopeFlat && !in_array($filterId, array_map('intval', $scopeIds), true))
        ? [0]
        : [$filterId];
} elseif ($scopeFlat) {
    $where['id'] = Scope::inList($scopeIds);
} else {
    $where['projects_id'] = 0;
}

$report = [];
foreach (
    $DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => 'glpi_projects',
        'WHERE'  => $where,
        'ORDER'  => 'name',
    ]) as $root
) {
    $rootId = (int) $root['id'];

    // Lançamentos do projeto + de todos os descendentes
    $entries = Budget::getEntriesForProject($rootId, __('Projeto', 'projectplus'));
    $visited = [];
    foreach (($scopeFlat ? [] : Budget::getDescendantIds($rootId, $visited)) as $childId) {
        $childRow = $DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_projects',
            'WHERE'  => ['id' => $childId],
        ])->current();
        $entries = array_merge($entries, Budget::getEntriesForProject(
            $childId,
            sprintf(__('Subprojeto "%s"', 'projectplus'), $childRow['name'] ?? '—')
        ));
    }

    // Mais recentes primeiro (sem data por último)
    usort($entries, function (array $a, array $b): int {
        return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
    });

    $total = 0.0;
    foreach ($entries as &$e) {
        $total += $e['cost'];
        $e['cost_fmt'] = number_format($e['cost'], 2, ',', '.');
        $e['date_fmt'] = !empty($e['date']) ? date('d/m/Y', strtotime($e['date'])) : '—';
    }
    unset($e);

    $budget  = Budget::getForProject($rootId);
    $planned = (float) $budget['planned'];

    $report[] = [
        'id'          => $rootId,
        'name'        => $root['name'],
        'entries'     => $entries,
        'total_fmt'   => number_format($total, 2, ',', '.'),
        'has_ceiling' => $planned > 0,
        'planned_fmt' => number_format($planned, 2, ',', '.'),
        'balance_fmt' => $budget['balance'] !== null
            ? number_format((float) $budget['balance'], 2, ',', '.') : null,
        'percent'     => $budget['percent'],
        'state'       => ($budget['percent'] ?? 0) > 100 ? 'over'
            : ((($budget['percent'] ?? 0) >= 80) ? 'warn' : 'ok'),
    ];
}

TemplateRenderer::getInstance()->display(
    '@projectplus/costs.html.twig',
    [
        'plugin_web_dir' => Url::base(),
        'glpi_root'      => $CFG_GLPI['root_doc'] ?? '',
        'report'         => $report,
        'projects_list'  => $allRoots,
        'filter_project' => $filterId,
        'generated_at'   => date('d/m/Y H:i'),
        'can_templates'  => Session::haveRight('config', UPDATE),
        'nav'             => Access::sidebar(),
        'filter_scope'      => $scopeIsExpanded ? '' : 'mine',
        'scope_can_expand'  => $scopeCanExpand,
        'scope_is_expanded' => $scopeIsExpanded,
        'scope_toggle_url'  => $scopeToggleUrl,
    ]
);

Html::footer();

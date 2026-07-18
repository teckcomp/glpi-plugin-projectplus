<?php

/**
 * ProjectPlus — relatório de custos consolidados (item "Custos" da sidebar).
 *
 * Lista, por projeto raiz (ou um projeto escolhido no filtro), TODOS os
 * lançamentos: aba nativa "Custos" do projeto + custos por tarefa (plugin)
 * + os mesmos itens de todos os subprojetos, com totais e saldo do teto.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Budget;
use GlpiPlugin\Projectplus\Dashboard;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
/** @var \DBmysql $DB */
global $CFG_GLPI, $DB;

Session::checkRight('plugin_projectplus_dashboard', READ);

Html::header(
    __('Custos consolidados', 'projectplus'),
    $_SERVER['PHP_SELF'],
    'tools',
    Dashboard::class
);

$filterId = (int) ($_GET['project'] ?? 0);

// Projetos raiz para o seletor do filtro
$allRoots = [];
foreach (
    $DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => 'glpi_projects',
        'WHERE'  => [
            'projects_id' => 0,
            'is_deleted'  => 0,
            'is_template' => 0,
        ] + getEntitiesRestrictCriteria('glpi_projects'),
        'ORDER'  => 'name',
    ]) as $row
) {
    $allRoots[] = ['id' => (int) $row['id'], 'name' => $row['name']];
}

// Projetos do relatório: todos os raiz, ou apenas o filtrado
$where = [
    'is_deleted'  => 0,
    'is_template' => 0,
] + getEntitiesRestrictCriteria('glpi_projects');
if ($filterId > 0) {
    $where['id'] = $filterId;
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
    foreach (Budget::getDescendantIds($rootId, $visited) as $childId) {
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
        'plugin_web_dir' => Plugin::getWebDir('projectplus'),
        'glpi_root'      => $CFG_GLPI['root_doc'] ?? '',
        'report'         => $report,
        'projects_list'  => $allRoots,
        'filter_project' => $filterId,
        'generated_at'   => date('d/m/Y H:i'),
    ]
);

Html::footer();

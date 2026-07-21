<?php

/**
 * ProjectPlus — exportação CSV dos Relatórios (Etapa 5, Bloco 1 + 1.1).
 *
 * GET reports_export.php?type=projects|tasks|costs&project=<id>
 *     &task=<busca>&user=<id>&state=<id>&typefilter=p:<id>|t:<id>
 * (os 4 últimos só valem para type=projects/tasks — Custos ignora, mesmo
 * comportamento de front/reports.php). Sem HTML em volta: só cabeçalhos +
 * fluxo CSV (delimitador ';' e BOM UTF-8, para abrir certo no Excel PT-BR).
 * O CSV baixado reflete os MESMOS filtros aplicados na prévia em tela.
 */

use GlpiPlugin\Projectplus\Reports;

include('../../../inc/includes.php');

Session::checkRight('plugin_projectplus_reports', READ);

$type     = (string) ($_GET['type'] ?? '');
$filterId = (int) ($_GET['project'] ?? 0);

$typeRaw     = (string) ($_GET['typefilter'] ?? '');
$projectType = 0;
$taskType    = 0;
if (preg_match('/^p:(\d+)$/', $typeRaw, $m)) {
    $projectType = (int) $m[1];
} elseif (preg_match('/^t:(\d+)$/', $typeRaw, $m)) {
    $taskType = (int) $m[1];
}

$filters = [
    'user'         => (int) ($_GET['user'] ?? 0),
    'state'        => (int) ($_GET['state'] ?? 0),
    'project_type' => $projectType,
    'task_type'    => $taskType,
    'task_search'  => trim((string) ($_GET['task'] ?? '')),
    'from'         => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : null,
    'until'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['until'] ?? '') ? $_GET['until'] : null,
];

$data = Reports::dataFor($type, $filterId, $filters);
if ($data === null) {
    Html::displayErrorAndDie(__('Tipo de relatório inválido', 'projectplus'));
    exit;
}

$labels = [
    'projects' => 'projetos',
    'tasks'    => 'tarefas',
    'costs'    => 'custos',
];
$filename = 'projectplus_' . ($labels[$type] ?? $type) . '_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// BOM para o Excel reconhecer UTF-8 automaticamente
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $data['header'], ';');
foreach ($data['rows'] as $row) {
    fputcsv($out, $row, ';');
}
fclose($out);
exit;

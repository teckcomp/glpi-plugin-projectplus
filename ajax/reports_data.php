<?php

/**
 * ProjectPlus — endpoint JSON da tela "Relatórios" (Etapa 5, Bloco 2).
 *
 * GET ?action=burndown&project=NN -> dados brutos do burndown de um
 * projeto (total de tarefas do escopo + lista de conclusões, uma data por
 * tarefa concluída). A agregação por semana/dia acontece no cliente (JS);
 * ver GlpiPlugin\Projectplus\Reports::burndownData().
 */

use GlpiPlugin\Projectplus\Reports;

include('../../../inc/includes.php');

Session::checkRight('plugin_projectplus_dashboard', READ);

header('Content-Type: application/json; charset=UTF-8');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'burndown':
        $projectId = (int) ($_GET['project'] ?? 0);
        echo json_encode(Reports::burndownData($projectId));
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown action']);
        break;
}

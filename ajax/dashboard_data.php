<?php

/**
 * ProjectPlus — endpoint JSON do painel.
 *
 * GET ?action=data              -> KPIs + gráfico + projetos pai
 * GET ?action=children&id=NN    -> subprojetos de um pai (requisito 2)
 */

use GlpiPlugin\Projectplus\Dashboard;

include('../../../inc/includes.php');

Session::checkRight('plugin_projectplus_dashboard', READ);

header('Content-Type: application/json; charset=UTF-8');

$action = $_GET['action'] ?? 'data';

switch ($action) {
    case 'children':
        $parentId = (int) ($_GET['id'] ?? 0);
        echo json_encode(Dashboard::getChildren($parentId));
        break;

    case 'tasks':
        $projectId = (int) ($_GET['id'] ?? 0);
        echo json_encode(Dashboard::getTasks($projectId));
        break;

    case 'data':
    default:
        echo json_encode(Dashboard::getData());
        break;
}

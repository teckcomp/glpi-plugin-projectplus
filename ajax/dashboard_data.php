<?php

/**
 * ProjectPlus — endpoint JSON do painel.
 *
 * GET ?action=data              -> KPIs + gráfico + projetos pai
 * GET ?action=children&id=NN    -> subprojetos de um pai (requisito 2)
 * GET ?action=mytasks[&done=1]  -> tarefas do usuário logado (Etapa 3, Bloco 1)
 * GET ?action=taskcomments&id=NN -> comentários de uma tarefa (Etapa 3, Bloco 2)
 */

use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\TaskComment;

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

    case 'taskchildren':
        $taskId = (int) ($_GET['id'] ?? 0);
        echo json_encode(Dashboard::getOpenTaskChildren($taskId));
        break;

    case 'taskcomments':
        $taskId = (int) ($_GET['id'] ?? 0);
        echo json_encode(TaskComment::getForTask($taskId));
        break;

    case 'mytasks':
        echo json_encode(Dashboard::getMyTasks(
            (int) Session::getLoginUserID(),
            !empty($_GET['done'])
        ));
        break;

    case 'data':
    default:
        echo json_encode(Dashboard::getData());
        break;
}

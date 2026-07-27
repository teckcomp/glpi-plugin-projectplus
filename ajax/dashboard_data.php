<?php

/**
 * ProjectPlus — endpoint JSON do painel.
 *
 * GET ?action=data              -> KPIs + gráfico + projetos pai
 * GET ?action=children&id=NN    -> subprojetos de um pai (requisito 2)
 * GET ?action=mytasks[&done=1]  -> tarefas do usuário logado (Etapa 3, Bloco 1)
 * GET ?action=taskcomments&id=NN -> comentários de uma tarefa (Etapa 3, Bloco 2)
 * GET ?action=taskdeps&id=NN    -> dependências de uma tarefa (Etapa 3, Bloco 3)
 */

use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\TaskComment;
use GlpiPlugin\Projectplus\TaskDep;

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

    case 'taskdeps':
        $taskId = (int) ($_GET['id'] ?? 0);
        echo json_encode(TaskDep::getPanelData($taskId));
        break;

    case 'mytasks':
        echo json_encode(Dashboard::getMyTasks(
            (int) Session::getLoginUserID(),
            !empty($_GET['done'])
        ));
        break;

    case 'data':
        // REMOVIDO em 26/07/2026 após teste em homologação.
        //
        // Esta ação chamava `Dashboard::getData()` SEM NENHUM argumento de
        // escopo, enquanto `front/dashboard.php` chama a mesma função
        // passando Scope::projectIds(), Scope::myTaskIds() e
        // Scope::taskProjectIds(). Resultado comprovado com um perfil
        // Technician (escopo pessoal): a tela mostrava 1 projeto e 4
        // tarefas; este endpoint devolvia 3 projetos e 12 tarefas, com
        // orçamento e nomes de responsáveis de projetos que o perfil não
        // enxerga.
        //
        // Nenhum JavaScript do plugin chamava esta ação — era código morto
        // que vazava. E, por ser também o `default`, QUALQUER `?action=`
        // desconhecido caía aqui, então bastava errar o nome da ação.
        //
        // Não vale "consertar" passando o escopo: a tela já faz isso, e um
        // endpoint que só duplica a tela é superfície de ataque sem uso.
    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown action']);
        break;
}

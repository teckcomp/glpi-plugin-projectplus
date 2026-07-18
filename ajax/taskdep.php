<?php

/**
 * ProjectPlus — dependências entre tarefas (Etapa 3, Bloco 3).
 *
 * POST action=add     task_id, other_id, dir ('blocked_by' | 'blocks')
 *                     dir=blocked_by → other BLOQUEIA task
 *                     dir=blocks     → task BLOQUEIA other
 * POST action=delete  link_id
 *
 * A LISTAGEM é servida por ajax/dashboard_data.php?action=taskdeps&id=NN
 * (GET, sem CSRF), no padrão das demais leituras do painel.
 *
 * O CSRF é validado automaticamente pelo core (includes.php) em todo POST.
 * Cada resposta devolve um token novo em 'csrf' — o JS deve usá-lo na
 * próxima chamada (tokens são de uso único).
 */

use GlpiPlugin\Projectplus\TaskDep;

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json; charset=UTF-8');

/**
 * Resposta padrão com token novo.
 */
function pp_reply(array $payload): void
{
    $payload['csrf'] = Session::getNewCSRFToken();
    echo json_encode($payload);
    exit;
}

if (!TaskDep::canManage()) {
    pp_reply(['ok' => false, 'message' => __('Sem permissão para gerenciar dependências', 'projectplus')]);
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add':
        $taskId  = (int) ($_POST['task_id'] ?? 0);
        $otherId = (int) ($_POST['other_id'] ?? 0);
        $dir     = (string) ($_POST['dir'] ?? 'blocked_by');

        $result = ($dir === 'blocks')
            ? TaskDep::addLink($taskId, $otherId)   // task bloqueia other
            : TaskDep::addLink($otherId, $taskId);  // other bloqueia task
        pp_reply($result);
        break;

    case 'delete':
        pp_reply(TaskDep::deleteLink((int) ($_POST['link_id'] ?? 0)));
        break;

    default:
        http_response_code(400);
        pp_reply(['ok' => false, 'message' => 'ação inválida']);
}

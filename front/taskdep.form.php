<?php

/**
 * ProjectPlus — dependências pela aba nativa da tarefa (Etapa 3, Bloco 3).
 *
 * POST add=1     dir ('blocked_by'|'blocks'), other_id, projecttasks_id
 * POST delete=1  link_id, projecttasks_id
 *
 * CSRF: validado automaticamente pelo core em todo POST (Html::closeForm
 * inclui o token nos formulários da aba).
 */

use GlpiPlugin\Projectplus\TaskDep;

include('../../../inc/includes.php');

Session::checkLoginUser();

if (!TaskDep::canManage()) {
    Session::addMessageAfterRedirect(
        __('Sem permissão para gerenciar dependências', 'projectplus'),
        false,
        ERROR
    );
    Html::back();
}

$taskId = (int) ($_POST['projecttasks_id'] ?? 0);
$task   = new ProjectTask();
if ($taskId <= 0 || !$task->getFromDB($taskId)) {
    Session::addMessageAfterRedirect(__('Tarefa não encontrada', 'projectplus'), false, ERROR);
    Html::back();
}

if (isset($_POST['add'])) {
    $otherId = (int) ($_POST['other_id'] ?? 0);
    $dir     = (string) ($_POST['dir'] ?? 'blocked_by');

    $result = ($dir === 'blocks')
        ? TaskDep::addLink($taskId, $otherId)   // esta tarefa bloqueia a outra
        : TaskDep::addLink($otherId, $taskId);  // a outra bloqueia esta tarefa

    Session::addMessageAfterRedirect($result['message'], false, $result['ok'] ? INFO : ERROR);
} elseif (isset($_POST['delete'])) {
    $result = TaskDep::deleteLink((int) ($_POST['link_id'] ?? 0));
    Session::addMessageAfterRedirect($result['message'], false, $result['ok'] ? INFO : ERROR);
}

Html::back();

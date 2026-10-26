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

$denied = static function (): void {
    Session::addMessageAfterRedirect(
        __('Sem permissão para gerenciar dependências', 'projectplus'),
        false,
        ERROR
    );
    Html::back();
};

if (isset($_POST['add'])) {
    $otherId = (int) ($_POST['other_id'] ?? 0);
    $dir     = (string) ($_POST['dir'] ?? 'blocked_by');

    // O-1b-2b: por vínculo — gestor/Super-Admin ou ator das DUAS tarefas
    if (!TaskDep::canManageLinkIds($taskId, $otherId)) {
        $denied();
    }

    $result = ($dir === 'blocks')
        ? TaskDep::addLink($taskId, $otherId)   // esta tarefa bloqueia a outra
        : TaskDep::addLink($otherId, $taskId);  // a outra bloqueia esta tarefa

    Session::addMessageAfterRedirect($result['message'], false, $result['ok'] ? INFO : ERROR);
} elseif (isset($_POST['delete'])) {
    $linkId = (int) ($_POST['link_id'] ?? 0);
    $ends   = TaskDep::linkTaskIds($linkId);
    if ($ends === null) {
        Session::addMessageAfterRedirect(__('Vínculo não encontrado', 'projectplus'), false, ERROR);
        Html::back();
    }
    if (!TaskDep::canManageLinkIds($ends[0], $ends[1])) {
        $denied();
    }
    $result = TaskDep::deleteLink($linkId);
    Session::addMessageAfterRedirect($result['message'], false, $result['ok'] ? INFO : ERROR);
}

Html::back();

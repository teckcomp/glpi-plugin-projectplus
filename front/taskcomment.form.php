<?php

/**
 * ProjectPlus — comentários pela aba nativa da tarefa (Etapa 3, Bloco 2).
 *
 * POST add=1     content, projecttasks_id
 * POST delete=1  id, projecttasks_id
 *
 * CSRF: validado automaticamente pelo core em todo POST (Html::closeForm
 * inclui o token nos formulários da aba).
 */

use GlpiPlugin\Projectplus\TaskComment;

include('../../../inc/includes.php');

Session::checkLoginUser();

/** @var \DBmysql $DB */
global $DB;

if (!TaskComment::canComment()) {
    Session::addMessageAfterRedirect(
        __('Sem permissão para comentar', 'projectplus'),
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
    $content = trim((string) ($_POST['content'] ?? ''));
    if ($content === '' || mb_strlen($content) > 4000) {
        Session::addMessageAfterRedirect(
            __('Comentário vazio ou longo demais', 'projectplus'),
            false,
            ERROR
        );
        Html::back();
    }

    if (TaskComment::addForTask($task, $content) > 0) {
        Session::addMessageAfterRedirect(__('Comentário adicionado', 'projectplus'), false, INFO);
    } else {
        Session::addMessageAfterRedirect(__('Falha ao salvar o comentário', 'projectplus'), false, ERROR);
    }
} elseif (isset($_POST['delete'])) {
    $comment = new TaskComment();
    if (
        $comment->getFromDB((int) ($_POST['id'] ?? 0))
        && (int) $comment->fields['projecttasks_id'] === $taskId // trava: só desta tarefa
        && TaskComment::canManage((int) $comment->fields['users_id'])
    ) {
        $DB->delete(TaskComment::getTable(), ['id' => (int) $comment->getID()]);
        Session::addMessageAfterRedirect(__('Comentário excluído', 'projectplus'), false, INFO);
    } else {
        Session::addMessageAfterRedirect(
            __('Só o autor pode excluir este comentário', 'projectplus'),
            false,
            ERROR
        );
    }
}

Html::back();

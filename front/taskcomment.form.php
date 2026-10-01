<?php

/**
 * ProjectPlus — comentários pela aba nativa da tarefa (Etapa 3, Bloco 2).
 *
 * POST add=1     content, projecttasks_id
 * POST delete=1  id, projecttasks_id
 *
 * Bloco D-3b: aba do PROJETO manda projects_id no lugar de projecttasks_id
 * (só para quem edita projeto; alerta só ao gestor).
 *
 * CSRF: validado automaticamente pelo core em todo POST (Html::closeForm
 * inclui o token nos formulários da aba).
 */

use GlpiPlugin\Projectplus\CommentFile;
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

$projectId = (int) ($_POST['projects_id'] ?? 0);
$project   = null;
$task      = null;
$taskId    = 0;
if ($projectId > 0) {
    $project = new Project();
    if (!$project->getFromDB($projectId)) {
        Session::addMessageAfterRedirect(__('Projeto não encontrado', 'projectplus'), false, ERROR);
        Html::back();
    }
    if (!TaskComment::canCommentProject() || !$project->canViewItem()) {
        Session::addMessageAfterRedirect(__('Sem permissão para comentar', 'projectplus'), false, ERROR);
        Html::back();
    }
} else {
    $taskId = (int) ($_POST['projecttasks_id'] ?? 0);
    $task   = new ProjectTask();
    if ($taskId <= 0 || !$task->getFromDB($taskId)) {
        Session::addMessageAfterRedirect(__('Tarefa não encontrada', 'projectplus'), false, ERROR);
        Html::back();
    }
}

if (isset($_POST['add'])) {
    $content = trim((string) ($_POST['content'] ?? ''));
    $files   = CommentFile::normalizeUploads($_FILES['files'] ?? null);
    // Rodada 3, Bloco 4: comentário só de anexo é válido; vazio de tudo, não.
    if (($content === '' && $files === []) || mb_strlen($content) > 4000) {
        Session::addMessageAfterRedirect(
            __('Comentário vazio ou longo demais', 'projectplus'),
            false,
            ERROR
        );
        Html::back();
    }

    $id = $project !== null
        ? TaskComment::addForProject($project, $content)
        : TaskComment::addForTask($task, $content);
    if ($id > 0) {
        $upload = ['saved' => 0, 'errors' => []];
        if ($files !== []) {
            $upload = $project !== null
                ? CommentFile::saveUploads($id, 0, $files, null, $projectId)
                : CommentFile::saveUploads($id, $taskId, $files);
        }
        foreach ($upload['errors'] as $err) {
            Session::addMessageAfterRedirect($err, false, ERROR);
        }
        if ($content === '' && $upload['saved'] === 0) {
            // Sem texto e nenhum anexo aceito: o comentário ficaria vazio — desfaz.
            $DB->delete(TaskComment::getTable(), ['id' => $id]);
        } else {
            Session::addMessageAfterRedirect(__('Comentário adicionado', 'projectplus'), false, INFO);
        }
    } else {
        Session::addMessageAfterRedirect(__('Falha ao salvar o comentário', 'projectplus'), false, ERROR);
    }
} elseif (isset($_POST['delete'])) {
    $comment = new TaskComment();
    if (
        $comment->getFromDB((int) ($_POST['id'] ?? 0))
        && (int) $comment->fields['projecttasks_id'] === $taskId // trava: só desta tarefa
        && ($project === null || (int) ($comment->fields['projects_id'] ?? 0) === $projectId) // ...ou deste projeto
        && TaskComment::canManage((int) $comment->fields['users_id'])
    ) {
        CommentFile::deleteForComment((int) $comment->getID()); // cascata dos anexos
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

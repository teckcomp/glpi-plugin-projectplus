<?php

/**
 * ProjectPlus — comentários por tarefa (Etapa 3, Bloco 2).
 *
 * POST action=add     task_id, content
 * POST action=update  id, content        (só o autor / admin)
 * POST action=delete  id                 (só o autor / admin)
 *
 * A LISTAGEM é servida por ajax/dashboard_data.php?action=taskcomments&id=NN
 * (GET, sem CSRF), no padrão das demais leituras do painel.
 *
 * O CSRF é validado automaticamente pelo core (includes.php) em todo POST.
 * Cada resposta devolve um token novo em 'csrf' — o JS deve usá-lo na
 * próxima chamada (tokens são de uso único).
 */

use GlpiPlugin\Projectplus\CommentFile;
use GlpiPlugin\Projectplus\TaskComment;

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

/** @var \DBmysql $DB */
global $DB;

if (!TaskComment::canComment()) {
    pp_reply(['ok' => false, 'message' => __('Sem permissão para comentar', 'projectplus')]);
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add':
        $content = trim((string) ($_POST['content'] ?? ''));
        $files   = CommentFile::normalizeUploads($_FILES['files'] ?? null);
        // Rodada 3, Bloco 4: comentário só de anexo é válido; vazio de tudo, não.
        if (($content === '' && $files === []) || mb_strlen($content) > 4000) {
            pp_reply(['ok' => false, 'message' => __('Comentário vazio ou longo demais', 'projectplus')]);
        }

        $task = new ProjectTask();
        if (!$task->getFromDB((int) ($_POST['task_id'] ?? 0))) {
            pp_reply(['ok' => false, 'message' => __('Tarefa não encontrada', 'projectplus')]);
        }

        $id = TaskComment::addForTask($task, $content);
        if ($id <= 0) {
            pp_reply(['ok' => false, 'message' => __('Falha ao salvar o comentário', 'projectplus')]);
        }

        $upload = ['saved' => 0, 'errors' => []];
        if ($files !== []) {
            $upload = CommentFile::saveUploads($id, (int) $task->getID(), $files);
        }

        // Sem texto e nenhum anexo aceito: o comentário ficaria vazio — desfaz.
        if ($content === '' && $upload['saved'] === 0) {
            $DB->delete(TaskComment::getTable(), ['id' => $id]);
            pp_reply([
                'ok'      => false,
                'message' => implode("\n", $upload['errors'])
                    ?: __('Comentário vazio ou longo demais', 'projectplus'),
            ]);
        }

        pp_reply([
            'ok'           => true,
            'id'           => $id,
            'count'        => TaskComment::countForTask((int) $task->getID()),
            'files_saved'  => $upload['saved'],
            'files_errors' => $upload['errors'],
        ]);
        break;

    case 'update':
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($content === '' || mb_strlen($content) > 4000) {
            pp_reply(['ok' => false, 'message' => __('Comentário vazio ou longo demais', 'projectplus')]);
        }

        $comment = new TaskComment();
        if (!$comment->getFromDB((int) ($_POST['id'] ?? 0))) {
            pp_reply(['ok' => false, 'message' => __('Comentário não encontrado', 'projectplus')]);
        }
        if (!TaskComment::canManage((int) $comment->fields['users_id'])) {
            pp_reply(['ok' => false, 'message' => __('Só o autor pode editar este comentário', 'projectplus')]);
        }

        $DB->update(TaskComment::getTable(), [
            'content'  => $content,
            'date_mod' => date('Y-m-d H:i:s'),
        ], ['id' => (int) $comment->getID()]);

        pp_reply(['ok' => true]);
        break;

    case 'delete':
        $comment = new TaskComment();
        if (!$comment->getFromDB((int) ($_POST['id'] ?? 0))) {
            pp_reply(['ok' => false, 'message' => __('Comentário não encontrado', 'projectplus')]);
        }
        if (!TaskComment::canManage((int) $comment->fields['users_id'])) {
            pp_reply(['ok' => false, 'message' => __('Só o autor pode excluir este comentário', 'projectplus')]);
        }

        $taskId = (int) $comment->fields['projecttasks_id'];
        CommentFile::deleteForComment((int) $comment->getID()); // cascata dos anexos
        $DB->delete(TaskComment::getTable(), ['id' => (int) $comment->getID()]);

        pp_reply([
            'ok'    => true,
            'count' => TaskComment::countForTask($taskId),
        ]);
        break;

    default:
        http_response_code(400);
        pp_reply(['ok' => false, 'message' => 'ação inválida']);
}

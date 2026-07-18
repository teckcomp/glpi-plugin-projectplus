<?php

/**
 * ProjectPlus — endpoint dos alertas internos / sino (requisito 5).
 *
 * GET  action=list              -> {unread: [...], read: [...]} (sino c/ histórico)
 * GET  action=unread            -> apenas alertas não lidos
 * POST action=read&id=NN        -> marca um alerta como lido
 * POST action=read_all          -> marca todos como lidos
 *
 * CSRF: validado automaticamente pelo core em POST; cada resposta de POST
 * devolve um token novo em 'csrf' (tokens são de uso único — o JS rotaciona).
 */

use GlpiPlugin\Projectplus\Notification;

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json; charset=UTF-8');

function pp_reply(array $payload): void
{
    $payload['csrf'] = Session::getNewCSRFToken();
    echo json_encode($payload);
    exit;
}

$userId = (int) Session::getLoginUserID();
$action = $_POST['action'] ?? ($_GET['action'] ?? 'unread');

switch ($action) {
    case 'read':
        pp_reply([
            'ok' => Notification::markRead((int) ($_POST['id'] ?? 0), $userId),
        ]);
        break;

    case 'read_all':
        pp_reply([
            'ok' => Notification::markAllRead($userId),
        ]);
        break;

    case 'list':
        echo json_encode(Notification::getForBell($userId));
        break;

    case 'unread':
    default:
        echo json_encode(Notification::getUnread($userId));
        break;
}

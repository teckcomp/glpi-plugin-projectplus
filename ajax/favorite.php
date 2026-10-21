<?php

/**
 * ProjectPlus — Bloco L (21/10/2026): grava/remove o filtro favorito de uma
 * tela para o usuário logado. POST: action=save|clear, screen, qs (query
 * string da tela), ui (JSON dos filtros que só existem no navegador).
 *
 * O CSRF é validado automaticamente pelo core (includes.php) em todo POST;
 * cada resposta devolve um token novo em 'csrf' (uso único).
 */

use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Favorite;

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json; charset=UTF-8');

function pp_reply(array $payload): void
{
    $payload['csrf'] = Session::getNewCSRFToken();
    echo json_encode($payload);
    exit;
}

if (!Access::canEnter()) {
    pp_reply(['ok' => false, 'message' => __('Sem permissão', 'projectplus')]);
}

$screen = (string) ($_POST['screen'] ?? '');
if (!isset(Favorite::SCREENS[$screen]) || !Favorite::available()) {
    pp_reply(['ok' => false, 'message' => __('Sem permissão', 'projectplus')]);
}

$action = (string) ($_POST['action'] ?? '');
if ($action === 'clear') {
    Favorite::clear($screen);
    pp_reply(['ok' => true, 'saved' => null]);
}
if ($action === 'save') {
    $qs = [];
    parse_str(ltrim((string) ($_POST['qs'] ?? ''), '?'), $qs);
    $ui = json_decode((string) ($_POST['ui'] ?? '{}'), true);
    $saved = Favorite::save($screen, is_array($qs) ? $qs : [], is_array($ui) ? $ui : []);
    pp_reply(['ok' => true, 'saved' => $saved]);
}
pp_reply(['ok' => false]);

<?php

/**
 * ProjectPlus — download de anexo de comentário (Rodada 3, Bloco 4).
 *
 * GET ?id=NN — envia o arquivo com o mime gravado na validação do upload.
 * Imagens e PDF abrem no navegador (inline); Office baixa (attachment).
 *
 * Porta de acesso: login + direito do painel (a mesma dos comentários).
 * O guard fino por tarefa (Scope::canSeeTask) é a Etapa 10 — quando ela
 * entrar, este endpoint recebe a checagem como os demais.
 */

use GlpiPlugin\Projectplus\CommentFile;
use GlpiPlugin\Projectplus\TaskComment;

include('../../../inc/includes.php');

Session::checkLoginUser();

if (!TaskComment::canComment()) {
    http_response_code(403);
    exit;
}

/** @var \DBmysql $DB */
global $DB;

$id  = (int) ($_GET['id'] ?? 0);
$row = null;
foreach (
    $DB->request([
        'FROM'  => CommentFile::TABLE,
        'WHERE' => ['id' => $id],
        'LIMIT' => 1,
    ]) as $r
) {
    $row = $r;
}

if ($row === null) {
    http_response_code(404);
    exit;
}

$path = CommentFile::baseDir() . '/' . basename((string) $row['stored']);
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mime   = (string) $row['mime'];
$inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';

// filename* (RFC 5987) preserva acentos; filename= é o fallback ASCII.
$ascii = preg_replace('/[^\x20-\x7E]/', '_', (string) $row['filename']);
$ascii = str_replace(['"', '\\'], '_', $ascii);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header(
    'Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $ascii . '"'
    . "; filename*=UTF-8''" . rawurlencode((string) $row['filename'])
);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');

readfile($path);
exit;

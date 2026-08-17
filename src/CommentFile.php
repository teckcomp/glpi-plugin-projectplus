<?php

namespace GlpiPlugin\Projectplus;

use Session;

/**
 * Anexos de comentários (Rodada 3, Bloco 4).
 *
 * - Arquivos ficam FORA da webroot, em GLPI_PLUGIN_DOC_DIR/projectplus/comments
 *   (files/_plugins), com nome aleatório no disco — o nome original vive só no
 *   banco (glpi_plugin_projectplus_commentfiles) e o download passa sempre por
 *   front/commentfile.php, que confere login e permissão;
 * - Formatos aceitos (decisão de 17/08/2026): imagens, PDF, DOC/DOCX, XLS/XLSX;
 * - Limite de 10 MB por arquivo;
 * - A validação confere a EXTENSÃO e o mime REAL (finfo) — a extensão sozinha
 *   é só o nome que o cliente deu ao arquivo.
 */
class CommentFile
{
    public const TABLE = 'glpi_plugin_projectplus_commentfiles';

    public const MAX_SIZE = 10485760; // 10 MB

    /**
     * extensão => mimes reais aceitos (finfo). Os formatos Office antigos
     * (doc/xls) são contêiner OLE e o finfo devolve variantes; os novos
     * (docx/xlsx) são zip por baixo.
     */
    public const ALLOWED = [
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    private const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** Pasta física dos anexos (criada sob demanda). */
    public static function baseDir(): string
    {
        $root = defined('GLPI_PLUGIN_DOC_DIR')
            ? GLPI_PLUGIN_DOC_DIR
            : (GLPI_VAR_DIR . '/_plugins');
        $dir = $root . '/projectplus/comments';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Normaliza $_FILES['files'] (formato name[]) numa lista de arquivos.
     * Entradas UPLOAD_ERR_NO_FILE são descartadas em silêncio.
     *
     * @return array<int,array{name:string,tmp_name:string,size:int,error:int}>
     */
    public static function normalizeUploads($files): array
    {
        if (!is_array($files) || !isset($files['name'])) {
            return [];
        }
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $out   = [];
        foreach ($names as $i => $name) {
            $error = is_array($files['name'])
                ? (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE)
                : (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => (string) $name,
                'tmp_name' => is_array($files['name'])
                    ? (string) ($files['tmp_name'][$i] ?? '')
                    : (string) ($files['tmp_name'] ?? ''),
                'size'     => is_array($files['name'])
                    ? (int) ($files['size'][$i] ?? 0)
                    : (int) ($files['size'] ?? 0),
                'error'    => $error,
            ];
        }
        return $out;
    }

    /**
     * Valida UM arquivo. Devolve null se ok, ou a mensagem de erro.
     * O mime real só é conferido se o arquivo existir no disco (no teste
     * de unidade o tmp é um arquivo comum; em produção é o tmp do upload).
     */
    public static function checkFile(array $f): ?string
    {
        if ($f['error'] !== UPLOAD_ERR_OK) {
            return sprintf(__('Falha no envio de "%s"', 'projectplus'), $f['name']);
        }
        if ($f['size'] <= 0 || $f['size'] > self::MAX_SIZE) {
            return sprintf(__('"%s" excede o limite de 10 MB', 'projectplus'), $f['name']);
        }

        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext])) {
            return sprintf(
                __('Tipo de arquivo não permitido: "%s" (aceitos: imagens, PDF, DOC/DOCX, XLS/XLSX)', 'projectplus'),
                $f['name']
            );
        }

        if ($f['tmp_name'] !== '' && is_file($f['tmp_name']) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? (string) finfo_file($finfo, $f['tmp_name']) : '';
            if ($finfo) {
                finfo_close($finfo);
            }
            if ($mime !== '' && !in_array($mime, self::ALLOWED[$ext], true)) {
                return sprintf(
                    __('O conteúdo de "%s" não corresponde à extensão', 'projectplus'),
                    $f['name']
                );
            }
        }
        return null;
    }

    /**
     * Grava os uploads de um comentário.
     *
     * @param array $files   lista de normalizeUploads()
     * @param ?callable $mover  move o arquivo (padrão: move_uploaded_file);
     *                          parametrizável só para o teste de unidade.
     * @return array{saved:int,errors:string[]}
     */
    public static function saveUploads(int $commentId, int $taskId, array $files, ?callable $mover = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $mover ??= static fn (string $tmp, string $dest): bool => move_uploaded_file($tmp, $dest);

        $saved  = 0;
        $errors = [];
        foreach ($files as $f) {
            $err = self::checkFile($f);
            if ($err !== null) {
                $errors[] = $err;
                continue;
            }

            $ext    = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $stored = bin2hex(random_bytes(16)) . '.' . $ext;
            $dest   = self::baseDir() . '/' . $stored;

            if (!$mover($f['tmp_name'], $dest)) {
                $errors[] = sprintf(__('Falha ao gravar o arquivo "%s"', 'projectplus'), $f['name']);
                continue;
            }

            $DB->insert(self::TABLE, [
                'comments_id'     => $commentId,
                'projecttasks_id' => $taskId,
                'users_id'        => (int) Session::getLoginUserID(),
                'filename'        => mb_substr($f['name'], 0, 255),
                'stored'          => $stored,
                'mime'            => self::ALLOWED[$ext][0],
                'filesize'        => $f['size'],
                'date_creation'   => date('Y-m-d H:i:s'),
            ]);
            $saved++;
        }
        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * Anexos de vários comentários, em consulta única.
     *
     * @param int[] $commentIds
     * @return array<int,array<int,array>> [comments_id => [anexos]]
     */
    public static function forComments(array $commentIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (empty($commentIds) || !$DB->tableExists(self::TABLE)) {
            return [];
        }

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => ['comments_id' => $commentIds],
                'ORDER' => ['id ASC'],
            ]) as $row
        ) {
            $ext = strtolower(pathinfo((string) $row['filename'], PATHINFO_EXTENSION));
            $out[(int) $row['comments_id']][] = [
                'id'       => (int) $row['id'],
                'name'     => (string) $row['filename'],
                'size_h'   => self::humanSize((int) $row['filesize']),
                'is_image' => in_array($ext, self::IMAGE_EXT, true),
                'url'      => Url::to('front/commentfile.php') . '?id=' . (int) $row['id'],
            ];
        }
        return $out;
    }

    /** Anexos de UM comentário (para a aba nativa). */
    public static function forComment(int $commentId): array
    {
        return self::forComments([$commentId])[$commentId] ?? [];
    }

    /** Remove anexos (disco + banco) de um comentário — cascata do delete. */
    public static function deleteForComment(int $commentId): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }
        foreach (
            $DB->request([
                'SELECT' => ['stored'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['comments_id' => $commentId],
            ]) as $row
        ) {
            $path = self::baseDir() . '/' . basename((string) $row['stored']);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $DB->delete(self::TABLE, ['comments_id' => $commentId]);
    }

    /**
     * Expurgo completo dos arquivos físicos — só na desinstalação com
     * purge_on_uninstall ligado (a tabela é derrubada pelo Install).
     */
    public static function purgeAllFiles(): void
    {
        $dir = self::baseDir();
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    /** "1,2 MB" / "340 KB" — mesmo separador decimal do resto do plugin. */
    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0, ',', '.') . ' KB';
        }
        return $bytes . ' B';
    }
}

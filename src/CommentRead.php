<?php

namespace GlpiPlugin\Projectplus;

use Config as CoreConfig;

/**
 * Comentários não lidos por usuário (Ciclo O, O-4, 28/10/2026).
 *
 * Regras (decididas com o Claudio):
 *   - "lido" = abrir os comentários da tarefa ou do projeto — o modal do
 *     Kanban, o 💬 do painel ou a aba nativa (todos passam por markRead);
 *   - a leitura guarda o id do ÚLTIMO comentário visto: o id é monotônico,
 *     sem a janela de 1 segundo que a régua por hora teve no Task+ (16d);
 *   - comentário do próprio usuário nunca conta como novo;
 *   - marco da instalação (`unread_base_id`): o que já existia quando o O-4
 *     subiu conta como lido — nada de enxurrada no primeiro acesso.
 *
 * Tarefa e projeto seguem a convenção de `taskcomments`: tarefa =
 * `projecttasks_id` > 0 (e `projects_id` 0 aqui), projeto = `projects_id` > 0
 * com `projecttasks_id` 0.
 */
class CommentRead
{
    public const TABLE = 'glpi_plugin_projectplus_commentreads';

    private static ?bool $ready = null;

    /** Tabela criada? (sem ela, ninguém tem não lido e nada é gravado) */
    public static function tableReady(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::$ready === null) {
            self::$ready = $DB->tableExists(self::TABLE);
        }
        return self::$ready;
    }

    /** Harness: esquece os caches por requisição. */
    public static function resetCache(): void
    {
        self::$ready = null;
    }

    /**
     * Grava o marco UMA vez (marca `unread_seeded`). Reinstalar não move o
     * marco — senão comentários ainda não lidos virariam "lidos".
     */
    public static function seedBase(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $config = Config::get();
        if (!empty($config['unread_seeded'])) {
            return;
        }
        $max   = 0;
        $table = TaskComment::getTable();
        if ($DB->tableExists($table)) {
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $table]) as $row) {
                $max = max($max, (int) $row['id']); // lição 1: conta em PHP
            }
        }
        CoreConfig::setConfigurationValues(Config::CONTEXT, [
            'unread_base_id' => $max,
            'unread_seeded'  => 1,
        ]);
    }

    /** Comentários com id até este já nascem lidos. */
    public static function baseId(): int
    {
        $config = Config::get();
        return (int) ($config['unread_base_id'] ?? 0);
    }

    /**
     * Não lidos de várias tarefas ou projetos, para UM usuário.
     *
     * @param 'task'|'project' $kind
     * @param int[] $ids
     * @return array{count: array<int,int>, since: array<int,int>}
     *         count = owner => quantos novos; since = owner => maior id já
     *         lido (o que passar dele e não for do usuário é novo).
     */
    public static function unreadFor(string $kind, array $ids, int $userId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = ['count' => [], 'since' => []];
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === [] || $userId <= 0 || !self::tableReady()) {
            return $out;
        }
        $isProject = $kind === 'project';
        $key       = $isProject ? 'projects_id' : 'projecttasks_id';
        $table     = TaskComment::getTable();
        if (!$DB->tableExists($table) || ($isProject && !$DB->fieldExists($table, 'projects_id'))) {
            return $out;
        }

        $base = self::baseId();
        foreach ($ids as $id) {
            $out['count'][$id] = 0;
            $out['since'][$id] = $base;
        }

        $where = [self::TABLE . '.users_id' => $userId, self::TABLE . '.' . $key => $ids];
        $where[self::TABLE . '.' . ($isProject ? 'projecttasks_id' : 'projects_id')] = 0;
        foreach ($DB->request(['SELECT' => [$key, 'last_comment_id'], 'FROM' => self::TABLE, 'WHERE' => $where]) as $row) {
            $owner = (int) $row[$key];
            if (isset($out['since'][$owner])) {
                $out['since'][$owner] = max($base, (int) $row['last_comment_id']);
            }
        }

        $cWhere = [$table . '.' . $key => $ids, $table . '.id' => ['>', $base]];
        if ($isProject) {
            $cWhere[$table . '.projecttasks_id'] = 0;
        }
        foreach ($DB->request(['SELECT' => ['id', 'users_id', $key], 'FROM' => $table, 'WHERE' => $cWhere]) as $row) {
            $owner = (int) $row[$key];
            if (!isset($out['count'][$owner]) || (int) $row['users_id'] === $userId) {
                continue;
            }
            if ((int) $row['id'] > $out['since'][$owner]) {
                $out['count'][$owner]++;
            }
        }
        return $out;
    }

    /**
     * Registra que o usuário viu os comentários até $lastId. Nunca recua
     * (aba antiga aberta depois não "desmarca" o que já foi visto).
     *
     * @param 'task'|'project' $kind
     */
    public static function markRead(string $kind, int $itemId, int $userId, int $lastId): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($itemId <= 0 || $userId <= 0 || $lastId <= 0 || !self::tableReady()) {
            return;
        }
        $isProject = $kind === 'project';
        $where = [
            'users_id'        => $userId,
            'projecttasks_id' => $isProject ? 0 : $itemId,
            'projects_id'     => $isProject ? $itemId : 0,
        ];
        $current = null;
        foreach ($DB->request(['SELECT' => ['id', 'last_comment_id'], 'FROM' => self::TABLE, 'WHERE' => $where]) as $row) {
            $current = $row;
        }
        $now = date('Y-m-d H:i:s');
        if ($current === null) {
            try {
                $DB->insert(self::TABLE, $where + ['last_comment_id' => $lastId, 'date_read' => $now]);
            } catch (\Throwable $e) {
                // duas abas ao mesmo tempo: a outra inseriu (UNIQUE user_item)
                $DB->update(self::TABLE, ['last_comment_id' => $lastId, 'date_read' => $now], $where + ['last_comment_id' => ['<', $lastId]]);
            }
            return;
        }
        if ((int) $current['last_comment_id'] >= $lastId) {
            return;
        }
        $DB->update(self::TABLE, ['last_comment_id' => $lastId, 'date_read' => $now], ['id' => (int) $current['id']]);
    }

    /**
     * Atalho dos pontos de leitura: recebe a lista já carregada
     * (TaskComment::getForTask/getForProject, cada item com `id`) e marca
     * até o maior id. Lista vazia não grava nada.
     *
     * @param 'task'|'project' $kind
     */
    public static function markList(string $kind, int $itemId, array $comments, int $userId): void
    {
        $max = 0;
        foreach ($comments as $c) {
            $max = max($max, (int) ($c['id'] ?? 0));
        }
        self::markRead($kind, $itemId, $userId, $max);
    }

    /**
     * Prévia do cartão (TaskComment::previewFor): marca `new` em cada linha
     * que passou da leitura do usuário e não é dele; tira o `uid` (o JS não
     * precisa do id do autor).
     *
     * @param array<int, array<string,mixed>> $items
     * @return array<int, array<string,mixed>>
     */
    public static function flagPreview(array $items, int $since, int $userId): array
    {
        $out = [];
        foreach ($items as $it) {
            $it['new'] = self::tableReady() && $userId > 0
                && (int) ($it['id'] ?? 0) > $since
                && (int) ($it['uid'] ?? 0) !== $userId;
            unset($it['uid']);
            $out[] = $it;
        }
        return $out;
    }
}

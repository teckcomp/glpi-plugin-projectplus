<?php

namespace GlpiPlugin\Projectplus;

use CommonDBTM;
use CommonGLPI;
use Html;
use Plugin;
use ProjectTask;
use Session;
use User;

/**
 * Comentários por tarefa (Etapa 3, Bloco 2).
 *
 * - Conversa da equipe por tarefa, em tabela própria do plugin
 *   (glpi_plugin_projectplus_taskcomments) — o core não tem discussão
 *   em ProjectTask (só o Notepad, que não controla edição por autor);
 * - Aba "Comentários (ProjectPlus)" na ficha nativa da tarefa;
 * - No painel: balão com contador na árvore de tarefas e em "Minhas
 *   tarefas", com painel expansível (histórico + novo comentário);
 * - Só o autor (ou admin com UPDATE em config) edita/exclui um
 *   comentário; comentário novo gera alerta no sino para a equipe
 *   da tarefa (e entra no feed de atividades).
 */
class TaskComment extends CommonDBTM
{
    public static $rightname = 'plugin_projectplus_dashboard';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_projectplus_taskcomments';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Comentário', 'Comentários', $nb, 'projectplus');
    }

    /** Quem pode ler/escrever comentários: quem vê o painel. */
    public static function canComment(): bool
    {
        return Session::haveRight('plugin_projectplus_dashboard', READ);
    }

    /** Quem pode editar/excluir um comentário: o autor ou admin (config). */
    public static function canManage(int $authorId): bool
    {
        return $authorId === (int) Session::getLoginUserID()
            || Session::haveRight('config', UPDATE);
    }

    // ------------------------------------------------------------------
    // Aba na ficha nativa da tarefa
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof ProjectTask) {
            $count = countElementsInTable(
                self::getTable(),
                ['projecttasks_id' => (int) $item->getID()]
            );
            return self::createTabEntry(__('Comentários (ProjectPlus)', 'projectplus'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ) {
        if ($item instanceof ProjectTask) {
            self::showForTask($item);
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Consultas
    // ------------------------------------------------------------------

    /**
     * Contagem de comentários por tarefa, em consulta única.
     * (Contada em PHP: o iterator do GLPI 11 descarta os campos do
     * SELECT quando COUNT+GROUPBY são usados juntos.)
     *
     * @param int[] $taskIds
     * @return array<int,int> [projecttasks_id => total]
     */
    public static function countForTasks(array $taskIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (empty($taskIds) || !$DB->tableExists(self::getTable())) {
            return [];
        }

        $counts = [];
        foreach (
            $DB->request([
                'SELECT' => 'projecttasks_id',
                'FROM'   => self::getTable(),
                'WHERE'  => ['projecttasks_id' => $taskIds],
            ]) as $row
        ) {
            $tid          = (int) $row['projecttasks_id'];
            $counts[$tid] = ($counts[$tid] ?? 0) + 1;
        }
        return $counts;
    }

    /** Total de comentários de UMA tarefa (para o badge após add/delete). */
    public static function countForTask(int $taskId): int
    {
        $counts = self::countForTasks([$taskId]);
        return $counts[$taskId] ?? 0;
    }

    /**
     * Comentários de uma tarefa (mais antigos primeiro), com autor e
     * flag can_edit calculada para o usuário logado.
     */
    public static function getForTask(int $taskId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'SELECT'    => [
                    self::getTable() . '.*',
                    'glpi_users.realname AS author_realname',
                    'glpi_users.firstname AS author_firstname',
                    'glpi_users.name AS author_login',
                ],
                'FROM'      => self::getTable(),
                'LEFT JOIN' => [
                    'glpi_users' => [
                        'ON' => [
                            self::getTable() => 'users_id',
                            'glpi_users'     => 'id',
                        ],
                    ],
                ],
                'WHERE' => ['projecttasks_id' => $taskId],
                'ORDER' => [
                    self::getTable() . '.date_creation ASC',
                    self::getTable() . '.id ASC',
                ],
            ]) as $row
        ) {
            $author = trim(
                (string) ($row['author_realname'] ?? '') . ' '
                . (string) ($row['author_firstname'] ?? '')
            );
            if ($author === '') {
                $author = (string) ($row['author_login'] ?? '—');
            }

            $out[] = [
                'id'       => (int) $row['id'],
                'author'   => $author,
                'content'  => (string) $row['content'],
                'date'     => $row['date_creation']
                    ? date('d/m/Y H:i', strtotime($row['date_creation'])) : '',
                'edited'   => !empty($row['date_mod'])
                    && $row['date_mod'] !== $row['date_creation'],
                'can_edit' => self::canManage((int) $row['users_id']),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Escrita (consumida por ajax/comment.php e front/taskcomment.form.php)
    // ------------------------------------------------------------------

    /**
     * Insere um comentário, atualiza o indicador de atividade do projeto
     * e alerta a equipe da tarefa (sino + feed).
     *
     * @return int id do comentário criado (0 = falha)
     */
    public static function addForTask(ProjectTask $task, string $content): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now = date('Y-m-d H:i:s');
        $DB->insert(self::getTable(), [
            'projecttasks_id' => (int) $task->getID(),
            'users_id'        => (int) Session::getLoginUserID(),
            'content'         => $content,
            'date_creation'   => $now,
            'date_mod'        => $now,
        ]);
        $id = (int) $DB->insertId();

        if ($id > 0) {
            $projectId = (int) ($task->fields['projects_id'] ?? 0);
            if ($projectId > 0) {
                ProjectTracking::touch($projectId);
            }
            self::notifyTeam($task, $content);
        }
        return $id;
    }

    /**
     * Alerta interno (sino) para a equipe da tarefa, exceto o autor.
     * A unique key `dedup` guarda UM alerta 'comment' por usuário/tarefa:
     * comentários seguintes atualizam a mensagem e reabrem o não-lido.
     */
    private static function notifyTeam(ProjectTask $task, string $content): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $authorId = (int) Session::getLoginUserID();
        $author   = User::getFriendlyNameById($authorId);

        $excerpt = mb_substr(trim($content), 0, 80);
        if (mb_strlen(trim($content)) > 80) {
            $excerpt .= '…';
        }

        $message = sprintf(
            __('%1$s comentou na tarefa "%2$s": %3$s', 'projectplus'),
            $author,
            $task->fields['name'] ?? '',
            $excerpt
        );

        foreach (
            $DB->request([
                'SELECT' => 'items_id',
                'FROM'   => 'glpi_projecttaskteams',
                'WHERE'  => [
                    'projecttasks_id' => (int) $task->getID(),
                    'itemtype'        => 'User',
                ],
            ]) as $row
        ) {
            $userId = (int) $row['items_id'];
            if ($userId <= 0 || $userId === $authorId) {
                continue;
            }

            // 1ª vez: INSERT respeitando a unique key de dedup
            $DB->doQuery(
                'INSERT IGNORE INTO `glpi_plugin_projectplus_alerts`
                    (`users_id`, `itemtype`, `items_id`, `kind`, `message`, `is_read`, `date_creation`)
                 VALUES ('
                    . $userId . ", 'ProjectTask', " . (int) $task->getID() . ", 'comment', "
                    . "'" . $DB->escape($message) . "', 0, NOW())"
            );

            // Já existia: atualiza a mensagem e reabre como não lido
            if ($DB->affectedRows() === 0) {
                $DB->update(
                    'glpi_plugin_projectplus_alerts',
                    [
                        'message'       => $message,
                        'is_read'       => 0,
                        'date_creation' => date('Y-m-d H:i:s'),
                    ],
                    [
                        'users_id' => $userId,
                        'itemtype' => 'ProjectTask',
                        'items_id' => (int) $task->getID(),
                        'kind'     => 'comment',
                    ]
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Conteúdo da aba: lista + formulário
    // ------------------------------------------------------------------

    public static function showForTask(ProjectTask $task): void
    {
        $taskId     = (int) $task->getID();
        $canComment = self::canComment();
        $action     = Plugin::getWebDir('projectplus') . '/front/taskcomment.form.php';
        $comments   = self::getForTask($taskId);

        // ---- Formulário de novo comentário ----
        if ($canComment) {
            echo "<form method='post' action='" . htmlspecialchars($action) . "'>";
            echo "<table class='tab_cadre_fixe'>";
            echo '<tr><th colspan="2">' . __('Novo comentário', 'projectplus') . '</th></tr>';
            echo "<tr class='tab_bg_1'>";
            echo "<td><textarea name='content' rows='2' maxlength='4000' required "
                . "placeholder='" . __('Escreva um comentário…', 'projectplus') . "' class='form-control'></textarea></td>";
            echo '<td style="width:120px">';
            echo Html::hidden('projecttasks_id', ['value' => $taskId]);
            echo "<button type='submit' name='add' value='1' class='btn btn-primary'>"
                . __('Comentar', 'projectplus') . '</button>';
            echo '</td></tr></table>';
            Html::closeForm();
        }

        // ---- Lista de comentários ----
        echo "<div class='spaced'><table class='tab_cadre_fixe'>";
        echo '<tr><th colspan="4">' . __('Comentários da tarefa', 'projectplus') . '</th></tr>';

        if (empty($comments)) {
            echo "<tr class='tab_bg_1'><td class='center'>"
                . __('Nenhum comentário nesta tarefa', 'projectplus') . '</td></tr>';
        } else {
            echo '<tr>'
                . '<th>' . __('Autor', 'projectplus') . '</th>'
                . '<th>' . __('Data', 'projectplus') . '</th>'
                . '<th>' . _n('Comentário', 'Comentários', 1, 'projectplus') . '</th>'
                . '<th></th></tr>';

            foreach ($comments as $c) {
                echo "<tr class='tab_bg_1'>";
                echo '<td>' . htmlspecialchars($c['author']) . '</td>';
                echo '<td>' . htmlspecialchars($c['date'])
                    . ($c['edited'] ? ' <span class="text-muted">(' . __('editado', 'projectplus') . ')</span>' : '')
                    . '</td>';
                echo '<td>' . nl2br(htmlspecialchars($c['content'])) . '</td>';
                echo '<td>';
                if ($c['can_edit']) {
                    echo "<form method='post' action='" . htmlspecialchars($action) . "' style='display:inline'>";
                    echo Html::hidden('id', ['value' => (int) $c['id']]);
                    echo Html::hidden('projecttasks_id', ['value' => $taskId]);
                    echo "<button type='submit' name='delete' value='1' "
                        . "class='btn btn-sm btn-outline-danger' title='" . _sx('button', 'Delete permanently') . "'>&times;</button>";
                    Html::closeForm();
                }
                echo '</td></tr>';
            }
        }
        echo '</table></div>';

        echo "<p class='projectplus-muted' style='margin:6px 2px'>"
            . __('Estes comentários também aparecem no Gestor de Projetos (árvore de tarefas e Minhas tarefas).', 'projectplus')
            . '</p>';
    }
}

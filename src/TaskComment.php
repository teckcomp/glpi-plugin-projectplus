<?php

namespace GlpiPlugin\Projectplus;

use CommonDBTM;
use CommonGLPI;
use Html;
use Project;
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
 *
 * Bloco D-3b (01/10/2026): comentários de PROJETO na mesma tabela, com
 * `projects_id` > 0 e `projecttasks_id` = 0 (anexos idem em commentfiles).
 * Só quem EDITA projeto vê/escreve (Access::canEditProjects — decisão do
 * Claudio: o 💬 fica na faixa "Projeto"); alerta no sino só para o GESTOR.
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

    /**
     * Quem pode ler/escrever comentários: quem entra no plugin. Bloco F-1b:
     * antes era só quem tinha o Painel — o técnico sem Visão geral perdia o
     * 💬 em Minhas tarefas.
     */
    public static function canComment(): bool
    {
        return Access::canEnter();
    }

    /**
     * Comentários de projeto no geral (aba nativa, flag da faixa). Bloco
     * F-2b (05/10/2026): deixou de ser só de quem edita projeto — todo
     * mundo que entra no plugin; a regra POR PROJETO é a de baixo.
     */
    public static function canCommentProject(): bool
    {
        return self::canComment();
    }

    /**
     * Bloco F-2b: lê/escreve no 💬 DESTE projeto quem entra no plugin e
     * ENXERGA o projeto (entidade + escopo do plugin, inclusive o cliente
     * na equipe do projeto). Não depende do direito nativo de projeto.
     */
    public static function canCommentOnProject(int $projectId): bool
    {
        if ($projectId <= 0 || !self::canComment()) {
            return false;
        }
        $project = new Project();
        if (!$project->getFromDB($projectId) || (int) $project->fields['is_deleted'] === 1) {
            return false;
        }
        if (!Session::haveAccessToEntity((int) $project->fields['entities_id'])) {
            return false;
        }
        return Scope::canSeeProject($projectId);
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
        if ($item instanceof Project && self::canCommentProject()) {
            return self::createTabEntry(
                __('Comentários (ProjectPlus)', 'projectplus'),
                self::countForProject((int) $item->getID())
            );
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
        } elseif ($item instanceof Project && self::canCommentProject()) {
            self::showForProject($item);
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
        return self::getWhere(['projecttasks_id' => $taskId]);
    }

    /** Bloco D-3b — comentários do PROJETO (não inclui os das tarefas). */
    public static function getForProject(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }
        return self::getWhere(['projecttasks_id' => 0, 'projects_id' => $projectId]);
    }

    /** Bloco D-3b — total de comentários do projeto (contado em PHP, lição 1). */
    public static function countForProject(int $projectId): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($projectId <= 0 || !$DB->tableExists(self::getTable())
            || !$DB->fieldExists(self::getTable(), 'projects_id')) {
            return 0;
        }
        $n = 0;
        foreach (
            $DB->request([
                'SELECT' => 'id',
                'FROM'   => self::getTable(),
                'WHERE'  => ['projecttasks_id' => 0, 'projects_id' => $projectId],
            ]) as $row
        ) {
            $n++;
        }
        return $n;
    }

    private static function getWhere(array $where): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $qualified = [];
        foreach ($where as $k => $v) {
            $qualified[self::getTable() . '.' . $k] = $v; // lição 16: JOIN pede coluna qualificada
        }

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
                'WHERE' => $qualified,
                'ORDER' => [
                    self::getTable() . '.date_creation ASC',
                    self::getTable() . '.id ASC',
                ],
            ]) as $row
        ) {
            // Nome do autor segue formatUserName (names_format), como o resto
            // do plugin desde o commit 35dd900 — antes era "Sobrenome Nome" fixo.
            $author = \formatUserName(
                0,
                (string) ($row['author_login'] ?? ''),
                (string) ($row['author_realname'] ?? ''),
                (string) ($row['author_firstname'] ?? '')
            );
            if ($author === '') {
                $author = '—';
            }

            $out[] = [
                'id'       => (int) $row['id'],
                'author'   => $author,
                'content'  => (string) $row['content'],
                'date'     => $row['date_creation']
                    ? DateFmt::dateTime($row['date_creation']) : '',
                'edited'   => !empty($row['date_mod'])
                    && $row['date_mod'] !== $row['date_creation'],
                'can_edit' => self::canManage((int) $row['users_id']),
                'files'    => [],
            ];
        }

        // Anexos (Rodada 3, Bloco 4) — uma consulta para todos os comentários.
        if ($out !== []) {
            $filesBy = CommentFile::forComments(array_column($out, 'id'));
            foreach ($out as &$c) {
                $c['files'] = $filesBy[$c['id']] ?? [];
            }
            unset($c);
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
     * Bloco D-3b — comentário no PROJETO. Atualiza a atividade e avisa no
     * sino só o GESTOR (glpi_projects.users_id), se não for o autor.
     *
     * @return int id do comentário criado (0 = falha)
     */
    public static function addForProject(Project $project, string $content): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now = date('Y-m-d H:i:s');
        $DB->insert(self::getTable(), [
            'projecttasks_id' => 0,
            'projects_id'     => (int) $project->getID(),
            'users_id'        => (int) Session::getLoginUserID(),
            'content'         => $content,
            'date_creation'   => $now,
            'date_mod'        => $now,
        ]);
        $id = (int) $DB->insertId();

        if ($id > 0) {
            ProjectTracking::touch((int) $project->getID());
            self::notifyProjectParticipants($project, $content);
        }
        return $id;
    }

    /**
     * Bloco F-2b (decisão do Claudio): avisa no sino o GESTOR do projeto e
     * quem JÁ comentou nele (participantes da conversa), menos o autor.
     *
     * @return int[] ids avisados (para o harness)
     */
    public static function projectRecipients(Project $project, int $authorId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        $managerId = (int) ($project->fields['users_id'] ?? 0);
        if ($managerId > 0) {
            $ids[$managerId] = true;
        }
        foreach (
            $DB->request([
                'SELECT' => 'users_id',
                'FROM'   => self::getTable(),
                'WHERE'  => ['projecttasks_id' => 0, 'projects_id' => (int) $project->getID()],
            ]) as $r
        ) {
            if ((int) $r['users_id'] > 0) {
                $ids[(int) $r['users_id']] = true;
            }
        }
        unset($ids[$authorId]);
        return array_keys($ids);
    }

    private static function notifyProjectParticipants(Project $project, string $content): void
    {
        $authorId = (int) Session::getLoginUserID();
        foreach (self::projectRecipients($project, $authorId) as $uid) {
            self::notifyProjectUser($project, $content, $authorId, $uid);
        }
    }

    private static function notifyProjectUser(Project $project, string $content, int $authorId, int $managerId): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $excerpt = mb_substr(trim($content), 0, 80);
        if (mb_strlen(trim($content)) > 80) {
            $excerpt .= '…';
        }
        $message = sprintf(
            __('%1$s comentou no projeto "%2$s": %3$s', 'projectplus'),
            User::getFriendlyNameById($authorId),
            $project->fields['name'] ?? '',
            $excerpt
        );

        // Mesmo esquema do alerta de tarefa: unique key `dedup` guarda UM
        // alerta 'comment' por usuário/projeto; os seguintes reabrem.
        $DB->doQuery(
            'INSERT IGNORE INTO `glpi_plugin_projectplus_alerts`
                (`users_id`, `itemtype`, `items_id`, `kind`, `message`, `is_read`, `date_creation`)
             VALUES ('
                . $managerId . ", 'Project', " . (int) $project->getID() . ", 'comment', "
                . "'" . $DB->escape($message) . "', 0, NOW())"
        );
        if ($DB->affectedRows() === 0) {
            $DB->update(
                'glpi_plugin_projectplus_alerts',
                ['message' => $message, 'is_read' => 0, 'date_creation' => date('Y-m-d H:i:s')],
                [
                    'users_id' => $managerId,
                    'itemtype' => 'Project',
                    'items_id' => (int) $project->getID(),
                    'kind'     => 'comment',
                ]
            );
        }
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
        self::showTab(
            'projecttasks_id',
            (int) $task->getID(),
            self::getForTask((int) $task->getID()),
            self::canComment(),
            __('Comentários da tarefa', 'projectplus'),
            __('Nenhum comentário nesta tarefa', 'projectplus')
        );
    }

    /** Bloco D-3b — aba "Comentários (ProjectPlus)" na ficha do projeto. */
    public static function showForProject(Project $project): void
    {
        self::showTab(
            'projects_id',
            (int) $project->getID(),
            self::getForProject((int) $project->getID()),
            self::canCommentProject(),
            __('Comentários do projeto', 'projectplus'),
            __('Nenhum comentário neste projeto', 'projectplus')
        );
    }

    private static function showTab(
        string $field,
        int $itemId,
        array $comments,
        bool $canComment,
        string $title,
        string $empty
    ): void {
        $action = Url::to('front/taskcomment.form.php');

        // ---- Formulário de novo comentário ----
        if ($canComment) {
            echo "<form method='post' enctype='multipart/form-data' action='" . htmlspecialchars($action) . "'>";
            echo "<table class='tab_cadre_fixe'>";
            echo '<tr><th colspan="2">' . __('Novo comentário', 'projectplus') . '</th></tr>';
            echo "<tr class='tab_bg_1'>";
            echo "<td><textarea name='content' rows='2' maxlength='4000' "
                . "placeholder='" . __('Escreva um comentário…', 'projectplus') . "' class='form-control'></textarea>"
                . "<input type='file' name='files[]' multiple class='form-control' style='margin-top:6px' "
                . "accept='.png,.jpg,.jpeg,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx'>"
                . "<span class='text-muted' style='font-size:.8em'>"
                . __('Anexos: imagens, PDF, DOC/DOCX, XLS/XLSX — até 10 MB cada', 'projectplus')
                . '</span></td>';
            echo '<td style="width:120px">';
            echo Html::hidden($field, ['value' => $itemId]);
            echo "<button type='submit' name='add' value='1' class='btn btn-primary'>"
                . __('Comentar', 'projectplus') . '</button>';
            echo '</td></tr></table>';
            Html::closeForm();
        }

        // ---- Lista de comentários ----
        echo "<div class='spaced'><table class='tab_cadre_fixe'>";
        echo '<tr><th colspan="4">' . htmlspecialchars($title) . '</th></tr>';

        if (empty($comments)) {
            echo "<tr class='tab_bg_1'><td class='center'>"
                . htmlspecialchars($empty) . '</td></tr>';
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
                echo '<td>' . nl2br(htmlspecialchars($c['content']));
                foreach ($c['files'] as $f) {
                    echo '<div><a href="' . htmlspecialchars($f['url']) . '" target="_blank" rel="noopener">'
                        . ($f['is_image'] ? '🖼️ ' : '📄 ') . htmlspecialchars($f['name'])
                        . '</a> <span class="text-muted">(' . htmlspecialchars($f['size_h']) . ')</span></div>';
                }
                echo '</td>';
                echo '<td>';
                if ($c['can_edit']) {
                    echo "<form method='post' action='" . htmlspecialchars($action) . "' style='display:inline'>";
                    echo Html::hidden('id', ['value' => (int) $c['id']]);
                    echo Html::hidden($field, ['value' => $itemId]);
                    echo "<button type='submit' name='delete' value='1' "
                        . "class='btn btn-sm btn-outline-danger' title='" . _sx('button', 'Delete permanently') . "'>&times;</button>";
                    Html::closeForm();
                }
                echo '</td></tr>';
            }
        }
        echo '</table></div>';

        echo "<p class='projectplus-muted' style='margin:6px 2px'>"
            . ($field === 'projects_id'
                ? __('Estes comentários também aparecem no Gestor de Projetos (faixa Projeto do painel).', 'projectplus')
                : __('Estes comentários também aparecem no Gestor de Projetos (árvore de tarefas e Minhas tarefas).', 'projectplus'))
            . '</p>';
    }
}

<?php

namespace GlpiPlugin\Projectplus;

use CommonGLPI;
use Html;
use Project;
use ProjectTask;
use Session;

/**
 * Dependências entre tarefas (Etapa 3, Bloco 3).
 *
 * Usa a TABELA NATIVA glpi_projecttasklinks (core desde 9.5.4, sem UI
 * própria no GLPI — a classe ProjectTaskLink existe só para o plugin
 * Gantt). Vantagens: modelo do próprio core, cleanup automático quando
 * a tarefa é excluída (ProjectTask::cleanRelationData) e compatibilidade
 * com o Gantt oficial. Nenhuma migração no Install é necessária.
 *
 * Semântica adotada (apenas type=0, finish_to_start):
 *   source BLOQUEIA target  →  target é BLOQUEADA POR source
 *   (a bloqueada não pode ser concluída enquanto houver bloqueadora
 *   com percent_done < 100)
 *
 * O plugin acrescenta: painel 🔗 na árvore de tarefas e em "Minhas
 * tarefas", aba nativa "Dependências (ProjectPlus)" na tarefa, regra de
 * conclusão no servidor (ajax/task.php) e prevenção de ciclos.
 *
 * REGRA GERAL (Fix 1): filhos abertos bloqueiam o pai por padrão, sem
 * vínculo manual — tarefa com subtarefas abertas fica bloqueada (as
 * subtarefas aparecem como itens IMPLÍCITOS no painel, sem remoção);
 * projeto com tarefas abertas ou subprojetos não concluídos fica 🔒 e
 * não pode ir para fase finalizada (is_finished) — guarda via hook
 * PRE_ITEM_UPDATE, valendo também na ficha nativa. Os vínculos
 * explícitos continuam disponíveis por cima da regra geral.
 */
class TaskDep extends CommonGLPI
{
    public static $rightname = 'plugin_projectplus_dashboard';

    /** Tabela NATIVA do core (não é tabela do plugin). */
    public const TABLE = 'glpi_projecttasklinks';

    /** finish_to_start — único tipo exposto pela UI do ProjectPlus. */
    public const TYPE_FINISH_TO_START = 0;

    public static function getTypeName($nb = 0)
    {
        return _n('Dependência', 'Dependências', $nb, 'projectplus');
    }

    /** Quem pode criar/remover dependências: quem edita tarefas. */
    public static function canManage(): bool
    {
        return Session::haveRight('projecttask', UPDATE)
            || Session::haveRight('project', UPDATE);
    }

    // ------------------------------------------------------------------
    // Aba na ficha nativa da tarefa
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof ProjectTask) {
            /** @var \DBmysql $DB */
            global $DB;
            $count = 0;
            foreach (
                $DB->request([
                    'SELECT' => 'id',
                    'FROM'   => self::TABLE,
                    'WHERE'  => [
                        'OR' => [
                            'projecttasks_id_source' => (int) $item->getID(),
                            'projecttasks_id_target' => (int) $item->getID(),
                        ],
                        'type' => self::TYPE_FINISH_TO_START,
                    ],
                ]) as $ignored
            ) {
                $count++;
            }
            return self::createTabEntry(__('Dependências (ProjectPlus)', 'projectplus'), $count);
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
     * Contadores por tarefa, em consultas únicas (contagem em PHP — o
     * iterator do GLPI 11 descarta os campos do SELECT com COUNT+GROUPBY).
     *
     * @param int[] $taskIds
     * @return array<int,array{deps:int,blocked:bool}>
     *         deps    = total de vínculos EXPLÍCITOS (nas duas direções)
     *         blocked = tem bloqueadora aberta OU subtarefa aberta
     *                   (regra geral: filhos abertos bloqueiam o pai)
     */
    public static function countForTasks(array $taskIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (empty($taskIds)) {
            return [];
        }
        $taskIds = array_map('intval', $taskIds); // in_array estrito abaixo

        $out       = [];
        $links     = [];
        $sourceIds = [];

        foreach (
            $DB->request([
                'SELECT' => ['projecttasks_id_source', 'projecttasks_id_target'],
                'FROM'   => self::TABLE,
                'WHERE'  => [
                    'OR' => [
                        'projecttasks_id_source' => $taskIds,
                        'projecttasks_id_target' => $taskIds,
                    ],
                    'type' => self::TYPE_FINISH_TO_START,
                ],
            ]) as $row
        ) {
            $src = (int) $row['projecttasks_id_source'];
            $tgt = (int) $row['projecttasks_id_target'];
            $links[]     = [$src, $tgt];
            $sourceIds[] = $src;
        }

        // Percentual das bloqueadoras, em consulta única
        $openSources = [];
        if (!empty($sourceIds)) {
            foreach (
                $DB->request([
                    'SELECT' => 'id',
                    'FROM'   => 'glpi_projecttasks',
                    'WHERE'  => [
                        'id'           => array_values(array_unique($sourceIds)),
                        'percent_done' => ['<', 100],
                    ],
                ]) as $row
            ) {
                $openSources[(int) $row['id']] = true;
            }
        }

        foreach ($links as [$src, $tgt]) {
            foreach ([$src, $tgt] as $tid) {
                if (in_array($tid, $taskIds, true) && !isset($out[$tid])) {
                    $out[$tid] = ['deps' => 0, 'blocked' => false];
                }
            }
            if (isset($out[$src])) {
                $out[$src]['deps']++;
            }
            if (isset($out[$tgt])) {
                $out[$tgt]['deps']++;
                if (isset($openSources[$src])) {
                    $out[$tgt]['blocked'] = true;
                }
            }
        }

        // Regra geral: subtarefa DIRETA aberta bloqueia a mãe (consulta
        // única; a recursão é natural — cada filha-mãe repete a regra)
        foreach (
            $DB->request([
                'SELECT' => 'projecttasks_id',
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => [
                    'projecttasks_id' => $taskIds,
                    'percent_done'    => ['<', 100],
                ],
            ]) as $row
        ) {
            $tid = (int) $row['projecttasks_id'];
            if (!isset($out[$tid])) {
                $out[$tid] = ['deps' => 0, 'blocked' => false];
            }
            $out[$tid]['blocked'] = true;
        }

        return $out;
    }

    /**
     * Nomes das bloqueadoras ABERTAS de uma tarefa — usada pela regra de
     * conclusão no servidor (ajax/task.php) e pelas mensagens de erro.
     *
     * @return string[] nomes das tarefas que ainda impedem a conclusão
     */
    public static function openBlockerNames(int $taskId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $names = [];
        foreach (
            $DB->request([
                'SELECT'     => ['glpi_projecttasks.name'],
                'FROM'       => self::TABLE,
                'INNER JOIN' => [
                    'glpi_projecttasks' => [
                        'ON' => [
                            self::TABLE         => 'projecttasks_id_source',
                            'glpi_projecttasks' => 'id',
                        ],
                    ],
                ],
                'WHERE'      => [
                    self::TABLE . '.projecttasks_id_target' => $taskId,
                    self::TABLE . '.type'                   => self::TYPE_FINISH_TO_START,
                    'glpi_projecttasks.percent_done'        => ['<', 100],
                ],
            ]) as $row
        ) {
            $names[] = (string) $row['name'];
        }
        return $names;
    }

    /**
     * Dados do painel de uma tarefa: bloqueadoras, bloqueadas e
     * candidatas (tarefas do MESMO projeto ainda não vinculadas).
     */
    public static function getPanelData(int $taskId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $task = new ProjectTask();
        if (!$task->getFromDB($taskId)) {
            return ['blockers' => [], 'blocked' => [], 'candidates' => [], 'can_edit' => false];
        }

        $taskUrl = static function (int $id): string {
            return ProjectTask::getFormURLWithID($id);
        };

        $blockers = []; // quem bloqueia ESTA tarefa (esta é a target)
        $blocked  = []; // quem ESTA tarefa bloqueia (esta é a source)
        $linkedIds = [$taskId => true];

        // Regra geral: subtarefas DIRETAS abertas entram como bloqueadoras
        // IMPLÍCITAS (vínculo automático, sem remoção)
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'percent_done'],
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => [
                    'projecttasks_id' => $taskId,
                    'percent_done'    => ['<', 100],
                ],
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $blockers[] = [
                'link_id'  => 0,
                'task_id'  => (int) $row['id'],
                'name'     => (string) $row['name'],
                'percent'  => (int) $row['percent_done'],
                'open'     => true,
                'implicit' => true,
                'url'      => $taskUrl((int) $row['id']),
            ];
            $linkedIds[(int) $row['id']] = true;
        }

        foreach (['blockers', 'blocked'] as $dir) {
            $isBlockers = ($dir === 'blockers');
            $selfCol    = $isBlockers ? 'projecttasks_id_target' : 'projecttasks_id_source';
            $otherCol   = $isBlockers ? 'projecttasks_id_source' : 'projecttasks_id_target';

            foreach (
                $DB->request([
                    'SELECT'     => [
                        self::TABLE . '.id AS link_id',
                        'glpi_projecttasks.id AS task_id',
                        'glpi_projecttasks.name',
                        'glpi_projecttasks.percent_done',
                    ],
                    'FROM'       => self::TABLE,
                    'INNER JOIN' => [
                        'glpi_projecttasks' => [
                            'ON' => [
                                self::TABLE         => $otherCol,
                                'glpi_projecttasks' => 'id',
                            ],
                        ],
                    ],
                    'WHERE'      => [
                        self::TABLE . '.' . $selfCol => $taskId,
                        self::TABLE . '.type'        => self::TYPE_FINISH_TO_START,
                    ],
                    'ORDER'      => 'glpi_projecttasks.name',
                ]) as $row
            ) {
                $item = [
                    'link_id'  => (int) $row['link_id'],
                    'task_id'  => (int) $row['task_id'],
                    'name'     => (string) $row['name'],
                    'percent'  => (int) $row['percent_done'],
                    'open'     => ((int) $row['percent_done']) < 100,
                    'implicit' => false,
                    'url'      => $taskUrl((int) $row['task_id']),
                ];
                $linkedIds[(int) $row['task_id']] = true;
                if ($isBlockers) {
                    $blockers[] = $item;
                } else {
                    $blocked[] = $item;
                }
            }
        }

        // Candidatas: tarefas do mesmo projeto, fora as já vinculadas
        $candidates = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'percent_done'],
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => [
                    'projects_id' => (int) $task->fields['projects_id'],
                    'NOT'         => ['id' => array_keys($linkedIds)],
                ],
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $candidates[] = [
                'id'      => (int) $row['id'],
                'name'    => (string) $row['name'],
                'percent' => (int) $row['percent_done'],
            ];
        }

        return [
            'blockers'   => $blockers,
            'blocked'    => $blocked,
            'candidates' => $candidates,
            'can_edit'   => self::canManage(),
        ];
    }

    // ------------------------------------------------------------------
    // Escrita (consumida por ajax/taskdep.php e front/taskdep.form.php)
    // ------------------------------------------------------------------

    /**
     * Cria o vínculo "source BLOQUEIA target" (finish_to_start).
     * Valida: existência, mesmo projeto, duplicata e CICLO
     * (se já existe caminho target → … → source, o novo vínculo fecharia
     * um ciclo e as duas tarefas nunca poderiam ser concluídas).
     *
     * @return array{ok:bool,message:string}
     */
    public static function addLink(int $sourceId, int $targetId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) {
            return ['ok' => false, 'message' => __('Tarefas inválidas para o vínculo', 'projectplus')];
        }

        $source = new ProjectTask();
        $target = new ProjectTask();
        if (!$source->getFromDB($sourceId) || !$target->getFromDB($targetId)) {
            return ['ok' => false, 'message' => __('Tarefa não encontrada', 'projectplus')];
        }
        if ((int) $source->fields['projects_id'] !== (int) $target->fields['projects_id']) {
            return ['ok' => false, 'message' => __('As tarefas precisam ser do mesmo projeto', 'projectplus')];
        }

        // Duplicata exata
        $dup = $DB->request([
            'SELECT' => 'id',
            'FROM'   => self::TABLE,
            'WHERE'  => [
                'projecttasks_id_source' => $sourceId,
                'projecttasks_id_target' => $targetId,
                'type'                   => self::TYPE_FINISH_TO_START,
            ],
        ]);
        if (count($dup) > 0) {
            return ['ok' => false, 'message' => __('Este vínculo já existe', 'projectplus')];
        }

        // Ciclo: existe caminho target → … → source?
        if (self::hasPath($targetId, $sourceId)) {
            return ['ok' => false, 'message' => __(
                'Vínculo recusado: criaria um ciclo de bloqueio entre as tarefas',
                'projectplus'
            )];
        }

        $DB->insert(self::TABLE, [
            'projecttasks_id_source' => $sourceId,
            'source_uuid'            => (string) ($source->fields['uuid'] ?? ''),
            'projecttasks_id_target' => $targetId,
            'target_uuid'            => (string) ($target->fields['uuid'] ?? ''),
            'type'                   => self::TYPE_FINISH_TO_START,
            'lag'                    => 0,
            'lead'                   => 0,
        ]);

        if ((int) $DB->insertId() > 0) {
            ProjectTracking::touch((int) $source->fields['projects_id']);
            return ['ok' => true, 'message' => __('Dependência criada', 'projectplus')];
        }
        return ['ok' => false, 'message' => __('Falha ao criar a dependência', 'projectplus')];
    }

    /**
     * Remove um vínculo pelo id (somente type=0, os demais tipos são do
     * Gantt e não passam pela UI do ProjectPlus).
     *
     * @return array{ok:bool,message:string}
     */
    public static function deleteLink(int $linkId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id', 'projecttasks_id_source'],
            'FROM'   => self::TABLE,
            'WHERE'  => ['id' => $linkId, 'type' => self::TYPE_FINISH_TO_START],
        ])->current();

        if (!$row) {
            return ['ok' => false, 'message' => __('Vínculo não encontrado', 'projectplus')];
        }

        $DB->delete(self::TABLE, ['id' => $linkId]);

        $src = new ProjectTask();
        if ($src->getFromDB((int) $row['projecttasks_id_source'])) {
            ProjectTracking::touch((int) $src->fields['projects_id']);
        }
        return ['ok' => true, 'message' => __('Dependência removida', 'projectplus')];
    }

    /**
     * Busca em largura: existe caminho $fromId → … → $toId seguindo os
     * vínculos source→target? (Limite de segurança de 500 nós.)
     */
    private static function hasPath(int $fromId, int $toId): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $visited = [$fromId => true];
        $queue   = [$fromId];
        $guard   = 0;

        while (!empty($queue) && $guard++ < 500) {
            $batch = array_splice($queue, 0, count($queue));
            foreach (
                $DB->request([
                    'SELECT' => 'projecttasks_id_target',
                    'FROM'   => self::TABLE,
                    'WHERE'  => [
                        'projecttasks_id_source' => $batch,
                        'type'                   => self::TYPE_FINISH_TO_START,
                    ],
                ]) as $row
            ) {
                $next = (int) $row['projecttasks_id_target'];
                if ($next === $toId) {
                    return true;
                }
                if (!isset($visited[$next])) {
                    $visited[$next] = true;
                    $queue[]        = $next;
                }
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Regra geral em PROJETOS (Fix 1): filhos abertos bloqueiam o pai
    // ------------------------------------------------------------------

    /** IDs das fases marcadas como finalizadas (is_finished=1). */
    public static function finishedStateIds(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        static $ids = null;
        if ($ids !== null) {
            return $ids;
        }
        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => 'id',
                'FROM'   => 'glpi_projectstates',
                'WHERE'  => ['is_finished' => 1],
            ]) as $row
        ) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    /**
     * Projetos bloqueados por filhos abertos, em consultas únicas:
     * tarefa com percent_done < 100 OU subprojeto não concluído
     * (percent < 100 e fase não finalizada).
     *
     * @param int[] $projectIds
     * @return array<int,bool> [projects_id => true]
     */
    public static function blockedProjects(array $projectIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (empty($projectIds)) {
            return [];
        }
        $projectIds = array_map('intval', $projectIds);

        $blocked = [];
        foreach (
            $DB->request([
                'SELECT' => 'projects_id',
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => [
                    'projects_id'  => $projectIds,
                    'percent_done' => ['<', 100],
                ],
            ]) as $row
        ) {
            $blocked[(int) $row['projects_id']] = true;
        }

        $finished = self::finishedStateIds();
        foreach (
            $DB->request([
                'SELECT' => ['projects_id', 'projectstates_id', 'percent_done'],
                'FROM'   => 'glpi_projects',
                'WHERE'  => [
                    'projects_id' => $projectIds,
                    'is_deleted'  => 0,
                    'is_template' => 0,
                ],
            ]) as $row
        ) {
            $isDone = ((int) $row['percent_done'] >= 100)
                || in_array((int) $row['projectstates_id'], $finished, true);
            if (!$isDone) {
                $blocked[(int) $row['projects_id']] = true;
            }
        }
        return $blocked;
    }

    /**
     * Nomes dos filhos abertos de um projeto (para a mensagem da guarda
     * de fase) — tarefas abertas e subprojetos não concluídos.
     *
     * @return string[]
     */
    public static function projectOpenChildrenNames(int $projectId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $names = [];
        foreach (
            $DB->request([
                'SELECT' => 'name',
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => [
                    'projects_id'  => $projectId,
                    'percent_done' => ['<', 100],
                ],
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $names[] = (string) $row['name'];
        }

        $finished = self::finishedStateIds();
        foreach (
            $DB->request([
                'SELECT' => ['name', 'projectstates_id', 'percent_done'],
                'FROM'   => 'glpi_projects',
                'WHERE'  => [
                    'projects_id' => $projectId,
                    'is_deleted'  => 0,
                    'is_template' => 0,
                ],
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $isDone = ((int) $row['percent_done'] >= 100)
                || in_array((int) $row['projectstates_id'], $finished, true);
            if (!$isDone) {
                $names[] = (string) $row['name'];
            }
        }
        return $names;
    }

    /**
     * Guarda de fase (hook PRE_ITEM_UPDATE em Project): recusa mover o
     * projeto para uma fase FINALIZADA (is_finished=1) enquanto houver
     * tarefa aberta ou subprojeto não concluído. Vale para a ficha
     * nativa e para qualquer update via core; só o campo de fase é
     * revertido — o restante do update segue normal.
     */
    public static function onProjectPreUpdate(Project $item): void
    {
        if (!isset($item->input['projectstates_id'])) {
            return;
        }
        $newState = (int) $item->input['projectstates_id'];
        $oldState = (int) ($item->fields['projectstates_id'] ?? 0);
        if ($newState === $oldState
            || !in_array($newState, self::finishedStateIds(), true)) {
            return;
        }

        $open = self::projectOpenChildrenNames((int) $item->getID());
        if (empty($open)) {
            return;
        }

        unset($item->input['projectstates_id']);
        Session::addMessageAfterRedirect(
            sprintf(
                __('Fase não alterada: o projeto tem %d item(ns) aberto(s) — %s', 'projectplus'),
                count($open),
                implode(', ', array_slice($open, 0, 5)) . (count($open) > 5 ? '…' : '')
            ),
            false,
            ERROR
        );
    }

    // ------------------------------------------------------------------
    // Conteúdo da aba: listas + formulário
    // ------------------------------------------------------------------

    public static function showForTask(ProjectTask $task): void
    {
        $taskId  = (int) $task->getID();
        $canEdit = self::canManage();
        $action  = Url::to('front/taskdep.form.php');
        $data    = self::getPanelData($taskId);

        // ---- Formulário de novo vínculo ----
        if ($canEdit) {
            echo "<form method='post' action='" . htmlspecialchars($action) . "'>";
            echo "<table class='tab_cadre_fixe'>";
            echo '<tr><th colspan="3">' . __('Nova dependência', 'projectplus') . '</th></tr>';
            echo "<tr class='tab_bg_1'><td>";
            echo "<select name='dir' class='form-select'>";
            echo "<option value='blocked_by'>" . __('Esta tarefa é BLOQUEADA por', 'projectplus') . '</option>';
            echo "<option value='blocks'>" . __('Esta tarefa BLOQUEIA', 'projectplus') . '</option>';
            echo '</select></td><td>';
            if (empty($data['candidates'])) {
                echo "<span class='text-muted'>" . __('Sem outras tarefas neste projeto', 'projectplus') . '</span>';
            } else {
                echo "<select name='other_id' class='form-select'>";
                foreach ($data['candidates'] as $c) {
                    echo "<option value='" . (int) $c['id'] . "'>"
                        . htmlspecialchars($c['name']) . ' (' . (int) $c['percent'] . '%)</option>';
                }
                echo '</select>';
            }
            echo '</td><td style="width:120px">';
            echo Html::hidden('projecttasks_id', ['value' => $taskId]);
            echo "<button type='submit' name='add' value='1' class='btn btn-primary'"
                . (empty($data['candidates']) ? ' disabled' : '') . '>'
                . __('Adicionar', 'projectplus') . '</button>';
            echo '</td></tr></table>';
            Html::closeForm();
        }

        // ---- Listas ----
        foreach (
            [
                ['rows' => $data['blockers'], 'title' => __('Bloqueada por (precisam terminar antes)', 'projectplus')],
                ['rows' => $data['blocked'],  'title' => __('Bloqueia (só concluem depois desta)', 'projectplus')],
            ] as $section
        ) {
            echo "<div class='spaced'><table class='tab_cadre_fixe'>";
            echo '<tr><th colspan="3">' . $section['title'] . '</th></tr>';
            if (empty($section['rows'])) {
                echo "<tr class='tab_bg_1'><td class='center'>" . __('Nenhuma', 'projectplus') . '</td></tr>';
            } else {
                foreach ($section['rows'] as $r) {
                    $implicit = !empty($r['implicit']);
                    echo "<tr class='tab_bg_1'>";
                    echo "<td><a href='" . htmlspecialchars($r['url']) . "'>"
                        . htmlspecialchars($r['name']) . '</a>'
                        . ($implicit
                            ? " <span class='badge bg-secondary'>" . __('subtarefa', 'projectplus') . '</span>'
                            : '')
                        . '</td>';
                    echo '<td style="width:140px">' . ($r['open']
                        ? '<span class="text-warning">' . sprintf(__('aberta (%d%%)', 'projectplus'), $r['percent']) . '</span>'
                        : '<span class="text-success">' . __('concluída', 'projectplus') . '</span>') . '</td>';
                    echo '<td style="width:60px">';
                    if ($canEdit && !$implicit) {
                        echo "<form method='post' action='" . htmlspecialchars($action) . "' style='display:inline'>";
                        echo Html::hidden('link_id', ['value' => (int) $r['link_id']]);
                        echo Html::hidden('projecttasks_id', ['value' => $taskId]);
                        echo "<button type='submit' name='delete' value='1' "
                            . "class='btn btn-sm btn-outline-danger' title='" . __('Remover vínculo', 'projectplus') . "'>&times;</button>";
                        Html::closeForm();
                    }
                    echo '</td></tr>';
                }
            }
            echo '</table></div>';
        }

        echo "<p class='projectplus-muted' style='margin:6px 2px'>"
            . __('Regra geral: subtarefas abertas bloqueiam a tarefa mãe automaticamente (itens "subtarefa" acima). Uma tarefa bloqueada não pode ser concluída enquanto as bloqueadoras estiverem abertas. As dependências também aparecem no Gestor de Projetos (🔗).', 'projectplus')
            . '</p>';
    }
}

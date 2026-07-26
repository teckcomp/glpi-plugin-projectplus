<?php

namespace GlpiPlugin\Projectplus;

use CommonDBTM;
use CommonGLPI;
use Html;
use ProjectTask;
use Session;

/**
 * Custos por TAREFA (extensão do plugin — o GLPI só tem custos por projeto).
 *
 * - Aba "Custos (ProjectPlus)" na ficha nativa da tarefa do projeto;
 * - Lançamentos ficam em glpi_plugin_projectplus_taskcosts;
 * - O consumo entra no orçamento do projeto (Budget::getOwnCosts soma
 *   custos nativos do projeto + custos das tarefas dele) — as barras e os
 *   alertas budget_warn/budget_over passam a considerar os dois.
 */
class TaskCost extends CommonDBTM
{
    public static $rightname = 'project';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_projectplus_taskcosts';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Custo', 'Custos', $nb, 'projectplus');
    }

    /**
     * Ver custos da tarefa (Etapa 8, Bloco 4): agora depende do direito
     * PRÓPRIO do módulo Custos — é o que tira a aba do Colaborador e do
     * Cliente, que antes a viam por terem `project`/`projecttask` no core.
     */
    public static function canViewCosts(): bool
    {
        return Access::can('costs');
    }

    public static function canEditCosts(): bool
    {
        return Access::can('costs', UPDATE)
            && (Session::haveRight('projecttask', UPDATE) || Session::haveRight('project', UPDATE));
    }

    // ------------------------------------------------------------------
    // Aba na ficha nativa da tarefa
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof ProjectTask && self::canViewCosts()) {
            $count = countElementsInTable(
                self::getTable(),
                ['projecttasks_id' => (int) $item->getID()]
            );
            return self::createTabEntry(__('Custos (ProjectPlus)', 'projectplus'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ) {
        if ($item instanceof ProjectTask && self::canViewCosts()) {
            self::showForTask($item);
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Totais
    // ------------------------------------------------------------------

    /**
     * Total de custos lançados numa tarefa.
     */
    public static function getTotalForTask(int $taskId): float
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'SELECT' => ['SUM' => 'cost AS total'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['projecttasks_id' => $taskId],
        ])->current();

        return (float) ($row['total'] ?? 0);
    }

    /**
     * Total de custos de TODAS as tarefas de um projeto (consumido pelo
     * Budget na consolidação do orçamento).
     */
    public static function getTotalForProject(int $projectId): float
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::getTable())) {
            return 0.0;
        }

        $row = $DB->request([
            'SELECT'     => ['SUM' => self::getTable() . '.cost AS total'],
            'FROM'       => self::getTable(),
            'INNER JOIN' => [
                'glpi_projecttasks' => [
                    'ON' => [
                        self::getTable()    => 'projecttasks_id',
                        'glpi_projecttasks' => 'id',
                    ],
                ],
            ],
            'WHERE' => ['glpi_projecttasks.projects_id' => $projectId],
        ])->current();

        return (float) ($row['total'] ?? 0);
    }

    // ------------------------------------------------------------------
    // Conteúdo da aba: lista + formulário
    // ------------------------------------------------------------------

    public static function showForTask(ProjectTask $task): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $taskId  = (int) $task->getID();
        $canedit = self::canEditCosts();
        $action  = Url::to('front/taskcost.form.php');

        // ---- Formulário de lançamento ----
        if ($canedit) {
            echo "<form method='post' action='" . htmlspecialchars($action) . "'>";
            echo "<table class='tab_cadre_fixe'>";
            echo '<tr><th colspan="4">' . __('Adicionar custo à tarefa', 'projectplus') . '</th></tr>';
            echo "<tr class='tab_bg_1'>";
            echo "<td><input type='text' name='name' required maxlength='255' "
                . "placeholder='" . __('Descrição do custo', 'projectplus') . "' class='form-control'></td>";
            echo "<td><input type='date' name='date' value='" . date('Y-m-d') . "' class='form-control'></td>";
            echo "<td><input type='number' name='cost' step='0.01' min='0' required "
                . "placeholder='0,00' class='form-control'></td>";
            echo '<td>';
            echo "<input type='text' name='comment' maxlength='255' "
                . "placeholder='" . __('Comentário (opcional)', 'projectplus') . "' class='form-control' style='display:inline-block;max-width:60%'> ";
            echo Html::hidden('projecttasks_id', ['value' => $taskId]);
            echo "<button type='submit' name='add' value='1' class='btn btn-primary'>"
                . _sx('button', 'Add') . '</button>';
            echo '</td></tr></table>';
            Html::closeForm();
        }

        // ---- Lista de custos ----
        echo "<div class='spaced'><table class='tab_cadre_fixe'>";
        echo '<tr><th colspan="6">' . __('Custos da tarefa', 'projectplus') . '</th></tr>';
        echo '<tr>'
            . '<th>' . __('Descrição', 'projectplus') . '</th>'
            . '<th>' . __('Data', 'projectplus') . '</th>'
            . '<th>' . _n('Custo', 'Custos', 1, 'projectplus') . '</th>'
            . '<th>' . __('Lançado por', 'projectplus') . '</th>'
            . '<th>' . _n('Comentário', 'Comentários', 1, 'projectplus') . '</th>'
            . '<th></th></tr>';

        $total = 0.0;
        $rows  = 0;
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
                'ORDER' => ['date DESC', self::getTable() . '.id DESC'],
            ]) as $row
        ) {
            $rows++;
            $total += (float) $row['cost'];

            $author = trim(
                (string) ($row['author_realname'] ?? '') . ' '
                . (string) ($row['author_firstname'] ?? '')
            );
            if ($author === '') {
                $author = (string) ($row['author_login'] ?? '—');
            }

            echo "<tr class='tab_bg_1'>";
            echo '<td>' . htmlspecialchars($row['name']) . '</td>';
            echo '<td>' . (!empty($row['date']) ? DateFmt::date($row['date']) : '—') . '</td>';
            echo '<td>' . number_format((float) $row['cost'], 2, ',', '.') . '</td>';
            echo '<td>' . htmlspecialchars($author) . '</td>';
            echo '<td>' . htmlspecialchars($row['comment'] ?? '') . '</td>';
            echo '<td>';
            if ($canedit) {
                echo "<form method='post' action='" . htmlspecialchars($action) . "' style='display:inline'>";
                echo Html::hidden('id', ['value' => (int) $row['id']]);
                echo Html::hidden('projecttasks_id', ['value' => $taskId]);
                echo "<button type='submit' name='delete' value='1' "
                    . "class='btn btn-sm btn-outline-danger' title='" . _sx('button', 'Delete permanently') . "'>&times;</button>";
                Html::closeForm();
            }
            echo '</td></tr>';
        }

        if ($rows === 0) {
            echo "<tr class='tab_bg_1'><td colspan='6' class='center'>"
                . __('Nenhum custo lançado nesta tarefa', 'projectplus') . '</td></tr>';
        } else {
            echo "<tr class='tab_bg_2'><td><strong>" . __('Total', 'projectplus') . '</strong></td>'
                . '<td></td><td><strong>' . number_format($total, 2, ',', '.') . '</strong></td>'
                . '<td colspan="3"></td></tr>';
        }
        echo '</table></div>';

        echo "<p class='projectplus-muted' style='margin:6px 2px'>"
            . __('Estes custos consomem o orçamento do projeto (junto com a aba Custos nativa do projeto).', 'projectplus')
            . '</p>';
    }
}

<?php

namespace GlpiPlugin\Projectplus;

use CommonDBTM;
use CommonGLPI;
use Html;
use Plugin;
use Project;
use Session;

/**
 * Custos por PROJETO — aba própria do plugin (Bloco 5.3).
 *
 * Substitui na prática a aba "Custos" nativa (que fica oculta via JS e
 * deixa de ser lida pelo orçamento): fonte única de custos com AUTOR
 * registrado em todo lançamento. Os lançamentos antigos do nativo são
 * migrados uma única vez via SQL (autor "—").
 */
class ProjectCost extends CommonDBTM
{
    public static $rightname = 'project';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_projectplus_projectcosts';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Custo', 'Custos', $nb, 'projectplus');
    }

    public static function canEditCosts(): bool
    {
        return Session::haveRight('project', UPDATE);
    }

    // ------------------------------------------------------------------
    // Aba na ficha nativa do projeto
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Project) {
            $count = countElementsInTable(
                self::getTable(),
                ['projects_id' => (int) $item->getID()]
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
        if ($item instanceof Project) {
            self::showForProject($item);
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Totais
    // ------------------------------------------------------------------

    public static function getTotalForProject(int $projectId): float
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::getTable())) {
            return 0.0;
        }

        $row = $DB->request([
            'SELECT' => ['SUM' => 'cost AS total'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['projects_id' => $projectId],
        ])->current();

        return (float) ($row['total'] ?? 0);
    }

    // ------------------------------------------------------------------
    // Conteúdo da aba: lista + formulário
    // ------------------------------------------------------------------

    public static function showForProject(Project $project): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $projectId = (int) $project->getID();
        $canedit   = self::canEditCosts();
        $action    = Plugin::getWebDir('projectplus') . '/front/projectcost.form.php';

        // ---- Formulário de lançamento ----
        if ($canedit) {
            echo "<form method='post' action='" . htmlspecialchars($action) . "'>";
            echo "<table class='tab_cadre_fixe'>";
            echo '<tr><th colspan="4">' . __('Adicionar custo ao projeto', 'projectplus') . '</th></tr>';
            echo "<tr class='tab_bg_1'>";
            echo "<td><input type='text' name='name' required maxlength='255' "
                . "placeholder='" . __('Descrição do custo', 'projectplus') . "' class='form-control'></td>";
            echo "<td><input type='date' name='date' value='" . date('Y-m-d') . "' class='form-control'></td>";
            echo "<td><input type='number' name='cost' step='0.01' min='0' required "
                . "placeholder='0,00' class='form-control'></td>";
            echo '<td>';
            echo "<input type='text' name='comment' maxlength='255' "
                . "placeholder='" . __('Comentário (opcional)', 'projectplus') . "' class='form-control' style='display:inline-block;max-width:60%'> ";
            echo Html::hidden('projects_id', ['value' => $projectId]);
            echo "<button type='submit' name='add' value='1' class='btn btn-primary'>"
                . _sx('button', 'Add') . '</button>';
            echo '</td></tr></table>';
            Html::closeForm();
        }

        // ---- Lista de custos ----
        echo "<div class='spaced'><table class='tab_cadre_fixe'>";
        echo '<tr><th colspan="6">' . __('Custos do projeto', 'projectplus') . '</th></tr>';
        echo '<tr>'
            . '<th>' . __('Descrição', 'projectplus') . '</th>'
            . '<th>' . __('Data', 'projectplus') . '</th>'
            . '<th>' . __('Custo', 'projectplus') . '</th>'
            . '<th>' . __('Lançado por', 'projectplus') . '</th>'
            . '<th>' . __('Comentário', 'projectplus') . '</th>'
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
                'WHERE' => ['projects_id' => $projectId],
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
            echo '<td>' . (!empty($row['date']) ? date('d/m/Y', strtotime($row['date'])) : '—') . '</td>';
            echo '<td>' . number_format((float) $row['cost'], 2, ',', '.') . '</td>';
            echo '<td>' . htmlspecialchars($author) . '</td>';
            echo '<td>' . htmlspecialchars($row['comment'] ?? '') . '</td>';
            echo '<td>';
            if ($canedit) {
                echo "<form method='post' action='" . htmlspecialchars($action) . "' style='display:inline'>";
                echo Html::hidden('id', ['value' => (int) $row['id']]);
                echo Html::hidden('projects_id', ['value' => $projectId]);
                echo "<button type='submit' name='delete' value='1' "
                    . "class='btn btn-sm btn-outline-danger' title='" . _sx('button', 'Delete permanently') . "'>&times;</button>";
                Html::closeForm();
            }
            echo '</td></tr>';
        }

        if ($rows === 0) {
            echo "<tr class='tab_bg_1'><td colspan='6' class='center'>"
                . __('Nenhum custo lançado neste projeto', 'projectplus') . '</td></tr>';
        } else {
            echo "<tr class='tab_bg_2'><td><strong>" . __('Total', 'projectplus') . '</strong></td>'
                . '<td></td><td><strong>' . number_format($total, 2, ',', '.') . '</strong></td>'
                . '<td colspan="3"></td></tr>';
        }
        echo '</table></div>';

        echo "<p class='projectplus-muted' style='margin:6px 2px'>"
            . __('Fonte única de custos do projeto (a aba Custos nativa foi desativada pelo ProjectPlus).', 'projectplus')
            . '</p>';
    }
}

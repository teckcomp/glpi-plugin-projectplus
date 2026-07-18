<?php

namespace GlpiPlugin\Projectplus;

use CommonDBTM;
use CommonGLPI;
use Html;
use Plugin;
use Project;
use ProjectTask;
use Session;

/**
 * Indicadores extras por projeto (aba "Indicadores (ProjectPlus)").
 *
 * Mantém glpi_plugin_projectplus_projecttrackings: última atividade,
 * flag de "parado" e orçamento planejado x gasto.
 */
class ProjectTracking extends CommonDBTM
{
    public static $rightname = 'plugin_projectplus_dashboard';

    /** Dias sem atividade para considerar o projeto "parado" (padrão) */
    public const DEFAULT_STALLED_DAYS = 7;

    public static function getTypeName($nb = 0)
    {
        return __('Indicadores (ProjectPlus)', 'projectplus');
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_projectplus_projecttrackings';
    }

    // ------------------------------------------------------------------
    // Aba no formulário do Project
    // ------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Project && Session::haveRight(self::$rightname, READ)) {
            return self::getTypeName();
        }
        return '';
    }

    public static function displayTabContentForItem(
        CommonGLPI $item,
        $tabnum = 1,
        $withtemplate = 0
    ) {
        if (!($item instanceof Project)) {
            return false;
        }

        $tracking = self::getForProject((int) $item->getID());

        echo "<div class='projectplus-tracking'>";
        echo '<table class="tab_cadre_fixe">';
        echo '<tr><th colspan="2">' . self::getTypeName() . '</th></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Última atividade', 'projectplus') . '</td><td>'
            . ($tracking['last_activity']
                ? Html::convDateTime($tracking['last_activity'])
                : __('Sem registro', 'projectplus'))
            . '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Situação', 'projectplus') . '</td><td>'
            . ($tracking['is_stalled']
                ? "<span class='projectplus-badge projectplus-badge--stalled'>"
                    . __('Parado', 'projectplus') . '</span>'
                : "<span class='projectplus-badge projectplus-badge--ok'>"
                    . __('Ativo', 'projectplus') . '</span>')
            . '</td></tr>';

        echo '</table>';

        // ------------------ Orçamento (Fase B parte 1) ------------------
        $budget    = Budget::getForProject((int) $item->getID());
        $canUpdate = $item->canUpdateItem();

        echo '<table class="tab_cadre_fixe" style="margin-top:12px">';
        echo '<tr><th colspan="2">' . __('Orçamento (ProjectPlus)', 'projectplus') . '</th></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Teto planejado', 'projectplus') . '</td><td>';
        if ($canUpdate) {
            $formUrl = Plugin::getWebDir('projectplus') . '/front/budget.form.php';
            echo "<form method='post' action='{$formUrl}' style='display:inline-flex;gap:8px;align-items:center'>";
            echo Html::hidden('projects_id', ['value' => (int) $item->getID()]);
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo "<input type='number' step='0.01' min='0' name='budget_planned' value='"
                . htmlspecialchars((string) $budget['planned']) . "' style='width:140px'>";
            echo "<input type='submit' name='update' value='" . _sx('button', 'Save') . "' class='btn btn-sm btn-primary'>";
            echo '</form>';
            echo "<span class='projectplus-muted'> "
                . __('(0 = sem controle de orçamento)', 'projectplus') . '</span>';
        } else {
            echo Html::formatNumber($budget['planned']);
        }
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td>' . __('Gasto neste projeto', 'projectplus') . '</td><td>'
            . Html::formatNumber($budget['spent_own']) . '</td></tr>';

        if ($budget['spent_children'] > 0) {
            echo '<tr class="tab_bg_1"><td>' . __('Gasto nos subprojetos', 'projectplus') . '</td><td>'
                . Html::formatNumber($budget['spent_children']) . '</td></tr>';
        }

        echo '<tr class="tab_bg_1"><td><b>' . __('Gasto consolidado', 'projectplus') . '</b></td><td><b>'
            . Html::formatNumber($budget['spent_total']) . '</b></td></tr>';

        if ($budget['percent'] !== null) {
            $pct   = $budget['percent'];
            $class = $pct > 100 ? 'over' : ($pct >= 80 ? 'warn' : 'ok');
            echo '<tr class="tab_bg_1"><td>' . __('Consumo do teto', 'projectplus') . '</td><td>'
                . "<div class='projectplus-budgetbar'>"
                . "<div class='projectplus-budgetbar__fill projectplus-budgetbar__fill--{$class}' style='width:"
                . min(100, $pct) . "%'></div></div> "
                . "<b>{$pct}%</b>"
                . '</td></tr>';
            echo '<tr class="tab_bg_1"><td>' . __('Saldo', 'projectplus') . '</td><td>'
                . Html::formatNumber($budget['balance']) . '</td></tr>';
        }

        echo '</table>';
        echo '</div>';

        return true;
    }

    // ------------------------------------------------------------------
    // API interna
    // ------------------------------------------------------------------

    /**
     * Registro de tracking de um projeto (array vazio se inexistente).
     */
    public static function getForProject(int $projectId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['projects_id' => $projectId],
            'LIMIT' => 1,
        ]);

        foreach ($it as $row) {
            return $row;
        }
        return [];
    }

    /**
     * Marca atividade agora (chamado nos hooks de add/update de tarefas).
     */
    public static function touch(int $projectId): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now      = date('Y-m-d H:i:s');
        $existing = self::getForProject($projectId);

        if ($existing) {
            $DB->update(
                self::getTable(),
                [
                    'last_activity' => $now,
                    'is_stalled'    => 0,
                    'stalled_since' => null,
                    'date_mod'      => $now,
                ],
                ['projects_id' => $projectId]
            );
        } else {
            $DB->insert(self::getTable(), [
                'projects_id'   => $projectId,
                'last_activity' => $now,
                'is_stalled'    => 0,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Hooks (registrados em setup.php)
    // ------------------------------------------------------------------

    public static function onTaskAdd(ProjectTask $task): void
    {
        $projectId = (int) ($task->fields['projects_id'] ?? 0);
        if ($projectId > 0) {
            self::touch($projectId);
        }
    }

    public static function onProjectUpdate(Project $project): void
    {
        self::touch((int) $project->getID());
    }

    /**
     * Varre projetos sem atividade recente e marca como "parado".
     * Chamado pelo cron (Notification::cronProjectplusalerts).
     *
     * @return int quantidade de projetos marcados
     */
    public static function detectStalled(int $days = self::DEFAULT_STALLED_DAYS): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $limit = date('Y-m-d H:i:s', time() - ($days * DAY_TIMESTAMP));
        $count = 0;

        // Projetos ativos cuja última modificação (nativa) é antiga
        $iterator = $DB->request([
            'SELECT' => ['glpi_projects.id', 'glpi_projects.date_mod'],
            'FROM'   => 'glpi_projects',
            'WHERE'  => [
                'glpi_projects.is_deleted'   => 0,
                'glpi_projects.is_template'  => 0,
                'glpi_projects.percent_done' => ['<', 100],
                'glpi_projects.date_mod'     => ['<', $limit],
            ],
        ]);

        foreach ($iterator as $row) {
            $projectId = (int) $row['id'];
            $tracking  = self::getForProject($projectId);

            // Se o plugin registrou atividade mais recente que o core, respeita
            if (
                !empty($tracking['last_activity'])
                && $tracking['last_activity'] >= $limit
            ) {
                continue;
            }

            if (empty($tracking)) {
                $DB->insert(self::getTable(), [
                    'projects_id'   => $projectId,
                    'last_activity' => $row['date_mod'],
                    'is_stalled'    => 1,
                    'stalled_since' => date('Y-m-d H:i:s'),
                    'date_creation' => date('Y-m-d H:i:s'),
                ]);
                $count++;
            } elseif (empty($tracking['is_stalled'])) {
                $DB->update(
                    self::getTable(),
                    [
                        'is_stalled'    => 1,
                        'stalled_since' => date('Y-m-d H:i:s'),
                        'date_mod'      => date('Y-m-d H:i:s'),
                    ],
                    ['projects_id' => $projectId]
                );
                $count++;
            }
        }

        return $count;
    }
}

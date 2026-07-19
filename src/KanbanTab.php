<?php

namespace GlpiPlugin\Projectplus;

use CommonDBTM;
use CommonGLPI;
use Project;

/**
 * ProjectPlus — aba "Kanban (ProjectPlus)" na ficha nativa do projeto
 * (Etapa 7, Bloco 1.1).
 *
 * Substitui NA PRÁTICA a aba "Kanban" nativa do GLPI (que fica oculta via
 * JS quando a opção "hide_native_kanban" está ativa em Configurações —
 * mesmo mecanismo já usado para a aba "Custos" nativa, ver
 * public/js/hidenativekanban.js; nada é removido do core). Mostra o board
 * do plugin (colunas por fase, swimlanes Projeto/Responsável) já filtrado
 * para este projeto + seus subprojetos (Kanban::getBoardData($id)).
 *
 * Sem tabela própria: só reaproveita Kanban::getBoardData() e o mesmo
 * public/js/kanban.js do board global (função ProjectPlusKanban.mount(),
 * reusável para vários "widgets" independentes na mesma página).
 */
class KanbanTab extends CommonDBTM
{
    public static $rightname = 'plugin_projectplus_dashboard';

    public static function getTypeName($nb = 0)
    {
        return __('Kanban (ProjectPlus)', 'projectplus');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Project && Kanban::canAccess()) {
            $count = Kanban::countTasksForProject((int) $item->getID());
            return self::createTabEntry(__('Kanban (ProjectPlus)', 'projectplus'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Project && Kanban::canAccess()) {
            self::showForProject((int) $item->getID());
        }
        return true;
    }

    /**
     * Board do plugin escopado a este projeto (+ descendentes), montado
     * como um "widget" independente (mesmo JS do board global, IDs
     * únicos por projeto para não colidir se mais de uma instância
     * existir na página).
     */
    private static function showForProject(int $projectId): void
    {
        $data = Kanban::getBoardData($projectId);
        $uid  = 'pp-kb-widget-tab-' . $projectId;
        $did  = 'pp-kb-data-tab-' . $projectId;

        echo '<div class="pp-kb-widget pp-kb-widget--tab" id="' . htmlspecialchars($uid) . '">';
        echo '<div class="pp-kb-controls">';
        echo '<div class="pp-seg pp-kb-seg" role="group" aria-label="' . __('Agrupar por', 'projectplus') . '">';
        echo '<button type="button" class="pp-seg__btn pp-seg__btn--active" data-pp-lane="project">' . __('Projeto', 'projectplus') . '</button>';
        echo '<button type="button" class="pp-seg__btn" data-pp-lane="responsible">' . __('Responsável', 'projectplus') . '</button>';
        echo '</div>';
        echo '<label class="pp-mt-toggle"><input type="checkbox" data-pp-role="done"> '
            . __('Mostrar tarefas concluídas', 'projectplus') . '</label>';
        echo '<input type="search" class="pp-tablesearch" data-pp-role="search" placeholder="'
            . __('Buscar tarefa ou projeto…', 'projectplus') . '">';
        echo '</div>';
        echo '<div class="pp-kb-scroll" data-role="board"><p class="projectplus-muted">'
            . __('Carregando o Kanban…', 'projectplus') . '</p></div>';
        echo '</div>';

        // Lição nº 12: JSON embutido — o json_encode padrão já escapa "/"
        // (sem JSON_UNESCAPED_SLASHES), o que neutraliza "</script>" em
        // nomes de tarefa/projeto (mesmo comportamento do filtro
        // |json_encode do Twig usado nas outras telas).
        echo '<script id="' . htmlspecialchars($did) . '" type="application/json">'
            . json_encode($data) . '</script>';

        // A aba é carregada via AJAX pelo próprio GLPI (jQuery .html()),
        // que executa <script> embutido; o polling é só uma rede de
        // segurança caso public/js/kanban.js (carregado globalmente,
        // ver setup.php) ainda não tenha registrado window.ProjectPlusKanban.
        echo '<script>
(function () {
    function boot() {
        if (window.ProjectPlusKanban && typeof window.ProjectPlusKanban.mount === "function") {
            window.ProjectPlusKanban.mount(' . json_encode($uid) . ', ' . json_encode($did) . ', { defaultLane: "project" });
        } else {
            setTimeout(boot, 150);
        }
    }
    boot();
})();
</script>';
    }
}

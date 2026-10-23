<?php

/**
 * ProjectPlus — tela "Orçamento": custos consolidados por projeto (aba
 * Orçamento da barra superior; layout do mockup no Bloco N-8a).
 *
 * Lista, por projeto raiz (ou um projeto escolhido no filtro), TODOS os
 * lançamentos: aba nativa "Custos" do projeto + custos por tarefa (plugin)
 * + os mesmos itens de todos os subprojetos, com totais e saldo do teto.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Budget;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\DateFmt;
use GlpiPlugin\Projectplus\I18nJs;
use GlpiPlugin\Projectplus\Scope;
use GlpiPlugin\Projectplus\Url;
use GlpiPlugin\Projectplus\Favorite;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
/** @var \DBmysql $DB */
global $CFG_GLPI, $DB;

Session::checkRight('plugin_projectplus_costs', READ);

// Bloco L: entrando sem nada na URL, abre com o filtro favorito.
Favorite::applyRedirect('costs', 'front/costs.php');

Html::header(
    __('Orçamento', 'projectplus'),
    '', // \Html::header ignora o 2o argumento no GLPI 11 (Bloco 4a)
    'tools',
    Dashboard::class
);

// Dicionario de traducao do JavaScript (Etapa 6, Bloco 3b): imprime o
// <script type="application/json" id="pp-i18n"> que public/js/i18n.js le.
// Tem que vir DEPOIS do Html::header (o echo cai dentro do body) e ANTES
// dos <script> das telas.
I18nJs::render();

$filterId = (int) ($_GET['project'] ?? 0);

// Escopo (Etapa 8, Bloco 4): mesma regra das demais telas — padrão é o
// MAIOR escopo do perfil e ?scope=mine reduz ao pessoal. Com escopo ativo
// a lista é PLANA (só os projetos que o usuário vê, sem descer para
// subprojetos de terceiros — mesma decisão do Bloco 3).
$scopeIds        = Scope::projectIds();   // null = sem restrição
$scopeFlat       = ($scopeIds !== null);
$scopeCanExpand  = Scope::canExpand();
$scopeIsExpanded = Scope::isExpanded();
$scopeQuery      = [];
if ($filterId > 0) {
    $scopeQuery['project'] = $filterId;
}
if ($scopeIsExpanded) {
    $scopeQuery['scope'] = 'mine';
}
$scopeToggleUrl = Url::to('front/costs.php')
    . ('?' . http_build_query(Favorite::markAll($scopeQuery))); // Bloco L: Ver tudo nunca vai com URL vazia

// Projetos para o seletor do filtro: raízes (sem escopo) ou exatamente os
// projetos do escopo (que podem ser subprojetos).
$rootsWhere = [
    'is_deleted'  => 0,
    'is_template' => 0,
] + getEntitiesRestrictCriteria('glpi_projects');
if ($scopeFlat) {
    $rootsWhere['id'] = Scope::inList($scopeIds);
} else {
    $rootsWhere['projects_id'] = 0;
}

$allRoots = [];
foreach (
    $DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => 'glpi_projects',
        'WHERE'  => $rootsWhere,
        'ORDER'  => 'name',
    ]) as $row
) {
    $allRoots[] = ['id' => (int) $row['id'], 'name' => $row['name']];
}

// Projetos do relatório: todos os do escopo, ou apenas o filtrado
// (o filtro NUNCA amplia o escopo — interseção dos dois).
$where = [
    'is_deleted'  => 0,
    'is_template' => 0,
] + getEntitiesRestrictCriteria('glpi_projects');
if ($filterId > 0) {
    $where['id'] = ($scopeFlat && !in_array($filterId, array_map('intval', $scopeIds), true))
        ? [0]
        : [$filterId];
} elseif ($scopeFlat) {
    $where['id'] = Scope::inList($scopeIds);
} else {
    $where['projects_id'] = 0;
}

$report = [];
$listedIds = [];
foreach (
    $DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => 'glpi_projects',
        'WHERE'  => $where,
        'ORDER'  => 'name',
    ]) as $root
) {
    $rootId = (int) $root['id'];
    $listedIds[$rootId] = true;

    // Lançamentos do projeto + de todos os descendentes
    $entries = Budget::getEntriesForProject($rootId, __('Projeto', 'projectplus'));
    $visited = [];
    $descendants = Budget::getDescendantIds($rootId, $visited);
    foreach (($scopeFlat ? [] : $descendants) as $childId) {
        $childRow = $DB->request([
            'SELECT' => ['name'],
            'FROM'   => 'glpi_projects',
            'WHERE'  => ['id' => $childId],
        ])->current();
        $entries = array_merge($entries, Budget::getEntriesForProject(
            $childId,
            sprintf(__('Subprojeto "%s"', 'projectplus'), $childRow['name'] ?? '—')
        ));
    }

    // Mais recentes primeiro (sem data por último)
    usort($entries, function (array $a, array $b): int {
        return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
    });

    $listed = 0.0;
    foreach ($entries as &$e) {
        $listed += $e['cost'];
        $e['cost_fmt'] = Budget::money($e['cost']);
        $e['date_fmt'] = !empty($e['date']) ? DateFmt::date($e['date']) : '—';
    }
    unset($e);

    // Bloco N-8a: os números do cabeçalho do cartão vêm TODOS do mesmo cálculo
    // (Budget::getForProject — o do Painel e do cron, com os descendentes).
    // No escopo pessoal a tabela lista só os lançamentos do próprio projeto; a
    // diferença (lançamentos dos subprojetos) vira uma linha própria no rodapé,
    // para o total da tabela fechar com o "Consumido" do cabeçalho.
    $budget  = Budget::getForProject($rootId);
    $planned = (float) $budget['planned'];
    $spent   = (float) $budget['spent_total'];
    $outside = $spent - $listed;
    $percent = $budget['percent'];

    $report[] = [
        'id'          => $rootId,
        'name'        => $root['name'],
        'url'         => Url::project($rootId),
        'entries'     => $entries,
        'entry_count' => count($entries),
        'sub_count'   => $scopeFlat ? 0 : count($descendants),
        'listed_fmt'  => Budget::money($listed),
        'outside_fmt' => abs($outside) >= 0.005 ? Budget::money($outside) : null,
        'total_fmt'   => Budget::money($spent),
        'has_ceiling' => $planned > 0,
        'planned_fmt' => Budget::money($planned),
        'balance_fmt' => $budget['balance'] !== null ? Budget::money((float) $budget['balance']) : null,
        'balance_neg' => $budget['balance'] !== null && (float) $budget['balance'] < 0,
        'percent'     => $percent,
        'bar'         => $percent !== null ? max(0, min(100, (int) $percent)) : 0,
        'state'       => Budget::stateFor($percent),
        // usados só no resumo (Budget::costsSummary)
        '_sum'        => [
            'spent'   => $spent,
            'planned' => $planned,
            'balance' => $budget['balance'],
            'state'   => Budget::stateFor($percent),
            'entries' => count($entries),
            'nested'  => false,
        ],
    ];
}

// Bloco N-8a: no escopo pessoal a lista é plana e pode trazer pai E subprojeto —
// o gasto do subprojeto já está dentro do do pai, então ele não entra nas somas.
if ($scopeFlat && count($listedIds) > 1) {
    foreach ($report as &$card) {
        foreach (Dashboard::projectAncestors($card['id']) as $ancestorId) {
            if (isset($listedIds[$ancestorId])) {
                $card['_sum']['nested'] = true;
                break;
            }
        }
    }
    unset($card);
}

$sum = Budget::costsSummary(array_column($report, '_sum'));
$summary = [
    'projects'     => $sum['projects'],
    'entries'      => $sum['entries'],
    'top'          => $sum['top'],
    'consumed_fmt' => Budget::money($sum['consumed']),
    'with_ceiling' => $sum['with_ceiling'],
    'planned_fmt'  => $sum['with_ceiling'] > 0 ? Budget::money($sum['planned']) : null,
    'balance_fmt'  => $sum['with_ceiling'] > 0 ? Budget::money($sum['balance']) : null,
    'balance_neg'  => $sum['balance'] < 0,
    'warn'         => $sum['warn'],
    'over'         => $sum['over'],
    'warn_percent' => Budget::warnPercent(),
];

TemplateRenderer::getInstance()->display(
    '@projectplus/costs.html.twig',
    [
        'plugin_web_dir' => Url::base(),
        'glpi_root'      => $CFG_GLPI['root_doc'] ?? '',
        'report'         => $report,
        'projects_list'  => $allRoots,
        'filter_project' => $filterId,
        'generated_at'   => DateFmt::now(),
        'summary'        => $summary, // Bloco N-8a
        'can_templates'  => Session::haveRight('config', UPDATE),
        'nav'             => Access::sidebar(),
        'fav'            => Favorite::payload('costs'), // Bloco L
        'filter_scope'      => $scopeIsExpanded ? '' : 'mine',
        'scope_can_expand'  => $scopeCanExpand,
        'scope_is_expanded' => $scopeIsExpanded,
        'scope_toggle_url'  => $scopeToggleUrl,
    ]
);

Html::footer();

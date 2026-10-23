<?php

/**
 * ProjectPlus — página do painel (requisitos 1 e 2).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Budget;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\DateFmt;
use GlpiPlugin\Projectplus\I18nJs;
use GlpiPlugin\Projectplus\Scope;
use GlpiPlugin\Projectplus\TypePhase;
use GlpiPlugin\Projectplus\Templates;
use GlpiPlugin\Projectplus\Url;
use GlpiPlugin\Projectplus\Favorite;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('plugin_projectplus_dashboard', READ);

// Bloco L: entrando sem nada na URL, abre com o filtro favorito.
Favorite::applyRedirect('dashboard', 'front/dashboard.php');

Html::header(
    Dashboard::getMenuName(),
    '', // \Html::header ignora o 2o argumento no GLPI 11 (Bloco 4a)
    'tools',
    Dashboard::class
);

// Dicionario de traducao do JavaScript (Etapa 6, Bloco 3b): imprime o
// <script type="application/json" id="pp-i18n"> que public/js/i18n.js le.
// Tem que vir DEPOIS do Html::header (o echo cai dentro do body) e ANTES
// dos <script> das telas.
I18nJs::render();

// Caminho do template base do GLPI 11, validado em homologação.
// (layout/base.html.twig) pode variar conforme a versão do GLPI.
// Este Twig é autocontido (não estende layout) para reduzir o risco.
// Filtro de período (Bloco 3.2)
$from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : null;
$until = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['until'] ?? '') ? $_GET['until'] : null;

// Escopo (Etapa 8, Bloco 3): a tela abre sempre no PESSOAL; quem tem
// direito de escopo pode ampliar via ?scope=all (sem memória em sessão).
$scopeMode           = Scope::mode();
$scopeProjectIds     = Scope::projectIds($scopeMode);      // projetos exatos (equipe/gerência)
$scopeMyTaskIds      = Scope::myTaskIds($scopeMode);       // personal e managed: minhas tarefas (F-1)
$scopeTaskProjectIds = Scope::taskProjectIds($scopeMode);  // managed: projetos alcançáveis (subprojetos)

// Tipo de projeto (Etapa 9). Aqui o tipo é OPCIONAL — ao contrário dos
// Kanbans, a Visão geral é justamente a tela que cruza departamentos: sem
// tipo escolhido ela mostra tudo e o donut de fase (que misturaria os
// vocabulários de todos os setores) vira "Projetos por tipo".
$typeId      = TypePhase::requestedTypeOrNull();
$typeOptions = TypePhase::selectorTypes();

// ---- Foco em um projeto (Bloco A, 28/09/2026) ----
// `?project=ID` transforma a Visão geral na TELA DO PROJETO: a lista passa
// a mostrar só ele, com o painel de tarefas aberto pelo JS. É para onde
// apontam agora o nome do projeto no painel, os cartões dos dois Kanbans e
// o redirect depois de criar a partir de um modelo — nada mais manda o
// usuário para a tela NATIVA, que exige direito nativo de projeto.
// Período e tipo são ignorados no foco: eles só serviriam para esconder o
// projeto que o usuário acabou de pedir para ver.
$focusId     = (int) ($_GET['project'] ?? 0);
$focusTaskId = (int) ($_GET['task'] ?? 0);
$focusName       = '';
$focusError      = false;
$focusProjectIds = null;
if ($focusId > 0) {
    $focusProject = new Project();
    if (
        $focusProject->getFromDB($focusId)
        && !(int) $focusProject->fields['is_deleted']
        && Session::haveAccessToEntity((int) $focusProject->fields['entities_id'])
        && Scope::canSeeProject($focusId, $scopeMode)
    ) {
        $focusName       = (string) $focusProject->fields['name'];
        $scopeProjectIds = [$focusId];
        $from            = null;
        $until           = null;
        $typeId          = null;
        // A-2: tarefas e indicadores do projeto E dos subprojetos dele.
        $focusProjectIds = array_merge([$focusId], Budget::getDescendantIds($focusId));
    } else {
        // Sem redirect: o Html::header já saiu. A tela abre normal com um
        // aviso, em vez de morrer numa página de erro.
        $focusId    = 0;
        $focusError = true;
    }
}

$data = Dashboard::getData(
    $from,
    $until,
    $scopeProjectIds,
    $scopeMyTaskIds,
    $scopeTaskProjectIds,
    $typeId,
    $focusProjectIds
);

// Botão "Ver tudo" / "Ver só os meus" — preserva o filtro de período na URL.
$scopeCanExpand  = Scope::canExpand();
$scopeIsExpanded = Scope::isExpanded();
$scopeToggle     = [];
if ($from) {
    $scopeToggle['from'] = $from;
}
if ($until) {
    $scopeToggle['until'] = $until;
}
if ($typeId !== null) {
    $scopeToggle['type'] = $typeId;
}
if ($focusId > 0) {
    // Bloco A: alternar escopo não deve tirar o usuário da tela do projeto.
    $scopeToggle['project'] = $focusId;
}
if ($scopeIsExpanded) {
    // Do escopo amplo (padrão), o botão oferece REDUZIR ao pessoal.
    $scopeToggle['scope'] = 'mine';
}
$scopeToggleUrl = Url::to('front/dashboard.php')
    . ('?' . http_build_query(Favorite::markAll($scopeToggle))); // Bloco L: Ver tudo nunca vai com URL vazia

$ganttActive = false;
$plugin      = new Plugin();
if (method_exists($plugin, 'isActivated')) {
    $ganttActive = $plugin->isActivated('gantt');
}

// ---- Dados para o modal "Novo projeto" (Etapa 3, Bloco 2) ----
global $DB;

$states = [];
foreach (Dashboard::getStatesMap() as $sid => $s) {
    $states[] = ['id' => $sid, 'name' => $s['name'], 'color' => $s['color']];
}

// Usuários para Gestor/Responsável (rótulo por formatUserName, ordem pelo
// rótulo). Bloco D-2a: o mesmo método serve Minhas tarefas.
$users = Dashboard::userOptions();

// Tipos de projeto para o modal "Novo projeto" (Etapa 9): escolher o tipo é
// o que faz o campo Estado listar só as fases DAQUELE conjunto.
$ptypes = [];
foreach (TypePhase::projectTypes() as $tid => $tname) {
    $ptypes[] = ['id' => (int) $tid, 'name' => $tname];
}

$parents = [];
foreach (
    $DB->request([
        'SELECT' => ['id', 'name'],
        'FROM'   => 'glpi_projects',
        'WHERE'  => ['is_deleted' => 0, 'is_template' => 0]
            + getEntitiesRestrictCriteria('glpi_projects'),
        'ORDER'  => 'name',
    ]) as $row
) {
    $parents[] = ['id' => (int) $row['id'], 'name' => $row['name']];
}

// ---- Bloco N-2: dados de apresentação do Painel (cabeçalho, painéis, atenção) ----
// Linha do título: data por extenso no idioma do usuário · projetos ativos · entidade.
$todayLabel = DateFmt::longToday(); // Bloco N-3: extraído para o DateFmt
$monthName  = DateFmt::monthName();
$entityName = Dropdown::getDropdownName('glpi_entities', (int) Session::getActiveEntity());
$activeCount = (int) ($data['kpis']['active'] ?? 0);
$headSub = $todayLabel . ' · '
    . sprintf(_n('%d projeto ativo', '%d projetos ativos', $activeCount, 'projectplus'), $activeCount)
    . ' · ' . $entityName;
$headMeta = sprintf(__('Atualizado às %s', 'projectplus'), date('H:i'));

// Situação dos projetos: no prazo (planejado + em andamento) × atrasado × concluído.
$sc = $data['status_chart'];
$onTime = (int) ($sc['in_progress'] ?? 0) + (int) ($sc['planned'] ?? 0);
$situationItems = [
    ['name' => __('No prazo', 'projectplus'),  'color' => 'var(--pp-ok)',     'count' => $onTime],
    ['name' => __('Atrasado', 'projectplus'),  'color' => 'var(--pp-danger)', 'count' => (int) ($sc['overdue'] ?? 0)],
    ['name' => __('Concluído', 'projectplus'), 'color' => 'var(--pp-muted)',  'count' => (int) ($sc['done'] ?? 0)],
];
$situation = Dashboard::donutSegments($situationItems);
$situation['items']  = $situationItems;
$situation['center'] = $situation['total'] > 0 ? (round(100 * $onTime / $situation['total']) . '%') : '—';

// Segundo donut: por tipo (sem tipo escolhido) ou por fase (com tipo).
$secondDonut = Dashboard::donutSegments($typeId === null ? $data['type_chart'] : $data['phase_chart']);
$kpiTypes    = count(array_filter($data['type_chart'], static fn($t) => (int) ($t['count'] ?? 0) > 0));

// Tarefas por fase: barras proporcionais ao total.
$taskTotal = 0;
foreach ($data['task_state_chart'] as $t) {
    $taskTotal += (int) $t['count'];
}
$taskBars = [];
foreach ($data['task_state_chart'] as $t) {
    $taskBars[] = [
        'name'  => $t['name'],
        'color' => $t['color'],
        'count' => (int) $t['count'],
        'pct'   => $taskTotal > 0 ? round(100 * (int) $t['count'] / $taskTotal, 1) : 0,
    ];
}

// Precisa de atenção — contado sobre a MESMA lista de projetos da tela.
$noEnd = 0;
$overBudget = 0;
foreach ($data['projects'] as $p) {
    if (empty($p['plan_end_date'])) {
        $noEnd++;
    }
    if (!empty($p['budget']) && in_array($p['budget']['state'] ?? '', ['warn', 'over'], true)) {
        $overBudget++;
    }
}
$attention = [];
if (Access::can('tasks')) {
    $attention[] = ['label' => __('Tarefas atrasadas', 'projectplus'), 'count' => (int) ($data['kpis']['tasks_overdue'] ?? 0), 'level' => 'danger'];
}
$attention[] = ['label' => __('Projetos atrasados', 'projectplus'), 'count' => (int) ($data['kpis']['overdue'] ?? 0), 'level' => 'danger'];
$attention[] = ['label' => __('Projetos sem prazo final', 'projectplus'), 'count' => $noEnd, 'level' => 'warn'];
if (Access::can('costs')) {
    $attention[] = ['label' => __('Orçamento acima do alerta', 'projectplus'), 'count' => $overBudget, 'level' => 'warn'];
}

TemplateRenderer::getInstance()->display(
    '@projectplus/dashboard.html.twig',
    [
        'plugin_web_dir'  => Url::base(),
        'glpi_root'       => $CFG_GLPI['root_doc'] ?? '',
        'gantt_active'    => $ganttActive,
        'kpis'             => $data['kpis'],
        'status_chart'     => $data['status_chart'],
        'tasks_chart'      => $data['tasks_chart'],
        'phase_chart'      => $data['phase_chart'],
        'task_state_chart' => $data['task_state_chart'],
        'priority_chart'   => $data['priority_chart'],
        'open_tasks'       => $data['open_tasks'],
        'filter_from'     => $from ?? '',
        'filter_until'    => $until ?? '',
        'projects'        => $data['projects'],
        'states'          => $states,
        'users'           => $users,
        // Bloco D-2a: chips editáveis de responsáveis (só gestor)
        'can_team'        => Access::canManageTaskTeams(),
        // Bloco M-2 / O-1b: 🗑 da tarefa — Super-Admin, gestor de algum
        // projeto, ou Interagir + Excluir nativo (servidor confere por tarefa).
        'can_delete_tasks'   => Access::canDeleteTasks(),
        'can_delete_project' => Access::can('projects', UPDATE) && Session::haveRight('project', DELETE),
        // Bloco D-3a: faixa "Projeto" no painel (equipe, datas, fase, %, auto)
        'can_edit_project' => Access::canEditProjects(),
        'parents'         => $parents,
        // Etapa 9 — filtro de tipo e donut adaptativo
        'ptypes'          => $ptypes,
        'type_options'    => $typeOptions,
        'filter_type'     => $typeId,
        'type_chart'      => $data['type_chart'],
        'filter_scope'    => $scopeIsExpanded ? '' : 'mine',
        // Bloco F-1: painel do projeto só com as MINHAS tarefas (fora do
        // "Ver todos") — o JS escolhe a mensagem de lista vazia por isto.
        'tasks_mine'      => $scopeMyTaskIds !== null,
        // O-1b: projetos da árvore que o usuário GERENCIA (só no modo gestor;
        // no "Só os meus" a lista é a pessoal) — nesses o painel lista todas
        // as tarefas e a mensagem de vazio é a neutra.
        'managed_projects' => Scope::mode() === 'managed' ? Scope::managedTreeIds() : [],
        // Conjunto de fases por tipo, para o campo Estado do modal seguir o
        // tipo escolhido sem recarregar a página.
        'phases_by_type'  => TypePhase::phasesByType(),
        'current_user_id' => (int) Session::getLoginUserID(),
        'csrf_token'      => Session::getNewCSRFToken(),
        'can_create'      => Session::haveRight('plugin_projectplus_projects', CREATE),
        'can_templates'   => Session::haveRight('config', UPDATE),
        // Etapa 8, Bloco 4: o painel some com o que o perfil não pode ver —
        // coluna/campo de Orçamento sem o direito de Custos (Cliente e
        // Colaborador) e bloco de Tarefas sem o direito de Tarefas (Cliente).
        'can_costs'       => Access::can('costs'),
        // Bloco J: modelos para o campo "Modelo" do modal Novo projeto
        'templates'       => Access::can('templates') ? Templates::pickerOptions() : [],
        'today'           => date('Y-m-d'), // Bloco J: início padrão no modal (fuso do servidor, não UTC do navegador)
        'can_tasks'       => Access::can('tasks'),
        'nav'             => Access::sidebar(),
        'fav'            => Favorite::payload('dashboard'), // Bloco L
        'scope_can_expand'  => $scopeCanExpand,
        'scope_is_expanded' => $scopeIsExpanded,
        'scope_toggle_url'  => $scopeToggleUrl,
        // Bloco A — foco em um projeto (?project=ID[&task=ID])
        'focus_project_id'   => $focusId,
        'focus_project_name' => $focusName,
        'focus_task_id'      => $focusTaskId,
        'focus_error'        => $focusError,
        // Bloco N-2 — apresentação do Painel
        'head_sub'     => $headSub,
        'head_meta'    => $headMeta,
        'month_name'   => $monthName,
        'kpi_types'    => $kpiTypes,
        'situation'    => $situation,
        'second_donut' => $secondDonut,
        'task_bars'    => $taskBars,
        'attention'    => $attention,
    ]
);

Html::footer();

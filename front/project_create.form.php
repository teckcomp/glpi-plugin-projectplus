<?php

/**
 * ProjectPlus — cria um projeto a partir do modal do painel (Etapa 3, Bloco 2).
 * Usa Project::add() nativo: o projeto criado é 100% nativo (Kanban, GANTT, buscas).
 */

use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Budget;
use GlpiPlugin\Projectplus\ProjectTracking;
use GlpiPlugin\Projectplus\Url;

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('project', CREATE);

$name = trim((string) ($_POST['name'] ?? ''));
if ($name === '') {
    Session::addMessageAfterRedirect(__('Informe o nome do projeto', 'projectplus'), true, ERROR);
    Html::back();
}

$input = [
    'name'             => $name,
    'entities_id'      => Session::getActiveEntity(),
    'projects_id'      => (int) ($_POST['projects_id'] ?? 0),      // pai (0 = raiz)
    'projectstates_id' => (int) ($_POST['projectstates_id'] ?? 0),
    // Etapa 9: o tipo passou a ser escolhido no modal — é ele que define o
    // conjunto de fases do projeto (colunas do Kanban, filtro da Visão geral).
    'projecttypes_id'  => (int) ($_POST['projecttypes_id'] ?? 0),
    'priority'         => min(6, max(1, (int) ($_POST['priority'] ?? 3))),
    'users_id'         => (int) ($_POST['users_id'] ?? Session::getLoginUserID()),
    'content'          => $_POST['content'] ?? '',
    'date'             => date('Y-m-d H:i:s'),
];

if (!empty($_POST['plan_start_date'])) {
    $input['plan_start_date'] = $_POST['plan_start_date'] . ' 09:00:00';
}
if (!empty($_POST['plan_end_date'])) {
    $input['plan_end_date'] = $_POST['plan_end_date'] . ' 18:00:00';
}

$project   = new Project();
$projectId = $project->add($input);

if (!$projectId) {
    Session::addMessageAfterRedirect(__('Falha ao criar o projeto', 'projectplus'), true, ERROR);
    Html::back();
}

// Indicadores do plugin
ProjectTracking::touch((int) $projectId);

// Teto de orçamento opcional já na criação (Etapa 2) — Bloco 4: só para
// quem tem o direito de Custos (o campo nem aparece para os demais).
$budgetRaw = str_replace(',', '.', (string) ($_POST['budget_planned'] ?? '0'));
$budget    = max(0, (float) $budgetRaw);
if ($budget > 0 && Access::can('costs', UPDATE)) {
    Budget::setPlanned((int) $projectId, $budget);
}

Session::addMessageAfterRedirect(
    sprintf(__('Projeto "%s" criado', 'projectplus'), $name),
    true,
    INFO
);
Html::redirect(Url::to('front/dashboard.php'));

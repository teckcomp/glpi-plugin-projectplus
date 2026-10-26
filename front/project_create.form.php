<?php

/**
 * ProjectPlus — cria um projeto a partir do modal do painel (Etapa 3, Bloco 2).
 * Usa Project::add() nativo: o projeto criado é 100% nativo (Kanban, GANTT, buscas).
 */

use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Budget;
use GlpiPlugin\Projectplus\ProjectManager;
use GlpiPlugin\Projectplus\ProjectTracking;
use GlpiPlugin\Projectplus\TemplateCloner;
use GlpiPlugin\Projectplus\Url;

include('../../../inc/includes.php');

/**
 * O-1c (26/10/2026): gestor escolhido no modal; sem escolha (ou usuário
 * com Visão do cliente — R6), quem cria vira o gestor (R4). O hook de
 * criação grava a tabela de gestores a partir deste campo.
 */
function pp_creation_manager(): int
{
    $chosen = (int) ($_POST['users_id'] ?? 0);
    if ($chosen > 0 && ProjectManager::isClientUser($chosen)) {
        Session::addMessageAfterRedirect(
            __('Usuário com Visão do cliente não pode ser gestor', 'projectplus'),
            true,
            WARNING
        );
        $chosen = 0;
    }
    return $chosen > 0 ? $chosen : (int) Session::getLoginUserID();
}

Session::checkLoginUser();
// O-1g (26/10/2026) — matriz única: "Projetos: Criar" da aba ProjectPlus
// (o mesmo direito que mostra o botão Novo projeto). Antes: nativo
// "Projeto: Criar". A trava da Visão do cliente (O-1e) está em Access::can.
Access::require('projects', CREATE);

$name = trim((string) ($_POST['name'] ?? ''));
if ($name === '') {
    Session::addMessageAfterRedirect(__('Informe o nome do projeto', 'projectplus'), true, ERROR);
    Html::back();
}

// Bloco J (21/10/2026): criar a partir de um MODELO do plugin direto no
// modal. O formulário vem pré-preenchido com o modelo (gestor, tipo, fase,
// orçamento, descrição) e o que o usuário deixar nele sobrepõe o modelo;
// tarefas, subprojetos e o fim planejado vêm sempre do modelo
// (TemplateCloner::instantiate). Gate = ver Modelos.
$templateId = (int) ($_POST['template_id'] ?? 0);
if ($templateId > 0) {
    if (!Access::can('templates')) {
        Session::addMessageAfterRedirect(__('Sem permissão', 'projectplus'), true, ERROR);
        Html::back();
    }
    $startDate = (string) ($_POST['plan_start_date'] ?? '');
    if ($startDate === '') {
        Session::addMessageAfterRedirect(__('Informe o nome do projeto e a data de início', 'projectplus'), true, ERROR);
        Html::back();
    }
    $overrides = [
        'users_id'         => (int) ($_POST['users_id'] ?? 0),
        'projecttypes_id'  => (int) ($_POST['projecttypes_id'] ?? 0),
        'projectstates_id' => (int) ($_POST['projectstates_id'] ?? 0),
        'priority'         => (int) ($_POST['priority'] ?? 0),
        'content'          => (string) ($_POST['content'] ?? ''),
    ];
    if (Access::can('costs', UPDATE)) {
        $overrides['budget'] = max(0, (float) str_replace(',', '.', (string) ($_POST['budget_planned'] ?? '0')));
    }
    $result = TemplateCloner::instantiate($templateId, $name, $startDate, [
        'entities_id' => Session::getActiveEntity(),
        'projects_id' => (int) ($_POST['projects_id'] ?? 0),
        'overrides'   => $overrides,
    ]);
    if (empty($result['ok']) || empty($result['projects_id'])) {
        Session::addMessageAfterRedirect($result['message'] ?? __('Falha ao criar o projeto', 'projectplus'), true, ERROR);
        Html::back();
    }
    $newId = (int) $result['projects_id'];
    TemplateCloner::ensureCreatorInTeam($newId);
    Session::addMessageAfterRedirect($result['message'], true, INFO);
    Html::redirect(Url::project($newId));
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
    'users_id'         => pp_creation_manager(),
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

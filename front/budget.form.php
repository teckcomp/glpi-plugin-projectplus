<?php

/**
 * ProjectPlus — grava o teto de orçamento de um projeto.
 */

use GlpiPlugin\Projectplus\Budget;

include('../../../inc/includes.php');

Session::checkLoginUser();

$projectId = (int) ($_POST['projects_id'] ?? 0);

$project = new Project();
if ($projectId <= 0 || !$project->getFromDB($projectId)) {
    Html::displayErrorAndDie(__('Projeto não encontrado', 'projectplus'));
}
if (!$project->canUpdateItem()) {
    Html::displayRightError();
}

// Aceita vírgula decimal (pt-BR) ou ponto
$raw   = str_replace(',', '.', (string) ($_POST['budget_planned'] ?? '0'));
$value = max(0, (float) $raw);

Budget::setPlanned($projectId, $value);

Session::addMessageAfterRedirect(__('Orçamento atualizado', 'projectplus'), true, INFO);
Html::back();

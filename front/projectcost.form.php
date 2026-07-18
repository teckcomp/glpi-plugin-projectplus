<?php

/**
 * ProjectPlus — lançamento/exclusão de custos por projeto (aba própria).
 *
 * POST add=1     name, cost, [date, comment], projects_id
 * POST delete=1  id, projects_id
 *
 * CSRF: validado automaticamente pelo core em todo POST.
 */

use GlpiPlugin\Projectplus\Budget;
use GlpiPlugin\Projectplus\ProjectCost;

include('../../../inc/includes.php');

Session::checkLoginUser();

/** @var \DBmysql $DB */
global $DB;

if (!ProjectCost::canEditCosts()) {
    Session::addMessageAfterRedirect(
        __('Sem permissão para alterar custos', 'projectplus'),
        false,
        ERROR
    );
    Html::back();
}

$projectId = (int) ($_POST['projects_id'] ?? 0);
$project   = new Project();
if ($projectId <= 0 || !$project->getFromDB($projectId)) {
    Session::addMessageAfterRedirect(__('Projeto não encontrado', 'projectplus'), false, ERROR);
    Html::back();
}

$now = date('Y-m-d H:i:s');

if (isset($_POST['add'])) {
    $name = trim((string) ($_POST['name'] ?? ''));
    $cost = (float) str_replace(',', '.', (string) ($_POST['cost'] ?? '0'));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '')
        ? $_POST['date'] : date('Y-m-d');

    if ($name === '' || $cost < 0) {
        Session::addMessageAfterRedirect(
            __('Descrição e custo válido são obrigatórios', 'projectplus'),
            false,
            ERROR
        );
        Html::back();
    }

    $DB->insert(ProjectCost::getTable(), [
        'projects_id'   => $projectId,
        'name'          => $name,
        'date'          => $date,
        'cost'          => $cost,
        'comment'       => trim((string) ($_POST['comment'] ?? '')),
        'users_id'      => (int) Session::getLoginUserID(),
        'date_creation' => $now,
        'date_mod'      => $now,
    ]);

    Session::addMessageAfterRedirect(__('Custo lançado', 'projectplus'), false, INFO);
} elseif (isset($_POST['delete'])) {
    $DB->delete(ProjectCost::getTable(), [
        'id'          => (int) ($_POST['id'] ?? 0),
        'projects_id' => $projectId, // trava: só apaga custo deste projeto
    ]);

    Session::addMessageAfterRedirect(__('Custo excluído', 'projectplus'), false, INFO);
}

Budget::refreshSpent($projectId);

Html::back();

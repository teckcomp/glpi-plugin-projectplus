<?php

/**
 * ProjectPlus — lançamento/exclusão de custos por tarefa (aba Custos).
 *
 * POST add=1     name, cost, [date, comment], projecttasks_id
 * POST delete=1  id, projecttasks_id
 *
 * CSRF: validado automaticamente pelo core em todo POST (Html::closeForm
 * inclui o token nos formulários da aba).
 */

use GlpiPlugin\Projectplus\Budget;
use GlpiPlugin\Projectplus\TaskCost;

include('../../../inc/includes.php');

Session::checkLoginUser();

/** @var \DBmysql $DB */
global $DB;

if (!TaskCost::canEditCosts()) {
    Session::addMessageAfterRedirect(
        __('Sem permissão para alterar custos', 'projectplus'),
        false,
        ERROR
    );
    Html::back();
}

$taskId = (int) ($_POST['projecttasks_id'] ?? 0);
$task   = new ProjectTask();
if ($taskId <= 0 || !$task->getFromDB($taskId)) {
    Session::addMessageAfterRedirect(__('Tarefa não encontrada', 'projectplus'), false, ERROR);
    Html::back();
}

$now       = date('Y-m-d H:i:s');
$projectId = (int) ($task->fields['projects_id'] ?? 0);

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

    $DB->insert(TaskCost::getTable(), [
        'projecttasks_id' => $taskId,
        'name'            => $name,
        'date'            => $date,
        'cost'            => $cost,
        'comment'         => trim((string) ($_POST['comment'] ?? '')),
        'users_id'        => (int) Session::getLoginUserID(),
        'date_creation'   => $now,
        'date_mod'        => $now,
    ]);

    Session::addMessageAfterRedirect(__('Custo lançado', 'projectplus'), false, INFO);
} elseif (isset($_POST['delete'])) {
    $DB->delete(TaskCost::getTable(), [
        'id'              => (int) ($_POST['id'] ?? 0),
        'projecttasks_id' => $taskId, // trava: só apaga custo desta tarefa
    ]);

    Session::addMessageAfterRedirect(__('Custo excluído', 'projectplus'), false, INFO);
}

// Mantém o snapshot de orçamento do projeto coerente
if ($projectId > 0) {
    Budget::refreshSpent($projectId);
}

Html::back();

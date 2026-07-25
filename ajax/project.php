<?php

/**
 * ProjectPlus — operações de PROJETO pelo Kanban de projetos
 * (Etapa 8, Bloco 4, ajuste 4b.2).
 *
 * POST action=kanban_move   project_id, projectstates_id
 *
 * O CSRF é validado automaticamente pelo core (includes.php) em todo POST —
 * nunca chamar Session::checkCSRF aqui. Cada resposta devolve um token novo
 * em 'csrf' (uso único); o JS rotaciona.
 */

use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\ProjectTracking;
use GlpiPlugin\Projectplus\TaskDep;

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json; charset=UTF-8');

function pp_reply(array $payload): void
{
    $payload['csrf'] = Session::getNewCSRFToken();
    echo json_encode($payload);
    exit;
}

$action = $_POST['action'] ?? '';

// Mover projeto = alterar projeto: exige o direito do módulo (Projetos em
// UPDATE) E o direito nativo de projeto. O Cliente não passa por aqui.
$canUpdate = Access::can('projects', UPDATE) && Session::haveRight('project', UPDATE);

switch ($action) {
    case 'kanban_move':
        if (!$canUpdate) {
            pp_reply(['ok' => false, 'message' => __('Sem permissão', 'projectplus')]);
        }

        $project = new Project();
        if (!$project->getFromDB((int) ($_POST['project_id'] ?? 0))) {
            pp_reply(['ok' => false, 'message' => __('Projeto não encontrado', 'projectplus')]);
        }
        if (!$project->canUpdateItem()) {
            pp_reply(['ok' => false, 'message' => __('Sem permissão para alterar este projeto', 'projectplus')]);
        }

        $newState = (int) ($_POST['projectstates_id'] ?? 0);
        $oldState = (int) ($project->fields['projectstates_id'] ?? 0);
        if ($newState === $oldState) {
            pp_reply(['ok' => true, 'project_id' => (int) $project->getID(), 'state_id' => $newState]);
        }

        // MESMA regra da guarda de fase da ficha nativa
        // (TaskDep::onProjectPreUpdate, hook PRE_ITEM_UPDATE): projeto com
        // tarefa aberta ou subprojeto não concluído não vai para fase
        // finalizada. Aqui a recusa é ANTECIPADA para devolver a mensagem
        // ao JS — o hook apenas removeria o campo do update, e a tela
        // "voltaria sozinha" sem explicação.
        if ($newState > 0 && in_array($newState, TaskDep::finishedStateIds(), true)) {
            $open = TaskDep::projectOpenChildrenNames((int) $project->getID());
            if (!empty($open)) {
                pp_reply(['ok' => false, 'message' => sprintf(
                    __('Não é possível mover para uma fase finalizada — %d item(ns) aberto(s): %s', 'projectplus'),
                    count($open),
                    implode(', ', array_slice($open, 0, 5)) . (count($open) > 5 ? '…' : '')
                )]);
            }
        }

        $ok = $project->update(['id' => $project->getID(), 'projectstates_id' => $newState]);

        // Defesa: se algum outro hook reverter o campo (lição 7), o update
        // volta "true" sem ter mudado nada — confere no banco antes de dizer
        // ok ao JS, senão o cartão ficaria numa coluna que não é a real.
        $applied = $newState;
        if ($ok) {
            $fresh = new Project();
            if ($fresh->getFromDB((int) $project->getID())) {
                $applied = (int) ($fresh->fields['projectstates_id'] ?? $newState);
            }
        }
        if (!$ok || $applied !== $newState) {
            pp_reply([
                'ok'      => false,
                'state_id' => $applied,
                'message' => __('A fase não pôde ser alterada.', 'projectplus'),
            ]);
        }

        // Mesmo carimbo de atividade das demais telas do plugin
        ProjectTracking::touch((int) $project->getID());

        pp_reply(['ok' => true, 'project_id' => (int) $project->getID(), 'state_id' => $newState]);
        break;

    default:
        http_response_code(400);
        pp_reply(['ok' => false, 'message' => 'ação inválida']);
}

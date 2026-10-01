<?php

/**
 * ProjectPlus — operações de tarefa pelo painel (Etapa 3, Bloco 3).
 *
 * POST action=create   name, projects_id, [projecttasks_id, users_id,
 *                      plan_start_date, plan_end_date, projectstates_id]
 * POST action=state    task_id, projectstates_id
 * POST action=percent  task_id, percent (0-100)
 * POST action=complete task_id
 * POST action=auto_percent task_id, value (0|1) — liga/desliga o
 *                      "Calcular automaticamente" nativo (auto_percent_done)
 *
 * O CSRF é validado automaticamente pelo core (includes.php) em todo POST.
 * Cada resposta devolve um token novo em 'csrf' — o JS deve usá-lo na
 * próxima chamada (tokens são de uso único).
 */

use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\ProjectTracking;
use GlpiPlugin\Projectplus\TaskDep;

include('../../../inc/includes.php');

Session::checkLoginUser();

header('Content-Type: application/json; charset=UTF-8');

/**
 * Resposta padrão com token novo.
 */
function pp_reply(array $payload): void
{
    $payload['csrf'] = Session::getNewCSRFToken();
    echo json_encode($payload);
    exit;
}

/**
 * Subtarefas DIRETAS ainda abertas (percent < 100) de uma tarefa.
 * Regra (Fix 2): a mãe só pode ser concluída com todas as filhas fechadas —
 * aplicada recursivamente, já que cada filha-mãe passa pela mesma checagem.
 */
function pp_open_children(int $taskId): int
{
    /** @var \DBmysql $DB */
    global $DB;

    $row = $DB->request([
        'COUNT' => 'cpt',
        'FROM'  => 'glpi_projecttasks',
        'WHERE' => [
            'projecttasks_id' => $taskId,
            'percent_done'    => ['<', 100],
        ],
    ])->current();

    return (int) ($row['cpt'] ?? 0);
}

/**
 * A tarefa tem ao menos uma subtarefa direta? O cálculo automático nativo
 * (ProjectTask::recalculatePercentDone) é a MÉDIA das subtarefas — sem
 * nenhuma, o AVG do core volta NULL. Por isso só se liga com filhas.
 */
function pp_has_children(int $taskId): bool
{
    /** @var \DBmysql $DB */
    global $DB;

    $row = $DB->request([
        'COUNT' => 'cpt',
        'FROM'  => 'glpi_projecttasks',
        'WHERE' => ['projecttasks_id' => $taskId],
    ])->current();

    return (int) ($row['cpt'] ?? 0) > 0;
}

/**
 * Regra do Bloco 3 (Etapa 3): tarefa BLOQUEADA não pode ser concluída
 * enquanto houver bloqueadora aberta (percent_done < 100).
 * Devolve a mensagem de recusa, ou null se pode concluir.
 */
function pp_blocked_message(int $taskId): ?string
{
    $names = TaskDep::openBlockerNames($taskId);
    if (empty($names)) {
        return null;
    }
    return sprintf(
        __('Tarefa bloqueada — conclua antes: %s', 'projectplus'),
        implode(', ', array_slice($names, 0, 5))
        . (count($names) > 5 ? '…' : '')
    );
}

$action = $_POST['action'] ?? '';

// Criar: o GLPI 11 não tem CREATE em `projecttask` (ProjectTask::canCreate
// é `project` UPDATE) — o 1º termo nunca é verdadeiro; mantido como estava.
$canCreate = Session::haveRight('projecttask', CREATE) || Session::haveRight('project', UPDATE);

/**
 * Carrega a tarefa do POST e aplica a regra POR TAREFA de Access::canUpdateTask
 * (gestor, OU "Interagir" do módulo + ator da tarefa). Encerra a requisição
 * com a mensagem adequada quando não encontra ou não pode.
 * Correção de 22/09/2026: antes o teste era `projecttask` UPDATE, bit que o
 * GLPI 11 não tem — técnico com "Interagir" recebia "Sem permissão".
 */
function pp_task_for_update(string $module = 'tasks'): ProjectTask
{
    $task = new ProjectTask();
    if (!$task->getFromDB((int) ($_POST['task_id'] ?? 0))) {
        pp_reply(['ok' => false, 'message' => __('Tarefa não encontrada', 'projectplus')]);
    }
    if (!Access::canUpdateTask($task, $module)) {
        pp_reply(['ok' => false, 'message' => __('Sem permissão', 'projectplus')]);
    }
    return $task;
}

switch ($action) {
    case 'create':
        if (!$canCreate) {
            pp_reply(['ok' => false, 'message' => __('Sem permissão para criar tarefas', 'projectplus')]);
        }

        $name      = trim((string) ($_POST['name'] ?? ''));
        $projectId = (int) ($_POST['projects_id'] ?? 0);
        if ($name === '' || $projectId <= 0) {
            pp_reply(['ok' => false, 'message' => __('Nome e projeto são obrigatórios', 'projectplus')]);
        }

        $input = [
            'name'             => $name,
            'projects_id'      => $projectId,
            'projecttasks_id'  => (int) ($_POST['projecttasks_id'] ?? 0),
            'projectstates_id' => (int) ($_POST['projectstates_id'] ?? 0),
            'percent_done'     => 0,
        ];
        if (!empty($_POST['plan_start_date'])) {
            $input['plan_start_date'] = $_POST['plan_start_date'] . ' 09:00:00';
        }
        if (!empty($_POST['plan_end_date'])) {
            $input['plan_end_date'] = $_POST['plan_end_date'] . ' 18:00:00';
        }

        $task   = new ProjectTask();
        $taskId = $task->add($input);
        if (!$taskId) {
            pp_reply(['ok' => false, 'message' => __('Falha ao criar a tarefa', 'projectplus')]);
        }

        // Responsável (equipe da tarefa) — opcional
        $assignee = (int) ($_POST['users_id'] ?? 0);
        if ($assignee > 0) {
            $team = new ProjectTaskTeam();
            $team->add([
                'projecttasks_id' => (int) $taskId,
                'itemtype'        => 'User',
                'items_id'        => $assignee,
            ]);
        }

        ProjectTracking::touch($projectId);
        pp_reply(['ok' => true, 'task_id' => (int) $taskId, 'message' => __('Tarefa criada', 'projectplus')]);
        break;

    case 'state':
        $task = pp_task_for_update('tasks');
        $ok = $task->update([
            'id'               => $task->getID(),
            'projectstates_id' => (int) ($_POST['projectstates_id'] ?? 0),
        ]);
        pp_reply(['ok' => (bool) $ok]);
        break;

    case 'percent':
        $task = pp_task_for_update('tasks');
        if (!empty($task->fields['auto_percent_done'])) {
            pp_reply(['ok' => false, 'message' => __('O percentual desta tarefa é calculado automaticamente a partir das subtarefas', 'projectplus')]);
        }
        $percent = min(100, max(0, (int) ($_POST['percent'] ?? 0)));
        if ($percent >= 100 && ($open = pp_open_children($task->getID())) > 0) {
            pp_reply(['ok' => false, 'message' => sprintf(
                __('Conclua antes as %d subtarefa(s) aberta(s) desta tarefa', 'projectplus'),
                $open
            )]);
        }
        if ($percent >= 100 && ($msg = pp_blocked_message($task->getID())) !== null) {
            pp_reply(['ok' => false, 'message' => $msg]);
        }
        $ok = $task->update(['id' => $task->getID(), 'percent_done' => $percent]);
        pp_reply(['ok' => (bool) $ok, 'percent' => $percent]);
        break;

    case 'dates':
        $task = pp_task_for_update('tasks');
        $input = ['id' => $task->getID()];
        if (isset($_POST['plan_start_date'])) {
            $input['plan_start_date'] = $_POST['plan_start_date'] !== ''
                ? $_POST['plan_start_date'] . ' 09:00:00' : 'NULL';
        }
        if (isset($_POST['plan_end_date'])) {
            $input['plan_end_date'] = $_POST['plan_end_date'] !== ''
                ? $_POST['plan_end_date'] . ' 18:00:00' : 'NULL';
        }
        $ok = $task->update($input);
        pp_reply(['ok' => (bool) $ok]);
        break;

    case 'complete':
        $task = pp_task_for_update('tasks');
        if (!empty($task->fields['auto_percent_done'])) {
            pp_reply(['ok' => false, 'message' => __('O percentual desta tarefa é calculado automaticamente a partir das subtarefas', 'projectplus')]);
        }
        if (($open = pp_open_children($task->getID())) > 0) {
            pp_reply(['ok' => false, 'message' => sprintf(
                __('Conclua antes as %d subtarefa(s) aberta(s) desta tarefa', 'projectplus'),
                $open
            )]);
        }
        if (($msg = pp_blocked_message($task->getID())) !== null) {
            pp_reply(['ok' => false, 'message' => $msg]);
        }
        $ok = $task->update(['id' => $task->getID(), 'percent_done' => 100]);
        pp_reply(['ok' => (bool) $ok]);
        break;

    case 'auto_percent':
        // Mesmo interruptor "Calcular automaticamente" da ficha nativa.
        // O core faz o resto: ligando, o prepareInputForUpdate descarta o
        // percent_done enviado e o post_updateItem recalcula a tarefa pela
        // média das subtarefas (e sobe para mães/projeto); desligando, o %
        // atual fica e volta a ser editável.
        $task = pp_task_for_update('tasks');
        $on   = !empty($_POST['value']) ? 1 : 0;
        if ($on === 1 && !pp_has_children($task->getID())) {
            pp_reply(['ok' => false, 'message' => __('O cálculo automático precisa de ao menos uma subtarefa', 'projectplus')]);
        }
        $ok = $task->update(['id' => $task->getID(), 'auto_percent_done' => $on]);
        pp_reply(['ok' => (bool) $ok, 'auto' => $on]);
        break;

    case 'kanban_move':
        // Etapa 7, Bloco 2a — arrastar cartão entre colunas do Kanban =
        // mudar a fase da tarefa. TRAVA por dependência: não deixa mover
        // para uma fase FINALIZADA (is_finished=1) enquanto houver
        // subtarefa aberta ou bloqueadora aberta (mesma regra da guarda de
        // fase de projeto, onProjectPreUpdate).
        $task = pp_task_for_update('kanban');
        $newState = (int) ($_POST['projectstates_id'] ?? 0);

        if ($newState > 0 && in_array($newState, TaskDep::finishedStateIds(), true)) {
            $reasons  = [];
            if (($open = pp_open_children($task->getID())) > 0) {
                $reasons[] = sprintf(
                    _n('%d subtarefa aberta', '%d subtarefas abertas', $open, 'projectplus'),
                    $open
                );
            }
            $blockers = TaskDep::openBlockerNames($task->getID());
            if (!empty($blockers)) {
                $reasons[] = sprintf(
                    __('bloqueada por: %s', 'projectplus'),
                    implode(', ', array_slice($blockers, 0, 5)) . (count($blockers) > 5 ? '…' : '')
                );
            }
            if (!empty($reasons)) {
                pp_reply(['ok' => false, 'message' => sprintf(
                    __('Não é possível mover para uma fase finalizada — %s', 'projectplus'),
                    implode('; ', $reasons)
                )]);
            }
        }

        $ok = $task->update(['id' => $task->getID(), 'projectstates_id' => $newState]);
        pp_reply(['ok' => (bool) $ok, 'task_id' => (int) $task->getID(), 'state_id' => $newState]);
        break;

    default:
        http_response_code(400);
        pp_reply(['ok' => false, 'message' => 'ação inválida']);
}

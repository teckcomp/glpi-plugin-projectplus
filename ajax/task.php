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
 * POST action=team_add    task_id, users_id — Bloco D-2a: inclui responsável
 * POST action=team_remove task_id, users_id — Bloco D-2a: retira responsável
 * POST action=kanban_edit task_id — O-2b: o que o modal do Kanban pode editar
 *                      nesta tarefa (+ valores atuais e lista de usuários)
 *                      (os dois só para o GESTOR — vínculo, O-1b — ou o
 *                      Super-Admin: Access::canManageTaskTeam)
 *
 * No create, `users_ids` (lista separada por vírgula) traz VÁRIOS
 * responsáveis; `users_id` (um só) segue aceito por compatibilidade.
 *
 * O-2b (27/10/2026): `state`, `percent` e `dates` aceitam `module=kanban`
 * (a regra passa a ser a do módulo Kanban, a mesma do arrastar) e, com
 * `kanban_card=1`, devolvem também `card` — o cartão recalculado
 * (Kanban::cardFor) para o modal redesenhar o board. O mesmo vale para
 * `team_add`/`team_remove`.
 *
 * O CSRF é validado automaticamente pelo core (includes.php) em todo POST.
 * Cada resposta devolve um token novo em 'csrf' — o JS deve usá-lo na
 * próxima chamada (tokens são de uso único).
 */

use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\Kanban;
use GlpiPlugin\Projectplus\ProjectTracking;
use GlpiPlugin\Projectplus\TaskDep;
use GlpiPlugin\Projectplus\Trash;

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
    return TaskDep::openChildTaskCount($taskId); // O-1b-1t: regra única
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
        'WHERE' => array_merge(['projecttasks_id' => $taskId], Trash::liveTasks()), // Bloco M-1
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

/**
 * O-2b — módulo da regra de edição: `kanban` quando a chamada vem do modal do
 * Kanban (mesma regra do arrastar), `tasks` nos demais (painel, Minhas
 * tarefas). Qualquer outro valor cai em `tasks`.
 */
function pp_module(): string
{
    return (($_POST['module'] ?? '') === 'kanban') ? 'kanban' : 'tasks';
}

/**
 * O-2b — acrescenta o cartão recalculado do Kanban quando o modal pede
 * (`kanban_card=1`), tanto no sucesso quanto na recusa: o modal volta os
 * campos para o valor REAL que está no banco.
 */
function pp_with_card(array $payload, int $taskId): array
{
    if (!empty($_POST['kanban_card'])) {
        $payload['card'] = Kanban::cardFor($taskId);
    }
    return $payload;
}

/** O-2b — data `AAAA-MM-DD` do POST (vazio = limpar); null = inválida. */
function pp_post_date(string $value, string $time): ?string
{
    $value = trim($value);
    if ($value === '') {
        return 'NULL';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value . $time;
    }
    return null;
}

/**
 * O-2b — responsáveis (usuários) da tarefa, na ordem de entrada, com o nome
 * no formato configurado no GLPI.
 *
 * @return array<int, array{id:int, name:string}>
 */
function pp_team_users(int $taskId): array
{
    /** @var \DBmysql $DB */
    global $DB;

    $out = [];
    foreach (
        $DB->request([
            'SELECT'    => [
                'glpi_projecttaskteams.items_id',
                'glpi_users.realname',
                'glpi_users.firstname',
                'glpi_users.name AS login',
            ],
            'FROM'      => 'glpi_projecttaskteams',
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => ['glpi_projecttaskteams' => 'items_id', 'glpi_users' => 'id'],
                ],
            ],
            'WHERE'     => [
                'glpi_projecttaskteams.projecttasks_id' => $taskId,
                'glpi_projecttaskteams.itemtype'        => 'User',
            ],
            'ORDER'     => 'glpi_projecttaskteams.id',
        ]) as $row
    ) {
        $uid   = (int) $row['items_id'];
        $label = \formatUserName($uid, (string) ($row['login'] ?? ''), (string) ($row['realname'] ?? ''), (string) ($row['firstname'] ?? ''));
        $out[] = ['id' => $uid, 'name' => $label !== '' ? $label : '?'];
    }
    return $out;
}

$action = $_POST['action'] ?? '';

// Criar: O-1b (23/10/2026) — decidido POR PROJETO em Access::canCreateTaskIn
// (Super-Admin ou gestor da árvore). Antes: nativo `project` UPDATE, global.
// O-1f (26/10/2026): + equipe do projeto com Tarefas "Criar" (para si).

/**
 * Carrega a tarefa do POST e aplica a regra POR TAREFA de Access::canUpdateTask
 * (Super-Admin, gestor da árvore, OU "Interagir" do módulo + ator da tarefa). Encerra a requisição
 * com a mensagem adequada quando não encontra ou não pode.
 * Correção de 22/09/2026: antes o teste era `projecttask` UPDATE, bit que o
 * GLPI 11 não tem — técnico com "Interagir" recebia "Sem permissão".
 */
/**
 * Bloco D-2a — ids de usuário vindos do POST ("10,12" ou array), só
 * positivos, sem repetição, e só de usuários ATIVOS e não apagados.
 *
 * @return int[]
 */
function pp_user_ids($raw): array
{
    /** @var \DBmysql $DB */
    global $DB;

    $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
    $ids   = [];
    foreach ($parts as $p) {
        $n = (int) trim((string) $p);
        if ($n > 0) {
            $ids[$n] = $n;
        }
    }
    if (empty($ids)) {
        return [];
    }
    $ok = [];
    foreach (
        $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => array_values($ids), 'is_active' => 1, 'is_deleted' => 0],
        ]) as $row
    ) {
        $ok[(int) $row['id']] = true;
    }
    // preserva a ordem de escolha
    return array_values(array_filter($ids, static fn ($id) => isset($ok[$id])));
}

/** Id da linha de equipe (usuário) da tarefa, ou 0. */
function pp_team_row(int $taskId, int $userId): int
{
    /** @var \DBmysql $DB */
    global $DB;

    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_projecttaskteams',
        'WHERE'  => [
            'projecttasks_id' => $taskId,
            'itemtype'        => 'User',
            'items_id'        => $userId,
        ],
        'LIMIT'  => 1,
    ])->current();

    return (int) ($row['id'] ?? 0);
}

/** Tarefa do POST para mexer na EQUIPE (só gestor — Bloco D-2a). */
function pp_task_for_team(): ProjectTask
{
    $task = new ProjectTask();
    if (!$task->getFromDB((int) ($_POST['task_id'] ?? 0))) {
        pp_reply(['ok' => false, 'message' => __('Tarefa não encontrada', 'projectplus')]);
    }
    if (!Access::canManageTaskTeam($task)) {
        pp_reply(['ok' => false, 'message' => __('Sem permissão', 'projectplus')]);
    }
    return $task;
}

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
    // Bloco M-2 (21/10/2026): tarefa para a LIXEIRA nativa (reversível).
    // Regra: módulo Tarefas em Interagir + direito NATIVO de excluir tarefa
    // de projeto, conferido pelo próprio core (can(DELETE): direito +
    // entidade). O core leva as subtarefas junto (ProjectTask::post_deleteItem)
    // e as traz de volta ao restaurar.
    case 'delete':
        $task = new ProjectTask();
        $tid  = (int) ($_POST['task_id'] ?? 0);
        if (!$task->getFromDB($tid) || !empty($task->fields['is_deleted'])) {
            pp_reply(['ok' => false, 'message' => __('Tarefa não encontrada', 'projectplus')]);
        }
        // O-1b: Super-Admin/gestor da árvore, ou Interagir + Excluir nativo
        // sendo ator da tarefa (Access::canDeleteTask).
        if (!Access::canDeleteTask($task)) {
            pp_reply(['ok' => false, 'message' => __('Sem permissão', 'projectplus')]);
        }
        $pid = (int) $task->fields['projects_id'];
        if (!$task->delete(['id' => $tid])) {
            pp_reply(['ok' => false, 'message' => __('Não foi possível mover para a lixeira', 'projectplus')]);
        }
        ProjectTracking::touch($pid);
        pp_reply(['ok' => true]);

    case 'create':
        $name      = trim((string) ($_POST['name'] ?? ''));
        $projectId = (int) ($_POST['projects_id'] ?? 0);
        if ($name === '' || $projectId <= 0) {
            pp_reply(['ok' => false, 'message' => __('Nome e projeto são obrigatórios', 'projectplus')]);
        }
        if (!Access::canCreateTaskIn($projectId)) {
            pp_reply(['ok' => false, 'message' => __('Sem permissão para criar tarefas', 'projectplus')]);
        }
        // O-1f: Super-Admin/gestor escolhem responsáveis e mãe livremente;
        // a equipe do projeto (Tarefas "Criar") cria para si mesma.
        $fullCreate = Access::canAssignTaskTeamIn($projectId);
        // O-1b: a tarefa-mãe (se houver) tem de ser do MESMO projeto — a
        // permissão foi conferida no projeto informado, não no da mãe.
        // O-1f: pela equipe, a mãe tem de ser uma tarefa da qual é ator.
        $parentId = (int) ($_POST['projecttasks_id'] ?? 0);
        if ($parentId > 0) {
            $parent = new ProjectTask();
            if (!$parent->getFromDB($parentId) || (int) $parent->fields['projects_id'] !== $projectId
                || (!$fullCreate && !Access::isTaskActor($parent))) {
                pp_reply(['ok' => false, 'message' => __('Sem permissão para criar tarefas', 'projectplus')]);
            }
        }

        $input = [
            'name'             => $name,
            'projects_id'      => $projectId,
            'projecttasks_id'  => $parentId,
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

        // Responsáveis (equipe da tarefa) — opcional. Bloco D-2a: vários,
        // em `users_ids`; `users_id` único segue valendo (compatibilidade).
        $assignees = pp_user_ids(
            isset($_POST['users_ids']) ? $_POST['users_ids'] : ($_POST['users_id'] ?? '')
        );
        if (!$fullCreate) {
            // O-1f: pela equipe do projeto, o responsável é quem criou.
            $assignees = [(int) Session::getLoginUserID()];
        }
        foreach ($assignees as $assignee) {
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
        $task = pp_task_for_update(pp_module()); // O-2b: `kanban` no modal
        $tid  = (int) $task->getID();
        $newState = (int) ($_POST['projectstates_id'] ?? 0);
        // O-1b-1t: mesma trava do Kanban (fase finalizada com subtarefa ou
        // bloqueadora aberta) — antes só o arrastar e o ✓ recusavam.
        if (($msg = TaskDep::finishedMoveBlock($tid, $newState)) !== null) {
            pp_reply(pp_with_card(['ok' => false, 'message' => $msg], $tid));
        }
        $ok = $task->update([
            'id'               => $tid,
            'projectstates_id' => $newState,
        ]);
        pp_reply(pp_with_card(['ok' => (bool) $ok], $tid));
        break;

    case 'percent':
        $task = pp_task_for_update(pp_module()); // O-2b: `kanban` no modal
        $tid  = (int) $task->getID();
        if (!empty($task->fields['auto_percent_done'])) {
            pp_reply(pp_with_card(['ok' => false, 'message' => __('O percentual desta tarefa é calculado automaticamente a partir das subtarefas', 'projectplus')], $tid));
        }
        $percent = min(100, max(0, (int) ($_POST['percent'] ?? 0)));
        if ($percent >= 100 && ($open = pp_open_children($tid)) > 0) {
            pp_reply(pp_with_card(['ok' => false, 'message' => sprintf(
                __('Conclua antes as %d subtarefa(s) aberta(s) desta tarefa', 'projectplus'),
                $open
            )], $tid));
        }
        if ($percent >= 100 && ($msg = pp_blocked_message($tid)) !== null) {
            pp_reply(pp_with_card(['ok' => false, 'message' => $msg], $tid));
        }
        $ok = $task->update(['id' => $tid, 'percent_done' => $percent]);
        pp_reply(pp_with_card(['ok' => (bool) $ok, 'percent' => $percent], $tid));
        break;

    case 'dates':
        $task  = pp_task_for_update(pp_module()); // O-2b: `kanban` no modal
        $tid   = (int) $task->getID();
        $input = ['id' => $tid];
        // O-2b: formato conferido (antes ia direto para o update), como na
        // faixa do projeto (ajax/project.php action=dates).
        foreach (['plan_start_date' => ' 09:00:00', 'plan_end_date' => ' 18:00:00'] as $f => $time) {
            if (!isset($_POST[$f])) {
                continue;
            }
            $v = pp_post_date((string) $_POST[$f], $time);
            if ($v === null) {
                pp_reply(pp_with_card(['ok' => false, 'message' => __('Data inválida', 'projectplus')], $tid));
            }
            $input[$f] = $v;
        }
        $ok = $task->update($input);
        pp_reply(pp_with_card(['ok' => (bool) $ok], $tid));
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
        // subtarefa aberta ou bloqueadora aberta. O-1b-1t: regra única em
        // TaskDep::finishedMoveBlock (também no `state` e no hook).
        $task = pp_task_for_update('kanban');
        $newState = (int) ($_POST['projectstates_id'] ?? 0);

        if (($msg = TaskDep::finishedMoveBlock((int) $task->getID(), $newState)) !== null) {
            pp_reply(['ok' => false, 'message' => $msg]);
        }

        $ok = $task->update(['id' => $task->getID(), 'projectstates_id' => $newState]);
        pp_reply(['ok' => (bool) $ok, 'task_id' => (int) $task->getID(), 'state_id' => $newState]);
        break;

    case 'team_add':
        // Bloco D-2a — inclui um responsável (usuário) na equipe da tarefa.
        $task = pp_task_for_team();
        $uid  = pp_user_ids($_POST['users_id'] ?? '')[0] ?? 0;
        if ($uid <= 0) {
            pp_reply(['ok' => false, 'message' => __('Usuário não encontrado.', 'projectplus')]);
        }
        if (pp_team_row((int) $task->getID(), $uid) > 0) {
            pp_reply(pp_with_card(['ok' => true, 'already' => true], (int) $task->getID())); // idempotente
        }
        $team = new ProjectTaskTeam();
        $ok   = $team->add([
            'projecttasks_id' => (int) $task->getID(),
            'itemtype'        => 'User',
            'items_id'        => $uid,
        ]);
        if ($ok) {
            ProjectTracking::touch((int) $task->fields['projects_id']);
        }
        pp_reply(pp_with_card(['ok' => (bool) $ok], (int) $task->getID()));
        break;

    case 'team_remove':
        // Bloco D-2a — retira um responsável. Só linha de USUÁRIO: grupo na
        // equipe (cadastrado pela ficha nativa) nunca é tocado aqui.
        $task  = pp_task_for_team();
        $rowId = pp_team_row((int) $task->getID(), (int) ($_POST['users_id'] ?? 0));
        if ($rowId <= 0) {
            pp_reply(pp_with_card(['ok' => true, 'already' => true], (int) $task->getID())); // já não estava
        }
        $team = new ProjectTaskTeam();
        $ok   = $team->delete(['id' => $rowId]);
        if ($ok) {
            ProjectTracking::touch((int) $task->fields['projects_id']);
        }
        pp_reply(pp_with_card(['ok' => (bool) $ok], (int) $task->getID()));
        break;

    case 'kanban_edit':
        // O-2b (27/10/2026) — o modal do Kanban abre só leitura e pergunta
        // aqui o que pode virar campo editável. A regra é a MESMA dos
        // endpoints que gravam (Access::canUpdateTask com o módulo Kanban,
        // Access::canManageTaskTeam para os responsáveis) — nada é decidido
        // no cliente. Sem permissão nenhuma, a resposta não traz dado algum.
        $task = new ProjectTask();
        if (!$task->getFromDB((int) ($_POST['task_id'] ?? 0)) || !empty($task->fields['is_deleted'])) {
            pp_reply(['ok' => false, 'message' => __('Tarefa não encontrada', 'projectplus')]);
        }
        $canEdit = Access::canUpdateTask($task, 'kanban');
        $canTeam = Access::canManageTaskTeam($task);
        if (!$canEdit && !$canTeam) {
            pp_reply(['ok' => true, 'can_edit' => false, 'can_team' => false]);
        }
        $tid = (int) $task->getID();
        pp_reply([
            'ok'           => true,
            'can_edit'     => $canEdit,
            'can_team'     => $canTeam,
            'state_id'     => (int) ($task->fields['projectstates_id'] ?? 0),
            'percent'      => (int) ($task->fields['percent_done'] ?? 0),
            'auto_percent' => !empty($task->fields['auto_percent_done']),
            'plan_start'   => substr((string) ($task->fields['plan_start_date'] ?? ''), 0, 10),
            'plan_end'     => substr((string) ($task->fields['plan_end_date'] ?? ''), 0, 10),
            'team'         => pp_team_users($tid),
            'users'        => $canTeam ? Dashboard::userOptions() : [],
        ]);
        break;

    default:
        http_response_code(400);
        pp_reply(['ok' => false, 'message' => 'ação inválida']);
}

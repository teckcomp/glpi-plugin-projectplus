<?php

/**
 * ProjectPlus — operações de PROJETO pelo Kanban de projetos
 * (Etapa 8, Bloco 4, ajuste 4b.2).
 *
 * POST action=kanban_move   project_id, projectstates_id
 *
 * Bloco D-3a (01/10/2026) — faixa "Projeto" do painel. Todas devolvem, além
 * de ok/message, `meta` (Dashboard::getProjectMeta) e `rows` (linhas da
 * tabela de projetos do próprio projeto e dos ancestrais), para o JS
 * redesenhar a faixa e as linhas sem recarregar a página:
 * POST action=state        project_id, projectstates_id (mesma trava do kanban_move)
 * POST action=dates        project_id, plan_start_date, plan_end_date (Y-m-d ou vazio)
 * POST action=percent      project_id, percent (0-100; recusado com auto ligado)
 * POST action=auto_percent project_id, value (0|1) — auto_percent_done nativo
 * POST action=team_add     project_id, users_id — Equipe do projeto (glpi_projectteams)
 * POST action=team_remove  project_id, users_id — só linha de USUÁRIO
 *
 * O CSRF é validado automaticamente pelo core (includes.php) em todo POST —
 * nunca chamar Session::checkCSRF aqui. Cada resposta devolve um token novo
 * em 'csrf' (uso único); o JS rotaciona.
 */

use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Dashboard;
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

/**
 * Projeto do POST, já conferido para edição (módulo Projetos UPDATE + nativo
 * `project` UPDATE + canUpdateItem). Responde e encerra se não puder.
 */
function pp_project_for_update(): Project
{
    if (!Access::canEditProjects()) {
        pp_reply(['ok' => false, 'message' => __('Sem permissão', 'projectplus')]);
    }
    $project = new Project();
    if (!$project->getFromDB((int) ($_POST['project_id'] ?? 0))) {
        pp_reply(['ok' => false, 'message' => __('Projeto não encontrado', 'projectplus')]);
    }
    if (!$project->canUpdateItem()) {
        pp_reply(['ok' => false, 'message' => __('Sem permissão para alterar este projeto', 'projectplus')]);
    }
    return $project;
}

/**
 * Trava de fase finalizada — MESMA regra da guarda da ficha nativa
 * (TaskDep::onProjectPreUpdate, hook PRE_ITEM_UPDATE). A recusa é
 * ANTECIPADA para devolver mensagem: o hook só removeria o campo, em
 * silêncio (lição 7). $extra vai junto na resposta de recusa.
 */
function pp_state_guard(Project $project, int $newState, array $extra = []): void
{
    if ($newState > 0 && in_array($newState, TaskDep::finishedStateIds(), true)) {
        $open = TaskDep::projectOpenChildrenNames((int) $project->getID());
        if (!empty($open)) {
            pp_reply(['ok' => false, 'message' => sprintf(
                __('Não é possível mover para uma fase finalizada — %d item(ns) aberto(s): %s', 'projectplus'),
                count($open),
                implode(', ', array_slice($open, 0, 5)) . (count($open) > 5 ? '…' : '')
            )] + $extra);
        }
    }
}

/** Fase realmente gravada (defesa contra hook que reverte em silêncio). */
function pp_applied_state(int $projectId, int $fallback): int
{
    $fresh = new Project();
    if ($fresh->getFromDB($projectId)) {
        return (int) ($fresh->fields['projectstates_id'] ?? $fallback);
    }
    return $fallback;
}

/** meta + linhas do projeto e dos ancestrais (Bloco D-3a). */
function pp_project_payload(int $projectId): array
{
    return [
        'meta' => Dashboard::getProjectMeta($projectId),
        'rows' => Dashboard::getProjectRows(
            array_merge([$projectId], Dashboard::projectAncestors($projectId))
        ),
    ];
}

/** Ids de usuários ATIVOS e não apagados. */
function pp_active_user(int $userId): int
{
    /** @var \DBmysql $DB */
    global $DB;

    if ($userId <= 0) {
        return 0;
    }
    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_users',
        'WHERE'  => ['id' => $userId, 'is_active' => 1, 'is_deleted' => 0],
    ])->current();
    return (int) ($row['id'] ?? 0);
}

/** Id da linha da Equipe do projeto (usuário), ou 0. */
function pp_project_team_row(int $projectId, int $userId): int
{
    /** @var \DBmysql $DB */
    global $DB;

    $row = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => 'glpi_projectteams',
        'WHERE'  => [
            'projects_id' => $projectId,
            'itemtype'    => 'User',
            'items_id'    => $userId,
        ],
        'LIMIT'  => 1,
    ])->current();
    return (int) ($row['id'] ?? 0);
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

    case 'state':
        $project  = pp_project_for_update();
        $pid      = (int) $project->getID();
        $newState = (int) ($_POST['projectstates_id'] ?? 0);
        if ($newState !== (int) ($project->fields['projectstates_id'] ?? 0)) {
            pp_state_guard($project, $newState, pp_project_payload($pid));
            $ok = $project->update(['id' => $pid, 'projectstates_id' => $newState]);
            if (!$ok || pp_applied_state($pid, $newState) !== $newState) {
                pp_reply(['ok' => false, 'message' => __('A fase não pôde ser alterada.', 'projectplus')]
                    + pp_project_payload($pid));
            }
            ProjectTracking::touch($pid);
        }
        pp_reply(['ok' => true] + pp_project_payload($pid));
        break;

    case 'dates':
        $project = pp_project_for_update();
        $pid     = (int) $project->getID();
        $input   = ['id' => $pid];
        foreach (['plan_start_date' => ' 09:00:00', 'plan_end_date' => ' 18:00:00'] as $f => $time) {
            if (!isset($_POST[$f])) {
                continue;
            }
            $v = trim((string) $_POST[$f]);
            if ($v === '') {
                $input[$f] = 'NULL';
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $input[$f] = $v . $time;
            } else {
                pp_reply(['ok' => false, 'message' => __('Data inválida', 'projectplus')] + pp_project_payload($pid));
            }
        }
        $ok = $project->update($input);
        if ($ok) {
            ProjectTracking::touch($pid);
        }
        pp_reply(['ok' => (bool) $ok] + pp_project_payload($pid));
        break;

    case 'percent':
        $project = pp_project_for_update();
        $pid     = (int) $project->getID();
        if (!empty($project->fields['auto_percent_done'])) {
            pp_reply(['ok' => false, 'message' => __('O percentual deste projeto é calculado automaticamente', 'projectplus')]
                + pp_project_payload($pid));
        }
        $pct = max(0, min(100, (int) ($_POST['percent'] ?? 0)));
        $ok  = $project->update(['id' => $pid, 'percent_done' => $pct]);
        if ($ok) {
            ProjectTracking::touch($pid);
        }
        pp_reply(['ok' => (bool) $ok] + pp_project_payload($pid));
        break;

    case 'auto_percent':
        // O core faz o cálculo: ligando, Project::post_updateItem roda
        // recalculatePercentDone (média de subprojetos + tarefas) e sobe
        // para os pais. Desligando, o % atual fica e volta a ser editável.
        $project = pp_project_for_update();
        $pid     = (int) $project->getID();
        $on      = !empty($_POST['value']) ? 1 : 0;
        $ok      = $project->update(['id' => $pid, 'auto_percent_done' => $on]);
        if ($ok) {
            ProjectTracking::touch($pid);
        }
        pp_reply(['ok' => (bool) $ok] + pp_project_payload($pid));
        break;

    case 'team_add':
        // Equipe do projeto: quem entra passa a ENXERGAR o projeto no escopo
        // (Scope usa glpi_projectteams) — decisão do Claudio, 01/10/2026.
        $project = pp_project_for_update();
        $pid     = (int) $project->getID();
        $uid     = pp_active_user((int) ($_POST['users_id'] ?? 0));
        if ($uid <= 0) {
            pp_reply(['ok' => false, 'message' => __('Usuário não encontrado.', 'projectplus')] + pp_project_payload($pid));
        }
        if (pp_project_team_row($pid, $uid) === 0) {
            $team = new ProjectTeam();
            $ok   = $team->add(['projects_id' => $pid, 'itemtype' => 'User', 'items_id' => $uid]);
            if (!$ok) {
                pp_reply(['ok' => false] + pp_project_payload($pid));
            }
            ProjectTracking::touch($pid);
        }
        pp_reply(['ok' => true] + pp_project_payload($pid));
        break;

    case 'team_remove':
        $project = pp_project_for_update();
        $pid     = (int) $project->getID();
        $rowId   = pp_project_team_row($pid, (int) ($_POST['users_id'] ?? 0));
        if ($rowId > 0) {
            $team = new ProjectTeam();
            if (!$team->delete(['id' => $rowId])) {
                pp_reply(['ok' => false] + pp_project_payload($pid));
            }
            ProjectTracking::touch($pid);
        }
        pp_reply(['ok' => true] + pp_project_payload($pid));
        break;

    default:
        http_response_code(400);
        pp_reply(['ok' => false, 'message' => 'ação inválida']);
}

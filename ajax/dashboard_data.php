<?php

/**
 * ProjectPlus — endpoint JSON do painel.
 *
 * GET ?action=data              -> KPIs + gráfico + projetos pai
 * GET ?action=children&id=NN    -> subprojetos de um pai (requisito 2)
 * GET ?action=mytasks[&done=1]  -> tarefas do usuário logado (Etapa 3, Bloco 1)
 * GET ?action=taskcomments&id=NN -> comentários de uma tarefa (Etapa 3, Bloco 2;
 *                                  O-4: marca como lidos para quem abriu)
 * GET ?action=taskdeps&id=NN    -> dependências de uma tarefa (Etapa 3, Bloco 3)
 * GET ?action=projectmeta&id=NN -> faixa "Projeto" do painel (Bloco D-3a; só
 *                                  para quem edita projeto)
 * GET ?action=projectcomments&id=NN -> comentários do projeto (Bloco D-3b)
 * GET ?action=projectdesc&id=NN    -> descrição do projeto (O-2e)
 * GET ?action=taskdesc&id=NN[&module=kanban] -> descrição da tarefa (O-2e-2)
 */

use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\CommentRead;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\Description;
use GlpiPlugin\Projectplus\Scope;
use GlpiPlugin\Projectplus\TaskComment;
use GlpiPlugin\Projectplus\TaskDep;

include('../../../inc/includes.php');

// Bloco F-1b: o endpoint serve o painel E Minhas tarefas/comentários. A
// porta é "entra no plugin"; cada ação confere o módulo dela abaixo.
if (!Access::canEnter()) {
    Html::displayRightError();
}

header('Content-Type: application/json; charset=UTF-8');

$action = $_GET['action'] ?? 'data';

$moduleOf = [
    'children'    => 'dashboard',
    'projectmeta' => 'dashboard',
    'tasks'       => 'dashboard',
    'taskchildren' => 'dashboard',
    'mytasks'     => 'tasks',
];
if (isset($moduleOf[$action]) && !Access::can($moduleOf[$action])) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    return;
}

switch ($action) {
    case 'children':
        // Bloco F-1: só os subprojetos que o usuário enxerga. O JS repassa o
        // `scope=mine` da tela, então Scope::mode() aqui é o mesmo da página.
        $parentId = (int) ($_GET['id'] ?? 0);
        echo json_encode(Dashboard::getChildren($parentId, Scope::visibleProjectMap()));
        break;

    case 'projectmeta':
        // O-1b-2a (26/10/2026): faixa "Projeto" só onde o usuário edita
        // ESTE projeto (Access::canEditProject — Super-Admin, gestor da
        // árvore ou equipe com Projetos "Interagir"); a meta leva as
        // permissões que mudam o desenho (equipe e lixeira).
        $meta    = null;
        $project = new Project();
        if ($project->getFromDB((int) ($_GET['id'] ?? 0))
            && empty($project->fields['is_deleted'])
            && Access::canEditProject($project)) {
            $meta = Dashboard::getProjectMeta((int) $project->getID());
            if ($meta !== null) {
                $meta += Access::projectStripFlags($project);
            }
        }
        echo json_encode(['ok' => $meta !== null, 'meta' => $meta]);
        break;

    case 'tasks':
        // Bloco F-1: fora do "Ver todos", só as MINHAS tarefas (mães de
        // outros entram só com o nome, como contexto).
        $projectId = (int) ($_GET['id'] ?? 0);
        echo json_encode(Dashboard::getTasks($projectId, Scope::myTaskIds()));
        break;

    case 'taskchildren':
        $taskId = (int) ($_GET['id'] ?? 0);
        echo json_encode(Dashboard::getOpenTaskChildren($taskId));
        break;

    case 'projectcomments':
        // Bloco F-2b: quem enxerga o projeto (💬 da linha do projeto)
        $pcId   = (int) ($_GET['id'] ?? 0);
        $pcList = TaskComment::canCommentOnProject($pcId) ? TaskComment::getForProject($pcId) : [];
        // O-4: abrir os comentários = ler (só quem enxerga o projeto chega aqui com lista)
        CommentRead::markList('project', $pcId, $pcList, (int) Session::getLoginUserID());
        echo json_encode($pcList);
        break;

    case 'projectdesc':
        // O-2e (27/10/2026): descrição do projeto para quem ENXERGA o projeto
        // (mesma porta do 💬 do projeto). `can_edit` = mesma regra da faixa
        // "Projeto" (Access::canEditProject) — decide o botão Editar.
        $pdId = (int) ($_GET['id'] ?? 0);
        if (!TaskComment::canCommentOnProject($pdId)) {
            echo json_encode(['ok' => false]);
            break;
        }
        $pdProject = new Project();
        $pdProject->getFromDB($pdId);
        echo json_encode([
            'ok'          => true,
            'description' => Description::payload($pdProject->fields['content'] ?? ''),
            'can_edit'    => Access::canEditProject($pdProject),
        ]);
        break;

    case 'taskdesc':
        // O-2e-2 (28/10/2026): descrição da tarefa para quem ENXERGA a tarefa
        // — tarefa e projeto fora da lixeira, entidade, projeto visível no
        // escopo (Scope::canSeeProject). `can_edit` = a regra de quem grava
        // (Access::canUpdateTask; módulo `kanban` no modal, `tasks` nas
        // tabelas).
        $tdTask = new ProjectTask();
        $tdProj = new Project();
        if (!$tdTask->getFromDB((int) ($_GET['id'] ?? 0))
            || !empty($tdTask->fields['is_deleted'])
            || !$tdProj->getFromDB((int) $tdTask->fields['projects_id'])
            || !empty($tdProj->fields['is_deleted'])
            || !Session::haveAccessToEntity((int) $tdTask->getEntityID())
            || !Scope::canSeeProject((int) $tdTask->fields['projects_id'])) {
            echo json_encode(['ok' => false]);
            break;
        }
        $tdModule = (($_GET['module'] ?? '') === 'kanban') ? 'kanban' : 'tasks';
        echo json_encode([
            'ok'          => true,
            'description' => Description::payload($tdTask->fields['content'] ?? ''),
            'can_edit'    => Access::canUpdateTask($tdTask, $tdModule),
        ]);
        break;

    case 'taskcomments':
        $taskId = (int) ($_GET['id'] ?? 0);
        $tcList = TaskComment::getForTask($taskId);
        CommentRead::markList('task', $taskId, $tcList, (int) Session::getLoginUserID()); // O-4
        echo json_encode($tcList);
        break;

    case 'taskdeps':
        $taskId = (int) ($_GET['id'] ?? 0);
        echo json_encode(TaskDep::getPanelData($taskId));
        break;

    case 'mytasks':
        $out = Dashboard::getMyTasks(
            (int) Session::getLoginUserID(),
            !empty($_GET['done'])
        );
        // Bloco C-3: opções do filtro Projeto = projetos que o usuário
        // enxerga (escopo padrão = maior do perfil), mesmo sem tarefa dele
        $scopeMode = Scope::mode();
        $out['project_options'] = Dashboard::myTasksProjectOptions(
            Scope::projectIds($scopeMode),
            Scope::taskProjectIds($scopeMode)
        );
        echo json_encode($out);
        break;

    case 'data':
        // REMOVIDO em 26/07/2026 após teste em homologação.
        //
        // Esta ação chamava `Dashboard::getData()` SEM NENHUM argumento de
        // escopo, enquanto `front/dashboard.php` chama a mesma função
        // passando Scope::projectIds(), Scope::myTaskIds() e
        // Scope::taskProjectIds(). Resultado comprovado com um perfil
        // Technician (escopo pessoal): a tela mostrava 1 projeto e 4
        // tarefas; este endpoint devolvia 3 projetos e 12 tarefas, com
        // orçamento e nomes de responsáveis de projetos que o perfil não
        // enxerga.
        //
        // Nenhum JavaScript do plugin chamava esta ação — era código morto
        // que vazava. E, por ser também o `default`, QUALQUER `?action=`
        // desconhecido caía aqui, então bastava errar o nome da ação.
        //
        // Não vale "consertar" passando o escopo: a tela já faz isso, e um
        // endpoint que só duplica a tela é superfície de ataque sem uso.
    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown action']);
        break;
}

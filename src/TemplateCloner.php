<?php

namespace GlpiPlugin\Projectplus;

use Project;
use ProjectTask;
use ProjectTaskTeam;
use Session;

/**
 * Clonagem própria de modelos de projeto (requisito 4).
 *
 * NÃO usa o mecanismo nativo de templates do GLPI, que possui bug
 * conhecido (issue #21804 — "Project Task and Project Task template
 * is different"): duplicação de tarefas ou tarefas não criadas quando
 * há valores pré-definidos.
 *
 * O modelo fica em glpi_plugin_projectplus_templates.structure como JSON:
 *
 * {
 *   "tasks": [
 *     {
 *       "name": "Levantamento de requisitos",
 *       "content": "Descrição...",
 *       "offset_start_days": 0,     // dias após a data de início do projeto
 *       "duration_days": 5,
 *       "planned_duration": 28800,  // segundos (opcional)
 *       "children": [ { ...mesma estrutura... } ]
 *     }
 *   ]
 * }
 */
class TemplateCloner
{
    /**
     * Instancia um projeto a partir de um modelo do plugin.
     *
     * @param int    $templateId id em glpi_plugin_projectplus_templates
     * @param string $name       nome do novo projeto
     * @param string $startDate  data de início (Y-m-d)
     * @param array  $extra      campos extras do Project (entities_id, users_id...)
     *
     * @return array{ok: bool, message: string, projects_id?: int, tasks_created?: int}
     */
    public static function instantiate(
        int $templateId,
        string $name,
        string $startDate,
        array $extra = []
    ): array {
        /** @var \DBmysql $DB */
        global $DB;

        // --- Carrega o modelo ---
        $it = $DB->request([
            'FROM'  => 'glpi_plugin_projectplus_templates',
            'WHERE' => ['id' => $templateId],
            'LIMIT' => 1,
        ]);

        $template = null;
        foreach ($it as $row) {
            $template = $row;
        }
        if ($template === null) {
            return ['ok' => false, 'message' => __('Modelo não encontrado', 'projectplus')];
        }

        $structure = json_decode($template['structure'] ?? '', true);
        if (!is_array($structure)) {
            return ['ok' => false, 'message' => __('JSON do modelo inválido', 'projectplus')];
        }

        $rootTasks = (!empty($structure['tasks']) && is_array($structure['tasks']))
            ? $structure['tasks'] : [];
        $rootSubs  = (!empty($structure['subprojects']) && is_array($structure['subprojects']))
            ? $structure['subprojects'] : [];

        if (empty($rootTasks) && empty($rootSubs)) {
            return ['ok' => false, 'message' => __('JSON do modelo inválido', 'projectplus')];
        }

        $startTs = strtotime($startDate . ' 09:00:00');
        if ($startTs === false) {
            return ['ok' => false, 'message' => __('Data de início inválida', 'projectplus')];
        }

        $entityId  = $extra['entities_id'] ?? Session::getActiveEntity();
        $managerId = $extra['users_id'] ?? Session::getLoginUserID();

        // Atributos do projeto raiz definidos no modelo (estado, tipo,
        // gestor, % automático, descrição, orçamento). O gestor do modelo,
        // se houver, tem prioridade sobre o default.
        $meta = (!empty($structure['project']) && is_array($structure['project']))
            ? $structure['project'] : [];
        if (!empty($meta['users_id'])) {
            $managerId = (int) $meta['users_id'];
        }

        // Datas do projeto raiz: início = data escolhida + offset do modelo;
        // fim = início + (duração-1). Se o modelo não trouxer duração
        // (modelos antigos), o fim fica em aberto, como antes.
        $rootOffset = max(0, (int) ($meta['offset_start_days'] ?? 0));
        $rootStartTs = strtotime("+{$rootOffset} days", $startTs);

        // --- Cria o projeto RAIZ (SEM usar template nativo) ---
        $rootInput = [
            'name'            => $name,
            'entities_id'     => $entityId,
            'is_recursive'    => $extra['is_recursive'] ?? 0,
            'users_id'        => $managerId,
            'content'         => $meta['content'] ?? '',
            'plan_start_date' => date('Y-m-d 09:00:00', $rootStartTs),
            'date'            => date('Y-m-d H:i:s'),
        ];
        if (isset($meta['duration_days'])) {
            $rootDur = max(1, (int) $meta['duration_days']);
            $rootEnd = strtotime('+' . ($rootDur - 1) . ' days', $rootStartTs);
            $rootInput['plan_end_date'] = date('Y-m-d 18:00:00', $rootEnd);
        }
        if (!empty($meta['projectstates_id'])) {
            $rootInput['projectstates_id'] = (int) $meta['projectstates_id'];
        }
        if (!empty($meta['projecttypes_id'])) {
            $rootInput['projecttypes_id'] = (int) $meta['projecttypes_id'];
        }
        if (!empty($meta['auto_percent_done'])) {
            $rootInput['auto_percent_done'] = 1;
        }

        $project   = new Project();
        $projectId = $project->add($rootInput);

        if (!$projectId) {
            return ['ok' => false, 'message' => __('Falha ao criar o projeto', 'projectplus')];
        }

        // Orçamento (teto do plugin) do projeto raiz
        if (!empty($meta['budget']) && (float) $meta['budget'] > 0) {
            Budget::setPlanned((int) $projectId, (float) $meta['budget']);
        }

        // --- Tarefas da raiz (controle anti-duplicação por projeto) ---
        $tasksCreated = 0;
        $seenTasks    = []; // nomes já criados por nível NESTE projeto
        foreach ($rootTasks as $taskDef) {
            if (is_array($taskDef)) {
                $tasksCreated += self::createTaskTree($taskDef, (int) $projectId, 0, $startTs, $seenTasks);
            }
        }

        // --- Subprojetos recursivos (item 3): cada um vira um Project
        //     nativo com projects_id apontando para o pai; offsets de tudo
        //     são relativos ao início da RAIZ ($startTs). Anti-duplicação
        //     por nome sob o mesmo pai. NUNCA tocamos em templates nativos
        //     (is_template), então o bug #21804 não se aplica (item 5). ---
        $subsCreated  = 0;
        $seenProjects = [];
        foreach ($rootSubs as $subDef) {
            if (is_array($subDef)) {
                $r = self::createSubprojectTree(
                    $subDef,
                    (int) $projectId,
                    $startTs,
                    (int) $entityId,
                    (int) $managerId,
                    $seenProjects
                );
                $subsCreated  += $r['projects'];
                $tasksCreated += $r['tasks'];
            }
        }

        ProjectTracking::touch((int) $projectId);

        return [
            'ok'                => true,
            'message'           => sprintf(
                __('Projeto criado com %1$d tarefa(s) e %2$d subprojeto(s)', 'projectplus'),
                $tasksCreated,
                $subsCreated
            ),
            'projects_id'       => (int) $projectId,
            'tasks_created'     => $tasksCreated,
            'subprojects_created' => $subsCreated,
        ];
    }

    /**
     * Cria um subprojeto (Project nativo com projects_id = pai), sua
     * árvore de tarefas e seus próprios subprojetos, recursivamente.
     *
     * Cada projeto recebe seu PRÓPRIO mapa de dedup de tarefas — nomes
     * iguais em projetos diferentes NÃO colidem (o parentId 0 se repete
     * entre projetos).
     *
     * @param array $def           definição do subprojeto no JSON
     * @param int   $parentProjectId projeto pai
     * @param int   $rootStartTs   timestamp de início da RAIZ (referência única)
     * @param int   $entityId      entidade herdada da raiz
     * @param int   $managerId     gestor herdado da raiz
     * @param array $seenProjects  dedup de subprojetos por nome sob o mesmo pai (ref)
     *
     * @return array{projects:int, tasks:int}
     */
    private static function createSubprojectTree(
        array $def,
        int $parentProjectId,
        int $rootStartTs,
        int $entityId,
        int $managerId,
        array &$seenProjects
    ): array {
        $name = trim((string) ($def['name'] ?? ''));
        if ($name === '') {
            return ['projects' => 0, 'tasks' => 0];
        }

        $dedupKey = $parentProjectId . '|' . mb_strtolower($name);
        if (isset($seenProjects[$dedupKey])) {
            return ['projects' => 0, 'tasks' => 0];
        }
        $seenProjects[$dedupKey] = true;

        $offsetDays   = max(0, (int) ($def['offset_start_days'] ?? 0));
        $durationDays = max(1, (int) ($def['duration_days'] ?? 1));
        $planStart    = strtotime("+{$offsetDays} days", $rootStartTs);
        $planEnd      = strtotime('+' . ($durationDays - 1) . ' days', $planStart);

        // Gestor do subprojeto: o do modelo, se houver; senão herda da raiz.
        $subManager = !empty($def['users_id']) ? (int) $def['users_id'] : $managerId;

        $subInput = [
            'name'            => $name,
            'content'         => $def['content'] ?? '',
            'entities_id'     => $entityId,
            'projects_id'     => $parentProjectId,
            'users_id'        => $subManager,
            'plan_start_date' => date('Y-m-d 09:00:00', $planStart),
            'plan_end_date'   => date('Y-m-d 18:00:00', $planEnd),
            'date'            => date('Y-m-d H:i:s'),
        ];
        if (!empty($def['projectstates_id'])) {
            $subInput['projectstates_id'] = (int) $def['projectstates_id'];
        }
        if (!empty($def['projecttypes_id'])) {
            $subInput['projecttypes_id'] = (int) $def['projecttypes_id'];
        }
        if (!empty($def['auto_percent_done'])) {
            $subInput['auto_percent_done'] = 1;
        }

        $project = new Project();
        $newId   = $project->add($subInput);

        if (!$newId) {
            return ['projects' => 0, 'tasks' => 0];
        }

        // Orçamento (teto do plugin) do subprojeto
        if (!empty($def['budget']) && (float) $def['budget'] > 0) {
            Budget::setPlanned((int) $newId, (float) $def['budget']);
        }

        // Tarefas do subprojeto — mapa de dedup PRÓPRIO
        $tasksCreated = 0;
        $seenTasks    = [];
        foreach (($def['tasks'] ?? []) as $taskDef) {
            if (is_array($taskDef)) {
                $tasksCreated += self::createTaskTree($taskDef, (int) $newId, 0, $rootStartTs, $seenTasks);
            }
        }

        // Subprojetos aninhados
        $projectsCreated   = 1;
        $seenChildProjects = [];
        foreach (($def['subprojects'] ?? []) as $childDef) {
            if (is_array($childDef)) {
                $r = self::createSubprojectTree(
                    $childDef,
                    (int) $newId,
                    $rootStartTs,
                    $entityId,
                    $managerId,
                    $seenChildProjects
                );
                $projectsCreated += $r['projects'];
                $tasksCreated    += $r['tasks'];
            }
        }

        ProjectTracking::touch((int) $newId);

        return ['projects' => $projectsCreated, 'tasks' => $tasksCreated];
    }

    /**
     * Cria uma tarefa e seus filhos recursivamente.
     *
     * @param array $def       definição da tarefa no JSON
     * @param int   $projectId projeto destino
     * @param int   $parentId  0 para tarefa raiz
     * @param int   $startTs   timestamp de início do projeto
     * @param array $seen      controle de duplicação (por referência)
     */
    private static function createTaskTree(
        array $def,
        int $projectId,
        int $parentId,
        int $startTs,
        array &$seen
    ): int {
        $name = trim((string) ($def['name'] ?? ''));
        if ($name === '') {
            return 0;
        }

        // Anti-duplicação: mesma tarefa (nome) no mesmo pai só entra uma vez
        $dedupKey = $parentId . '|' . mb_strtolower($name);
        if (isset($seen[$dedupKey])) {
            return 0;
        }
        $seen[$dedupKey] = true;

        $offsetDays   = (int) ($def['offset_start_days'] ?? 0);
        $durationDays = max(1, (int) ($def['duration_days'] ?? 1));

        $planStart = strtotime("+{$offsetDays} days", $startTs);
        $planEnd   = strtotime('+' . ($durationDays - 1) . ' days', $planStart);

        $taskInput = [
            'name'             => $name,
            'content'          => $def['content'] ?? '',
            'projects_id'      => $projectId,
            'projecttasks_id'  => $parentId,
            'plan_start_date'  => date('Y-m-d 09:00:00', $planStart),
            'plan_end_date'    => date('Y-m-d 18:00:00', $planEnd),
            'planned_duration' => (int) ($def['planned_duration'] ?? 0),
            'percent_done'     => 0,
        ];
        if (!empty($def['projectstates_id'])) {
            $taskInput['projectstates_id'] = (int) $def['projectstates_id'];
        }
        if (!empty($def['projecttasktypes_id'])) {
            $taskInput['projecttasktypes_id'] = (int) $def['projecttasktypes_id'];
        }
        if (!empty($def['auto_percent_done'])) {
            $taskInput['auto_percent_done'] = 1;
        }

        $task   = new ProjectTask();
        $taskId = $task->add($taskInput);

        if (!$taskId) {
            return 0;
        }

        // Responsável (equipe da tarefa) — mesmo mecanismo do painel
        $responsavel = (int) ($def['users_id'] ?? 0);
        if ($responsavel > 0) {
            $team = new ProjectTaskTeam();
            $team->add([
                'projecttasks_id' => (int) $taskId,
                'itemtype'        => 'User',
                'items_id'        => $responsavel,
            ]);
        }

        $created = 1;

        foreach (($def['children'] ?? []) as $childDef) {
            if (is_array($childDef)) {
                $created += self::createTaskTree(
                    $childDef,
                    $projectId,
                    (int) $taskId,
                    $startTs,
                    $seen
                );
            }
        }

        return $created;
    }

    /**
     * Valida um JSON de modelo antes de salvar (usado pela futura
     * tela de administração de templates).
     *
     * @return array{valid: bool, errors: string[]}
     */
    public static function validateStructure(string $json): array
    {
        $errors = [];
        $data   = json_decode($json, true);

        if (!is_array($data)) {
            return ['valid' => false, 'errors' => [__('JSON malformado', 'projectplus')]];
        }

        $hasTasks = isset($data['tasks']);
        $hasSubs  = isset($data['subprojects']);

        if (!$hasTasks && !$hasSubs) {
            $errors[] = __('O modelo precisa de "tasks" e/ou "subprojects"', 'projectplus');
        }

        if ($hasTasks) {
            if (!is_array($data['tasks'])) {
                $errors[] = __('Chave "tasks" inválida', 'projectplus');
            } else {
                self::validateTasks($data['tasks'], $errors, 'tasks');
            }
        }

        if ($hasSubs) {
            if (!is_array($data['subprojects'])) {
                $errors[] = __('Chave "subprojects" inválida', 'projectplus');
            } else {
                self::validateSubprojects($data['subprojects'], $errors, 'subprojects');
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private static function validateTasks(array $tasks, array &$errors, string $path): void
    {
        foreach ($tasks as $i => $task) {
            $p = "{$path}[{$i}]";
            if (!is_array($task)) {
                $errors[] = "{$p}: " . __('deve ser um objeto', 'projectplus');
                continue;
            }
            if (empty($task['name'])) {
                $errors[] = "{$p}: " . __('campo "name" obrigatório', 'projectplus');
            }
            if (isset($task['children'])) {
                if (!is_array($task['children'])) {
                    $errors[] = "{$p}.children: " . __('deve ser uma lista', 'projectplus');
                } else {
                    self::validateTasks($task['children'], $errors, "{$p}.children");
                }
            }
        }
    }

    private static function validateSubprojects(array $subs, array &$errors, string $path): void
    {
        foreach ($subs as $i => $sub) {
            $p = "{$path}[{$i}]";
            if (!is_array($sub)) {
                $errors[] = "{$p}: " . __('deve ser um objeto', 'projectplus');
                continue;
            }
            if (empty($sub['name'])) {
                $errors[] = "{$p}: " . __('campo "name" obrigatório', 'projectplus');
            }
            if (isset($sub['tasks'])) {
                if (!is_array($sub['tasks'])) {
                    $errors[] = "{$p}.tasks: " . __('deve ser uma lista', 'projectplus');
                } else {
                    self::validateTasks($sub['tasks'], $errors, "{$p}.tasks");
                }
            }
            if (isset($sub['subprojects'])) {
                if (!is_array($sub['subprojects'])) {
                    $errors[] = "{$p}.subprojects: " . __('deve ser uma lista', 'projectplus');
                } else {
                    self::validateSubprojects($sub['subprojects'], $errors, "{$p}.subprojects");
                }
            }
        }
    }
}

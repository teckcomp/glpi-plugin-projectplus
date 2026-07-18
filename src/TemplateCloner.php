<?php

namespace GlpiPlugin\Projectplus;

use Project;
use ProjectTask;
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
        if (!is_array($structure) || !isset($structure['tasks'])) {
            return ['ok' => false, 'message' => __('JSON do modelo inválido', 'projectplus')];
        }

        $startTs = strtotime($startDate . ' 09:00:00');
        if ($startTs === false) {
            return ['ok' => false, 'message' => __('Data de início inválida', 'projectplus')];
        }

        // --- Cria o projeto (SEM usar template nativo) ---
        $project   = new Project();
        $projectId = $project->add([
            'name'            => $name,
            'entities_id'     => $extra['entities_id'] ?? Session::getActiveEntity(),
            'is_recursive'    => $extra['is_recursive'] ?? 0,
            'users_id'        => $extra['users_id'] ?? Session::getLoginUserID(),
            'plan_start_date' => date('Y-m-d H:i:s', $startTs),
            'date'            => date('Y-m-d H:i:s'),
        ]);

        if (!$projectId) {
            return ['ok' => false, 'message' => __('Falha ao criar o projeto', 'projectplus')];
        }

        // --- Cria a árvore de tarefas, com controle anti-duplicação ---
        $created = 0;
        $seen    = []; // nomes já criados por nível, defesa extra contra duplicação
        foreach ($structure['tasks'] as $taskDef) {
            $created += self::createTaskTree(
                $taskDef,
                (int) $projectId,
                0,
                $startTs,
                $seen
            );
        }

        ProjectTracking::touch((int) $projectId);

        return [
            'ok'            => true,
            'message'       => sprintf(
                __('Projeto criado com %d tarefa(s)', 'projectplus'),
                $created
            ),
            'projects_id'   => (int) $projectId,
            'tasks_created' => $created,
        ];
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

        $task   = new ProjectTask();
        $taskId = $task->add([
            'name'             => $name,
            'content'          => $def['content'] ?? '',
            'projects_id'      => $projectId,
            'projecttasks_id'  => $parentId,
            'plan_start_date'  => date('Y-m-d 09:00:00', $planStart),
            'plan_end_date'    => date('Y-m-d 18:00:00', $planEnd),
            'planned_duration' => (int) ($def['planned_duration'] ?? 0),
            'percent_done'     => 0,
        ]);

        if (!$taskId) {
            return 0;
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
        if (!isset($data['tasks']) || !is_array($data['tasks'])) {
            $errors[] = __('Chave "tasks" ausente ou inválida', 'projectplus');
        } else {
            self::validateTasks($data['tasks'], $errors, 'tasks');
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
}

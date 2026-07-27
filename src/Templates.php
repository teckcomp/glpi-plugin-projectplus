<?php

namespace GlpiPlugin\Projectplus;

use Session;

/**
 * Modelos de projeto (Etapa 4).
 *
 * A estrutura fica em glpi_plugin_projectplus_templates.structure como
 * JSON, no formato consumido por TemplateCloner::instantiate():
 *
 * {
 *   "tasks": [
 *     { "name", "content", "offset_start_days", "duration_days",
 *       "planned_duration"?, "children": [ ...tarefas... ] }
 *   ],
 *   "subprojects": [
 *     { "name", "content", "offset_start_days", "duration_days",
 *       "tasks": [ ...tarefas... ],
 *       "subprojects": [ ...subprojetos (recursivo)... ] }
 *   ]
 * }
 *
 * CONVENÇÃO DE DATAS: todo offset_start_days (de tarefa OU de subprojeto,
 * em qualquer profundidade) é relativo à data de início do PROJETO RAIZ.
 * Referência única e previsível, casada entre a captura (saveFromProject)
 * e a instanciação (TemplateCloner).
 *
 * ACESSO (Etapa 4): toda a área "Modelos" é restrita a quem tem 'config'
 * UPDATE (super-admin). A atribuição por perfil a gestores é a Etapa 8
 * (permissão "ver todos os projetos" / direitos granulares).
 */
class Templates
{
    /**
     * Gate único da área de Modelos (sidebar + páginas + ações).
     * Etapa 8 substituirá por direito granular por perfil.
     */
    public static function canAccess(): bool
    {
        // Etapa 8, Bloco 2: gate de Modelos migrou de `config` (super-admin)
        // para o direito próprio do módulo (Interagir = UPDATE).
        return Session::haveRight('plugin_projectplus_templates', UPDATE);
    }

    /**
     * Lista os modelos cadastrados, com contagem de tarefas e de
     * subprojetos calculada a partir do JSON (não há colunas próprias).
     *
     * @return array<array{id:int, name:string, comment:string, count:int, subcount:int, date_mod:?string}>
     */
    public static function listAll(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'FROM'  => 'glpi_plugin_projectplus_templates',
                'ORDER' => 'name',
            ]) as $row
        ) {
            $structure = json_decode($row['structure'] ?? '', true);
            $count     = 0;
            $subcount  = 0;
            if (is_array($structure)) {
                if (!empty($structure['tasks']) && is_array($structure['tasks'])) {
                    $count += self::countTasks($structure['tasks']);
                }
                if (!empty($structure['subprojects']) && is_array($structure['subprojects'])) {
                    [$c, $s] = self::countSubprojects($structure['subprojects']);
                    $count    += $c;
                    $subcount += $s;
                }
            }

            $out[] = [
                'id'       => (int) $row['id'],
                'name'     => (string) $row['name'],
                'comment'  => (string) ($row['comment'] ?? ''),
                'count'    => $count,
                'subcount' => $subcount,
                'date_mod' => $row['date_mod'] ?? null,
            ];
        }
        return $out;
    }

    private static function countTasks(array $tasks): int
    {
        $n = 0;
        foreach ($tasks as $t) {
            if (!is_array($t)) {
                continue;
            }
            $n++;
            if (!empty($t['children']) && is_array($t['children'])) {
                $n += self::countTasks($t['children']);
            }
        }
        return $n;
    }

    /**
     * @return array{0:int,1:int} [total de tarefas, total de subprojetos]
     */
    private static function countSubprojects(array $subs): array
    {
        $tasks = 0;
        $projs = 0;
        foreach ($subs as $p) {
            if (!is_array($p)) {
                continue;
            }
            $projs++;
            if (!empty($p['tasks']) && is_array($p['tasks'])) {
                $tasks += self::countTasks($p['tasks']);
            }
            if (!empty($p['subprojects']) && is_array($p['subprojects'])) {
                [$c, $s] = self::countSubprojects($p['subprojects']);
                $tasks += $c;
                $projs += $s;
            }
        }
        return [$tasks, $projs];
    }

    /**
     * Projetos elegíveis para "salvar como modelo": raízes (sem pai),
     * não excluídos, não template nativo, respeitando a entidade.
     * Só projetos-raiz porque a captura já leva os subprojetos junto.
     *
     * @return array<array{id:int, name:string}>
     */
    public static function getProjectsForSelect(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_projects',
                'WHERE'  => [
                    'is_deleted'  => 0,
                    'is_template' => 0,
                    'projects_id' => 0,
                ] + getEntitiesRestrictCriteria('glpi_projects'),
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $out[] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Captura de um projeto existente -> modelo (inclui subprojetos)
    // ------------------------------------------------------------------

    /**
     * Grava a árvore COMPLETA de um projeto existente (tarefas + todos os
     * subprojetos, recursivamente, com suas tarefas) como um novo modelo.
     * O projeto de origem NÃO é alterado.
     *
     * @return array{ok: bool, message: string, template_id?: int, tasks_captured?: int, subprojects_captured?: int}
     */
    public static function saveFromProject(int $projectId, string $name, string $comment): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'message' => __('Informe o nome do modelo', 'projectplus')];
        }
        if ($projectId <= 0) {
            return ['ok' => false, 'message' => __('Selecione o projeto de origem', 'projectplus')];
        }

        $project = $DB->request([
            'FROM'  => 'glpi_projects',
            'WHERE' => ['id' => $projectId, 'is_deleted' => 0],
            'LIMIT' => 1,
        ])->current();

        if (!$project) {
            return ['ok' => false, 'message' => __('Projeto não encontrado', 'projectplus')];
        }

        // Referência de início (raiz): data planejada do projeto; sem ela,
        // a menor plan_start_date de qualquer tarefa da árvore; sem nada, agora.
        $refStart = !empty($project['plan_start_date'])
            ? strtotime($project['plan_start_date'])
            : self::earliestTaskStart($projectId);
        if ($refStart === null) {
            $refStart = time();
        }

        $counters = ['tasks' => 0, 'subs' => 0];

        $rootTasks  = self::captureTasks($projectId, $refStart, $counters);
        $visited    = [$projectId => true];
        $subs       = self::captureSubprojects($projectId, $refStart, $visited, $counters);

        if ($counters['tasks'] === 0 && $counters['subs'] === 0) {
            return ['ok' => false, 'message' => __('Este projeto não tem tarefas nem subprojetos para capturar', 'projectplus')];
        }

        // Atributos do próprio projeto raiz (aplicados ao clonar): estado,
        // tipo, gestor, orçamento (teto do plugin), % automático e descrição.
        $structure = [
            'project'     => [
                'offset_start_days' => self::offsetDays($project['plan_start_date'] ?? null, $refStart),
                'duration_days'     => self::durationDays($project['plan_start_date'] ?? null, $project['plan_end_date'] ?? null),
                'projectstates_id'  => (int) ($project['projectstates_id'] ?? 0),
                'projecttypes_id'   => (int) ($project['projecttypes_id'] ?? 0),
                'users_id'          => (int) ($project['users_id'] ?? 0),
                'auto_percent_done' => (int) ($project['auto_percent_done'] ?? 0),
                'budget'            => (float) (Budget::getForProject($projectId)['planned'] ?? 0),
                'content'           => (string) ($project['content'] ?? ''),
            ],
            'tasks'       => $rootTasks,
            'subprojects' => $subs,
        ];

        $now = date('Y-m-d H:i:s');
        $DB->insert('glpi_plugin_projectplus_templates', [
            'name'          => $name,
            'comment'       => trim($comment),
            'entities_id'   => (int) ($project['entities_id'] ?? 0),
            'is_recursive'  => 0,
            'structure'     => json_encode($structure, JSON_UNESCAPED_UNICODE),
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);

        return [
            'ok'                   => true,
            'message'              => sprintf(
                __('Modelo "%1$s" criado com %2$d tarefa(s) e %3$d subprojeto(s)', 'projectplus'),
                $name,
                $counters['tasks'],
                $counters['subs']
            ),
            'template_id'          => (int) $DB->insertId(),
            'tasks_captured'       => $counters['tasks'],
            'subprojects_captured' => $counters['subs'],
        ];
    }

    /**
     * Menor plan_start_date entre as tarefas de um projeto (ou null).
     */
    private static function earliestTaskStart(int $projectId): ?int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $earliest = null;
        foreach (
            $DB->request([
                'SELECT' => ['plan_start_date'],
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => ['projects_id' => $projectId, 'NOT' => ['plan_start_date' => null]],
            ]) as $t
        ) {
            $ts = strtotime($t['plan_start_date']);
            if ($ts !== false && ($earliest === null || $ts < $earliest)) {
                $earliest = $ts;
            }
        }
        return $earliest;
    }

    /**
     * Captura a árvore de tarefas (mãe/filha) de UM projeto, com offsets
     * relativos a $refStart (início da raiz).
     */
    private static function captureTasks(int $projectId, int $refStart, array &$counters): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $byParent = [];
        foreach (
            $DB->request([
                'SELECT' => [
                    'id', 'name', 'content', 'projecttasks_id',
                    'plan_start_date', 'plan_end_date', 'planned_duration',
                    'projectstates_id', 'projecttasktypes_id', 'auto_percent_done',
                ],
                'FROM'  => 'glpi_projecttasks',
                'WHERE' => ['projects_id' => $projectId],
                'ORDER' => ['projecttasks_id', 'id'],
            ]) as $row
        ) {
            $byParent[(int) $row['projecttasks_id']][] = $row;
        }

        // Responsável (primeiro usuário da equipe) por tarefa do projeto.
        $teamByTask = [];
        foreach (
            $DB->request([
                'SELECT'     => [
                    'glpi_projecttaskteams.projecttasks_id',
                    'glpi_projecttaskteams.items_id',
                ],
                'FROM'       => 'glpi_projecttaskteams',
                'INNER JOIN' => [
                    'glpi_projecttasks' => [
                        'ON' => [
                            'glpi_projecttaskteams' => 'projecttasks_id',
                            'glpi_projecttasks'     => 'id',
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_projecttaskteams.itemtype' => 'User',
                    'glpi_projecttasks.projects_id'  => $projectId,
                ],
                'ORDER' => ['glpi_projecttaskteams.projecttasks_id', 'glpi_projecttaskteams.id'],
            ]) as $tm
        ) {
            $tid = (int) $tm['projecttasks_id'];
            if (!isset($teamByTask[$tid])) {
                $teamByTask[$tid] = (int) $tm['items_id']; // primeiro da equipe
            }
        }

        $walk = function (int $parentTaskId) use (&$walk, $byParent, $refStart, &$counters, $teamByTask): array {
            $out = [];
            foreach ($byParent[$parentTaskId] ?? [] as $t) {
                $counters['tasks']++;

                $def = [
                    'name'                => (string) $t['name'],
                    'content'             => (string) ($t['content'] ?? ''),
                    'offset_start_days'   => self::offsetDays($t['plan_start_date'] ?? null, $refStart),
                    'duration_days'       => self::durationDays($t['plan_start_date'] ?? null, $t['plan_end_date'] ?? null),
                    'projectstates_id'    => (int) ($t['projectstates_id'] ?? 0),
                    'projecttasktypes_id' => (int) ($t['projecttasktypes_id'] ?? 0),
                    'auto_percent_done'   => (int) ($t['auto_percent_done'] ?? 0),
                    'users_id'            => (int) ($teamByTask[(int) $t['id']] ?? 0),
                ];
                if (!empty($t['planned_duration'])) {
                    $def['planned_duration'] = (int) $t['planned_duration'];
                }

                $children = $walk((int) $t['id']);
                if (!empty($children)) {
                    $def['children'] = $children;
                }
                $out[] = $def;
            }
            return $out;
        };

        return $walk(0);
    }

    /**
     * Captura recursivamente os subprojetos de $parentId, com trava
     * anti-loop, cada um com sua própria árvore de tarefas.
     */
    private static function captureSubprojects(
        int $parentId,
        int $refStart,
        array &$visited,
        array &$counters
    ): array {
        /** @var \DBmysql $DB */
        global $DB;

        $out = [];
        foreach (
            $DB->request([
                'SELECT' => [
                    'id', 'name', 'content', 'plan_start_date', 'plan_end_date',
                    'projectstates_id', 'projecttypes_id', 'users_id', 'auto_percent_done',
                ],
                'FROM'   => 'glpi_projects',
                'WHERE'  => [
                    'projects_id' => $parentId,
                    'is_deleted'  => 0,
                    'is_template' => 0,
                ],
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $childId = (int) $row['id'];
            if (isset($visited[$childId])) {
                continue; // proteção contra ciclo
            }
            $visited[$childId] = true;
            $counters['subs']++;

            $def = [
                'name'              => (string) $row['name'],
                'content'           => (string) ($row['content'] ?? ''),
                'offset_start_days' => self::offsetDays($row['plan_start_date'] ?? null, $refStart),
                'duration_days'     => self::durationDays($row['plan_start_date'] ?? null, $row['plan_end_date'] ?? null),
                'projectstates_id'  => (int) ($row['projectstates_id'] ?? 0),
                'projecttypes_id'   => (int) ($row['projecttypes_id'] ?? 0),
                'users_id'          => (int) ($row['users_id'] ?? 0),
                'auto_percent_done' => (int) ($row['auto_percent_done'] ?? 0),
                'budget'            => (float) (Budget::getForProject($childId)['planned'] ?? 0),
                'tasks'             => self::captureTasks($childId, $refStart, $counters),
                'subprojects'       => self::captureSubprojects($childId, $refStart, $visited, $counters),
            ];
            $out[] = $def;
        }
        return $out;
    }

    private static function offsetDays(?string $planStart, int $refStart): int
    {
        if (empty($planStart)) {
            return 0;
        }
        $days = (int) round((strtotime($planStart) - $refStart) / DAY_TIMESTAMP);
        return max(0, $days); // offsets negativos não são suportados no modelo
    }

    private static function durationDays(?string $planStart, ?string $planEnd): int
    {
        if (empty($planStart) || empty($planEnd)) {
            return 1;
        }
        $days = (int) round((strtotime($planEnd) - strtotime($planStart)) / DAY_TIMESTAMP) + 1;
        return max(1, $days);
    }

    // ------------------------------------------------------------------
    // Editor visual: carregar / salvar a estrutura em JSON
    // ------------------------------------------------------------------

    /**
     * Dados de um modelo para o editor (id, nome, comentário e o JSON
     * da estrutura já normalizado para {tasks, subprojects}).
     *
     * @return ?array{id:int, name:string, comment:string, structure:string}
     */
    public static function getForEdit(int $id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_projectplus_templates',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])->current();

        if (!$row) {
            return null;
        }

        $structure = json_decode($row['structure'] ?? '', true);
        if (!is_array($structure)) {
            $structure = [];
        }
        $normalized = [
            'project'     => (!empty($structure['project']) && is_array($structure['project'])) ? $structure['project'] : [],
            'tasks'       => (!empty($structure['tasks']) && is_array($structure['tasks'])) ? $structure['tasks'] : [],
            'subprojects' => (!empty($structure['subprojects']) && is_array($structure['subprojects'])) ? $structure['subprojects'] : [],
        ];

        return [
            'id'        => (int) $row['id'],
            'name'      => (string) $row['name'],
            'comment'   => (string) ($row['comment'] ?? ''),
            // HEX_TAG/AMP: o JSON é embutido num <script> — evita que um
            // nome contendo "</script>" quebre a página ou vire XSS.
            'structure' => json_encode(
                $normalized,
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ),
        ];
    }

    /**
     * Cria (id=0) ou atualiza (id>0) um modelo a partir do JSON montado
     * no editor visual. Valida a estrutura antes de gravar.
     *
     * @return array{ok: bool, message: string, template_id?: int}
     */
    public static function saveStructure(int $id, string $name, string $comment, string $structureJson): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'message' => __('Informe o nome do modelo', 'projectplus')];
        }

        $check = TemplateCloner::validateStructure($structureJson);
        if (!$check['valid']) {
            return [
                'ok'      => false,
                'message' => __('Estrutura do modelo inválida', 'projectplus')
                    . ': ' . implode('; ', $check['errors']),
            ];
        }

        // Re-serializa a partir do decode validado (higieniza o JSON)
        $decoded   = json_decode($structureJson, true);
        $clean     = [
            'project'     => (!empty($decoded['project']) && is_array($decoded['project'])) ? $decoded['project'] : [],
            'tasks'       => (!empty($decoded['tasks']) && is_array($decoded['tasks'])) ? $decoded['tasks'] : [],
            'subprojects' => (!empty($decoded['subprojects']) && is_array($decoded['subprojects'])) ? $decoded['subprojects'] : [],
        ];
        if (empty($clean['tasks']) && empty($clean['subprojects'])) {
            return ['ok' => false, 'message' => __('Adicione ao menos uma tarefa ou subprojeto', 'projectplus')];
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $now  = date('Y-m-d H:i:s');

        if ($id > 0) {
            $exists = $DB->request([
                'FROM'  => 'glpi_plugin_projectplus_templates',
                'WHERE' => ['id' => $id],
                'LIMIT' => 1,
            ])->current();
            if (!$exists) {
                return ['ok' => false, 'message' => __('Modelo não encontrado', 'projectplus')];
            }

            $DB->update('glpi_plugin_projectplus_templates', [
                'name'      => $name,
                'comment'   => trim($comment),
                'structure' => $json,
                'date_mod'  => $now,
            ], ['id' => $id]);

            return ['ok' => true, 'message' => __('Modelo atualizado', 'projectplus'), 'template_id' => $id];
        }

        $DB->insert('glpi_plugin_projectplus_templates', [
            'name'          => $name,
            'comment'       => trim($comment),
            'entities_id'   => (int) Session::getActiveEntity(),
            'is_recursive'  => 0,
            'structure'     => $json,
            'date_creation' => $now,
            'date_mod'      => $now,
        ]);

        return ['ok' => true, 'message' => __('Modelo criado', 'projectplus'), 'template_id' => (int) $DB->insertId()];
    }

    /**
     * Listas para os dropdowns do editor visual: estados/fases, tipos de
     * projeto, tipos de tarefa e usuários (gestores possíveis).
     *
     * @return array{states:array, ptypes:array, ttypes:array, users:array}
     */
    public static function getEditorRefData(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $states = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_projectstates', 'ORDER' => 'name']) as $r) {
            $states[] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        }

        $ptypes = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_projecttypes', 'ORDER' => 'name']) as $r) {
            $ptypes[] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        }

        $ttypes = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_projecttasktypes', 'ORDER' => 'name']) as $r) {
            $ttypes[] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        }

        $users = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'realname', 'firstname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['is_active' => 1, 'is_deleted' => 0],
                'ORDER'  => 'realname',
                'LIMIT'  => 500,
            ]) as $r
        ) {
            $label   = trim(($r['realname'] ?? '') . ' ' . ($r['firstname'] ?? ''));
            $users[] = ['id' => (int) $r['id'], 'name' => $label !== '' ? $label : (string) $r['name']];
        }

        return [
            'states' => $states,
            'ptypes' => $ptypes,
            'ttypes' => $ttypes,
            'users'  => $users,
            // Etapa 9: conjunto de fases por tipo de projeto. O editor usa
            // isto para que os campos "Estado" listem só as fases do
            // conjunto do TIPO escolhido para o projeto do modelo — a chave
            // 0 é o conjunto padrão. `states` continua completo porque é o
            // fallback (tipo sem conjunto) e o que resolve um estado antigo
            // já gravado na estrutura JSON do modelo.
            'phases_by_type' => TypePhase::phasesByType(),
        ];
    }

    public static function delete(int $id): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        return (bool) $DB->delete('glpi_plugin_projectplus_templates', ['id' => $id]);
    }
}

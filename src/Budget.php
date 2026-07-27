<?php

namespace GlpiPlugin\Projectplus;

/**
 * Orçamento por projeto (Fase B — parte 1).
 *
 * Modelo híbrido:
 *  - Teto (budget_planned) opcional em QUALQUER projeto (pai ou filho);
 *  - O gasto de um projeto consolida o dele + o de TODOS os descendentes;
 *  - Assim, o teto do pai é o "orçamento global" da árvore, e tetos
 *    individuais nos filhos funcionam como sub-orçamentos.
 *
 * Fonte dos gastos: aba nativa "Custos" do projeto (glpi_projectcosts).
 * O plugin NÃO cria lançamento de custo próprio — só lê e consolida.
 *
 * Campos usados de `glpi_projectcosts`: `projects_id` e `cost`. CONFERIDO
 * contra o schema do core 11 (`install/mysql/glpi-empty.sql`), onde a coluna
 * é `cost decimal(20,4)` — não é suposição por convenção.
 */
class Budget
{
    /**
     * Resumo de orçamento de um projeto.
     *
     * @return array{
     *   planned: float,
     *   spent_own: float,
     *   spent_children: float,
     *   spent_total: float,
     *   percent: ?int,
     *   balance: ?float
     * }
     */
    public static function getForProject(int $projectId): array
    {
        $tracking = ProjectTracking::getForProject($projectId);
        $planned  = (float) ($tracking['budget_planned'] ?? 0);

        $spentOwn      = self::getOwnCosts($projectId);
        $descendants   = self::getDescendantIds($projectId);
        $spentChildren = 0.0;
        foreach ($descendants as $childId) {
            $spentChildren += self::getOwnCosts($childId);
        }
        $spentTotal = $spentOwn + $spentChildren;

        return [
            'planned'        => $planned,
            'spent_own'      => $spentOwn,
            'spent_children' => $spentChildren,
            'spent_total'    => $spentTotal,
            'percent'        => $planned > 0 ? (int) round(($spentTotal / $planned) * 100) : null,
            'balance'        => $planned > 0 ? $planned - $spentTotal : null,
        ];
    }

    /**
     * Define o teto de orçamento de um projeto (upsert em projecttrackings).
     */
    public static function setPlanned(int $projectId, float $value): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $now      = date('Y-m-d H:i:s');
        $existing = ProjectTracking::getForProject($projectId);

        if ($existing) {
            $DB->update(
                ProjectTracking::getTable(),
                ['budget_planned' => $value, 'date_mod' => $now],
                ['projects_id' => $projectId]
            );
        } else {
            $DB->insert(ProjectTracking::getTable(), [
                'projects_id'    => $projectId,
                'budget_planned' => $value,
                'date_creation'  => $now,
                'date_mod'       => $now,
            ]);
        }

        // Mantém o snapshot de gasto atualizado na mesma tabela
        self::refreshSpent($projectId);
    }

    /**
     * Atualiza o snapshot budget_spent (gasto consolidado) na tabela.
     */
    public static function refreshSpent(int $projectId): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $budget = self::getForProject($projectId);
        $DB->update(
            ProjectTracking::getTable(),
            ['budget_spent' => $budget['spent_total'], 'date_mod' => date('Y-m-d H:i:s')],
            ['projects_id' => $projectId]
        );
    }

    /**
     * Gasto próprio do projeto (Bloco 5.3 — fonte única no plugin):
     *   custos do projeto (aba própria, glpi_plugin_projectplus_projectcosts)
     *   + custos lançados nas TAREFAS do projeto (aba do plugin).
     *
     * A aba "Custos" NATIVA não é mais lida — os lançamentos antigos
     * foram migrados para a tabela do plugin e a aba fica oculta via JS.
     *
     * Como a consolidação pai+filhos soma o getOwnCosts de cada
     * descendente, os custos de tarefas dos subprojetos também entram.
     */
    public static function getOwnCosts(int $projectId): float
    {
        return ProjectCost::getTotalForProject($projectId)
            + TaskCost::getTotalForProject($projectId);
    }

    /**
     * Lançamentos de custo de UM projeto (sem descendentes), já com origem:
     *  - aba nativa "Custos" do projeto;
     *  - custos por tarefa (aba do plugin), com o nome da tarefa.
     *
     * @return array<array{origin: string, name: string, date: ?string, cost: float, comment: string}>
     */
    public static function getEntriesForProject(int $projectId, string $originLabel): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $entries = [];

        if ($DB->tableExists(ProjectCost::getTable())) {
            foreach (
                $DB->request([
                    'SELECT'    => [
                        ProjectCost::getTable() . '.*',
                        'glpi_users.realname AS author_realname',
                        'glpi_users.firstname AS author_firstname',
                        'glpi_users.name AS author_login',
                    ],
                    'FROM'      => ProjectCost::getTable(),
                    'LEFT JOIN' => [
                        'glpi_users' => [
                            'ON' => [
                                ProjectCost::getTable() => 'users_id',
                                'glpi_users'            => 'id',
                            ],
                        ],
                    ],
                    'WHERE' => ['projects_id' => $projectId],
                ]) as $row
            ) {
                $author = trim(
                    (string) ($row['author_realname'] ?? '') . ' '
                    . (string) ($row['author_firstname'] ?? '')
                );
                if ($author === '') {
                    $author = (string) ($row['author_login'] ?? '');
                }

                $entries[] = [
                    'origin'  => $originLabel,
                    'name'    => (string) ($row['name'] ?? ''),
                    'date'    => $row['date'] ?? null,
                    'cost'    => (float) ($row['cost'] ?? 0),
                    'comment' => (string) ($row['comment'] ?? ''),
                    'author'  => $author !== '' ? $author : null,
                ];
            }
        }

        if ($DB->tableExists('glpi_plugin_projectplus_taskcosts')) {
            foreach (
                $DB->request([
                    'SELECT'     => [
                        'glpi_plugin_projectplus_taskcosts.name',
                        'glpi_plugin_projectplus_taskcosts.date',
                        'glpi_plugin_projectplus_taskcosts.cost',
                        'glpi_plugin_projectplus_taskcosts.comment',
                        'glpi_projecttasks.name AS task_name',
                        'glpi_users.realname AS author_realname',
                        'glpi_users.firstname AS author_firstname',
                        'glpi_users.name AS author_login',
                    ],
                    'FROM'       => 'glpi_plugin_projectplus_taskcosts',
                    'INNER JOIN' => [
                        'glpi_projecttasks' => [
                            'ON' => [
                                'glpi_plugin_projectplus_taskcosts' => 'projecttasks_id',
                                'glpi_projecttasks'                 => 'id',
                            ],
                        ],
                    ],
                    'LEFT JOIN'  => [
                        'glpi_users' => [
                            'ON' => [
                                'glpi_plugin_projectplus_taskcosts' => 'users_id',
                                'glpi_users'                        => 'id',
                            ],
                        ],
                    ],
                    'WHERE' => ['glpi_projecttasks.projects_id' => $projectId],
                ]) as $row
            ) {
                $author = trim(
                    (string) ($row['author_realname'] ?? '') . ' '
                    . (string) ($row['author_firstname'] ?? '')
                );
                if ($author === '') {
                    $author = (string) ($row['author_login'] ?? '');
                }

                $entries[] = [
                    'origin'  => $originLabel . ' · ' . sprintf(
                        __('Tarefa "%s"', 'projectplus'),
                        $row['task_name'] ?? '—'
                    ),
                    'name'    => (string) ($row['name'] ?? ''),
                    'date'    => $row['date'] ?? null,
                    'cost'    => (float) ($row['cost'] ?? 0),
                    'comment' => (string) ($row['comment'] ?? ''),
                    'author'  => $author !== '' ? $author : null,
                ];
            }
        }

        return $entries;
    }

    /**
     * IDs de todos os descendentes (recursivo, com trava anti-loop).
     */
    public static function getDescendantIds(int $projectId, array &$visited = []): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids      = [];
        $iterator = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_projects',
            'WHERE'  => [
                'projects_id' => $projectId,
                'is_deleted'  => 0,
                'is_template' => 0,
            ],
        ]);

        foreach ($iterator as $row) {
            $childId = (int) $row['id'];
            if (isset($visited[$childId])) {
                continue; // proteção contra ciclo
            }
            $visited[$childId] = true;
            $ids[] = $childId;
            $ids   = array_merge($ids, self::getDescendantIds($childId, $visited));
        }
        return $ids;
    }

    /**
     * Verificação de orçamento para o cron (chamada por Notification).
     * Alerta o gestor do projeto ao cruzar o limiar e ao estourar.
     *
     * @return int alertas novos gerados
     */
    public static function cronCheck(int $warnPercent): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $count    = 0;
        $iterator = $DB->request([
            'SELECT'    => [
                ProjectTracking::getTable() . '.projects_id',
                'glpi_projects.name',
                'glpi_projects.users_id',
            ],
            'FROM'      => ProjectTracking::getTable(),
            'LEFT JOIN' => [
                'glpi_projects' => [
                    'ON' => [
                        ProjectTracking::getTable() => 'projects_id',
                        'glpi_projects'             => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                ProjectTracking::getTable() . '.budget_planned' => ['>', 0],
                'glpi_projects.is_deleted'                      => 0,
            ],
        ]);

        foreach ($iterator as $row) {
            $projectId = (int) $row['projects_id'];
            $managerId = (int) ($row['users_id'] ?? 0);
            if ($managerId <= 0) {
                continue;
            }

            $budget = self::getForProject($projectId);
            self::refreshSpent($projectId);

            if ($budget['percent'] === null) {
                continue;
            }

            if ($budget['percent'] > 100) {
                $count += (int) Notification::budgetAlert(
                    $managerId,
                    $projectId,
                    'budget_over',
                    sprintf(
                        __('Orçamento ESTOURADO no projeto "%s": %d%% consumido', 'projectplus'),
                        $row['name'],
                        $budget['percent']
                    )
                );
            } elseif ($budget['percent'] >= $warnPercent) {
                $count += (int) Notification::budgetAlert(
                    $managerId,
                    $projectId,
                    'budget_warn',
                    sprintf(
                        __('Orçamento do projeto "%s" atingiu %d%% do teto', 'projectplus'),
                        $row['name'],
                        $budget['percent']
                    )
                );
            }
        }
        return $count;
    }
}

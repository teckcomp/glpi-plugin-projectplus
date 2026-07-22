<?php

namespace GlpiPlugin\Projectplus;

use Project;
use ProjectTask;

/**
 * ProjectPlus — Timeline (Etapa 3, bloco final).
 *
 * Gantt somente-leitura em HTML/JS puro: todos os projetos (árvore
 * pai/filho) com todas as suas tarefas (árvore mãe/filha), no período
 * planejado (plan_start_date / plan_end_date).
 *
 * Regras de desenho (aplicadas no JS a partir dos campos daqui):
 * - start E end presentes  -> barra;
 * - só uma das datas       -> losango (marco) na data existente;
 * - nenhuma data           -> linha listada sem barra ("sem datas").
 *
 * Sem AJAX próprio: os dados são embutidos na página pelo front/timeline.php
 * (lição nº 9: toda chave nova precisa ser repassada explicitamente lá).
 */
class Timeline
{
    /**
     * Dados completos da timeline.
     *
     * @param int|null $onlyUserId quando informado, restringe às tarefas em
     *                             que o usuário está na equipe (itemtype
     *                             User) e aos projetos dessas tarefas —
     *                             usado para perfis sem Projetos "ver todos"
     *
     * @return array{range: array{min: string, max: string, today: string},
     *               groups: array}
     */
    public static function getData(?int $onlyUserId = null, ?array $taskProjectIds = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $states = Dashboard::getStatesMap();
        $now    = time();

        // Escopo (Bloco 3): personal usa $onlyUserId (minhas tarefas);
        // managed usa $taskProjectIds (tarefas dos meus projetos); "todos"
        // = ambos null. Só o "todos" mostra projetos sem tarefas no escopo.
        $scopeProj = ($taskProjectIds !== null)
            ? array_flip(array_map('intval', $taskProjectIds))
            : null;
        $showEmpty = ($onlyUserId === null && $taskProjectIds === null);

        // ---------- Projetos (todos, em árvore) ----------
        $projByParent = [];
        foreach (
            $DB->request([
                'SELECT' => [
                    'id', 'name', 'projects_id', 'percent_done',
                    'plan_start_date', 'plan_end_date', 'projectstates_id',
                ],
                'FROM'   => 'glpi_projects',
                'WHERE'  => [
                    'is_deleted'  => 0,
                    'is_template' => 0,
                ] + getEntitiesRestrictCriteria('glpi_projects'),
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $projByParent[(int) $row['projects_id']][] = $row;
        }

        if (empty($projByParent)) {
            return [
                'range'  => self::rangeFrom([], $now),
                'groups' => [],
            ];
        }

        // ---------- Escopo por usuário (perfil sem "ver todos") ----------
        // Tarefas em que o usuário está na equipe, em consulta única.
        $mine = null;
        if ($onlyUserId !== null) {
            $mine = [];
            foreach (
                $DB->request([
                    'SELECT' => 'projecttasks_id',
                    'FROM'   => 'glpi_projecttaskteams',
                    'WHERE'  => [
                        'itemtype' => 'User',
                        'items_id' => $onlyUserId,
                    ],
                ]) as $r
            ) {
                $mine[(int) $r['projecttasks_id']] = true;
            }
        }

        // ---------- Tarefas de todos os projetos, agrupadas ----------
        $tasksByProject = [];
        $allTaskIds     = [];
        foreach (
            $DB->request([
                'SELECT' => [
                    'id', 'name', 'projects_id', 'projecttasks_id',
                    'percent_done', 'plan_start_date', 'plan_end_date',
                    'projectstates_id',
                ],
                'FROM'  => 'glpi_projecttasks',
                'ORDER' => ['projecttasks_id', 'id'],
            ]) as $row
        ) {
            if ($mine !== null && !isset($mine[(int) $row['id']])) {
                continue; // fora do escopo do usuário (personal)
            }
            if ($scopeProj !== null && !isset($scopeProj[(int) $row['projects_id']])) {
                continue; // fora dos projetos do escopo (managed)
            }
            $tasksByProject[(int) $row['projects_id']][] = $row;
            $allTaskIds[] = (int) $row['id'];
        }

        // 🔒 explícitos + implícitos — mesma consulta única das outras telas
        $deps = TaskDep::countForTasks($allTaskIds);

        // ---------- Montagem em árvore + intervalo global ----------
        $dates  = [];
        $groups = [];

        $walkProjects = function (int $parentId, int $depth) use (
            &$walkProjects,
            &$groups,
            &$dates,
            $projByParent,
            $tasksByProject,
            $states,
            $deps,
            $now,
            $showEmpty
        ): void {
            foreach ($projByParent[$parentId] ?? [] as $p) {
                $pid     = (int) $p['id'];
                $stateId = (int) $p['projectstates_id'];
                $pct     = (int) $p['percent_done'];
                $start   = $p['plan_start_date'] ? substr($p['plan_start_date'], 0, 10) : null;
                $end     = $p['plan_end_date'] ? substr($p['plan_end_date'], 0, 10) : null;

                $group = [
                    'id'          => $pid,
                    'name'        => $p['name'],
                    'depth'       => $depth,
                    'url'         => Project::getFormURLWithID($pid),
                    'start'       => $start,
                    'end'         => $end,
                    'percent'     => $pct,
                    'state_name'  => $states[$stateId]['name'] ?? null,
                    'state_color' => $states[$stateId]['color'] ?? Dashboard::PHASE_DEFAULT_COLOR,
                    'is_overdue'  => $end !== null && strtotime($end . ' 23:59:59') < $now && $pct < 100,
                    'tasks'       => [],
                ];

                // Tarefas do projeto em árvore (mesma ordem de getTasks).
                // Reindexação mãe/filha: no escopo por usuário, a filha
                // cuja mãe ficou fora do escopo sobe para a raiz do
                // projeto (mesma regra de "Minhas tarefas").
                $flat  = $tasksByProject[$pid] ?? [];
                $inSet = [];
                foreach ($flat as $t) {
                    $inSet[(int) $t['id']] = true;
                }
                $byParent = [];
                foreach ($flat as $t) {
                    $key = isset($inSet[(int) $t['projecttasks_id']])
                        ? (int) $t['projecttasks_id'] : 0;
                    $byParent[$key][] = $t;
                }
                $walkTask = function (int $taskParent, int $taskDepth) use (
                    &$walkTask,
                    &$group,
                    &$dates,
                    $byParent,
                    $states,
                    $deps,
                    $now
                ): void {
                    foreach ($byParent[$taskParent] ?? [] as $t) {
                        $tid      = (int) $t['id'];
                        $tStateId = (int) $t['projectstates_id'];
                        $tPct     = (int) $t['percent_done'];
                        $tStart   = $t['plan_start_date'] ? substr($t['plan_start_date'], 0, 10) : null;
                        $tEnd     = $t['plan_end_date'] ? substr($t['plan_end_date'], 0, 10) : null;
                        if ($tStart) {
                            $dates[] = $tStart;
                        }
                        if ($tEnd) {
                            $dates[] = $tEnd;
                        }

                        $group['tasks'][] = [
                            'id'          => $tid,
                            'name'        => $t['name'],
                            'depth'       => $taskDepth,
                            'url'         => ProjectTask::getFormURLWithID($tid),
                            'start'       => $tStart,
                            'end'         => $tEnd,
                            'percent'     => $tPct,
                            'state_name'  => $states[$tStateId]['name'] ?? null,
                            'state_color' => $states[$tStateId]['color'] ?? Dashboard::PHASE_DEFAULT_COLOR,
                            'is_done'     => $tPct >= 100,
                            'is_overdue'  => $tEnd !== null && strtotime($tEnd . ' 23:59:59') < $now && $tPct < 100,
                            'blocked'     => $deps[$tid]['blocked'] ?? false,
                        ];
                        $walkTask($tid, $taskDepth + 1);
                    }
                };
                $walkTask(0, 0);

                // Nos escopos pessoal/gerência, projeto sem tarefas do
                // escopo sai da lista (subprojetos ainda são percorridos);
                // só o "Ver tudo" total mostra projetos vazios.
                if ($showEmpty || !empty($group['tasks'])) {
                    if ($start) {
                        $dates[] = $start;
                    }
                    if ($end) {
                        $dates[] = $end;
                    }
                    $groups[] = $group;
                }
                $walkProjects($pid, $depth + 1);
            }
        };
        $walkProjects(0, 0);

        return [
            'range'  => self::rangeFrom($dates, $now),
            'groups' => $groups,
        ];
    }

    /**
     * Intervalo global da régua: min/max das datas encontradas com 7 dias
     * de folga em cada ponta; sem datas, mês corrente ± 30 dias.
     *
     * @param string[] $dates datas Y-m-d
     */
    private static function rangeFrom(array $dates, int $now): array
    {
        if (!empty($dates)) {
            $min = strtotime(min($dates) . ' 12:00:00') - (7 * 86400);
            $max = strtotime(max($dates) . ' 12:00:00') + (7 * 86400);
        } else {
            $min = $now - (30 * 86400);
            $max = $now + (30 * 86400);
        }

        // A linha "hoje" precisa caber na régua mesmo com tudo no passado/futuro
        $min = min($min, $now - (3 * 86400));
        $max = max($max, $now + (3 * 86400));

        return [
            'min'   => date('Y-m-d', $min),
            'max'   => date('Y-m-d', $max),
            'today' => date('Y-m-d', $now),
        ];
    }
}

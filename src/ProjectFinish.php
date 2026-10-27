<?php

namespace GlpiPlugin\Projectplus;

use Project;
use ProjectTask;
use Session;

/**
 * ProjectPlus — fase finalizadora que finaliza de verdade (O-2d, 27/10/2026).
 *
 * Pedido do Claudio: levar um PROJETO para uma fase finalizadora finaliza
 * tudo o que está abaixo dele (subprojetos de qualquer nível, tarefas e
 * subtarefas). Com itens não finalizados, o plugin não recusa mais em
 * silêncio: conta os itens e pergunta "Finalizar mesmo assim?" — e só o
 * Super-Admin ou o gestor da árvore podem confirmar (decisão do Claudio; a
 * equipe do projeto continua recebendo a recusa de antes).
 *
 * "Não finalizado" segue a régua que o plugin já usava na guarda de fase:
 *   - tarefa (fora da lixeira) com % < 100;
 *   - subprojeto (fora da lixeira, não modelo) com % < 100 E fase que não é
 *     finalizadora.
 * Itens já concluídos não são tocados.
 *
 * A finalização vai de BAIXO para CIMA (subtarefas antes das mães, tarefas
 * antes dos projetos, subprojeto mais fundo primeiro) e, durante ela,
 * `$running` desliga as recusas dos hooks de fase (TaskDep) — inclusive a de
 * dependência: o "mesmo assim" vale para a árvore inteira. Cada item recebe
 * 100% e uma fase finalizadora: a escolhida para o projeto quando ela faz
 * parte do conjunto do tipo do item; senão, a primeira finalizadora do
 * conjunto do tipo dele (phaseFor).
 *
 * A ficha nativa do GLPI não tem como perguntar: lá vale a guarda antiga
 * (TaskDep::onProjectPreUpdate).
 */
class ProjectFinish
{
    /** Finalização em cascata em andamento: os hooks não recusam a fase. */
    public static bool $running = false;

    /** Quantos nomes entram na mensagem. */
    public const NAMES_IN_MESSAGE = 5;

    /**
     * Itens NÃO finalizados abaixo do projeto (ele mesmo fora).
     *
     * @return array{
     *   tasks: array<int, array{name:string, projects_id:int, parent:int}>,
     *   projects: array<int, array{name:string, parent:int, type:int}>
     * }
     */
    public static function openItems(int $projectId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $out = ['tasks' => [], 'projects' => []];
        if ($projectId <= 0) {
            return $out;
        }
        $descendants = Budget::getDescendantIds($projectId);
        $finished    = TaskDep::finishedStateIds();

        if (!empty($descendants)) {
            foreach (
                $DB->request([
                    'SELECT' => ['id', 'name', 'projects_id', 'projecttypes_id', 'projectstates_id', 'percent_done'],
                    'FROM'   => 'glpi_projects',
                    'WHERE'  => ['id' => $descendants, 'is_deleted' => 0, 'is_template' => 0],
                    'ORDER'  => 'name',
                ]) as $row
            ) {
                $done = (int) $row['percent_done'] >= 100
                    || in_array((int) $row['projectstates_id'], $finished, true);
                if (!$done) {
                    $out['projects'][(int) $row['id']] = [
                        'name'   => (string) $row['name'],
                        'parent' => (int) $row['projects_id'],
                        'type'   => (int) $row['projecttypes_id'],
                    ];
                }
            }
        }

        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'projects_id', 'projecttasks_id'],
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => array_merge([
                    'projects_id'  => array_merge([$projectId], $descendants),
                    'percent_done' => ['<', 100],
                ], Trash::liveTasks()),
                'ORDER'  => 'name',
            ]) as $row
        ) {
            $out['tasks'][(int) $row['id']] = [
                'name'        => (string) $row['name'],
                'projects_id' => (int) $row['projects_id'],
                'parent'      => (int) $row['projecttasks_id'],
            ];
        }

        return $out;
    }

    /**
     * Resumo para a mensagem: total e os primeiros nomes (subprojetos antes
     * de tarefas).
     *
     * @return array{count:int, names:string[]}
     */
    public static function summary(array $open): array
    {
        $names = [];
        foreach ($open['projects'] as $p) {
            $names[] = $p['name'];
        }
        foreach ($open['tasks'] as $t) {
            $names[] = $t['name'];
        }
        return ['count' => count($names), 'names' => $names];
    }

    /** "A, B, C, D, E…" com no máximo NAMES_IN_MESSAGE nomes. */
    public static function namesText(array $names): string
    {
        return implode(', ', array_slice($names, 0, self::NAMES_IN_MESSAGE))
            . (count($names) > self::NAMES_IN_MESSAGE ? '…' : '');
    }

    /** Pergunta do "Finalizar mesmo assim?" (texto pronto, já traduzido). */
    public static function confirmText(array $summary): string
    {
        $n = (int) $summary['count'];
        return sprintf(
            _n(
                'Existe %1$d item não finalizado abaixo deste projeto: %2$s. Finalizar mesmo assim? Ele vai para uma fase finalizadora com 100%%.',
                'Existem %1$d itens não finalizados abaixo deste projeto: %2$s. Finalizar mesmo assim? Todos vão para uma fase finalizadora com 100%%.',
                $n,
                'projectplus'
            ),
            $n,
            self::namesText($summary['names'])
        );
    }

    /** Recusa para quem não pode confirmar (mesma frase da trava antiga). */
    public static function refuseText(array $summary): string
    {
        return sprintf(
            __('Não é possível mover para uma fase finalizada — %d item(ns) aberto(s): %s', 'projectplus'),
            (int) $summary['count'],
            self::namesText($summary['names'])
        );
    }

    /**
     * Pode usar o "Finalizar mesmo assim"? Super-Admin ou gestor do projeto
     * (ou de um acima) — decisão do Claudio, 27/10/2026. Cliente nunca.
     *
     * @param Project $project
     */
    public static function canForce($project): bool
    {
        if (Access::isClient()) {
            return false;
        }
        if (!Session::haveAccessToEntity((int) $project->getEntityID())) {
            return false;
        }
        return Access::isSuperAdmin() || Scope::managesProject((int) $project->getID());
    }

    /**
     * Fase finalizadora para um item do tipo $typeId: a escolhida ($target)
     * se ela está no conjunto do tipo; senão a primeira finalizadora do
     * conjunto; sem nenhuma, $target.
     */
    public static function phaseFor(int $typeId, int $target): int
    {
        $set = TypePhase::statesFor($typeId);
        if (isset($set[$target])) {
            return $target;
        }
        $finished = TaskDep::finishedStateIds();
        foreach (array_keys($set) as $sid) {
            if (in_array((int) $sid, $finished, true)) {
                return (int) $sid;
            }
        }
        return $target;
    }

    /**
     * Ordem de baixo para cima: profundidade dentro do próprio conjunto
     * (pai que também está na lista conta um nível), maior primeiro.
     *
     * @param array<int, array{parent:int}> $items
     * @return int[]
     */
    public static function bottomUp(array $items): array
    {
        $depth = [];
        $calc  = function (int $id, int $guard = 0) use (&$calc, &$depth, $items): int {
            if (isset($depth[$id])) {
                return $depth[$id];
            }
            $parent = (int) ($items[$id]['parent'] ?? 0);
            $d = ($parent > 0 && isset($items[$parent]) && $guard < 1000) ? $calc($parent, $guard + 1) + 1 : 0;
            return $depth[$id] = $d;
        };
        $ids = array_keys($items);
        foreach ($ids as $id) {
            $calc((int) $id);
        }
        usort($ids, static function ($a, $b) use ($depth) {
            return ($depth[$b] <=> $depth[$a]) ?: ($a <=> $b);
        });
        return array_map('intval', $ids);
    }

    /**
     * Finaliza tudo o que está aberto abaixo do projeto (ele mesmo fica
     * para quem chama). Quem chama já conferiu canForce().
     *
     * @return array{tasks:int, projects:int}
     */
    public static function finishBelow(int $projectId, int $target, ?array $open = null): array
    {
        $open   = $open ?? self::openItems($projectId);
        $done   = ['tasks' => 0, 'projects' => 0];
        $types  = [];
        $typeOf = static function (int $pid) use (&$types): int {
            return $types[$pid] ??= TypePhase::typeOfProject($pid);
        };

        $was = self::$running;
        self::$running = true;
        try {
            foreach (self::bottomUp($open['tasks']) as $tid) {
                $task = new ProjectTask();
                if (!$task->getFromDB($tid)) {
                    continue;
                }
                $ok = $task->update([
                    'id'               => $tid,
                    'projectstates_id' => self::phaseFor($typeOf((int) $open['tasks'][$tid]['projects_id']), $target),
                    'percent_done'     => 100,
                ]);
                if ($ok) {
                    $done['tasks']++;
                }
            }
            foreach (self::bottomUp($open['projects']) as $pid) {
                $project = new Project();
                if (!$project->getFromDB($pid)) {
                    continue;
                }
                $ok = $project->update([
                    'id'               => $pid,
                    'projectstates_id' => self::phaseFor((int) $open['projects'][$pid]['type'], $target),
                    'percent_done'     => 100,
                ]);
                if ($ok) {
                    $done['projects']++;
                    ProjectTracking::touch($pid);
                }
            }
        } finally {
            self::$running = $was;
        }
        return $done;
    }
}

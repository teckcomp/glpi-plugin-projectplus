<?php

/**
 * ProjectPlus — camada de ESCOPO (Etapa 8, Bloco 3; regras por VÍNCULO no
 * Ciclo O, bloco O-1a, 23/10/2026).
 *
 * Enquanto `Access` responde "o perfil PODE ver este módulo?", `Scope`
 * responde "QUAIS itens este usuário vê agora?". O PERFIL diz o quê; o
 * VÍNCULO com o projeto diz onde (ROADMAP, Ciclo O, regras R1–R8).
 *
 * Modos (`mode()`):
 *   - all      : sem filtro. Super-Admin (nativo "Configuração: Atualizar",
 *                R1) e quem tem "Ver todos" (`seeall`, visão de LEITURA —
 *                a ação continua só onde é gestor ou atribuído, O-1b).
 *   - managed  : quem é GESTOR de algum projeto (tabela própria,
 *                `ProjectManager`; não depende mais de direito — R2/R8).
 *                PROJETOS: os da equipe (cada um por si) + os que gerencia,
 *                com a ÁRVORE inteira (subprojetos). TAREFAS: as minhas +
 *                TODAS as da árvore que gerencia (R2). Gestor de subprojeto
 *                não vê o projeto de cima (R7) — ele não entra no escopo.
 *   - personal : os demais, e qualquer um com `?scope=mine` (R8).
 *                PROJETOS: os da equipe do projeto (cada um por si);
 *                TAREFAS: só as minhas (equipe da tarefa) — R5. Inalterado
 *                desde o Bloco F-1.
 * Usuário com "Visão do cliente" nunca é gestor para o escopo (R6).
 *
 * O padrão é o MAIOR modo do usuário; `?scope=mine` reduz ao pessoal, sem
 * memória de sessão. O direito "Escopo: ver projetos que gerencia"
 * (`seemanaged`) deixou de ser consultado — virou legado (R8).
 *
 * Classe estática, sem extends — autoload PSR-4.
 */

namespace GlpiPlugin\Projectplus;

use Session;

class Scope
{
    /** Cache por requisição: uid => projetos que gerencia (ids exatos). */
    private static array $managedCache = [];

    /** Cache por requisição: uid => tarefas da árvore que gerencia. */
    private static array $managedTaskCache = [];

    /** Para harness: esquece os caches da requisição. */
    public static function resetCache(): void
    {
        self::$managedCache     = [];
        self::$managedTaskCache = [];
    }

    /**
     * Modo efetivo: 'personal' | 'managed' | 'all'.
     *
     * O PADRÃO é o maior modo do usuário (all → managed → personal); ele
     * REDUZ ao pessoal via `?scope=mine` (sem memória de sessão).
     */
    public static function mode(): string
    {
        $wantsMine = (($_GET['scope'] ?? '') === 'mine');
        if ($wantsMine) {
            return 'personal';
        }
        if (self::seesAll()) {
            return 'all';
        }
        if (self::managedProjects((int) Session::getLoginUserID()) !== []) {
            return 'managed';
        }
        return 'personal';
    }

    /**
     * Vê todos os projetos? Super-Admin (R1) ou "Ver todos" (leitura, R1).
     */
    public static function seesAll(): bool
    {
        return Access::isSuperAdmin() || Access::can('seeall');
    }

    /**
     * O usuário pode alternar o escopo (botão "Todos | Só os meus")? Só faz
     * sentido quando o maior modo dele é diferente do pessoal.
     */
    public static function canExpand(): bool
    {
        return self::seesAll()
            || self::managedProjects((int) Session::getLoginUserID()) !== [];
    }

    /** O escopo atual já está ampliado (diferente do pessoal)? */
    public static function isExpanded(): bool
    {
        return self::mode() !== 'personal';
    }

    /**
     * Lista pronta para o `IN` do GLPI: vazio vira `[0]` (nada), nunca lista
     * vazia. `null` → `[]` (o chamador só usa quando o filtro está ativo).
     */
    public static function inList(?array $ids): array
    {
        if ($ids === null) {
            return [];
        }
        return $ids === [] ? [0] : $ids;
    }

    /**
     * IDs dos projetos a LISTAR (cada um por si — raiz OU subprojeto).
     * `null` = sem filtro (modo 'all'); array vazio = não participa de nada.
     *
     * No managed: equipe + gerenciados, SEM os que já estão dentro da árvore
     * de um projeto gerenciado (esses aparecem pela expansão do pai — senão
     * o mesmo subprojeto viria duas vezes no Painel). O alcance completo é
     * `reachProjectIds()`.
     */
    public static function projectIds(?string $mode = null): ?array
    {
        $mode = $mode ?? self::mode();
        if ($mode === 'all') {
            return null;
        }
        $uid = (int) Session::getLoginUserID();
        $ids = self::teamProjects($uid);
        if ($mode === 'managed') {
            $managed = self::managedProjects($uid);
            foreach ($managed as $p) {
                $ids[(int) $p] = true;
            }
            foreach (self::managedDescendants($managed) as $d) {
                unset($ids[(int) $d]);
            }
        }
        return array_map('intval', array_keys($ids));
    }

    /**
     * ALCANCE de projetos: tudo o que o usuário enxerga como projeto
     * (lista + árvore gerenciada). `null` no 'all'. Para telas que listam
     * projetos soltos, sem expansão (Kanban de projetos, Relatório de
     * projetos, burndown, seletor de tipo dos Kanbans).
     */
    public static function reachProjectIds(?string $mode = null): ?array
    {
        $map = self::visibleProjectMap($mode);
        return $map === null ? null : array_map('intval', array_keys($map));
    }

    /**
     * O projeto está DENTRO do escopo atual do usuário? (Bloco A, 28/09/2026)
     *
     * Usada pelo foco do painel (`front/dashboard.php?project=ID`) e
     * pensada para virar a base do guard de escopo da Etapa 10.
     * `projectIds() === null` = modo "todos" (sem filtro). No modo
     * "managed", os descendentes entram por taskProjectIds().
     * NÃO checa entidade nem exclusão — quem chama faz isso.
     */
    public static function canSeeProject(int $projectId, ?string $mode = null): bool
    {
        $mode = $mode ?? self::mode();

        return self::projectInLists(
            $projectId,
            self::projectIds($mode),
            self::taskProjectIds($mode)
        );
    }

    /**
     * Parte pura da regra acima (recebe as listas prontas) — é o que o
     * harness exercita, sem banco nem sessão.
     */
    public static function projectInLists(int $projectId, ?array $projectIds, ?array $taskProjectIds): bool
    {
        if ($projectId <= 0) {
            return false;
        }
        if ($projectIds === null) {
            return true; // modo "todos": sem filtro de projeto
        }
        if (in_array($projectId, array_map('intval', $projectIds), true)) {
            return true;
        }

        return in_array($projectId, array_map('intval', (array) $taskProjectIds), true);
    }

    /**
     * IDs das tarefas visíveis — filtra lista/indicadores de tarefas.
     * personal = as minhas (equipe da tarefa); managed = as minhas + TODAS
     * as da árvore que gerencia (R2); `null` só no 'all'.
     */
    public static function myTaskIds(?string $mode = null): ?array
    {
        $mode = $mode ?? self::mode();
        if ($mode === 'all') {
            return null;
        }
        $uid  = (int) Session::getLoginUserID();
        $mine = self::myTasks($uid);
        if ($mode !== 'managed') {
            return $mine;
        }
        return self::mergeIds($mine, self::managedTreeTasks($uid));
    }

    /** União de listas de ids, sem repetição, só positivos. */
    public static function mergeIds(array ...$lists): array
    {
        $out = [];
        foreach ($lists as $list) {
            foreach ($list as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $out[$id] = true;
                }
            }
        }
        return array_keys($out);
    }

    /**
     * PROJETOS alcançáveis no modo 'managed': os da equipe (cada um por si)
     * + os que ele gerencia COM descendentes. `null` fora do managed.
     *
     * Bloco F-1: o nome ficou do tempo em que filtrava tarefas por projeto;
     * hoje serve a canSeeProject(), às opções de projeto de "Minhas
     * tarefas" e à expansão de subprojetos do painel. Antes somava os
     * descendentes também dos projetos em que ele era só da equipe.
     * O-1a: "gerencia" = vínculo na tabela de gestores (ProjectManager).
     */
    public static function taskProjectIds(?string $mode = null): ?array
    {
        $mode = $mode ?? self::mode();
        if ($mode !== 'managed') {
            return null;
        }
        $uid = (int) Session::getLoginUserID();
        $all = self::teamProjects($uid);
        foreach (self::managedTree($uid) as $pid) {
            $all[(int) $pid] = true;
        }
        return array_map('intval', array_keys($all));
    }

    /**
     * Projetos que o usuário pode VER no modo atual (lista ou expansão):
     * `null` = todos; personal = equipe; managed = equipe + gerenciados com
     * descendentes. Uma consulta só — o laço de getChildren() usa o mapa.
     *
     * @return array<int, true>|null
     */
    public static function visibleProjectMap(?string $mode = null): ?array
    {
        $mode = $mode ?? self::mode();
        $ids  = self::projectIds($mode);
        if ($ids === null) {
            return null;
        }
        $map = [];
        foreach (array_merge($ids, (array) self::taskProjectIds($mode)) as $id) {
            $map[(int) $id] = true;
        }
        return $map;
    }

    // ---------------------------------------------------------------
    // Internos — cada um devolve um MAPA id=>true (chaves = ids)
    // ---------------------------------------------------------------

    /** Projetos onde o usuário está na equipe do projeto. */
    private static function teamProjects(int $uid): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => 'projects_id',
                'FROM'   => 'glpi_projectteams',
                'WHERE'  => ['itemtype' => 'User', 'items_id' => $uid],
            ]) as $r
        ) {
            $ids[(int) $r['projects_id']] = true;
        }
        unset($ids[0]);
        return $ids;
    }

    /**
     * Projetos que o usuário GERENCIA (ids exatos) — tabela de gestores
     * (O-1a; antes `glpi_projects.users_id`). Entidade, lixeira e modelo
     * filtrados em ProjectManager::projectsOf (achado da auditoria de
     * 26/07/2026 continua valendo). "Visão do cliente" nunca gerencia (R6).
     */
    private static function managedProjects(int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }
        if (!array_key_exists($uid, self::$managedCache)) {
            self::$managedCache[$uid] = Access::can('clientview')
                ? []
                : ProjectManager::projectsOf($uid);
        }
        return self::$managedCache[$uid];
    }

    /** Descendentes (só os de baixo) dos projetos informados. */
    private static function managedDescendants(array $roots): array
    {
        $out = [];
        foreach ($roots as $pid) {
            foreach (Budget::getDescendantIds((int) $pid) as $d) {
                $out[(int) $d] = true;
            }
        }
        return array_keys($out);
    }

    /** Árvore gerenciada: os projetos que gerencia + descendentes. */
    private static function managedTree(int $uid): array
    {
        $roots = self::managedProjects($uid);
        return self::mergeIds($roots, self::managedDescendants($roots));
    }

    /** Tarefas (fora da lixeira) de todos os projetos da árvore gerenciada. */
    private static function managedTreeTasks(int $uid): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (array_key_exists($uid, self::$managedTaskCache)) {
            return self::$managedTaskCache[$uid];
        }
        $tree = self::managedTree($uid);
        $ids  = [];
        if ($tree !== []) {
            foreach (
                $DB->request([
                    'SELECT' => 'glpi_projecttasks.id',
                    'FROM'   => 'glpi_projecttasks',
                    'WHERE'  => array_merge(
                        ['glpi_projecttasks.projects_id' => $tree],
                        Trash::liveTasks()
                    ),
                ]) as $r
            ) {
                $ids[(int) $r['id']] = true;
            }
        }
        unset($ids[0]);
        return self::$managedTaskCache[$uid] = array_keys($ids);
    }

    /** IDs das tarefas onde o usuário está na equipe da tarefa. Lista. */
    private static function myTasks(int $uid): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => 'projecttasks_id',
                'FROM'   => 'glpi_projecttaskteams',
                'WHERE'  => ['itemtype' => 'User', 'items_id' => $uid],
            ]) as $r
        ) {
            $ids[(int) $r['projecttasks_id']] = true;
        }
        unset($ids[0]);
        return array_keys($ids);
    }
}

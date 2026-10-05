<?php

/**
 * ProjectPlus — camada de ESCOPO (Etapa 8, Bloco 3).
 *
 * Enquanto `Access` responde "o perfil PODE ver este módulo?", `Scope`
 * responde "QUAIS itens este usuário vê agora?". Toda tela global abre no
 * escopo PESSOAL e só amplia via botão "Ver tudo".
 *
 * Regras (decididas com o usuário em 21/07/2026):
 *   PROJETOS que aparecem:
 *     - personal : projetos/subprojetos onde o usuário está na EQUIPE DO
 *                  PROJETO (`glpi_projectteams`). Cada um por si — NÃO sobe
 *                  para o projeto-pai, NÃO conta ter tarefa nem gerenciar.
 *     - managed  : personal + projetos onde ele é o gestor
 *                  (`glpi_projects.users_id`). Requer direito `seemanaged`.
 *     - all      : sem filtro. Requer direito `seeall`.
 *   TAREFAS que aparecem (Bloco F-1, 05/10/2026 — decisão do Claudio):
 *     - personal : só as MINHAS tarefas (equipe da tarefa,
 *                  `glpi_projecttaskteams`), independentemente do projeto.
 *     - managed  : IDEM — só as minhas. "Ver projetos que gerencio"
 *                  amplia só a lista de PROJETOS; quem precisa da visão da
 *                  equipe inteira recebe "Ver todos" (seeall).
 *     - all      : sem filtro.
 *   Subprojetos no managed: os DESCENDENTES dos projetos que ele gerencia
 *   entram; os de projeto em que ele é só da equipe, não (cada um por si).
 *
 * O modo vem do `?scope` da URL (sem memória em sessão — a tela sempre
 * reabre no pessoal) cruzado com o direito de escopo do perfil.
 *
 * Classe estática, sem extends — autoload PSR-4.
 */

namespace GlpiPlugin\Projectplus;

use Session;

class Scope
{
    /**
     * Modo efetivo: 'personal' | 'managed' | 'all'.
     *
     * Inversão (decidida com o usuário em 21/07/2026): o PADRÃO é o MAIOR
     * escopo que o perfil permite (seeall → all; seemanaged → managed; sem
     * direito → personal). O usuário REDUZ ao pessoal via `?scope=mine`
     * (sem memória de sessão — recarregar/abrir volta ao padrão).
     */
    public static function mode(): string
    {
        $wantsMine = (($_GET['scope'] ?? '') === 'mine');
        if ($wantsMine) {
            return 'personal';
        }
        if (Access::can('seeall')) {
            return 'all';
        }
        if (Access::can('seemanaged')) {
            return 'managed';
        }
        return 'personal';
    }

    /** O perfil pode ampliar o escopo (botão "Ver tudo" aparece)? */
    public static function canExpand(): bool
    {
        return Access::can('seemanaged') || Access::can('seeall');
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
     * IDs EXATOS dos projetos a exibir (cada um por si — raiz OU subprojeto).
     * `null` = sem filtro (modo 'all'); array vazio = não participa de nada.
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
            foreach (self::managedProjects($uid) as $p) {
                $ids[$p] = true;
            }
        }
        return array_map('intval', array_keys($ids));
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
     * IDs das MINHAS tarefas (equipe da tarefa) — filtra lista/indicadores
     * de tarefas no personal E no managed (Bloco F-1). `null` só no 'all'.
     */
    public static function myTaskIds(?string $mode = null): ?array
    {
        $mode = $mode ?? self::mode();
        if ($mode === 'all') {
            return null;
        }
        return self::myTasks((int) Session::getLoginUserID());
    }

    /**
     * PROJETOS alcançáveis no modo 'managed': os da equipe (cada um por si)
     * + os que ele gerencia COM descendentes. `null` fora do managed.
     *
     * Bloco F-1: o nome ficou do tempo em que filtrava tarefas por projeto;
     * hoje serve a canSeeProject(), às opções de projeto de "Minhas
     * tarefas" e à expansão de subprojetos do painel. Antes somava os
     * descendentes também dos projetos em que ele era só da equipe.
     */
    public static function taskProjectIds(?string $mode = null): ?array
    {
        $mode = $mode ?? self::mode();
        if ($mode !== 'managed') {
            return null;
        }
        $uid = (int) Session::getLoginUserID();
        $all = self::teamProjects($uid);
        foreach (self::managedProjects($uid) as $pid) {
            $all[(int) $pid] = true;
            foreach (Budget::getDescendantIds((int) $pid) as $d) {
                $all[(int) $d] = true;
            }
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

    /** Projetos que o usuário gerencia (users_id). Lista de ids. */
    private static function managedProjects(int $uid): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach (
            $DB->request([
                'SELECT' => 'id',
                'FROM'   => 'glpi_projects',
                // ACHADO DA AUDITORIA (26/07/2026): faltavam a restrição de
                // ENTIDADE e o filtro de MODELO. Numa instalação de entidade
                // única — como a de homologação — isso nunca aparece; numa
                // multi-entidade, o gestor que administra projeto em outra
                // entidade trazia esse id para o escopo, e um projeto-MODELO
                // dele entrava como se fosse projeto real.
                'WHERE'  => [
                    'users_id'    => $uid,
                    'is_deleted'  => 0,
                    'is_template' => 0,
                ] + getEntitiesRestrictCriteria('glpi_projects'),
            ]) as $r
        ) {
            $ids[(int) $r['id']] = true;
        }
        unset($ids[0]);
        return array_keys($ids);
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

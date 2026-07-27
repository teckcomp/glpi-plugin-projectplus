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
 *   TAREFAS que aparecem:
 *     - personal : só as MINHAS tarefas (equipe da tarefa,
 *                  `glpi_projecttaskteams`), independentemente do projeto.
 *     - managed  : todas as tarefas dos projetos do escopo (raízes +
 *                  descendentes) — visão de status do que ele gerencia.
 *     - all      : sem filtro.
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
     * IDs das MINHAS tarefas (equipe da tarefa) — usado só no modo personal
     * para filtrar a lista/indicadores de tarefas. `null` fora do personal.
     */
    public static function myTaskIds(?string $mode = null): ?array
    {
        $mode = $mode ?? self::mode();
        if ($mode !== 'personal') {
            return null;
        }
        return self::myTasks((int) Session::getLoginUserID());
    }

    /**
     * IDs de projeto (com descendentes) para filtrar TAREFAS por projeto no
     * modo 'managed'. `null` fora do managed.
     */
    public static function taskProjectIds(?string $mode = null): ?array
    {
        $mode = $mode ?? self::mode();
        if ($mode !== 'managed') {
            return null;
        }
        $all = [];
        foreach (self::projectIds('managed') as $pid) {
            $all[(int) $pid] = true;
            foreach (Budget::getDescendantIds((int) $pid) as $d) {
                $all[(int) $d] = true;
            }
        }
        return array_map('intval', array_keys($all));
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

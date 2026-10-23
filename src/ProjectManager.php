<?php

/**
 * ProjectPlus — GESTORES do projeto (Ciclo O, bloco O-1a, 23/10/2026).
 *
 * Regras de acesso por vínculo (ROADMAP, Ciclo O — R2, R3):
 *   - um projeto pode ter VÁRIOS gestores; o vínculo de gestor dá visão da
 *     árvore inteira (projeto, subprojetos, tarefas e subtarefas) sem
 *     depender de direito de módulo;
 *   - o campo nativo `glpi_projects.users_id` fica com o PRIMEIRO gestor e
 *     é mantido em sincronia até a virada do Ciclo P.
 *
 * Tabela `glpi_plugin_projectplus_projectmanagers` — uma linha por
 * (projeto, usuário), chave única `project_user`.
 *
 * Sincronia com o nativo (até o O-1c existir, o gestor só muda por fora —
 * ficha nativa, faixa "Projeto", Modelo): os hooks de item mantêm a tabela:
 *   - projeto criado com `users_id` → entra como gestor;
 *   - `users_id` trocado de A para B → A sai, B entra (os DEMAIS gestores
 *     ficam — é a troca do "primeiro");
 *   - projeto purgado → linhas dele saem.
 *
 * Enquanto a tabela não existe (zip aplicado, `plugin:install --force` ainda
 * não rodado), tudo cai no campo nativo — nenhuma tela quebra na janela.
 *
 * Classe estática, sem extends — autoload PSR-4.
 */

namespace GlpiPlugin\Projectplus;

use Project;

class ProjectManager
{
    public const TABLE = 'glpi_plugin_projectplus_projectmanagers';

    /** Cache por requisição de tableExists (o hook roda a cada update). */
    private static ?bool $tableOk = null;

    public static function tableReady(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::$tableOk === null) {
            self::$tableOk = (bool) $DB->tableExists(self::TABLE);
        }
        return self::$tableOk;
    }

    /** Para harness/instalador: esquece o cache de tableExists. */
    public static function resetCache(): void
    {
        self::$tableOk = null;
    }

    /**
     * Projetos que o usuário GERENCIA (ids exatos, sem descendentes): fora
     * da lixeira, que não são modelo, na entidade da sessão.
     *
     * @return int[]
     */
    public static function projectsOf(int $userId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($userId <= 0) {
            return [];
        }

        $where = [
            'glpi_projects.is_deleted'  => 0,
            'glpi_projects.is_template' => 0,
        ] + getEntitiesRestrictCriteria('glpi_projects');

        if (self::tableReady()) {
            $request = [
                'SELECT'     => 'glpi_projects.id',
                'FROM'       => 'glpi_projects',
                'INNER JOIN' => [
                    self::TABLE => [
                        'ON' => [
                            self::TABLE    => 'projects_id',
                            'glpi_projects' => 'id',
                        ],
                    ],
                ],
                // Lição 16: com JOIN, toda chave do WHERE qualificada.
                'WHERE'      => $where + [self::TABLE . '.users_id' => $userId],
            ];
        } else {
            // Janela entre o zip e o install --force: campo nativo.
            $request = [
                'SELECT' => 'glpi_projects.id',
                'FROM'   => 'glpi_projects',
                'WHERE'  => $where + ['glpi_projects.users_id' => $userId],
            ];
        }

        $ids = [];
        foreach ($DB->request($request) as $r) {
            $ids[(int) $r['id']] = true;
        }
        unset($ids[0]);
        return array_keys($ids);
    }

    /**
     * Gestores de um projeto, na ordem de entrada (o primeiro é o do campo
     * nativo). Sem a tabela, o campo nativo.
     *
     * @return int[]
     */
    public static function managersOf(int $projectId): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($projectId <= 0) {
            return [];
        }
        $out = [];
        if (self::tableReady()) {
            foreach (
                $DB->request([
                    'SELECT' => 'users_id',
                    'FROM'   => self::TABLE,
                    'WHERE'  => ['projects_id' => $projectId],
                    'ORDER'  => 'id',
                ]) as $r
            ) {
                $out[(int) $r['users_id']] = true;
            }
        } else {
            foreach (
                $DB->request([
                    'SELECT' => 'users_id',
                    'FROM'   => 'glpi_projects',
                    'WHERE'  => ['id' => $projectId],
                ]) as $r
            ) {
                $out[(int) $r['users_id']] = true;
            }
        }
        unset($out[0]);
        return array_keys($out);
    }

    /** Põe o usuário como gestor (no-op se já é). */
    public static function add(int $projectId, int $userId): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($projectId <= 0 || $userId <= 0 || !self::tableReady()) {
            return false;
        }
        $exists = $DB->request([
            'SELECT' => 'id',
            'FROM'   => self::TABLE,
            'WHERE'  => ['projects_id' => $projectId, 'users_id' => $userId],
            'LIMIT'  => 1,
        ]);
        if (count($exists) > 0) {
            return false;
        }
        return (bool) $DB->insert(self::TABLE, [
            'projects_id'   => $projectId,
            'users_id'      => $userId,
            'date_creation' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    /** Tira o usuário dos gestores (sem a trava do último — é do O-1c). */
    public static function remove(int $projectId, int $userId): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($projectId <= 0 || $userId <= 0 || !self::tableReady()) {
            return;
        }
        $DB->delete(self::TABLE, ['projects_id' => $projectId, 'users_id' => $userId]);
    }

    // ---------------------------------------------------------------
    // Hooks de item (setup.php)
    // ---------------------------------------------------------------

    /** ITEM_ADD de Project: o `users_id` escolhido vira gestor. */
    public static function onProjectAdd(Project $project): void
    {
        self::add((int) $project->getID(), (int) ($project->fields['users_id'] ?? 0));
    }

    /**
     * ITEM_UPDATE de Project. O GLPI aceita UM callback por tipo e plugin —
     * este substitui o de `ProjectTracking::onProjectUpdate` e o chama
     * primeiro (comportamento de antes intacto).
     */
    public static function onProjectUpdate(Project $project): void
    {
        ProjectTracking::onProjectUpdate($project);
        self::syncNative($project);
    }

    /** ITEM_PURGE de Project: as linhas do projeto saem junto. */
    public static function onProjectPurge(Project $project): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $id = (int) $project->getID();
        if ($id > 0 && self::tableReady()) {
            $DB->delete(self::TABLE, ['projects_id' => $id]);
        }
    }

    /**
     * `users_id` trocado (ficha nativa, faixa "Projeto", qualquer update):
     * o antigo sai, o novo entra; os demais gestores ficam.
     */
    public static function syncNative(Project $project): void
    {
        if (!in_array('users_id', (array) ($project->updates ?? []), true)) {
            return;
        }
        $pid = (int) $project->getID();
        $old = (int) ($project->oldvalues['users_id'] ?? 0);
        $new = (int) ($project->fields['users_id'] ?? 0);
        if ($old === $new) {
            return;
        }
        if ($old > 0) {
            self::remove($pid, $old);
        }
        if ($new > 0) {
            self::add($pid, $new);
        }
    }

    // ---------------------------------------------------------------
    // Instalação
    // ---------------------------------------------------------------

    /**
     * Migração idempotente: todo `glpi_projects.users_id` > 0 vira linha da
     * tabela (o UNIQUE `project_user` + INSERT IGNORE impedem duplicata).
     * Rodar de novo não apaga nada e não duplica.
     *
     * @return int linhas novas
     */
    public static function migrateFromNative(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        self::resetCache();
        if (!self::tableReady()) {
            return 0;
        }
        $before = (int) ($DB->request(['COUNT' => 'cpt', 'FROM' => self::TABLE])->current()['cpt'] ?? 0);
        $DB->doQuery("
            INSERT IGNORE INTO `" . self::TABLE . "` (`projects_id`, `users_id`, `date_creation`)
            SELECT `id`, `users_id`, NOW()
            FROM `glpi_projects`
            WHERE `users_id` > 0
            ORDER BY `id`
        ");
        $after = (int) ($DB->request(['COUNT' => 'cpt', 'FROM' => self::TABLE])->current()['cpt'] ?? 0);
        return max(0, $after - $before);
    }
}

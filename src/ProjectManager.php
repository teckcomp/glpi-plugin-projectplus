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
 * O-1c (26/10/2026) — a TABELA é a fonte: o gestor se troca na faixa
 * "Projeto" do Project+ (addManager/removeManager, vários, nunca o último,
 * nunca usuário com Visão do cliente). O campo nativo vira só ESPELHO do
 * primeiro gestor (mirrorNative, gravação direta sem hooks), para as telas
 * nativas não ficarem desatualizadas até a virada do Ciclo P. As leituras
 * do plugin (faixa, Relatórios) passam a vir da tabela.
 *
 * Sincronia com o nativo (antes do O-1c o gestor só mudava por fora —
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
    // O-1c — gestores pelo Project+ (faixa "Projeto")
    // ---------------------------------------------------------------

    /**
     * Adiciona um gestor pelo Project+. Recusa usuário inativo/apagado e
     * usuário com "Visão do cliente" em algum perfil (R6).
     *
     * @return string|null mensagem de recusa, ou null se ficou gestor
     */
    public static function addManager(int $projectId, int $userId): ?string
    {
        if (!self::tableReady()) {
            return __('Sem permissão', 'projectplus');
        }
        if (!self::isActiveUser($userId)) {
            return __('Usuário não encontrado.', 'projectplus');
        }
        if (self::isClientUser($userId)) {
            return __('Usuário com Visão do cliente não pode ser gestor', 'projectplus');
        }
        self::add($projectId, $userId);
        self::mirrorNative($projectId);
        return null;
    }

    /**
     * Remove um gestor pelo Project+. Nunca o ÚLTIMO (R4).
     *
     * @return string|null mensagem de recusa, ou null se saiu (ou já não era)
     */
    public static function removeManager(int $projectId, int $userId): ?string
    {
        if (!self::tableReady()) {
            return __('Sem permissão', 'projectplus');
        }
        $current = self::managersOf($projectId);
        if (!in_array($userId, $current, true)) {
            return null;
        }
        if (count($current) <= 1) {
            return __('O projeto precisa de pelo menos um gestor', 'projectplus');
        }
        self::remove($projectId, $userId);
        self::mirrorNative($projectId);
        return null;
    }

    /**
     * Espelha o PRIMEIRO gestor da tabela no campo nativo `users_id`, com
     * gravação direta (sem `Project::update`): não dispara o hook de
     * sincronia (que desfaria a ordem) nem o histórico nativo.
     */
    public static function mirrorNative(int $projectId): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $first = self::managersOf($projectId)[0] ?? 0;
        $DB->update('glpi_projects', ['users_id' => (int) $first], ['id' => $projectId]);
    }

    /**
     * Gestores com nome, na ordem de entrada: [['id' => int, 'name' => string], ...].
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function managersWithNames(int $projectId): array
    {
        return self::namesOf(self::managersOf($projectId));
    }

    /**
     * Nomes de usuários na ordem pedida (formatUserName do core).
     *
     * @param int[] $userIds
     * @return array<int, array{id: int, name: string}>
     */
    public static function namesOf(array $userIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }
        $byId = [];
        foreach (
            $DB->request([
                'SELECT' => ['id', 'name', 'realname', 'firstname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $userIds],
            ]) as $u
        ) {
            $label = \formatUserName(0, $u['name'] ?? '', $u['realname'] ?? '', $u['firstname'] ?? '');
            $byId[(int) $u['id']] = $label !== '' ? $label : '?';
        }
        $out = [];
        foreach ($userIds as $id) {
            $out[] = ['id' => $id, 'name' => $byId[$id] ?? ('#' . $id)];
        }
        return $out;
    }

    /**
     * Gestores de VÁRIOS projetos de uma vez (Relatórios): pid => [uids]
     * na ordem de entrada. Sem a tabela, o campo nativo.
     *
     * @param int[] $projectIds
     * @return array<int, int[]>
     */
    public static function managersOfMany(array $projectIds): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $projectIds = array_values(array_filter(array_map('intval', $projectIds)));
        if ($projectIds === []) {
            return [];
        }
        $out = [];
        $request = self::tableReady()
            ? ['SELECT' => ['projects_id', 'users_id'], 'FROM' => self::TABLE,
               'WHERE' => ['projects_id' => $projectIds], 'ORDER' => 'id']
            : ['SELECT' => ['id AS projects_id', 'users_id'], 'FROM' => 'glpi_projects',
               'WHERE' => ['id' => $projectIds, 'users_id' => ['>', 0]]];
        foreach ($DB->request($request) as $r) {
            $out[(int) $r['projects_id']][] = (int) $r['users_id'];
        }
        return $out;
    }

    /** Todos os usuários que gerenciam algum projeto (filtro dos Relatórios). */
    public static function allManagerIds(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        $request = self::tableReady()
            ? ['SELECT' => 'users_id', 'FROM' => self::TABLE]
            : ['SELECT' => 'users_id', 'FROM' => 'glpi_projects', 'WHERE' => ['users_id' => ['>', 0]]];
        foreach ($DB->request($request) as $r) {
            $ids[(int) $r['users_id']] = true;
        }
        unset($ids[0]);
        return array_keys($ids);
    }

    /** Usuário ativo e não apagado? */
    private static function isActiveUser(int $userId): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($userId <= 0) {
            return false;
        }
        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => $userId, 'is_active' => 1, 'is_deleted' => 0],
            'LIMIT'  => 1,
        ])->current();
        return !empty($row);
    }

    /**
     * O usuário tem "Visão do cliente" em ALGUM dos seus perfis? (R6 — o
     * cliente nunca é gestor.)
     */
    public static function isClientUser(int $userId): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $profiles = [];
        foreach (
            $DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => 'glpi_profiles_users',
                'WHERE'  => ['users_id' => $userId],
            ]) as $r
        ) {
            $profiles[(int) $r['profiles_id']] = true;
        }
        if ($profiles === []) {
            return false;
        }
        foreach (
            $DB->request([
                'SELECT' => 'rights',
                'FROM'   => 'glpi_profilerights',
                'WHERE'  => [
                    'profiles_id' => array_keys($profiles),
                    'name'        => 'plugin_projectplus_clientview',
                ],
            ]) as $r
        ) {
            if (((int) $r['rights'] & READ) === READ) {
                return true;
            }
        }
        return false;
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
        // O-1c: gestor APAGADO na ficha nativa não esvazia a tabela (o
        // projeto nunca fica sem gestor — R4); o campo volta ao primeiro.
        if ($new <= 0) {
            self::mirrorNative($pid);
            return;
        }
        self::add($pid, $new);
        if ($old > 0) {
            self::remove($pid, $old);
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

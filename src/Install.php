<?php

namespace GlpiPlugin\Projectplus;

use Config as CoreConfig;
use CronTask;
use Migration;
use ProfileRight;

/**
 * Instalação / desinstalação do ProjectPlus.
 *
 * Cria APENAS tabelas próprias do plugin (prefixo glpi_plugin_projectplus_).
 * Nenhuma tabela nativa é alterada.
 *
 * Etapa 6, Bloco 1 — ciclo desinstalar/reinstalar seguro:
 *  - install() reconcilia o schema de bases antigas (colunas que passaram
 *    a existir depois da criação da tabela);
 *  - a migração dos custos nativos roda UMA vez, marcada por flag de
 *    configuração (antes dependia de "tabela do plugin vazia", o que a
 *    fazia rodar de novo se o admin tivesse apagado os custos migrados);
 *  - uninstall() preserva os dados E a configuração de direitos por
 *    perfil, a menos que "purge_on_uninstall" esteja ligado.
 */
class Install
{
    /**
     * Tabelas próprias do plugin. Ordem estável (diagnóstico e purga).
     */
    public const TABLES = [
        'glpi_plugin_projectplus_projecttrackings',
        'glpi_plugin_projectplus_tasktimers',
        'glpi_plugin_projectplus_templates',
        'glpi_plugin_projectplus_alerts',
        'glpi_plugin_projectplus_taskcosts',
        'glpi_plugin_projectplus_projectcosts',
        'glpi_plugin_projectplus_taskcomments',
    ];

    /**
     * Direitos próprios do plugin (Painel + 10 granulares da Etapa 8).
     * Fonte única para o uninstall e para o diagnóstico.
     */
    public const RIGHTS = [
        'plugin_projectplus_dashboard',
        'plugin_projectplus_projects',
        'plugin_projectplus_tasks',
        'plugin_projectplus_kanban',
        'plugin_projectplus_projectkanban',
        'plugin_projectplus_costs',
        'plugin_projectplus_reports',
        'plugin_projectplus_templates',
        'plugin_projectplus_alerts',
        'plugin_projectplus_seemanaged',
        'plugin_projectplus_seeall',
    ];

    /**
     * Colunas esperadas em cada tabela, com o tipo SQL literal.
     *
     * Serve para bases criadas por versões antigas do plugin: a guarda
     * `tableExists` do install só protege a CRIAÇÃO, então coluna
     * acrescentada depois nunca chegava a quem já tinha a tabela.
     * `Migration::addField` é no-op quando a coluna já existe.
     *
     * A chave primária e os índices UNIQUE ficam de fora de propósito:
     * criar UNIQUE em base com duplicata falha. Ausência de UNIQUE é
     * apenas REPORTADA no diagnóstico (ver healthReport()).
     */
    private const COLUMNS = [
        'glpi_plugin_projectplus_projecttrackings' => [
            'projects_id'    => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'last_activity'  => 'TIMESTAMP NULL DEFAULT NULL',
            'is_stalled'     => 'TINYINT NOT NULL DEFAULT 0',
            'stalled_since'  => 'TIMESTAMP NULL DEFAULT NULL',
            'budget_planned' => 'DECIMAL(20,4) NOT NULL DEFAULT 0',
            'budget_spent'   => 'DECIMAL(20,4) NOT NULL DEFAULT 0',
            'date_creation'  => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'       => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_tasktimers' => [
            'projecttasks_id' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'users_id'        => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'start'           => 'TIMESTAMP NULL DEFAULT NULL',
            'end'             => 'TIMESTAMP NULL DEFAULT NULL',
            'duration'        => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'date_creation'   => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_templates' => [
            'name'          => "VARCHAR(255) NOT NULL DEFAULT ''",
            'comment'       => 'TEXT',
            'entities_id'   => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'is_recursive'  => 'TINYINT NOT NULL DEFAULT 0',
            'structure'     => 'LONGTEXT',
            'date_creation' => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'      => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_alerts' => [
            'users_id'      => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'itemtype'      => "VARCHAR(100) NOT NULL DEFAULT ''",
            'items_id'      => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'kind'          => "VARCHAR(30) NOT NULL DEFAULT ''",
            'message'       => 'TEXT',
            'is_read'       => 'TINYINT NOT NULL DEFAULT 0',
            'date_creation' => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_taskcosts' => [
            'projecttasks_id' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'name'            => "VARCHAR(255) NOT NULL DEFAULT ''",
            'date'            => 'DATE NULL DEFAULT NULL',
            'cost'            => 'DECIMAL(20,4) NOT NULL DEFAULT 0',
            'comment'         => 'TEXT',
            'users_id'        => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'date_creation'   => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'        => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_projectcosts' => [
            'projects_id'   => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'name'          => "VARCHAR(255) NOT NULL DEFAULT ''",
            'date'          => 'DATE NULL DEFAULT NULL',
            'cost'          => 'DECIMAL(20,4) NOT NULL DEFAULT 0',
            'comment'       => 'TEXT',
            'users_id'      => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'date_creation' => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'      => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_taskcomments' => [
            'projecttasks_id' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'users_id'        => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'content'         => 'TEXT',
            'date_creation'   => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'        => 'TIMESTAMP NULL DEFAULT NULL',
        ],
    ];

    /**
     * Índices UNIQUE de que o plugin depende. NÃO são criados
     * automaticamente (falhariam em base com duplicata) — só conferidos
     * pelo diagnóstico.
     */
    private const UNIQUE_KEYS = [
        'glpi_plugin_projectplus_projecttrackings' => ['projects_id'],
        'glpi_plugin_projectplus_alerts'           => ['dedup'],
    ];

    public static function install(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $migration = new Migration(PLUGIN_PROJECTPLUS_VERSION);

        $charset   = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';

        // ------------------------------------------------------------------
        // 1) Indicadores extras por projeto (última atividade, parado, orçamento)
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_projectplus_projecttrackings')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_projectplus_projecttrackings` (
                    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `projects_id`       INT UNSIGNED NOT NULL DEFAULT 0,
                    `last_activity`     TIMESTAMP NULL DEFAULT NULL,
                    `is_stalled`        TINYINT NOT NULL DEFAULT 0,
                    `stalled_since`     TIMESTAMP NULL DEFAULT NULL,
                    `budget_planned`    DECIMAL(20,4) NOT NULL DEFAULT 0,
                    `budget_spent`      DECIMAL(20,4) NOT NULL DEFAULT 0,
                    `date_creation`     TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`          TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `projects_id` (`projects_id`),
                    KEY `is_stalled` (`is_stalled`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 2) Cronômetro por tarefa/usuário
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_projectplus_tasktimers')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_projectplus_tasktimers` (
                    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `projecttasks_id`  INT UNSIGNED NOT NULL DEFAULT 0,
                    `users_id`         INT UNSIGNED NOT NULL DEFAULT 0,
                    `start`            TIMESTAMP NULL DEFAULT NULL,
                    `end`              TIMESTAMP NULL DEFAULT NULL,
                    `duration`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'segundos',
                    `date_creation`    TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `projecttasks_id` (`projecttasks_id`),
                    KEY `users_id` (`users_id`),
                    KEY `running` (`users_id`, `end`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 3) Modelos próprios do plugin (árvore de tarefas em JSON)
        //    Contorna o bug de templates do core (issue #21804).
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_projectplus_templates')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_projectplus_templates` (
                    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `name`           VARCHAR(255) NOT NULL DEFAULT '',
                    `comment`        TEXT,
                    `entities_id`    INT UNSIGNED NOT NULL DEFAULT 0,
                    `is_recursive`   TINYINT NOT NULL DEFAULT 0,
                    `structure`      LONGTEXT COMMENT 'JSON da arvore de tarefas',
                    `date_creation`  TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`       TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `name` (`name`),
                    KEY `entities_id` (`entities_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 4) Alertas internos (sino na UI)
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_projectplus_alerts')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_projectplus_alerts` (
                    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `users_id`       INT UNSIGNED NOT NULL DEFAULT 0,
                    `itemtype`       VARCHAR(100) NOT NULL DEFAULT '',
                    `items_id`       INT UNSIGNED NOT NULL DEFAULT 0,
                    `kind`           VARCHAR(30) NOT NULL DEFAULT '' COMMENT 'overdue|pending|completed|stalled',
                    `message`        TEXT,
                    `is_read`        TINYINT NOT NULL DEFAULT 0,
                    `date_creation`  TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `user_unread` (`users_id`, `is_read`),
                    KEY `item` (`itemtype`, `items_id`),
                    UNIQUE KEY `dedup` (`users_id`, `itemtype`, `items_id`, `kind`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 5) Custos por tarefa (aba na ficha nativa; consome orçamento)
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_projectplus_taskcosts')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_projectplus_taskcosts` (
                    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `projecttasks_id`  INT UNSIGNED NOT NULL DEFAULT 0,
                    `name`             VARCHAR(255) NOT NULL DEFAULT '',
                    `date`             DATE NULL DEFAULT NULL,
                    `cost`             DECIMAL(20,4) NOT NULL DEFAULT 0,
                    `comment`          TEXT,
                    `users_id`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'quem lançou',
                    `date_creation`    TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`         TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `projecttasks_id` (`projecttasks_id`),
                    KEY `date` (`date`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 6) Custos por projeto (aba própria; substitui a nativa na prática)
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_projectplus_projectcosts')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_projectplus_projectcosts` (
                    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `projects_id`    INT UNSIGNED NOT NULL DEFAULT 0,
                    `name`           VARCHAR(255) NOT NULL DEFAULT '',
                    `date`           DATE NULL DEFAULT NULL,
                    `cost`           DECIMAL(20,4) NOT NULL DEFAULT 0,
                    `comment`        TEXT,
                    `users_id`       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'quem lançou',
                    `date_creation`  TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`       TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `projects_id` (`projects_id`),
                    KEY `date` (`date`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 7) Comentários por tarefa (Etapa 3, Bloco 2)
        //    Conversa da equipe por tarefa — o core não tem discussão em
        //    ProjectTask (só Notepad, sem controle por autor).
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_projectplus_taskcomments')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_projectplus_taskcomments` (
                    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `projecttasks_id`  INT UNSIGNED NOT NULL DEFAULT 0,
                    `users_id`         INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'autor',
                    `content`          TEXT,
                    `date_creation`    TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`         TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `projecttasks_id` (`projecttasks_id`),
                    KEY `users_id` (`users_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
            ");
        }

        // ------------------------------------------------------------------
        // 7.1) Reconciliação de schema (Etapa 6, Bloco 1a).
        //      Para bases criadas por versões antigas: garante as colunas
        //      que passaram a existir DEPOIS da criação da tabela.
        // ------------------------------------------------------------------
        self::ensureSchema($migration);

        // ------------------------------------------------------------------
        // 7.2) Migração ÚNICA dos custos da aba nativa do GLPI para a
        //      tabela do plugin (Etapa 6, Bloco 1b). Os registros nativos
        //      ficam intactos no banco.
        // ------------------------------------------------------------------
        self::migrateNativeCosts();

        // ------------------------------------------------------------------
        // Direitos.
        //
        // ATENÇÃO (lição 38): Migration::addRight insere a linha para
        // TODOS os perfis que ainda não a têm — com o valor pedido para
        // quem atende os pré-requisitos, e 0 para os demais. Ou seja, só a
        // PRIMEIRA chamada para um mesmo nome de direito tem efeito; uma
        // segunda chamada com outro pré-requisito é código morto (havia
        // uma para 'dashboard' + config UPDATE, removida aqui). Por isso
        // há exatamente UMA chamada por direito. O ajuste fino é feito na
        // aba "ProjectPlus" do Perfil.
        //
        // addRight nunca rebaixa valor existente: é seguro reexecutar o
        // install (plugin:install --force) sem desfazer a configuração.
        // ------------------------------------------------------------------
        $migration->addRight('plugin_projectplus_dashboard', READ, ['project' => READ]);

        // ------------------------------------------------------------------
        // Etapa 8, Bloco 1 — direitos granulares por módulo + escopo.
        //
        // Migração (opção A "preservar"): quem já tinha o direito único
        // 'plugin_projectplus_dashboard' (READ) recebe os módulos no nível
        // máximo, para NINGUÉM perder acesso ao atualizar. Os direitos de
        // ESCOPO ("ver os que gerencia" / "ver todos") NÃO são soltos para
        // todos: só o super-admin (config UPDATE) os recebe; o Gestor é
        // marcado à mão.
        // ------------------------------------------------------------------
        $keepDashboard = ['plugin_projectplus_dashboard' => READ];

        // Níveis da matriz do plugin: Ver/Interagir/Criar/Excluir
        // (READ|UPDATE|CREATE|DELETE = 15). NÃO usar ALLSTANDARDRIGHT, que
        // inclui PURGE (16) — bit sem coluna na matriz, que sumiria no
        // primeiro save do perfil (viraria 15 na prática de qualquer jeito).
        $crudBits = READ | UPDATE | CREATE | DELETE;

        // Módulos com CRUD completo (Ver/Interagir/Criar/Excluir)
        $migration->addRight('plugin_projectplus_projects', $crudBits, $keepDashboard);
        $migration->addRight('plugin_projectplus_tasks', $crudBits, $keepDashboard);

        // Kanban de tarefas: Ver + Interagir (mover fase)
        $migration->addRight('plugin_projectplus_kanban', READ | UPDATE, $keepDashboard);

        // Custos / Orçamento: Ver + Interagir (lançar)
        $migration->addRight('plugin_projectplus_costs', READ | UPDATE, $keepDashboard);

        // Somente leitura
        $migration->addRight('plugin_projectplus_reports', READ, $keepDashboard);
        $migration->addRight('plugin_projectplus_alerts', READ, $keepDashboard);

        // Kanban de projetos (exclusivo do perfil Cliente): a linha existe
        // para todos (valor 0) e é marcada à mão no perfil Cliente.
        $migration->addRight('plugin_projectplus_projectkanban', 0);

        // Modelos: hoje travado em super-admin (config UPDATE, lição 11).
        // Vira direito próprio, preservando o comportamento (só quem tem
        // config UPDATE recebe); a partir daqui é configurável por perfil.
        $migration->addRight('plugin_projectplus_templates', $crudBits, ['config' => UPDATE]);

        // Escopo: só super-admin por padrão (o Gestor recebe "ver os que
        // gerencia" manualmente na aba do Perfil). A linha "seemanaged"
        // existe para todos (valor 0) para aparecer na matriz.
        $migration->addRight('plugin_projectplus_seemanaged', 0);
        $migration->addRight('plugin_projectplus_seeall', READ, ['config' => UPDATE]);

        // ------------------------------------------------------------------
        // Cron: verificação de prazos/pendências (requisito 5).
        // CronTask::register já é idempotente (ignora duplicata pelo nome).
        // ------------------------------------------------------------------
        CronTask::register(
            Notification::class,
            'projectplusalerts',
            HOUR_TIMESTAMP,
            [
                'state'         => CronTask::STATE_WAITING,
                'mode'          => CronTask::MODE_EXTERNAL,
                'logs_lifetime' => 30,
                'comment'       => 'ProjectPlus: verifica atrasos, pendências e projetos parados',
            ]
        );

        $migration->executeMigration();

        return true;
    }

    /**
     * Garante que as tabelas existentes tenham todas as colunas atuais.
     * Idempotente: addField é no-op quando a coluna já existe.
     */
    private static function ensureSchema(Migration $migration): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        foreach (self::COLUMNS as $table => $columns) {
            if (!$DB->tableExists($table)) {
                continue;
            }
            foreach ($columns as $field => $sqlType) {
                if (!$DB->fieldExists($table, $field)) {
                    // fieldFormat do core devolve o tipo cru quando não
                    // reconhece o nome — é assim que se passa SQL literal.
                    $migration->addField($table, $field, $sqlType);
                }
            }
        }
    }

    /**
     * Migração única dos custos nativos (glpi_projectcosts) para a tabela
     * do plugin. Roda no máximo uma vez por instalação: a flag
     * 'costs_migrated' é gravada mesmo quando não há nada a copiar.
     */
    private static function migrateNativeCosts(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $config = Config::get();
        if (!empty($config['costs_migrated'])) {
            return;
        }

        if (
            $DB->tableExists('glpi_projectcosts')
            && $DB->tableExists('glpi_plugin_projectplus_projectcosts')
        ) {
            $cpt = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_plugin_projectplus_projectcosts',
            ])->current();

            // Cinto e suspensório: com a tabela do plugin já povoada não
            // copia nada (evita duplicar em base que migrou na versão
            // antiga, quando ainda não havia a flag).
            if ((int) ($cpt['cpt'] ?? 0) === 0) {
                $dateCol = $DB->fieldExists('glpi_projectcosts', 'begin_date')
                    ? '`begin_date`' : 'NULL';
                $nameCol = $DB->fieldExists('glpi_projectcosts', 'name')
                    ? '`name`' : "''";
                $commCol = $DB->fieldExists('glpi_projectcosts', 'comment')
                    ? '`comment`' : "''";

                $DB->doQuery("
                    INSERT INTO `glpi_plugin_projectplus_projectcosts`
                        (`projects_id`, `name`, `date`, `cost`, `comment`,
                         `users_id`, `date_creation`, `date_mod`)
                    SELECT `projects_id`, {$nameCol}, {$dateCol}, `cost`, {$commCol},
                           0, NOW(), NOW()
                    FROM `glpi_projectcosts`
                ");
            }
        }

        CoreConfig::setConfigurationValues(Config::CONTEXT, ['costs_migrated' => 1]);
    }

    public static function uninstall(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Proteção de dados: por padrão as tabelas são MANTIDAS na
        // desinstalação (custos, alertas e indicadores sobrevivem a um
        // ciclo desinstalar/reinstalar). O expurgo completo só acontece
        // se o admin ativar "purge_on_uninstall" em Configurações.
        //
        // Etapa 6, Bloco 1c: a MESMA proteção passa a valer para a
        // configuração de direitos por perfil (a matriz da Etapa 8).
        // Antes, desinstalar apagava as 11 linhas de glpi_profilerights de
        // TODOS os perfis; a reinstalação recompunha só os valores padrão
        // e todo o ajuste fino de Gestor/Cliente/Técnico/Colaborador se
        // perdia em silêncio. Como addRight não sobrescreve direito
        // existente, preservar as linhas devolve a configuração intacta.
        $config = Config::get();
        if (!empty($config['purge_on_uninstall'])) {
            foreach (self::TABLES as $table) {
                if ($DB->tableExists($table)) {
                    $DB->doQuery("DROP TABLE `{$table}`");
                }
            }

            // Remove os direitos de todos os perfis
            ProfileRight::deleteProfileRights(self::RIGHTS);

            // Remove também a configuração do plugin
            CoreConfig::deleteConfigurationValues(
                Config::CONTEXT,
                array_keys(Config::DEFAULTS)
            );
        }

        // Remove o cron (a classe deixa de existir com o plugin
        // desinstalado; register() o recria na reinstalação)
        CronTask::unregister('projectplus');

        return true;
    }

    /**
     * Diagnóstico do estado da instalação (Etapa 6, Bloco 1d).
     *
     * Usado pela tela de Configuração para conferir, sem SQL manual, o
     * resultado de um ciclo desinstalar/reinstalar: tabelas presentes e
     * com quantas linhas, colunas/índices faltando, os 11 direitos e em
     * quantos perfis estão concedidos, e o cron.
     *
     * @return array{version:string,tables:array,rights:array,cron:array,
     *               costs_migrated:bool,purge_on_uninstall:bool,issues:int}
     */
    public static function healthReport(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $config = Config::get();
        $issues = 0;

        // --- Tabelas -------------------------------------------------------
        $tables = [];
        foreach (self::TABLES as $table) {
            $exists  = $DB->tableExists($table);
            $rows    = 0;
            $missing = [];

            if ($exists) {
                $cpt  = $DB->request(['COUNT' => 'cpt', 'FROM' => $table])->current();
                $rows = (int) ($cpt['cpt'] ?? 0);

                foreach (array_keys(self::COLUMNS[$table] ?? []) as $field) {
                    if (!$DB->fieldExists($table, $field)) {
                        $missing[] = $field;
                    }
                }
                foreach (self::UNIQUE_KEYS[$table] ?? [] as $key) {
                    if (!self::hasIndex($table, $key)) {
                        $missing[] = 'índice ' . $key;
                    }
                }
            }

            if (!$exists || $missing) {
                $issues++;
            }

            $tables[] = [
                'name'    => $table,
                'exists'  => $exists,
                'rows'    => $rows,
                'missing' => $missing,
            ];
        }

        // --- Direitos ------------------------------------------------------
        // Lição 1: COUNT + GROUPBY juntos descartam os campos do SELECT no
        // iterator do GLPI 11 — traz as linhas e conta em PHP.
        $byName = [];
        $it = $DB->request([
            'SELECT' => ['name', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => self::RIGHTS],
        ]);
        foreach ($it as $row) {
            $name = (string) $row['name'];
            if (!isset($byName[$name])) {
                $byName[$name] = ['profiles' => 0, 'granted' => 0];
            }
            $byName[$name]['profiles']++;
            if ((int) $row['rights'] > 0) {
                $byName[$name]['granted']++;
            }
        }

        $rights = [];
        foreach (self::RIGHTS as $name) {
            $found = $byName[$name] ?? ['profiles' => 0, 'granted' => 0];
            if ($found['profiles'] === 0) {
                $issues++;
            }
            $rights[] = [
                'name'     => $name,
                'profiles' => $found['profiles'],
                'granted'  => $found['granted'],
            ];
        }

        // --- Cron ----------------------------------------------------------
        $cron = ['registered' => false, 'state' => 0, 'lastrun' => null];
        if ($DB->tableExists('glpi_crontasks')) {
            $row = $DB->request([
                'SELECT' => ['state', 'lastrun'],
                'FROM'   => 'glpi_crontasks',
                'WHERE'  => [
                    'itemtype' => Notification::class,
                    'name'     => 'projectplusalerts',
                ],
                'LIMIT'  => 1,
            ])->current();

            if ($row) {
                $cron = [
                    'registered' => true,
                    'state'      => (int) $row['state'],
                    'lastrun'    => $row['lastrun'],
                ];
            } else {
                $issues++;
            }
        }

        return [
            'version'            => defined('PLUGIN_PROJECTPLUS_VERSION')
                ? PLUGIN_PROJECTPLUS_VERSION : '?',
            'tables'             => $tables,
            'rights'             => $rights,
            'cron'               => $cron,
            'costs_migrated'     => !empty($config['costs_migrated']),
            'purge_on_uninstall' => !empty($config['purge_on_uninstall']),
            'issues'             => $issues,
        ];
    }

    /**
     * Existe índice com esse nome na tabela?
     * (o core tem Migration::hasKey, mas é método privado de instância)
     */
    private static function hasIndex(string $table, string $indexName): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $it = $DB->request([
            'FROM'  => 'information_schema.STATISTICS',
            'WHERE' => [
                'TABLE_SCHEMA' => $DB->dbdefault,
                'TABLE_NAME'   => $table,
                'INDEX_NAME'   => $indexName,
            ],
            'LIMIT' => 1,
        ]);

        return count($it) > 0;
    }
}

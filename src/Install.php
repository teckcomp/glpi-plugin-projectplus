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
 */
class Install
{
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
        // 6.1) Migração ÚNICA dos custos da aba nativa do GLPI para a
        //      tabela do plugin (só roda com a tabela do plugin vazia —
        //      idempotente; os registros nativos ficam intactos no banco).
        // ------------------------------------------------------------------
        if ($DB->tableExists('glpi_projectcosts')) {
            $cpt = $DB->request([
                'COUNT' => 'cpt',
                'FROM'  => 'glpi_plugin_projectplus_projectcosts',
            ])->current();

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

        // ------------------------------------------------------------------
        // ProfileRight: plugin_projectplus_dashboard
        // (pendência resolvida: era referenciado mas não cadastrado)
        // Concede READ a todos os perfis que já possuem READ em 'project',
        // e controle total ao perfil que tem UPDATE em 'config'.
        // ------------------------------------------------------------------
        $migration->addRight('plugin_projectplus_dashboard', READ, ['project' => READ]);
        $migration->addRight('plugin_projectplus_dashboard', ALLSTANDARDRIGHT, ['config' => UPDATE]);

        // ------------------------------------------------------------------
        // Etapa 8, Bloco 1 — direitos granulares por módulo + escopo.
        //
        // Migração (opção A "preservar"): quem já tinha o direito único
        // 'plugin_projectplus_dashboard' (READ) recebe os módulos no nível
        // máximo, para NINGUÉM perder acesso ao atualizar. O ajuste fino
        // (retirar o que cada perfil não deve ter) é feito depois na aba
        // "ProjectPlus" do Perfil. Os direitos de ESCOPO ("ver os que
        // gerencia" / "ver todos") NÃO são soltos para todos: só o
        // super-admin (config UPDATE) os recebe; o Gestor é marcado à mão.
        //
        // addRight é idempotente: se o direito já existe para o perfil,
        // não sobrescreve — seguro rodar de novo (plugin:install --force).
        //
        // OBS.: este bloco só CRIA e MIGRA os direitos + exibe a matriz.
        // O gate das telas por esses direitos é o Bloco 2 (nada de
        // comportamento de tela muda ainda neste bloco).
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
        // Cron: verificação de prazos/pendências (requisito 5)
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

    public static function uninstall(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        // Proteção de dados: por padrão as tabelas são MANTIDAS na
        // desinstalação (custos, alertas e indicadores sobrevivem a um
        // ciclo desinstalar/reinstalar). O expurgo completo só acontece
        // se o admin ativar "purge_on_uninstall" em Configurações.
        $config = Config::get();
        if (!empty($config['purge_on_uninstall'])) {
            foreach (
                [
                    'glpi_plugin_projectplus_projecttrackings',
                    'glpi_plugin_projectplus_tasktimers',
                    'glpi_plugin_projectplus_templates',
                    'glpi_plugin_projectplus_alerts',
                    'glpi_plugin_projectplus_taskcosts',
                    'glpi_plugin_projectplus_projectcosts',
                    'glpi_plugin_projectplus_taskcomments',
                ] as $table
            ) {
                if ($DB->tableExists($table)) {
                    $DB->doQuery("DROP TABLE `{$table}`");
                }
            }

            // Remove também a configuração do plugin
            CoreConfig::deleteConfigurationValues(
                Config::CONTEXT,
                array_keys(Config::DEFAULTS)
            );
        }

        // Remove os direitos de todos os perfis (o único original +
        // os granulares da Etapa 8)
        ProfileRight::deleteProfileRights([
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
        ]);

        // Remove o cron
        CronTask::unregister('projectplus');

        return true;
    }
}

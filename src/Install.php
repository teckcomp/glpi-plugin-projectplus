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
     *
     * Etapa 9: `tasktimers` SAIU daqui (ver LEGACY_TABLES) e entrou
     * `typephases`, o mapeamento tipo de projeto -> fases.
     */
    public const TABLES = [
        'glpi_plugin_projectplus_projecttrackings',
        'glpi_plugin_projectplus_templates',
        'glpi_plugin_projectplus_alerts',
        'glpi_plugin_projectplus_taskcosts',
        'glpi_plugin_projectplus_projectcosts',
        'glpi_plugin_projectplus_taskcomments',
        'glpi_plugin_projectplus_typephases',
        'glpi_plugin_projectplus_commentfiles',
    ];

    /**
     * Tabelas que o plugin JÁ NÃO CRIA, mas que continuam existindo em bases
     * antigas (Etapa 9, decisão tomada com o Claudio).
     *
     * `tasktimers` ficou órfã desde a Etapa 1 — o cronômetro por tarefa foi
     * substituído pela barra de prazo e nada mais lê nem escreve nessa
     * tabela. Instalação nova não a cria mais; base existente NÃO é alterada
     * (apagar dado de quem talvez tenha registro histórico não é papel de uma
     * atualização de plugin). Ela continua:
     *   - sendo removida pela purga (`purge_on_uninstall`), e
     *   - reportada pelo diagnóstico como "órfã (pode ser removida)".
     */
    public const LEGACY_TABLES = [
        'glpi_plugin_projectplus_tasktimers',
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
    /**
     * `%SIGN%` é resolvido em tempo de execução por `ensureSchema()` com
     * `DBConnection::getDefaultPrimaryKeySignOption()`. Não dá para
     * interpolar aqui: constante de classe não aceita variável.
     */
    private const COLUMNS = [
        'glpi_plugin_projectplus_projecttrackings' => [
            'projects_id'    => 'INT %SIGN% NOT NULL DEFAULT 0',
            'last_activity'  => 'TIMESTAMP NULL DEFAULT NULL',
            'is_stalled'     => 'TINYINT NOT NULL DEFAULT 0',
            'stalled_since'  => 'TIMESTAMP NULL DEFAULT NULL',
            'budget_planned' => 'DECIMAL(20,4) NOT NULL DEFAULT 0',
            'budget_spent'   => 'DECIMAL(20,4) NOT NULL DEFAULT 0',
            'date_creation'  => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'       => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_templates' => [
            'name'          => "VARCHAR(255) NOT NULL DEFAULT ''",
            'comment'       => 'TEXT',
            'entities_id'   => 'INT %SIGN% NOT NULL DEFAULT 0',
            'is_recursive'  => 'TINYINT NOT NULL DEFAULT 0',
            'structure'     => 'LONGTEXT',
            'date_creation' => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'      => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_alerts' => [
            'users_id'      => 'INT %SIGN% NOT NULL DEFAULT 0',
            'itemtype'      => "VARCHAR(100) NOT NULL DEFAULT ''",
            'items_id'      => 'INT %SIGN% NOT NULL DEFAULT 0',
            'kind'          => "VARCHAR(30) NOT NULL DEFAULT ''",
            'message'       => 'TEXT',
            'is_read'       => 'TINYINT NOT NULL DEFAULT 0',
            'date_creation' => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_taskcosts' => [
            'projecttasks_id' => 'INT %SIGN% NOT NULL DEFAULT 0',
            'name'            => "VARCHAR(255) NOT NULL DEFAULT ''",
            'date'            => 'DATE NULL DEFAULT NULL',
            'cost'            => 'DECIMAL(20,4) NOT NULL DEFAULT 0',
            'comment'         => 'TEXT',
            'users_id'        => 'INT %SIGN% NOT NULL DEFAULT 0',
            'date_creation'   => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'        => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_projectcosts' => [
            'projects_id'   => 'INT %SIGN% NOT NULL DEFAULT 0',
            'name'          => "VARCHAR(255) NOT NULL DEFAULT ''",
            'date'          => 'DATE NULL DEFAULT NULL',
            'cost'          => 'DECIMAL(20,4) NOT NULL DEFAULT 0',
            'comment'       => 'TEXT',
            'users_id'      => 'INT %SIGN% NOT NULL DEFAULT 0',
            'date_creation' => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'      => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_taskcomments' => [
            'projecttasks_id' => 'INT %SIGN% NOT NULL DEFAULT 0',
            'users_id'        => 'INT %SIGN% NOT NULL DEFAULT 0',
            'content'         => 'TEXT',
            'date_creation'   => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'        => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_typephases' => [
            'projecttypes_id'  => 'INT %SIGN% NOT NULL DEFAULT 0',
            'projectstates_id' => 'INT %SIGN% NOT NULL DEFAULT 0',
            'ordem'            => 'INT %SIGN% NOT NULL DEFAULT 0',
            'date_creation'    => 'TIMESTAMP NULL DEFAULT NULL',
            'date_mod'         => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'glpi_plugin_projectplus_commentfiles' => [
            'comments_id'     => 'INT %SIGN% NOT NULL DEFAULT 0',
            'projecttasks_id' => 'INT %SIGN% NOT NULL DEFAULT 0',
            'users_id'        => 'INT %SIGN% NOT NULL DEFAULT 0',
            'filename'        => "VARCHAR(255) NOT NULL DEFAULT ''",
            'stored'          => "VARCHAR(80) NOT NULL DEFAULT ''",
            'mime'            => "VARCHAR(100) NOT NULL DEFAULT ''",
            'filesize'        => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'date_creation'   => 'TIMESTAMP NULL DEFAULT NULL',
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
        'glpi_plugin_projectplus_typephases'       => ['type_state'],
    ];

    public static function install(): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        $migration = new Migration(PLUGIN_PROJECTPLUS_VERSION);

        // ACHADO DA AUDITORIA DE PUBLICAÇÃO (26/07/2026).
        //
        // Isto era `utf8mb4` / `utf8mb4_unicode_ci` FIXO. Funcionava aqui
        // porque esta base nasceu no GLPI 11, mas uma instalação atualizada
        // de versões antigas roda com `$DB->use_utf8mb4 = false` — as tabelas
        // do core ficam em `utf8` / `utf8_unicode_ci`. Criar as tabelas do
        // plugin em utf8mb4 nessa base produz erro 1267 ("Illegal mix of
        // collations") em qualquer comparação de texto entre tabela do plugin
        // e tabela do core, e não haveria como o desenvolvedor reproduzir num
        // ambiente moderno.
        //
        // O core expõe exatamente estes três resolvedores; usá-los é o que a
        // própria Migration do GLPI faz.
        $charset   = \DBConnection::getDefaultCharset();
        $collation = \DBConnection::getDefaultCollation();
        $sign      = \DBConnection::getDefaultPrimaryKeySignOption();

        // ------------------------------------------------------------------
        // 1) Indicadores extras por projeto (última atividade, parado, orçamento)
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_projectplus_projecttrackings')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_projectplus_projecttrackings` (
                    `id`                INT {$sign} NOT NULL AUTO_INCREMENT,
                    `projects_id`       INT {$sign} NOT NULL DEFAULT 0,
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
        // 2) Fases por TIPO de projeto (Etapa 9).
        //
        //    Tabela de MAPEAMENTO, não de definição: `glpi_projectstates`
        //    continua sendo a fonte única do nome, da cor e do `is_finished`
        //    de cada fase. Aqui só se diz QUAIS fases pertencem a QUAL tipo e
        //    em que ordem as colunas aparecem.
        //
        //    `projecttypes_id = 0` é o CONJUNTO PADRÃO: vale para todo tipo
        //    que não tenha linha própria (só a exceção precisa ser
        //    configurada). A chave única impede a mesma fase duas vezes no
        //    mesmo tipo.
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_projectplus_typephases')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_projectplus_typephases` (
                    `id`                INT {$sign} NOT NULL AUTO_INCREMENT,
                    `projecttypes_id`   INT {$sign} NOT NULL DEFAULT 0 COMMENT '0 = conjunto padrao',
                    `projectstates_id`  INT {$sign} NOT NULL DEFAULT 0,
                    `ordem`             INT {$sign} NOT NULL DEFAULT 0 COMMENT 'posicao da coluna',
                    `date_creation`     TIMESTAMP NULL DEFAULT NULL,
                    `date_mod`          TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `type_state` (`projecttypes_id`, `projectstates_id`),
                    KEY `ordem` (`projecttypes_id`, `ordem`)
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
                    `id`             INT {$sign} NOT NULL AUTO_INCREMENT,
                    `name`           VARCHAR(255) NOT NULL DEFAULT '',
                    `comment`        TEXT,
                    `entities_id`    INT {$sign} NOT NULL DEFAULT 0,
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
                    `id`             INT {$sign} NOT NULL AUTO_INCREMENT,
                    `users_id`       INT {$sign} NOT NULL DEFAULT 0,
                    `itemtype`       VARCHAR(100) NOT NULL DEFAULT '',
                    `items_id`       INT {$sign} NOT NULL DEFAULT 0,
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
                    `id`               INT {$sign} NOT NULL AUTO_INCREMENT,
                    `projecttasks_id`  INT {$sign} NOT NULL DEFAULT 0,
                    `name`             VARCHAR(255) NOT NULL DEFAULT '',
                    `date`             DATE NULL DEFAULT NULL,
                    `cost`             DECIMAL(20,4) NOT NULL DEFAULT 0,
                    `comment`          TEXT,
                    `users_id`         INT {$sign} NOT NULL DEFAULT 0 COMMENT 'quem lançou',
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
                    `id`             INT {$sign} NOT NULL AUTO_INCREMENT,
                    `projects_id`    INT {$sign} NOT NULL DEFAULT 0,
                    `name`           VARCHAR(255) NOT NULL DEFAULT '',
                    `date`           DATE NULL DEFAULT NULL,
                    `cost`           DECIMAL(20,4) NOT NULL DEFAULT 0,
                    `comment`        TEXT,
                    `users_id`       INT {$sign} NOT NULL DEFAULT 0 COMMENT 'quem lançou',
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
                    `id`               INT {$sign} NOT NULL AUTO_INCREMENT,
                    `projecttasks_id`  INT {$sign} NOT NULL DEFAULT 0,
                    `users_id`         INT {$sign} NOT NULL DEFAULT 0 COMMENT 'autor',
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
        // 7.2) Anexos de comentários (Rodada 3, Bloco 4)
        //      Metadados dos arquivos; o binário fica em
        //      GLPI_PLUGIN_DOC_DIR/projectplus/comments com nome aleatório.
        // ------------------------------------------------------------------
        if (!$DB->tableExists('glpi_plugin_projectplus_commentfiles')) {
            $DB->doQuery("
                CREATE TABLE `glpi_plugin_projectplus_commentfiles` (
                    `id`               INT {$sign} NOT NULL AUTO_INCREMENT,
                    `comments_id`      INT {$sign} NOT NULL DEFAULT 0,
                    `projecttasks_id`  INT {$sign} NOT NULL DEFAULT 0,
                    `users_id`         INT {$sign} NOT NULL DEFAULT 0 COMMENT 'quem enviou',
                    `filename`         VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'nome original',
                    `stored`           VARCHAR(80) NOT NULL DEFAULT '' COMMENT 'nome no disco',
                    `mime`             VARCHAR(100) NOT NULL DEFAULT '',
                    `filesize`         INT UNSIGNED NOT NULL DEFAULT 0,
                    `date_creation`    TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `comments_id` (`comments_id`),
                    KEY `projecttasks_id` (`projecttasks_id`)
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
        // 7.3) Semeadura ÚNICA do conjunto PADRÃO de fases (Etapa 9).
        //      Copia as fases que a instância já usa para
        //      `projecttypes_id = 0`, de modo que no dia seguinte à
        //      atualização ninguém veja diferença nenhuma.
        // ------------------------------------------------------------------
        self::seedDefaultPhases();

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
        // Etapa 6, Bloco 4b — reconciliação dos direitos do administrador.
        //
        // POR QUE ISTO EXISTE: `Migration::addRight` (lição 38) só INSERE a
        // linha que falta; se a linha já existe ele nunca altera o valor, e
        // se TODOS os perfis já a têm ele nem chega a rodar (`return` na
        // contagem zero). Consequência real observada em homologação: o
        // Super-Admin ficou com LEITURA nos módulos em vez de acesso
        // completo, porque as linhas nasceram numa versão anterior do
        // plugin — e nenhuma reinstalação corrigia isso.
        //
        // É a mesma ideia do `ensureSchema()` do Bloco 1: o install não se
        // limita a criar, ele RECONCILIA o que já existe.
        // ------------------------------------------------------------------
        self::ensureAdminRights();

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
                    // `%SIGN%` vem da constante COLUMNS (constante de classe
                    // não interpola variável) e é resolvido aqui com o mesmo
                    // critério do CREATE TABLE — numa base com chaves
                    // assinadas, a coluna nova nasce assinada como as do core.
                    $sqlType = str_replace(
                        '%SIGN%',
                        \DBConnection::getDefaultPrimaryKeySignOption(),
                        $sqlType
                    );
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

    /**
     * Semeadura única do CONJUNTO PADRÃO de fases (Etapa 9).
     *
     * Copia as fases que a instância já tem em `glpi_projectstates` para
     * `projecttypes_id = 0`, na ordem alfabética — que é exatamente a ordem em
     * que o plugin as mostrava antes da Etapa 9 (o prefixo "1. ", "2. " do
     * modelo de 5 fases da Etapa 2.5 garante a sequência correta).
     *
     * É essa semeadura que sustenta a promessa "no dia seguinte ninguém vê
     * diferença": todo tipo, por não ter linha própria, herda esse conjunto.
     *
     * Roda no máximo uma vez: a marca `phases_seeded` é gravada mesmo quando
     * não há nada a copiar (instância sem fase nenhuma cadastrada). E nunca
     * mexe numa tabela que já tenha conteúdo — reinstalar não desfaz a
     * configuração feita à mão na tela de administração.
     */
    private static function seedDefaultPhases(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $config = Config::get();
        if (!empty($config['phases_seeded'])) {
            return;
        }

        if (
            $DB->tableExists(TypePhase::TABLE)
            && $DB->tableExists('glpi_projectstates')
        ) {
            $cpt = $DB->request(['COUNT' => 'cpt', 'FROM' => TypePhase::TABLE])->current();

            // Cinto e suspensório: tabela já povoada não é tocada.
            if ((int) ($cpt['cpt'] ?? 0) === 0) {
                $now   = date('Y-m-d H:i:s');
                $ordem = 0;
                foreach (
                    $DB->request([
                        'SELECT' => ['id'],
                        'FROM'   => 'glpi_projectstates',
                        'ORDER'  => 'name',
                    ]) as $row
                ) {
                    $DB->insert(TypePhase::TABLE, [
                        'projecttypes_id'  => TypePhase::DEFAULT_TYPE,
                        'projectstates_id' => (int) $row['id'],
                        'ordem'            => ++$ordem,
                        'date_creation'    => $now,
                        'date_mod'         => $now,
                    ]);
                }
            }
        }

        CoreConfig::setConfigurationValues(Config::CONTEXT, ['phases_seeded' => 1]);
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
            // Etapa 9: LEGACY_TABLES entra na purga — o plugin já não cria a
            // `tasktimers`, mas quem pediu expurgo completo quer o banco
            // limpo, inclusive do que versões antigas deixaram para trás.
            foreach (array_merge(self::TABLES, self::LEGACY_TABLES) as $table) {
                if ($DB->tableExists($table)) {
                    $DB->doQuery("DROP TABLE `{$table}`");
                }
            }

            // Anexos de comentários: o DROP acima levou os metadados;
            // aqui vão embora também os arquivos físicos (Rodada 3, Bloco 4)
            CommentFile::purgeAllFiles();

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
     * Perfis "administradores" — os que têm o direito NATIVO `config`
     * com o bit UPDATE. É o mesmo critério que o plugin já usa para
     * liberar a tela de Configuração, então não inventa um conceito novo.
     *
     * @return array<int,int> ids de perfil
     */
    public static function getAdminProfileIds(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        if (!$DB->tableExists('glpi_profilerights')) {
            return $ids;
        }

        // O teste de bit é feito em PHP de propósito: QueryExpression com
        // "rights & 2 = 2" funciona, mas some do log de depuração e é o
        // tipo de coisa que quebra silenciosamente numa troca de versão.
        $it = $DB->request([
            'SELECT' => ['profiles_id', 'rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'config'],
        ]);
        foreach ($it as $row) {
            if (((int) $row['rights'] & UPDATE) === UPDATE) {
                $ids[] = (int) $row['profiles_id'];
            }
        }

        return $ids;
    }

    /**
     * Compara os direitos do plugin de um perfil com o MÁXIMO da matriz.
     *
     * @return array<string,array{current:int,max:int}> só os que estão abaixo
     */
    public static function missingAdminRights(int $profileId): array
    {
        $max     = Profile::getMaxRights();
        $current = ProfileRight::getProfileRights($profileId, array_keys($max));

        $missing = [];
        foreach ($max as $name => $bits) {
            $cur = (int) ($current[$name] ?? 0);
            if (($cur | $bits) !== $cur) {
                $missing[$name] = ['current' => $cur, 'max' => $bits];
            }
        }

        return $missing;
    }

    /**
     * Garante que todo perfil administrador tenha os 11 direitos do
     * plugin no nível máximo da matriz (Etapa 6, Bloco 4b).
     *
     * REGRA DE OURO — só ELEVA, nunca rebaixa. O valor gravado é
     * `atual | máximo`, então:
     *  - bit que o administrador já tinha continua ligado;
     *  - bit fora da matriz (um PURGE herdado de `ALLSTANDARDRIGHT`, por
     *    exemplo) é preservado em vez de ser zerado;
     *  - reexecutar o install não muda mais nada — é idempotente.
     *
     * `ProfileRight::updateProfileRights()` foi escolhido em vez de um
     * UPDATE direto porque dispara `post_updateItem`, que atualiza
     * `glpi_profiles.last_rights_update`: é o que faz a sessão aberta
     * recarregar os direitos SEM exigir logout.
     *
     * @return array<int,int> id do perfil => quantos direitos foram elevados
     */
    public static function ensureAdminRights(): array
    {
        $changed = [];

        foreach (self::getAdminProfileIds() as $profileId) {
            $missing = self::missingAdminRights($profileId);
            if ($missing === []) {
                continue;
            }

            $update = [];
            foreach ($missing as $name => $info) {
                $update[$name] = $info['current'] | $info['max'];
            }

            ProfileRight::updateProfileRights($profileId, $update);
            $changed[$profileId] = count($update);
        }

        return $changed;
    }

    /**
     * Diagnóstico do estado da instalação (Etapa 6, Bloco 1d).
     *
     * Usado pela tela de Configuração para conferir, sem SQL manual, o
     * resultado de um ciclo desinstalar/reinstalar: tabelas presentes e
     * com quantas linhas, colunas/índices faltando, os 11 direitos e em
     * quantos perfis estão concedidos, e o cron.
     *
     * @return array{version:string,tables:array,legacy_tables:array,rights:array,
     *               cron:array,phases:array,costs_migrated:bool,
     *               purge_on_uninstall:bool,issues:int}
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

        // --- Tabelas legadas (Etapa 9) -------------------------------------
        // Não são erro: só informam que sobrou lixo de versão antiga, que a
        // purga remove. NÃO incrementam `issues`.
        $legacy = [];
        foreach (self::LEGACY_TABLES as $table) {
            if ($DB->tableExists($table)) {
                $cpt      = $DB->request(['COUNT' => 'cpt', 'FROM' => $table])->current();
                $legacy[] = [
                    'name' => $table,
                    'rows' => (int) ($cpt['cpt'] ?? 0),
                ];
            }
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

        // --- Administradores (Etapa 6, Bloco 4b) ---------------------------
        // Um perfil com `config` UPDATE que não tenha os 11 direitos no
        // máximo é um problema de verdade: foi exatamente o sintoma que
        // deixou o Super-Admin só com leitura.
        $admins = [];
        foreach (self::getAdminProfileIds() as $profileId) {
            $missing = self::missingAdminRights($profileId);
            $name    = '#' . $profileId;
            if ($DB->tableExists('glpi_profiles')) {
                $prow = $DB->request([
                    'SELECT' => ['name'],
                    'FROM'   => 'glpi_profiles',
                    'WHERE'  => ['id' => $profileId],
                    'LIMIT'  => 1,
                ])->current();
                if ($prow && !empty($prow['name'])) {
                    $name = (string) $prow['name'];
                }
            }

            if ($missing !== []) {
                $issues++;
            }

            $admins[] = [
                'id'      => $profileId,
                'name'    => $name,
                'missing' => array_keys($missing),
            ];
        }

        // --- Fases por tipo (Etapa 9) --------------------------------------
        // Um conjunto SEM nenhuma fase `is_finished` é um problema de
        // verdade: a trava "projeto com tarefa/subprojeto aberto não vai para
        // fase finalizada" (hook PRE_ITEM_UPDATE) nunca dispara nele — falha
        // em silêncio, que é o pior tipo de falha.
        $phases = [
            'table'         => $DB->tableExists(TypePhase::TABLE),
            'seeded'        => !empty($config['phases_seeded']),
            'sets'          => 0,
            'custom'        => false,
            'no_finished'   => [],
            'total_states'  => 0,
        ];
        if ($phases['table']) {
            $phases['sets']         = count(TypePhase::configuredTypeIds());
            $phases['custom']       = TypePhase::hasCustomSets();
            $phases['total_states'] = count(TypePhase::allStates());
            $phases['no_finished']  = TypePhase::setsWithoutFinished();
            $issues += count($phases['no_finished']);
        } else {
            $issues++;
        }

        return [
            'version'            => defined('PLUGIN_PROJECTPLUS_VERSION')
                ? PLUGIN_PROJECTPLUS_VERSION : '?',
            'tables'             => $tables,
            'legacy_tables'      => $legacy,
            'rights'             => $rights,
            'admins'             => $admins,
            'cron'               => $cron,
            'phases'             => $phases,
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

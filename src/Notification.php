<?php

namespace GlpiPlugin\Projectplus;

use CronTask;
use GLPIMailer;
use ProjectTask;
use User;

/**
 * Notificações do ProjectPlus (requisito 5).
 *
 * Dois canais:
 *  - Alertas internos (sino): glpi_plugin_projectplus_alerts
 *  - E-mail: enviado via mailer nativo do GLPI
 *
 * Eventos: tarefa atrasada (overdue), pendência próxima do prazo
 * (pending), tarefa concluída (completed) e projeto parado (stalled).
 */
class Notification
{
    /** Dias de antecedência para alertar prazo se aproximando */
    public const PENDING_DAYS = 2;

    // ------------------------------------------------------------------
    // Cron
    // ------------------------------------------------------------------

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'projectplusalerts' => [
                'description' => __('ProjectPlus: atrasos, pendências e projetos parados', 'projectplus'),
            ],
            default => [],
        };
    }

    /**
     * Tarefa cron principal (registrada em Install::install()).
     */
    public static function cronProjectplusalerts(CronTask $task): int
    {
        $config = Config::get();
        $total  = 0;

        $total += self::checkOverdueTasks();
        $total += self::checkPendingTasks((int) $config['pending_days']);

        // Barra de prazo (Bloco 4-revisado): limiares 50/75/90/100%
        $total += self::checkTaskDeadlines();
        $total += self::checkProjectDeadlines();

        ProjectTracking::detectStalled((int) $config['stalled_days']);
        $total += self::alertStalledProjects();

        // Orçamento (Fase B parte 1)
        $total += Budget::cronCheck((int) $config['budget_warn_percent']);

        $task->addVolume($total);
        return ($total > 0) ? 1 : 0;
    }

    /**
     * Alerta de orçamento (sino + e-mail). Público para uso do Budget.
     *
     * @return bool true se alerta novo foi criado
     */
    public static function budgetAlert(int $userId, int $projectId, string $kind, string $message): bool
    {
        if (self::addAlert($userId, 'Project', $projectId, $kind, $message)) {
            self::sendMail(
                $userId,
                $kind === 'budget_over'
                    ? __('Orçamento estourado', 'projectplus')
                    : __('Orçamento próximo do teto', 'projectplus'),
                $message
            );
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Verificações
    // ------------------------------------------------------------------

    /**
     * Tarefas com prazo vencido e não concluídas.
     */
    private static function checkOverdueTasks(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $count    = 0;
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'projects_id', 'plan_end_date'],
            'FROM'   => 'glpi_projecttasks',
            'WHERE'  => [
                'percent_done'  => ['<', 100],
                'plan_end_date' => ['<', date('Y-m-d H:i:s')],
                'NOT'           => ['plan_end_date' => null],
            ],
        ]);

        foreach ($iterator as $row) {
            $message = sprintf(
                __('Tarefa "%s" está atrasada (prazo: %s)', 'projectplus'),
                $row['name'],
                date('d/m/Y', strtotime($row['plan_end_date']))
            );
            $count += self::notifyTaskTeam(
                (int) $row['id'],
                'overdue',
                $message
            );
        }
        return $count;
    }

    /**
     * Tarefas com prazo nos próximos $days dias.
     */
    private static function checkPendingTasks(int $days = self::PENDING_DAYS): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $count    = 0;
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'plan_end_date'],
            'FROM'   => 'glpi_projecttasks',
            'WHERE'  => [
                'percent_done' => ['<', 100],
                ['plan_end_date' => ['>=', date('Y-m-d H:i:s')]],
                ['plan_end_date' => ['<=', date('Y-m-d H:i:s', time() + $days * DAY_TIMESTAMP)]],
            ],
        ]);

        foreach ($iterator as $row) {
            $message = sprintf(
                __('Tarefa "%s" vence em breve (%s)', 'projectplus'),
                $row['name'],
                date('d/m/Y', strtotime($row['plan_end_date']))
            );
            $count += self::notifyTaskTeam((int) $row['id'], 'pending', $message);
        }
        return $count;
    }

    /**
     * Alerta interno para projetos marcados como parados.
     */
    private static function alertStalledProjects(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $count    = 0;
        $iterator = $DB->request([
            'SELECT' => [
                'glpi_plugin_projectplus_projecttrackings.projects_id',
                'glpi_projects.name',
                'glpi_projects.users_id',
            ],
            'FROM'      => 'glpi_plugin_projectplus_projecttrackings',
            'LEFT JOIN' => [
                'glpi_projects' => [
                    'ON' => [
                        'glpi_plugin_projectplus_projecttrackings' => 'projects_id',
                        'glpi_projects'                            => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_plugin_projectplus_projecttrackings.is_stalled' => 1,
                'glpi_projects.is_deleted'                            => 0,
            ],
        ]);

        foreach ($iterator as $row) {
            $managerId = (int) ($row['users_id'] ?? 0);
            if ($managerId <= 0) {
                continue;
            }
            $message = sprintf(
                __('Projeto "%s" está sem atividade (parado)', 'projectplus'),
                $row['name']
            );
            if (self::addAlert($managerId, 'Project', (int) $row['projects_id'], 'stalled', $message)) {
                self::sendMail($managerId, __('Projeto parado', 'projectplus'), $message);
                $count++;
            }
        }
        return $count;
    }

    // ------------------------------------------------------------------
    // Barra de prazo (Bloco 4-revisado)
    //
    // Alertas ao GESTOR do projeto (glpi_projects.users_id) quando o
    // prazo consumido cruza 50/75/90/100%. Cada limiar dispara UMA vez
    // (dedup pela unique key da tabela de alertas); o estouro (100%)
    // é reenviado a cada 8h até o item ser concluído. Tarefa sem datas
    // planejadas gera alerta pedindo correção do planejamento.
    // ------------------------------------------------------------------

    /** Reenvio do alerta de estouro (segundos) */
    public const DEADLINE_REFIRE_SECONDS = 8 * 3600;

    /** Limiar (%) => kind do alerta, do mais grave para o mais leve */
    private const DEADLINE_STEPS = [
        100 => 'deadline_over',
        90  => 'deadline_90',
        75  => 'deadline_75',
        50  => 'deadline_50',
    ];

    /**
     * Tarefas abertas: limiares de prazo + falta de datas planejadas.
     */
    private static function checkTaskDeadlines(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $count    = 0;
        $iterator = $DB->request([
            'SELECT'    => [
                'glpi_projecttasks.id', 'glpi_projecttasks.name',
                'glpi_projecttasks.plan_start_date',
                'glpi_projecttasks.real_start_date',
                'glpi_projecttasks.plan_end_date',
                'glpi_projecttasks.percent_done',
                'glpi_projects.name AS project_name',
                'glpi_projects.users_id AS manager_id',
            ],
            'FROM'      => 'glpi_projecttasks',
            'LEFT JOIN' => [
                'glpi_projects' => [
                    'ON' => [
                        'glpi_projecttasks' => 'projects_id',
                        'glpi_projects'     => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_projecttasks.percent_done' => ['<', 100],
                'glpi_projects.is_deleted'       => 0,
                'glpi_projects.is_template'      => 0,
            ],
        ]);

        foreach ($iterator as $row) {
            $managerId = (int) ($row['manager_id'] ?? 0);
            if ($managerId <= 0) {
                continue; // projeto sem gestor definido
            }

            $dl = Deadline::compute(
                $row['plan_start_date'],
                $row['real_start_date'],
                $row['plan_end_date'],
                (int) $row['percent_done']
            );

            $label = sprintf(
                __('Tarefa "%s" (projeto "%s")', 'projectplus'),
                $row['name'],
                $row['project_name'] ?? '—'
            );

            if ($dl['state'] === 'none') {
                $count += (int) self::deadlineAlert(
                    $managerId,
                    'ProjectTask',
                    (int) $row['id'],
                    'deadline_nodates',
                    sprintf(
                        __('%s está sem datas planejadas — corrija o planejamento', 'projectplus'),
                        $label
                    ),
                    false
                );
                continue;
            }

            $count += self::fireDeadlineStep($managerId, 'ProjectTask', (int) $row['id'], $dl, $label);
        }
        return $count;
    }

    /**
     * Projetos abertos: mesmos limiares, no nível do projeto.
     */
    private static function checkProjectDeadlines(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $count    = 0;
        $iterator = $DB->request([
            'SELECT' => [
                'id', 'name', 'users_id',
                'plan_start_date', 'real_start_date', 'plan_end_date',
                'percent_done',
            ],
            'FROM'  => 'glpi_projects',
            'WHERE' => [
                'percent_done' => ['<', 100],
                'is_deleted'   => 0,
                'is_template'  => 0,
            ],
        ]);

        foreach ($iterator as $row) {
            $managerId = (int) ($row['users_id'] ?? 0);
            if ($managerId <= 0) {
                continue;
            }

            $dl = Deadline::compute(
                $row['plan_start_date'],
                $row['real_start_date'],
                $row['plan_end_date'],
                (int) $row['percent_done']
            );

            if ($dl['state'] === 'none' || $dl['state'] === 'done') {
                continue; // projeto sem datas: só barra cinza, sem alerta
            }

            $count += self::fireDeadlineStep(
                $managerId,
                'Project',
                (int) $row['id'],
                $dl,
                sprintf(__('Projeto "%s"', 'projectplus'), $row['name'])
            );
        }
        return $count;
    }

    /**
     * Dispara APENAS o limiar mais alto atingido (evita 3 alertas de uma
     * vez quando o item já entra em 92%, por exemplo).
     */
    private static function fireDeadlineStep(
        int $userId,
        string $itemtype,
        int $itemsId,
        array $dl,
        string $label
    ): int {
        foreach (self::DEADLINE_STEPS as $threshold => $kind) {
            if ($dl['percent'] < $threshold) {
                continue;
            }

            if ($kind === 'deadline_over') {
                $over = (string) ($dl['label'] ?? '');
                $over = str_starts_with($over, '+') ? substr($over, 1) : '<1h';
                $message = sprintf(
                    __('%s ESTOUROU o prazo planejado (excedente: %s)', 'projectplus'),
                    $label,
                    $over
                );
            } else {
                $message = sprintf(
                    __('%s atingiu %d%% do prazo planejado', 'projectplus'),
                    $label,
                    $threshold
                );
            }

            return (int) self::deadlineAlert(
                $userId,
                $itemtype,
                $itemsId,
                $kind,
                $message,
                $kind === 'deadline_over' // só o estouro reenvia a cada 8h
            );
        }
        return 0; // abaixo de 50%: nada a alertar
    }

    /**
     * Alerta de prazo com dedup; opcionalmente "re-dispara" (atualiza o
     * registro existente e reenvia e-mail) após DEADLINE_REFIRE_SECONDS.
     *
     * @return bool true se um alerta foi criado OU re-disparado
     */
    private static function deadlineAlert(
        int $userId,
        string $itemtype,
        int $itemsId,
        string $kind,
        string $message,
        bool $refire
    ): bool {
        /** @var \DBmysql $DB */
        global $DB;

        $subject = match ($kind) {
            'deadline_50'      => __('Prazo: 50% consumido', 'projectplus'),
            'deadline_75'      => __('Prazo: 75% consumido', 'projectplus'),
            'deadline_90'      => __('Prazo: 90% consumido', 'projectplus'),
            'deadline_over'    => __('PRAZO ESTOURADO', 'projectplus'),
            'deadline_nodates' => __('Tarefa sem datas planejadas', 'projectplus'),
            default            => __('ProjectPlus', 'projectplus'),
        };

        // 1ª vez: INSERT respeitando a unique key de dedup
        if (self::addAlert($userId, $itemtype, $itemsId, $kind, $message)) {
            self::sendMail($userId, $subject, $message);
            return true;
        }

        if (!$refire) {
            return false;
        }

        // Já existe: re-dispara se o último disparo tiver 8h ou mais
        $row = $DB->request([
            'FROM'  => 'glpi_plugin_projectplus_alerts',
            'WHERE' => [
                'users_id' => $userId,
                'itemtype' => $itemtype,
                'items_id' => $itemsId,
                'kind'     => $kind,
            ],
            'LIMIT' => 1,
        ])->current();

        if (
            $row
            && !empty($row['date_creation'])
            && strtotime($row['date_creation']) <= time() - self::DEADLINE_REFIRE_SECONDS
        ) {
            $DB->update(
                'glpi_plugin_projectplus_alerts',
                [
                    'message'       => $message,
                    'is_read'       => 0,
                    'date_creation' => date('Y-m-d H:i:s'),
                ],
                ['id' => (int) $row['id']]
            );
            self::sendMail($userId, $subject, $message);
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Hook: tarefa atualizada (detecção de conclusão)
    // ------------------------------------------------------------------

    public static function onTaskUpdate(ProjectTask $task): void
    {
        // Mantém indicador de atividade do projeto
        $projectId = (int) ($task->fields['projects_id'] ?? 0);
        if ($projectId > 0) {
            ProjectTracking::touch($projectId);
        }

        // Concluída agora?
        if (
            in_array('percent_done', $task->updates ?? [], true)
            && (int) $task->fields['percent_done'] >= 100
        ) {
            $message = sprintf(
                __('Tarefa "%s" foi concluída', 'projectplus'),
                $task->fields['name']
            );
            self::notifyTaskTeam((int) $task->getID(), 'completed', $message);
        }
    }

    // ------------------------------------------------------------------
    // Destinatários e envio
    // ------------------------------------------------------------------

    /**
     * Notifica todos os usuários da equipe da tarefa.
     *
     * ATENÇÃO (ponto a validar em homologação): os campos itemtype /
     * items_id de glpi_projecttaskteams foram assumidos por convenção
     * do GLPI, mas ainda não confirmados contra o schema real.
     * Validar com: DESCRIBE glpi_projecttaskteams;
     */
    private static function notifyTaskTeam(int $taskId, string $kind, string $message): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $count    = 0;
        $iterator = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM'   => 'glpi_projecttaskteams',
            'WHERE'  => [
                'projecttasks_id' => $taskId,
                'itemtype'        => 'User',   // <- VALIDAR contra schema real
            ],
        ]);

        foreach ($iterator as $row) {
            $userId = (int) $row['items_id'];
            if ($userId <= 0) {
                continue;
            }
            if (self::addAlert($userId, 'ProjectTask', $taskId, $kind, $message)) {
                $subject = match ($kind) {
                    'overdue'   => __('Tarefa atrasada', 'projectplus'),
                    'pending'   => __('Prazo se aproximando', 'projectplus'),
                    'completed' => __('Tarefa concluída', 'projectplus'),
                    default     => __('ProjectPlus', 'projectplus'),
                };
                self::sendMail($userId, $subject, $message);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Insere alerta interno (sino). A UNIQUE KEY `dedup` da tabela
     * impede alertas repetidos do mesmo tipo para o mesmo item/usuário.
     *
     * @return bool true se um alerta NOVO foi criado
     */
    private static function addAlert(
        int $userId,
        string $itemtype,
        int $itemsId,
        string $kind,
        string $message
    ): bool {
        /** @var \DBmysql $DB */
        global $DB;

        // INSERT IGNORE respeitando a unique key de deduplicação
        $result = $DB->doQuery(
            'INSERT IGNORE INTO `glpi_plugin_projectplus_alerts`
                (`users_id`, `itemtype`, `items_id`, `kind`, `message`, `is_read`, `date_creation`)
             VALUES ('
                . (int) $userId . ', '
                . "'" . $DB->escape($itemtype) . "', "
                . (int) $itemsId . ', '
                . "'" . $DB->escape($kind) . "', "
                . "'" . $DB->escape($message) . "', "
                . '0, NOW())'
        );

        return $result && $DB->affectedRows() > 0;
    }

    /**
     * E-mail simples via mailer nativo do GLPI.
     */
    private static function sendMail(int $userId, string $subject, string $body): bool
    {
        $config = Config::get();
        if (empty($config['email_enabled'])) {
            return false; // apenas sino
        }

        $user = new User();
        if (!$user->getFromDB($userId)) {
            return false;
        }

        $email = $user->getDefaultEmail();
        if (empty($email)) {
            return false;
        }

        try {
            $mailer = new GLPIMailer();
            $mail   = $mailer->getEmail();
            $mail->to($email);
            $mail->subject('[ProjectPlus] ' . $subject);
            $mail->text($body);
            return $mailer->send();
        } catch (\Throwable $e) {
            // Não interrompe o cron por falha de e-mail
            return false;
        }
    }

    // ------------------------------------------------------------------
    // API do sino (consumida por ajax/alerts.php)
    // ------------------------------------------------------------------

    public static function getUnread(int $userId, int $limit = 20): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $alerts   = [];
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_projectplus_alerts',
            'WHERE' => ['users_id' => $userId, 'is_read' => 0],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => $limit,
        ]);

        foreach ($iterator as $row) {
            $alerts[] = $row;
        }
        return $alerts;
    }

    /**
     * Conteúdo completo do sino: não lidas + histórico de lidas recentes.
     *
     * @return array{unread: array, read: array}
     */
    public static function getForBell(int $userId, int $unreadLimit = 20, int $readLimit = 10): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $read     = [];
        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_projectplus_alerts',
            'WHERE' => ['users_id' => $userId, 'is_read' => 1],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => $readLimit,
        ]);
        foreach ($iterator as $row) {
            $read[] = $row;
        }

        return [
            'unread' => self::getUnread($userId, $unreadLimit),
            'read'   => $read,
        ];
    }

    public static function markRead(int $alertId, int $userId): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        return (bool) $DB->update(
            'glpi_plugin_projectplus_alerts',
            ['is_read' => 1],
            ['id' => $alertId, 'users_id' => $userId]
        );
    }

    /**
     * Marca TODOS os alertas não lidos do usuário como lidos (sino).
     */
    public static function markAllRead(int $userId): bool
    {
        /** @var \DBmysql $DB */
        global $DB;

        return (bool) $DB->update(
            'glpi_plugin_projectplus_alerts',
            ['is_read' => 1],
            ['users_id' => $userId, 'is_read' => 0]
        );
    }
}

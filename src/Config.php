<?php

namespace GlpiPlugin\Projectplus;

use Config as CoreConfig;

/**
 * Configuração do ProjectPlus.
 *
 * Usa a tabela glpi_configs nativa com context = 'plugin:projectplus'
 * (mecanismo padrão do GLPI — não cria tabela extra para isso).
 */
class Config
{
    public const CONTEXT = 'plugin:projectplus';

    public const DEFAULTS = [
        'stalled_days'        => 7,   // dias sem atividade => projeto "parado"
        'pending_days'        => 2,   // antecedência (dias) para alertar prazo
        'email_enabled'       => 1,   // 1 = envia e-mail, 0 = só sino
        'menu_first'          => 1,   // 1 = Painel primeiro no menu Ferramentas
        'budget_warn_percent' => 80,  // % do teto que dispara o alerta de orçamento
        'hide_native_costs'   => 1,   // 1 = oculta a aba Custos nativa do projeto
        'hide_native_kanban'  => 1,   // 1 = oculta a aba Kanban nativa do projeto (Etapa 7)
        'move_comment'        => 2,   // O-3: comentário ao mover no Kanban (0 desligado, 1 opcional, 2 obrigatório)
        'move_new_deadline'   => 1,   // O-3: cartão atrasado exige novo fim planejado ao ser movido
        'desc_required'       => 1,   // O-2f: descrição obrigatória (criação e mudança de fase sem descrição)
        'purge_on_uninstall'  => 0,   // 1 = apaga tabelas/dados/direitos ao desinstalar
        'costs_migrated'      => 0,   // 1 = custos nativos já importados (marca interna)
        'phases_seeded'       => 0,   // 1 = conjunto padrão de fases já semeado (marca interna, Etapa 9)
        'unread_seeded'       => 0,   // O-4: 1 = marco dos não lidos já gravado (marca interna)
        'unread_base_id'      => 0,   // O-4: comentários com id até este contam como lidos (marca interna)
    ];

    /**
     * Chaves internas: existem em DEFAULTS (para serem apagadas na purga),
     * mas não aparecem no formulário de configuração.
     */
    public const INTERNAL_KEYS = ['costs_migrated', 'phases_seeded', 'unread_seeded', 'unread_base_id'];

    /** O-3: teto de chaves que não são número livre (o resto só tem piso 0). */
    public const MAX = ['move_comment' => 2];

    /**
     * Lê a configuração mesclada com os padrões.
     */
    public static function get(): array
    {
        $values = CoreConfig::getConfigurationValues(self::CONTEXT);
        return array_merge(self::DEFAULTS, $values);
    }

    /**
     * Grava valores.
     */
    public static function set(array $values): void
    {
        $clean = [];
        foreach (self::DEFAULTS as $key => $default) {
            // Chaves internas (marcas do instalador) nunca vêm do formulário
            if (in_array($key, self::INTERNAL_KEYS, true)) {
                continue;
            }
            if (isset($values[$key])) {
                $clean[$key] = max(0, (int) $values[$key]);
                if (isset(self::MAX[$key])) {
                    $clean[$key] = min(self::MAX[$key], $clean[$key]);
                }
            }
        }
        if ($clean) {
            CoreConfig::setConfigurationValues(self::CONTEXT, $clean);
        }
    }

    /**
     * URL desta tela.
     *
     * ATENÇÃO (Etapa 6, Bloco 2 — lição 44): no GLPI 11 TODA requisição
     * passa pelo front controller (`public/index.php`), então
     * `$_SERVER['PHP_SELF']` vale `/index.php` e NÃO a rota real. Usar
     * PHP_SELF como `action` fazia o formulário postar na raiz do GLPI,
     * onde o endpoint de inventário responde
     * `<REPLY><ERROR>XML not well formed!</ERROR></REPLY>`.
     * O caminho estável é montar a rota a partir de `root_doc` — o que
     * agora é feito num lugar só, em `Url` (Etapa 6, Bloco 4a).
     */
    public static function formUrl(): string
    {
        return Url::to('front/config.form.php');
    }

    /**
     * Bloco N-7 — tudo que a tela de Configuração (templates/config_shell.html.twig)
     * precisa, numa variável só. O HTML que antes saía em `echo` daqui
     * (showForm/showPhaseAdminLink/showDiagnostics) agora mora no template.
     */
    public static function viewData(): array
    {
        $config = self::get();
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            if (!in_array($key, self::INTERNAL_KEYS, true)) {
                $values[$key] = (int) ($config[$key] ?? $default);
            }
        }

        $report = Install::healthReport();

        return [
            'values'  => $values,
            'version' => (string) $report['version'],
            'health'  => $report,
            'summary' => self::summarize($report),
            'phases'  => self::phaseSummary(TypePhase::adminData()),
            'mail'    => Notification::mailStatus(),
        ];
    }

    /**
     * Resumo do diagnóstico para o cartão lateral (Bloco N-7). Parte pura:
     * recebe o Install::healthReport() e devolve o título + uma linha por
     * assunto, cada uma com `status` ok | warn | danger (a cor é do CSS).
     *
     * Cron DESATIVADO (state 0) não conta em `issues` no healthReport — aqui
     * aparece como observação (warn), para não esconder alerta que não sai.
     */
    public static function summarize(array $report): array
    {
        $items = [];

        $tables   = $report['tables'] ?? [];
        $tablesOk = 0;
        foreach ($tables as $t) {
            if (!empty($t['exists']) && empty($t['missing'])) {
                $tablesOk++;
            }
        }
        $items[] = [
            'label'  => __('Tabelas', 'projectplus'),
            'value'  => sprintf(__('%1$d de %2$d', 'projectplus'), $tablesOk, count($tables)),
            'status' => $tablesOk === count($tables) ? 'ok' : 'danger',
        ];

        $rights   = $report['rights'] ?? [];
        $rightsOk = 0;
        foreach ($rights as $r) {
            if ((int) ($r['profiles'] ?? 0) > 0) {
                $rightsOk++;
            }
        }
        $items[] = [
            'label'  => __('Direitos', 'projectplus'),
            'value'  => sprintf(__('%1$d de %2$d', 'projectplus'), $rightsOk, count($rights)),
            'status' => $rightsOk === count($rights) ? 'ok' : 'danger',
        ];

        $cron = $report['cron'] ?? [];
        if (empty($cron['registered'])) {
            $cronValue  = __('NÃO registrado', 'projectplus');
            $cronStatus = 'danger';
        } elseif ((int) ($cron['state'] ?? 0) === 0) {
            $cronValue  = __('desativado', 'projectplus');
            $cronStatus = 'warn';
        } else {
            $cronValue  = __('ativo', 'projectplus');
            $cronStatus = 'ok';
        }
        $items[] = [
            'label'  => __('Cron de alertas', 'projectplus'),
            'value'  => $cronValue,
            'status' => $cronStatus,
        ];

        $admins     = $report['admins'] ?? [];
        $adminsLow  = 0;
        foreach ($admins as $a) {
            if (!empty($a['missing'])) {
                $adminsLow++;
            }
        }
        if ($admins === []) {
            $adminValue  = __('nenhum perfil', 'projectplus');
            $adminStatus = 'danger';
        } elseif ($adminsLow > 0) {
            $adminValue  = sprintf(
                _n('%d perfil abaixo do máximo', '%d perfis abaixo do máximo', $adminsLow, 'projectplus'),
                $adminsLow
            );
            $adminStatus = 'danger';
        } else {
            $adminValue  = __('completo', 'projectplus');
            $adminStatus = 'ok';
        }
        $items[] = [
            'label'  => __('Perfil administrador', 'projectplus'),
            'value'  => $adminValue,
            'status' => $adminStatus,
        ];

        $ph = $report['phases'] ?? [];
        if (empty($ph['table'])) {
            $phValue  = __('AUSENTE', 'projectplus');
            $phStatus = 'danger';
        } elseif (!empty($ph['no_finished'])) {
            $n        = count($ph['no_finished']);
            $phValue  = sprintf(
                _n('%d conjunto sem finalizadora', '%d conjuntos sem finalizadora', $n, 'projectplus'),
                $n
            );
            $phStatus = 'danger';
        } else {
            $phValue  = 'OK';
            $phStatus = 'ok';
        }
        $items[] = [
            'label'  => __('Fases por tipo', 'projectplus'),
            'value'  => $phValue,
            'status' => $phStatus,
        ];

        $issues = (int) ($report['issues'] ?? 0);
        $warns  = 0;
        foreach ($items as $it) {
            if ($it['status'] === 'warn') {
                $warns++;
            }
        }
        $attention = $issues + $warns;

        if ($attention === 0) {
            $status = 'ok';
            $title  = __('Tudo certo', 'projectplus');
            $sub    = __('Tabelas, direitos e cron no lugar', 'projectplus');
        } else {
            $status = $issues > 0 ? 'danger' : 'warn';
            $title  = sprintf(
                _n('%d ponto de atenção', '%d pontos de atenção', $attention, 'projectplus'),
                $attention
            );
            $sub    = __('Detalhes na aba Diagnóstico', 'projectplus');
        }

        return [
            'status' => $status,
            'title'  => $title,
            'sub'    => $sub,
            'items'  => $items,
        ];
    }

    /**
     * Fases por tipo, resumidas para a aba da Configuração (Bloco N-7).
     * Parte pura: recebe o TypePhase::adminData(). Tipo sem conjunto próprio
     * vem com `inherits` = true (usa o padrão) e sem a lista de fases.
     */
    public static function phaseSummary(array $adminData): array
    {
        $sets   = [];
        $custom = 0;
        foreach ($adminData['sets'] ?? [] as $set) {
            $isDefault = !empty($set['is_default']);
            $mapped    = !empty($set['mapped']);
            if (!$isDefault && $mapped) {
                $custom++;
            }
            $inherits = !$isDefault && !$mapped;

            $phases      = [];
            $hasFinished = false;
            if (!$inherits) {
                foreach ($set['phases'] ?? [] as $p) {
                    $phases[] = [
                        'name'        => (string) ($p['name'] ?? ''),
                        'color'       => (string) ($p['color'] ?? ''),
                        'is_finished' => !empty($p['is_finished']),
                    ];
                    if (!empty($p['is_finished'])) {
                        $hasFinished = true;
                    }
                }
            }

            $sets[] = [
                'id'           => (int) ($set['id'] ?? 0),
                'name'         => (string) ($set['name'] ?? ''),
                'is_default'   => $isDefault,
                'inherits'     => $inherits,
                'projects'     => (int) ($set['projects'] ?? 0),
                'phases'       => $phases,
                'has_finished' => $inherits ? true : $hasFinished,
            ];
        }

        return [
            'states' => count($adminData['states'] ?? []),
            'custom' => $custom,
            'sets'   => $sets,
        ];
    }
}

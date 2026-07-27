<?php

namespace GlpiPlugin\Projectplus;

use Config as CoreConfig;
use Html;
use Session;

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
        'purge_on_uninstall'  => 0,   // 1 = apaga tabelas/dados/direitos ao desinstalar
        'costs_migrated'      => 0,   // 1 = custos nativos já importados (marca interna)
        'phases_seeded'       => 0,   // 1 = conjunto padrão de fases já semeado (marca interna, Etapa 9)
    ];

    /**
     * Chaves internas: existem em DEFAULTS (para serem apagadas na purga),
     * mas não aparecem no formulário de configuração.
     */
    public const INTERNAL_KEYS = ['costs_migrated', 'phases_seeded'];

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
     * Formulário simples de configuração.
     */
    public static function showForm(): void
    {
        Session::checkRight('config', UPDATE);

        $config = self::get();
        $action = htmlspecialchars(self::formUrl());

        echo "<form method='post' action='{$action}'>";
        echo '<table class="tab_cadre_fixe">';
        echo '<tr><th colspan="2">' . __('Configuração do ProjectPlus', 'projectplus') . '</th></tr>';

        echo '<tr class="tab_bg_1"><td>'
            . __('Dias sem atividade para marcar projeto como "parado"', 'projectplus')
            . '</td><td>'
            . "<input type='number' name='stalled_days' min='1' max='90' value='"
            . (int) $config['stalled_days'] . "'>"
            . '</td></tr>';

        echo '<tr class="tab_bg_1"><td>'
            . __('Antecedência (dias) para alertar prazo se aproximando', 'projectplus')
            . '</td><td>'
            . "<input type='number' name='pending_days' min='0' max='30' value='"
            . (int) $config['pending_days'] . "'>"
            . '</td></tr>';

        echo '<tr class="tab_bg_1"><td>'
            . __('Enviar e-mails (além do sino)', 'projectplus')
            . '</td><td>';
        echo "<select name='email_enabled'>";
        echo "<option value='1'" . ($config['email_enabled'] ? ' selected' : '') . '>'
            . __('Yes') . '</option>';
        echo "<option value='0'" . (!$config['email_enabled'] ? ' selected' : '') . '>'
            . __('No') . '</option>';
        echo '</select></td></tr>';

        echo '<tr class="tab_bg_1"><td>'
            . __('Gestor de Projetos como primeiro item do menu Ferramentas', 'projectplus')
            . '</td><td>';
        echo "<select name='menu_first'>";
        echo "<option value='1'" . ($config['menu_first'] ? ' selected' : '') . '>'
            . __('Yes') . '</option>';
        echo "<option value='0'" . (!$config['menu_first'] ? ' selected' : '') . '>'
            . __('No') . '</option>';
        echo '</select></td></tr>';

        echo '<tr class="tab_bg_1"><td>'
            . __('Alerta de orçamento ao atingir (% do teto)', 'projectplus')
            . '</td><td>'
            . "<input type='number' name='budget_warn_percent' min='1' max='100' value='"
            . (int) $config['budget_warn_percent'] . "'>"
            . '</td></tr>';

        echo '<tr class="tab_bg_1"><td>'
            . __('Ocultar a aba "Custos" nativa do projeto (fonte única: abas do ProjectPlus)', 'projectplus')
            . '</td><td>';
        echo "<select name='hide_native_costs'>";
        echo "<option value='1'" . ($config['hide_native_costs'] ? ' selected' : '') . '>'
            . __('Yes') . '</option>';
        echo "<option value='0'" . (!$config['hide_native_costs'] ? ' selected' : '') . '>'
            . __('No') . '</option>';
        echo '</select></td></tr>';

        echo '<tr class="tab_bg_1"><td>'
            . __('Ocultar a aba "Kanban" nativa do projeto (fonte única: aba Kanban do ProjectPlus)', 'projectplus')
            . '</td><td>';
        echo "<select name='hide_native_kanban'>";
        echo "<option value='1'" . ($config['hide_native_kanban'] ? ' selected' : '') . '>'
            . __('Yes') . '</option>';
        echo "<option value='0'" . (!$config['hide_native_kanban'] ? ' selected' : '') . '>'
            . __('No') . '</option>';
        echo '</select></td></tr>';

        echo '<tr class="tab_bg_1"><td>'
            . __('Apagar tabelas, dados e direitos do plugin ao desinstalar', 'projectplus')
            . '<br><small>'
            . __(
                'Com "Não", desinstalar preserva os dados E a configuração de direitos '
                . 'por perfil — reinstalar devolve tudo como estava.',
                'projectplus'
            )
            . '</small></td><td>';
        echo "<select name='purge_on_uninstall'>";
        echo "<option value='0'" . (!$config['purge_on_uninstall'] ? ' selected' : '') . '>'
            . __('Não (manter dados e direitos)', 'projectplus') . '</option>';
        echo "<option value='1'" . ($config['purge_on_uninstall'] ? ' selected' : '') . '>'
            . __('Sim (expurgo completo)', 'projectplus') . '</option>';
        echo '</select></td></tr>';

        echo '<tr class="tab_bg_2"><td colspan="2" class="center">';
        echo "<input type='submit' name='update' value='" . _sx('button', 'Save') . "' class='btn btn-primary'>";
        echo '&nbsp;';
        echo "<input type='submit' name='test_mail' value='"
            . __('Salvar e enviar e-mail de teste', 'projectplus')
            . "' class='btn btn-secondary'>";
        echo '<br><small>'
            . __(
                'O e-mail de teste vai para o endereço do seu próprio usuário e não '
                . 'depende da opção "Enviar e-mails" acima — serve para testar o canal.',
                'projectplus'
            )
            . '</small>';
        echo '</td></tr>';
        echo '</table>';
        Html::closeForm();

        self::showPhaseAdminLink();
        self::showDiagnostics();
    }

    /**
     * Caminho de IDA para a tela "Fases por tipo de projeto" (Etapa 9).
     *
     * Lição 37: tela nova sem caminho de ida fica invisível. A tela vive
     * debaixo do direito NATIVO `config` (é vocabulário da instância, não
     * dado de projeto), então o lugar natural do atalho é aqui — e os dois
     * Kanbans também ganharam um botão, porque é lá que a falta de
     * configuração aparece.
     */
    public static function showPhaseAdminLink(): void
    {
        $report = TypePhase::adminData();
        $custom = 0;
        foreach ($report['sets'] as $set) {
            if (!$set['is_default'] && $set['mapped']) {
                $custom++;
            }
        }

        echo '<table class="tab_cadre_fixe" style="margin-top:20px">';
        echo '<tr><th colspan="2">'
            . __('Fases por tipo de projeto', 'projectplus')
            . '</th></tr>';
        echo '<tr class="tab_bg_1"><td>'
            . __(
                'Cada tipo de projeto pode ter seu próprio conjunto de fases e sua '
                . 'própria ordem de colunas no Kanban. Tipo sem configuração usa o '
                . 'conjunto padrão.',
                'projectplus'
            )
            . '<br><small>'
            . sprintf(
                __('Fases cadastradas na instância: %1$d · tipos com conjunto próprio: %2$d', 'projectplus'),
                count($report['states']),
                $custom
            )
            . '</small></td><td class="center">'
            . '<a class="btn btn-primary" href="' . htmlspecialchars(Url::to('front/typephases.php')) . '">'
            . __('Configurar fases por tipo', 'projectplus')
            . '</a></td></tr>';
        echo '</table>';
    }

    /**
     * Diagnóstico da instalação (Etapa 6, Bloco 1d).
     *
     * Mostra tabelas, direitos e cron em tela, para conferir um ciclo
     * desinstalar/reinstalar sem precisar abrir o banco.
     */
    public static function showDiagnostics(): void
    {
        $report = Install::healthReport();

        echo '<table class="tab_cadre_fixe" style="margin-top:20px">';
        echo '<tr><th colspan="3">'
            . __('Diagnóstico da instalação', 'projectplus')
            . ' — v' . htmlspecialchars((string) $report['version'])
            . '</th></tr>';

        if ($report['issues'] === 0) {
            echo '<tr class="tab_bg_1"><td colspan="3" style="color:#2e7d32;font-weight:bold">'
                . __('Tudo certo: tabelas, direitos e cron no lugar.', 'projectplus')
                . '</td></tr>';
        } else {
            echo '<tr class="tab_bg_1"><td colspan="3" style="color:#c62828;font-weight:bold">'
                . sprintf(
                    __('%d ponto(s) de atenção — ver as linhas marcadas abaixo.', 'projectplus'),
                    (int) $report['issues']
                )
                . '<br><small>'
                . __(
                    'Reexecutar a instalação costuma resolver: '
                    . 'php bin/console plugin:install --force projectplus '
                    . 'e depois plugin:activate projectplus.',
                    'projectplus'
                )
                . '</small></td></tr>';
        }

        // --- Tabelas -------------------------------------------------------
        echo '<tr class="tab_bg_2"><th>' . __('Tabela', 'projectplus') . '</th>'
            . '<th>' . __('Registros', 'projectplus') . '</th>'
            . '<th>' . __('Situação', 'projectplus') . '</th></tr>';

        foreach ($report['tables'] as $t) {
            if (!$t['exists']) {
                $status = '<span style="color:#c62828">' . __('AUSENTE', 'projectplus') . '</span>';
                $rows   = '-';
            } elseif (!empty($t['missing'])) {
                $status = '<span style="color:#ef6c00">'
                    . __('faltando:', 'projectplus') . ' '
                    . htmlspecialchars(implode(', ', $t['missing']))
                    . '</span>';
                $rows   = (string) $t['rows'];
            } else {
                $status = '<span style="color:#2e7d32">OK</span>';
                $rows   = (string) $t['rows'];
            }

            echo '<tr class="tab_bg_1"><td><code>'
                . htmlspecialchars($t['name']) . '</code></td>'
                . '<td>' . $rows . '</td>'
                . '<td>' . $status . '</td></tr>';
        }

        foreach ($report['legacy_tables'] as $t) {
            echo '<tr class="tab_bg_1"><td><code>'
                . htmlspecialchars($t['name']) . '</code></td>'
                . '<td>' . (int) $t['rows'] . '</td>'
                . '<td><span style="color:#7a7a7a">'
                . __('órfã de versão antiga — não é mais usada; a purga remove', 'projectplus')
                . '</span></td></tr>';
        }

        // --- Fases por tipo (Etapa 9) --------------------------------------
        $ph = $report['phases'];

        echo '<tr class="tab_bg_2"><th colspan="3">'
            . __('Fases por tipo de projeto', 'projectplus') . '</th></tr>';

        if (!$ph['table']) {
            echo '<tr class="tab_bg_1"><td colspan="3" style="color:#c62828">'
                . __('Tabela de mapeamento AUSENTE — reexecute a instalação.', 'projectplus')
                . '</td></tr>';
        } else {
            echo '<tr class="tab_bg_1"><td colspan="2">'
                . __('Conjuntos configurados', 'projectplus') . '</td><td>'
                . sprintf(
                    __('%1$d conjunto(s) para %2$d fase(s) cadastrada(s)', 'projectplus'),
                    (int) $ph['sets'],
                    (int) $ph['total_states']
                )
                . ($ph['custom']
                    ? ''
                    : ' — <span style="color:#7a7a7a">'
                      . __('nenhum tipo com conjunto próprio: todos usam o padrão', 'projectplus')
                      . '</span>')
                . '</td></tr>';

            echo '<tr class="tab_bg_1"><td colspan="2">'
                . __('Conjunto padrão semeado na instalação', 'projectplus') . '</td><td>'
                . ($ph['seeded']
                    ? __('já realizada (não repete)', 'projectplus')
                    : __('pendente (roda na próxima instalação)', 'projectplus'))
                . '</td></tr>';

            if (empty($ph['no_finished'])) {
                echo '<tr class="tab_bg_1"><td colspan="2">'
                    . __('Fase finalizadora em cada conjunto', 'projectplus') . '</td><td>'
                    . '<span style="color:#2e7d32">'
                    . __('todos os conjuntos têm ao menos uma fase marcada como finalizada', 'projectplus')
                    . '</span></td></tr>';
            } else {
                foreach ($ph['no_finished'] as $set) {
                    echo '<tr class="tab_bg_1"><td colspan="2">'
                        . __('Conjunto sem fase finalizadora', 'projectplus') . '</td><td>'
                        . '<span style="color:#c62828">'
                        . htmlspecialchars((string) $set['name'])
                        . '</span> — <small>'
                        . __(
                            'sem uma fase marcada como "finalizada" em Configurar > '
                            . 'Listas suspensas > Estados de projeto, a trava "projeto com '
                            . 'tarefa ou subprojeto aberto não vai para fase finalizada" '
                            . 'nunca dispara nesse conjunto.',
                            'projectplus'
                        )
                        . '</small></td></tr>';
                }
            }
        }

        // --- Direitos ------------------------------------------------------
        echo '<tr class="tab_bg_2"><th>' . __('Direito', 'projectplus') . '</th>'
            . '<th>' . __('Perfis com a linha', 'projectplus') . '</th>'
            . '<th>' . __('Perfis com acesso', 'projectplus') . '</th></tr>';

        foreach ($report['rights'] as $r) {
            $color = $r['profiles'] === 0 ? '#c62828' : '#2e7d32';
            echo '<tr class="tab_bg_1"><td><code>'
                . htmlspecialchars($r['name']) . '</code></td>'
                . '<td style="color:' . $color . '">' . (int) $r['profiles'] . '</td>'
                . '<td>' . (int) $r['granted'] . '</td></tr>';
        }

        // --- Administradores (Etapa 6, Bloco 4b) ---------------------------
        echo '<tr class="tab_bg_2"><th>' . __('Perfil administrador', 'projectplus') . '</th>'
            . '<th colspan="2">' . __('Direitos do plugin no nível máximo', 'projectplus') . '</th></tr>';

        if (empty($report['admins'])) {
            echo '<tr class="tab_bg_1"><td colspan="3" style="color:#c62828">'
                . __('Nenhum perfil com o direito Configuração (atualizar).', 'projectplus')
                . '</td></tr>';
        }

        foreach ($report['admins'] as $a) {
            if (empty($a['missing'])) {
                $cell = '<span style="color:#2e7d32">'
                    . __('completo', 'projectplus') . '</span>';
            } else {
                $cell = '<span style="color:#c62828">'
                    . sprintf(
                        __('abaixo do máximo em %d direito(s)', 'projectplus'),
                        count($a['missing'])
                    )
                    . '</span> — <code>'
                    . htmlspecialchars(implode(', ', $a['missing']))
                    . '</code>';
            }

            echo '<tr class="tab_bg_1"><td>'
                . htmlspecialchars((string) $a['name']) . '</td>'
                . '<td colspan="2">' . $cell . '</td></tr>';
        }

        // --- Cron e marcas -------------------------------------------------
        echo '<tr class="tab_bg_2"><th colspan="3">' . __('Outros', 'projectplus') . '</th></tr>';

        $cron = $report['cron']['registered']
            ? '<span style="color:#2e7d32">' . __('registrado', 'projectplus') . '</span>'
              . ' — ' . __('última execução:', 'projectplus') . ' '
              . htmlspecialchars((string) ($report['cron']['lastrun'] ?: __('nunca', 'projectplus')))
            : '<span style="color:#c62828">' . __('NÃO registrado', 'projectplus') . '</span>';

        echo '<tr class="tab_bg_1"><td colspan="2">'
            . __('Cron projectplusalerts', 'projectplus') . '</td><td>' . $cron . '</td></tr>';

        echo '<tr class="tab_bg_1"><td colspan="2">'
            . __('Importação dos custos nativos', 'projectplus') . '</td><td>'
            . ($report['costs_migrated']
                ? __('já realizada (não repete)', 'projectplus')
                : __('pendente (roda na próxima instalação)', 'projectplus'))
            . '</td></tr>';

        echo '<tr class="tab_bg_1"><td colspan="2">'
            . __('Ao desinstalar', 'projectplus') . '</td><td>'
            . ($report['purge_on_uninstall']
                ? '<span style="color:#c62828">'
                  . __('APAGA tabelas, dados e direitos', 'projectplus') . '</span>'
                : __('preserva dados e direitos', 'projectplus'))
            . '</td></tr>';

        // --- E-mail (Etapa 6, Bloco 2) -------------------------------------
        $mail = Notification::mailStatus();

        echo '<tr class="tab_bg_2"><th colspan="3">' . __('E-mail', 'projectplus') . '</th></tr>';

        echo '<tr class="tab_bg_1"><td colspan="2">'
            . __('Alertas por e-mail (opção do plugin)', 'projectplus') . '</td><td>'
            . ($mail['plugin_enabled']
                ? __('ligados', 'projectplus')
                : '<span style="color:#ef6c00">' . __('desligados (só o sino)', 'projectplus') . '</span>')
            . '</td></tr>';

        echo '<tr class="tab_bg_1"><td colspan="2">'
            . __('Canal de e-mail do GLPI', 'projectplus') . '</td><td>'
            . ($mail['blocked'] === null
                ? '<span style="color:#2e7d32">' . __('liberado', 'projectplus') . '</span>'
                : '<span style="color:#c62828">' . __('BLOQUEADO:', 'projectplus') . ' '
                  . htmlspecialchars($mail['blocked']) . '</span>')
            . '</td></tr>';

        echo '<tr class="tab_bg_1"><td colspan="2">'
            . __('Remetente que será usado', 'projectplus') . '</td><td>'
            . (empty($mail['sender_email'])
                ? '<span style="color:#c62828">'
                  . __('NENHUM — preencha "E-mail do remetente" em Configurar > Notificações '
                      . 'ou "E-mail do administrador" em Configurar > Geral', 'projectplus')
                  . '</span>'
                : htmlspecialchars(
                    (string) $mail['sender_email']
                    . (!empty($mail['sender_name']) ? ' (' . $mail['sender_name'] . ')' : '')
                ))
            . '</td></tr>';

        echo '<tr class="tab_bg_1"><td colspan="2">'
            . __('Transporte (DSN, senha oculta)', 'projectplus') . '</td><td><code>'
            . htmlspecialchars($mail['dsn']) . '</code></td></tr>';

        echo '<tr class="tab_bg_1"><td colspan="2">'
            . __('Link nos e-mails (URL da base)', 'projectplus') . '</td><td>'
            . (empty($mail['url_base'])
                ? '<span style="color:#ef6c00">'
                  . __('não configurada — os e-mails saem sem link', 'projectplus') . '</span>'
                : htmlspecialchars($mail['url_base']))
            . '</td></tr>';

        echo '<tr class="tab_bg_1"><td colspan="3"><small>'
            . __('Falhas de envio ficam registradas em files/_log/projectplus.log; '
                . 'o cron projectplusalerts anota quantos e-mails saíram no próprio log da tarefa.', 'projectplus')
            . '</small></td></tr>';

        echo '</table>';
    }
}

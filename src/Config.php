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
        'purge_on_uninstall'  => 0,   // 1 = apaga tabelas/dados ao desinstalar
    ];

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
            if (isset($values[$key])) {
                $clean[$key] = max(0, (int) $values[$key]);
            }
        }
        if ($clean) {
            CoreConfig::setConfigurationValues(self::CONTEXT, $clean);
        }
    }

    /**
     * Formulário simples de configuração.
     */
    public static function showForm(): void
    {
        Session::checkRight('config', UPDATE);

        $config = self::get();
        $action = htmlspecialchars($_SERVER['PHP_SELF'] ?? '');

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
            . __('Sim') . '</option>';
        echo "<option value='0'" . (!$config['email_enabled'] ? ' selected' : '') . '>'
            . __('Não') . '</option>';
        echo '</select></td></tr>';

        echo '<tr class="tab_bg_1"><td>'
            . __('Painel de Projetos como primeiro item do menu Ferramentas', 'projectplus')
            . '</td><td>';
        echo "<select name='menu_first'>";
        echo "<option value='1'" . ($config['menu_first'] ? ' selected' : '') . '>'
            . __('Sim') . '</option>';
        echo "<option value='0'" . (!$config['menu_first'] ? ' selected' : '') . '>'
            . __('Não') . '</option>';
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
            . __('Sim') . '</option>';
        echo "<option value='0'" . (!$config['hide_native_costs'] ? ' selected' : '') . '>'
            . __('Não') . '</option>';
        echo '</select></td></tr>';

        echo '<tr class="tab_bg_1"><td>'
            . __('Apagar tabelas e dados do plugin ao desinstalar', 'projectplus')
            . '</td><td>';
        echo "<select name='purge_on_uninstall'>";
        echo "<option value='0'" . (!$config['purge_on_uninstall'] ? ' selected' : '') . '>'
            . __('Não (manter dados)', 'projectplus') . '</option>';
        echo "<option value='1'" . ($config['purge_on_uninstall'] ? ' selected' : '') . '>'
            . __('Sim (expurgo completo)', 'projectplus') . '</option>';
        echo '</select></td></tr>';

        echo '<tr class="tab_bg_2"><td colspan="2" class="center">';
        echo "<input type='submit' name='update' value='" . _sx('button', 'Save') . "' class='btn btn-primary'>";
        echo '</td></tr>';
        echo '</table>';
        Html::closeForm();
    }
}

<?php

/**
 * ProjectPlus — formulário de configuração.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Config as PluginConfig;
use GlpiPlugin\Projectplus\Notification as PluginNotification;
use GlpiPlugin\Projectplus\Url;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    PluginConfig::set($_POST);
    Session::addMessageAfterRedirect(__('Configuração salva', 'projectplus'), true, INFO);
    Html::back();
}

// Etapa 6, Bloco 2 — salva e dispara um e-mail de teste para o próprio
// usuário, devolvendo o motivo real quando o envio falha.
if (isset($_POST['test_mail'])) {
    PluginConfig::set($_POST);

    $result = PluginNotification::sendTestMail((int) Session::getLoginUserID());
    Session::addMessageAfterRedirect(
        $result['message'],
        true,
        $result['ok'] ? INFO : ERROR
    );
    Html::back();
}

// Lição 44: PHP_SELF vale "/index.php" no front controller do GLPI 11.
// 2o argumento vazio: o Html::header do GLPI 11 ignora o parametro $url
// (conferido no core 11.0.6) — quem posiciona o menu sao os argumentos
// 3 e 4, $sector e $item. Ver Bloco 4a.
Html::header(__('ProjectPlus', 'projectplus'), '', 'config', 'plugins');
// Bloco N-1a: barra superior do plugin acima do formulário — caminho de volta
// para as telas do Project+ (antes a Configuração era um beco sem saída).
TemplateRenderer::getInstance()->display('@projectplus/config_shell.html.twig', [
    'plugin_web_dir' => Url::base(),
    'nav'            => Access::sidebar(),
]);
PluginConfig::showForm();
Html::footer();

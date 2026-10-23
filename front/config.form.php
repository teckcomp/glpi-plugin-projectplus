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

// Bloco N-7: a tela inteira vem do template (abas Geral / Fases por tipo /
// Diagnóstico, ajustes em cartões com interruptores). Os nomes dos campos e
// dos botões de envio são os mesmos de antes — os dois `if` acima não mudaram.
$home = Access::homePath();

TemplateRenderer::getInstance()->display('@projectplus/config_shell.html.twig', [
    'plugin_web_dir' => Url::base(),
    'nav'            => Access::sidebar(),
    'cfg'            => PluginConfig::viewData(),
    'form_action'    => PluginConfig::formUrl(),
    'csrf_token'     => Session::getNewCSRFToken(),
    // Volta à primeira tela que o perfil alcança (o Painel, quando tem) — o
    // texto diz "Painel" só nesse caso; sem nenhuma tela, o botão não aparece.
    'back_url'       => $home !== null ? Url::to($home) : '',
    'back_is_panel'  => $home === 'front/dashboard.php',
    'phases_url'     => Url::to('front/typephases.php'),
]);
Html::footer();

<?php

/**
 * ProjectPlus — formulário de configuração.
 */

use GlpiPlugin\Projectplus\Config as PluginConfig;
use GlpiPlugin\Projectplus\Notification as PluginNotification;

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
Html::header(__('ProjectPlus', 'projectplus'), PluginConfig::formUrl(), 'config', 'plugins');
PluginConfig::showForm();
Html::footer();

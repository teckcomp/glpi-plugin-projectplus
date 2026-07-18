<?php

/**
 * ProjectPlus — formulário de configuração.
 */

use GlpiPlugin\Projectplus\Config as PluginConfig;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    PluginConfig::set($_POST);
    Session::addMessageAfterRedirect(__('Configuração salva', 'projectplus'), true, INFO);
    Html::back();
}

Html::header(__('ProjectPlus', 'projectplus'), $_SERVER['PHP_SELF'], 'config', 'plugins');
PluginConfig::showForm();
Html::footer();

<?php

/**
 * ProjectPlus — hooks de instalação/desinstalação.
 * O GLPI exige estas funções globais; a lógica real fica em src/Install.php.
 */

use GlpiPlugin\Projectplus\Install;

function plugin_projectplus_install(): bool
{
    return Install::install();
}

function plugin_projectplus_uninstall(): bool
{
    return Install::uninstall();
}

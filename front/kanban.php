<?php

/**
 * ProjectPlus — tela "Kanban" (Etapa 7, Bloco 1).
 *
 * Board próprio do plugin: colunas por fase, swimlanes alternáveis
 * (Projeto / Responsável) no cliente. Somente leitura neste bloco — sem
 * AJAX próprio, os dados vão embutidos na página (lição nº 9: cada
 * variável usada no Twig é enumerada explicitamente aqui).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\Kanban;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('plugin_projectplus_dashboard', READ);

Html::header(
    __('Kanban', 'projectplus'),
    $_SERVER['PHP_SELF'],
    'tools',
    Dashboard::class
);

TemplateRenderer::getInstance()->display(
    '@projectplus/kanban.html.twig',
    [
        'plugin_web_dir' => Plugin::getWebDir('projectplus'),
        'glpi_root'      => $CFG_GLPI['root_doc'] ?? '',
        'kanban'         => Kanban::getBoardData(),
        'can_templates'  => Session::haveRight('config', UPDATE),
    ]
);

Html::footer();

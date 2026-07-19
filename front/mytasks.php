<?php

/**
 * ProjectPlus — tela "Minhas tarefas" (Etapa 3, Bloco 1).
 *
 * Lista as tarefas em que o usuário logado está na equipe, agrupadas por
 * projeto, com KPIs pessoais, barra de prazo e edição inline (reaproveita
 * a renderização da árvore de tarefas do painel via JS).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Dashboard;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('plugin_projectplus_dashboard', READ);

Html::header(
    __('Minhas tarefas', 'projectplus'),
    $_SERVER['PHP_SELF'],
    'tools',
    Dashboard::class
);

// Estados/fases para o dropdown inline e a bolinha colorida (pp-data)
$states = [];
foreach (Dashboard::getStatesMap() as $sid => $s) {
    $states[] = ['id' => $sid, 'name' => $s['name'], 'color' => $s['color']];
}

TemplateRenderer::getInstance()->display(
    '@projectplus/mytasks.html.twig',
    [
        'plugin_web_dir'  => Plugin::getWebDir('projectplus'),
        'glpi_root'       => $CFG_GLPI['root_doc'] ?? '',
        'states'          => $states,
        'current_user_id' => (int) Session::getLoginUserID(),
        'csrf_token'      => Session::getNewCSRFToken(),
        'can_templates'   => Session::haveRight('config', UPDATE),
    ]
);

Html::footer();

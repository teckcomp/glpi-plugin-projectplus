<?php

/**
 * ProjectPlus — tela "Timeline" (Etapa 3, bloco final).
 *
 * Gantt somente-leitura, HTML/JS puro, de todos os projetos e tarefas.
 * Os dados vão embutidos na página (sem AJAX próprio); lembrar da lição
 * nº 9: cada variável usada no Twig é enumerada explicitamente aqui.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\Timeline;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('plugin_projectplus_tasks', READ);

// Escopo: mesmo critério de "Minhas tarefas" — a Timeline mostra somente
// as tarefas em que o usuário logado está na equipe (e os projetos dessas
// tarefas). Os direitos granulares por módulo (inclusive uma permissão
// "ver todos os projetos" para gestor/admin, que devolveria a timeline
// completa) ficam para a Etapa 8. Para reabrir a visão total a um perfil
// aqui, basta atribuir null a $onlyUser conforme a regra desejada.
$onlyUser = (int) Session::getLoginUserID();

Html::header(
    __('Timeline', 'projectplus'),
    $_SERVER['PHP_SELF'],
    'tools',
    Dashboard::class
);

TemplateRenderer::getInstance()->display(
    '@projectplus/timeline.html.twig',
    [
        'plugin_web_dir' => Plugin::getWebDir('projectplus'),
        'glpi_root'      => $CFG_GLPI['root_doc'] ?? '',
        'timeline'       => Timeline::getData($onlyUser),
        'only_mine'      => $onlyUser !== null,
        'can_templates'  => Session::haveRight('config', UPDATE),
        'nav'             => Access::sidebar(),
    ]
);

Html::footer();

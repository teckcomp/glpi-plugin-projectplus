<?php

/**
 * ProjectPlus — tela "Modelos" (Etapa 4).
 *
 * Lista os modelos cadastrados (nome, comentário, nº de tarefas e de
 * subprojetos) com ações: "Criar projeto" (TemplateCloner::instantiate,
 * clonagem COMPLETA com subprojetos), "Criar do zero" e "Editar"
 * (editor visual), "Salvar projeto existente como modelo" e "Excluir".
 *
 * ACESSO: toda a área é restrita a 'config' UPDATE (super-admin). A
 * atribuição por perfil a gestores é a Etapa 8.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\Templates;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('plugin_projectplus_templates', READ);

Html::header(
    __('Modelos', 'projectplus'),
    $_SERVER['PHP_SELF'],
    'tools',
    Dashboard::class
);

TemplateRenderer::getInstance()->display(
    '@projectplus/projecttemplates.html.twig',
    [
        'plugin_web_dir' => Plugin::getWebDir('projectplus'),
        'glpi_root'      => $CFG_GLPI['root_doc'] ?? '',
        'templates'      => Templates::listAll(),
        'projects'       => Templates::getProjectsForSelect(),
        'can_templates'  => true,
        'nav'             => Access::sidebar(),
        'can_create'     => Session::haveRight('plugin_projectplus_projects', CREATE),
        'csrf_token'     => Session::getNewCSRFToken(),
    ]
);

Html::footer();

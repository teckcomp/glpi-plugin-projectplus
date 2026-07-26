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
use GlpiPlugin\Projectplus\I18nJs;
use GlpiPlugin\Projectplus\Templates;
use GlpiPlugin\Projectplus\Url;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('plugin_projectplus_templates', READ);

Html::header(
    __('Modelos', 'projectplus'),
    '', // \Html::header ignora o 2o argumento no GLPI 11 (Bloco 4a)
    'tools',
    Dashboard::class
);

// Dicionario de traducao do JavaScript (Etapa 6, Bloco 3b): imprime o
// <script type="application/json" id="pp-i18n"> que public/js/i18n.js le.
// Tem que vir DEPOIS do Html::header (o echo cai dentro do body) e ANTES
// dos <script> das telas.
I18nJs::render();

TemplateRenderer::getInstance()->display(
    '@projectplus/projecttemplates.html.twig',
    [
        'plugin_web_dir' => Url::base(),
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

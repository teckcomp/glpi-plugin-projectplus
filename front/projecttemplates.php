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
use GlpiPlugin\Projectplus\DateFmt;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\I18nJs;
use GlpiPlugin\Projectplus\Templates;
use GlpiPlugin\Projectplus\TypePhase;
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

// Bloco N-8b-1: cada linha ganha o nome/cor do tipo que aplica ao projeto e a
// data já formatada (DateFmt — porta única de data); a lista de tipos que
// aparecem alimenta o filtro de Tipo (só os que algum modelo usa).
$types     = TypePhase::projectTypes();
$templates = [];
$usedTypes = [];
foreach (Templates::listAll() as $t) {
    $tid = (int) $t['type_id'];
    $t['type_name']  = $tid > 0 ? (string) ($types[$tid] ?? ('#' . $tid)) : '';
    $t['type_color'] = $tid > 0 ? TypePhase::typeColor($tid) : '';
    $t['date_label'] = DateFmt::dateTime($t['date_mod']);
    if ($tid > 0) {
        $usedTypes[$tid] = $t['type_name'];
    }
    $templates[] = $t;
}
asort($usedTypes, SORT_NATURAL | SORT_FLAG_CASE);
$typeOptions = [];
foreach ($usedTypes as $tid => $tname) {
    $typeOptions[] = ['id' => $tid, 'name' => $tname];
}
$summary = Templates::summary($templates);
$summary['last_label'] = $summary['last'] !== null
    ? $summary['last']['name'] . ' · ' . DateFmt::date($summary['last']['date_mod'])
    : '';

TemplateRenderer::getInstance()->display(
    '@projectplus/projecttemplates.html.twig',
    [
        'plugin_web_dir' => Url::base(),
        'glpi_root'      => $CFG_GLPI['root_doc'] ?? '',
        'templates'      => $templates,
        'type_options'   => $typeOptions,
        'summary'        => $summary,
        'projects'       => Templates::getProjectsForSelect(),
        'can_templates'  => true,
        'nav'             => Access::sidebar(),
        'can_create'     => Access::can('projects', CREATE), // O-1e: trava do cliente
        'csrf_token'     => Session::getNewCSRFToken(),
    ]
);

Html::footer();

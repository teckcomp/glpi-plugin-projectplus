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
use GlpiPlugin\Projectplus\I18nJs;
use GlpiPlugin\Projectplus\Scope;
use GlpiPlugin\Projectplus\Timeline;
use GlpiPlugin\Projectplus\Url;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('plugin_projectplus_tasks', READ);

// Escopo (Etapa 8, Bloco 3): abre no PESSOAL (minhas tarefas, mesmo critério
// de "Minhas tarefas"); quem tem direito de escopo amplia via ?scope=all
// (gerência = tarefas dos meus projetos; todos = tudo).
$scopeMode           = Scope::mode();
$onlyUser            = ($scopeMode === 'personal') ? (int) Session::getLoginUserID() : null;
$scopeTaskProjectIds = Scope::taskProjectIds($scopeMode); // managed: por projeto; senão null

// Botão "Ver tudo" / "Ver só os meus".
$scopeCanExpand  = Scope::canExpand();
$scopeIsExpanded = Scope::isExpanded();
$scopeToggleUrl  = Url::to('front/timeline.php')
    . ($scopeIsExpanded ? '?scope=mine' : '');

Html::header(
    __('Timeline', 'projectplus'),
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
    '@projectplus/timeline.html.twig',
    [
        'plugin_web_dir' => Url::base(),
        'glpi_root'      => $CFG_GLPI['root_doc'] ?? '',
        'timeline'       => Timeline::getData($onlyUser, $scopeTaskProjectIds),
        'only_mine'      => $scopeMode === 'personal',
        'can_templates'  => Session::haveRight('config', UPDATE),
        'nav'             => Access::sidebar(),
        'scope_can_expand'  => $scopeCanExpand,
        'scope_is_expanded' => $scopeIsExpanded,
        'scope_toggle_url'  => $scopeToggleUrl,
    ]
);

Html::footer();

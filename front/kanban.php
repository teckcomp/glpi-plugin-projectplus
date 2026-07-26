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
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\I18nJs;
use GlpiPlugin\Projectplus\Kanban;
use GlpiPlugin\Projectplus\ProjectKanban;
use GlpiPlugin\Projectplus\Scope;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

// Kanban aparece para quem tem o board de tarefas OU o de projetos (Cliente).
if (!Access::canKanban()) {
    Html::displayRightError();
}

// Roteamento (Etapa 8, Bloco 4): quem SÓ tem o Kanban de PROJETOS (Cliente)
// cai no board de projetos. Assim o item "Kanban" da sidebar continua
// apontando para esta URL em todas as telas — quem decide o destino é o
// direito, não o template.
if (Access::kanbanIsProjects()) {
    Html::redirect(Plugin::getWebDir('projectplus') . '/front/projectkanban.php'
        . (($_GET['scope'] ?? '') === 'mine' ? '?scope=mine' : ''));
}

// Escopo (Etapa 8, Bloco 3): board cheio abre no PESSOAL (minhas tarefas);
// quem tem direito de escopo amplia via ?scope=all.
$scopeMode           = Scope::mode();
$scopeMyTaskIds      = Scope::myTaskIds($scopeMode);      // personal
$scopeTaskProjectIds = Scope::taskProjectIds($scopeMode); // managed
$scopeCanExpand      = Scope::canExpand();
$scopeIsExpanded     = Scope::isExpanded();
$scopeToggleUrl      = Plugin::getWebDir('projectplus') . '/front/kanban.php'
    . ($scopeIsExpanded ? '?scope=mine' : '');

Html::header(
    __('Kanban', 'projectplus'),
    $_SERVER['PHP_SELF'],
    'tools',
    Dashboard::class
);

// Dicionario de traducao do JavaScript (Etapa 6, Bloco 3b): imprime o
// <script type="application/json" id="pp-i18n"> que public/js/i18n.js le.
// Tem que vir DEPOIS do Html::header (o echo cai dentro do body) e ANTES
// dos <script> das telas.
I18nJs::render();

TemplateRenderer::getInstance()->display(
    '@projectplus/kanban.html.twig',
    [
        'plugin_web_dir' => Plugin::getWebDir('projectplus'),
        'glpi_root'      => $CFG_GLPI['root_doc'] ?? '',
        'kanban'         => Kanban::getBoardData(null, $scopeMyTaskIds, $scopeTaskProjectIds),
        'can_templates'  => Session::haveRight('config', UPDATE),
        'nav'             => Access::sidebar(),
        // Bloco 4 (ajuste 4b.1): caminho de IDA para o board de projetos —
        // sem ele, quem tem os dois Kanbans só chegaria lá pela URL, porque
        // a sidebar aponta para o board de tarefas.
        'can_project_kanban' => ProjectKanban::canAccess(),
        'scope_can_expand'  => $scopeCanExpand,
        'scope_is_expanded' => $scopeIsExpanded,
        'scope_toggle_url'  => $scopeToggleUrl,
        // Etapa 7, Bloco 2a — arrastar-e-soltar: quem pode editar tarefa
        // arrasta o cartão entre colunas (muda a fase). Token inicial para
        // a 1ª chamada AJAX (ajax/task.php action=kanban_move); o JS
        // rotaciona a cada resposta.
        'can_edit'       => Session::haveRight('projecttask', UPDATE) || Session::haveRight('project', UPDATE),
        'csrf_token'     => Session::getNewCSRFToken(),
    ]
);

Html::footer();

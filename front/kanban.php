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
use GlpiPlugin\Projectplus\Kanban;
use GlpiPlugin\Projectplus\Scope;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

// Kanban aparece para quem tem o board de tarefas OU o de projetos (Cliente).
// O roteamento para o board de projetos em si é o Bloco 4 (Access::kanbanIsProjects()).
if (!Access::canKanban()) {
    Html::displayRightError();
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

TemplateRenderer::getInstance()->display(
    '@projectplus/kanban.html.twig',
    [
        'plugin_web_dir' => Plugin::getWebDir('projectplus'),
        'glpi_root'      => $CFG_GLPI['root_doc'] ?? '',
        'kanban'         => Kanban::getBoardData(null, $scopeMyTaskIds, $scopeTaskProjectIds),
        'can_templates'  => Session::haveRight('config', UPDATE),
        'nav'             => Access::sidebar(),
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

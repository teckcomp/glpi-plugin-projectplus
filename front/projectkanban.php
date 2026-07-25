<?php

/**
 * ProjectPlus — tela "Kanban de projetos" (Etapa 8, Bloco 4).
 *
 * Board de PROJETOS: colunas = fases, cartões = projetos/subprojetos.
 * Somente leitura (papel Cliente) — sem arrastar-e-soltar, sem AJAX, sem
 * token. Os dados vão embutidos na página (lição nº 9: cada variável usada
 * no Twig é enumerada explicitamente aqui).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\ProjectKanban;
use GlpiPlugin\Projectplus\Scope;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

// Aparece para quem tem o Kanban de projetos (Cliente) — e também para
// quem tem o Kanban de tarefas, que pode alternar entre os dois boards.
if (!ProjectKanban::canAccess()) {
    Html::displayRightError();
}

// Escopo (Bloco 3/4): padrão = maior escopo do perfil; ?scope=mine reduz
// ao pessoal (para o Cliente, os projetos em cuja equipe ele está).
$scopeMode       = Scope::mode();
$scopeProjectIds = Scope::projectIds($scopeMode);
$scopeCanExpand  = Scope::canExpand();
$scopeIsExpanded = Scope::isExpanded();
$scopeToggleUrl  = Plugin::getWebDir('projectplus') . '/front/projectkanban.php'
    . ($scopeIsExpanded ? '?scope=mine' : '');

Html::header(
    __('Kanban de projetos', 'projectplus'),
    $_SERVER['PHP_SELF'],
    'tools',
    Dashboard::class
);

TemplateRenderer::getInstance()->display(
    '@projectplus/projectkanban.html.twig',
    [
        'plugin_web_dir'    => Plugin::getWebDir('projectplus'),
        'glpi_root'         => $CFG_GLPI['root_doc'] ?? '',
        // Lição 12: JSON embutido em <script> com texto do usuário precisa
        // dos flags HEX (um "</script>" no nome do projeto quebraria a tela).
        'board_json'        => json_encode(
            ProjectKanban::getBoardData($scopeProjectIds),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ),
        'can_templates'     => Session::haveRight('config', UPDATE),
        'nav'               => Access::sidebar(),
        // Quem tem o Kanban de TAREFAS vê o atalho para o outro board.
        'can_task_kanban'   => Access::can('kanban'),
        // Ajuste 4b.2 — arrastar cartão muda a fase do PROJETO. Exige o
        // direito do módulo Projetos em UPDATE E o direito nativo (o mesmo
        // par validado em ajax/project.php). Cliente = somente leitura.
        'can_edit'          => Access::can('projects', UPDATE) && Session::haveRight('project', UPDATE),
        'csrf_token'        => Session::getNewCSRFToken(),
        'scope_can_expand'  => $scopeCanExpand,
        'scope_is_expanded' => $scopeIsExpanded,
        'scope_toggle_url'  => $scopeToggleUrl,
    ]
);

Html::footer();

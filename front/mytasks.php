<?php

/**
 * ProjectPlus — tela "Minhas tarefas" (Etapa 3, Bloco 1).
 *
 * Lista as tarefas em que o usuário logado está na equipe, agrupadas por
 * projeto, com KPIs pessoais, barra de prazo e edição inline (reaproveita
 * a renderização da árvore de tarefas do painel via JS).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\DateFmt;
use GlpiPlugin\Projectplus\I18nJs;
use GlpiPlugin\Projectplus\TypePhase;
use GlpiPlugin\Projectplus\Url;
use GlpiPlugin\Projectplus\Favorite;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('plugin_projectplus_tasks', READ);

// Bloco L: entrando sem nada na URL, abre com o filtro favorito.
Favorite::applyRedirect('mytasks', 'front/mytasks.php');

Html::header(
    __('Minhas tarefas', 'projectplus'),
    '', // \Html::header ignora o 2o argumento no GLPI 11 (Bloco 4a)
    'tools',
    Dashboard::class
);

// Dicionario de traducao do JavaScript (Etapa 6, Bloco 3b): imprime o
// <script type="application/json" id="pp-i18n"> que public/js/i18n.js le.
// Tem que vir DEPOIS do Html::header (o echo cai dentro do body) e ANTES
// dos <script> das telas.
I18nJs::render();

// Estados/fases para o dropdown inline e a bolinha colorida (pp-data)
$states = [];
foreach (Dashboard::getStatesMap() as $sid => $s) {
    $states[] = ['id' => $sid, 'name' => $s['name'], 'color' => $s['color']];
}

$canTeam = Access::canManageTaskTeams();

$favMt = Favorite::get('mytasks')['ui'] ?? [];

TemplateRenderer::getInstance()->display(
    '@projectplus/mytasks.html.twig',
    [
        'plugin_web_dir'  => Url::base(),
        'glpi_root'       => $CFG_GLPI['root_doc'] ?? '',
        'states'          => $states,
        'current_user_id' => (int) Session::getLoginUserID(),
        // Bloco D-2a: responsáveis editáveis também aqui (só gestor). A lista
        // de usuários só é carregada para quem pode editar.
        'can_team'        => $canTeam,
        // Bloco M-2: excluir = módulo em Interagir + direito NATIVO de excluir
        // (o servidor confere de novo por item, com entidade).
        'can_delete_tasks'   => Access::can('tasks', UPDATE) && Session::haveRight('projecttask', DELETE),
        'users'           => $canTeam ? Dashboard::userOptions() : [],
        'csrf_token'      => Session::getNewCSRFToken(),
        'can_templates'   => Session::haveRight('config', UPDATE),
        'nav'             => Access::sidebar(),
        // Bloco N-3: linha do título — hoje por extenso · nome do usuário
        'head_sub'        => DateFmt::longToday() . ' · '
            . (string) ($_SESSION['glpifriendlyname'] ?? ($_SESSION['glpiname'] ?? '')),
        'fav'            => Favorite::payload('mytasks'), // Bloco L
        // Bloco C: mesmos tipos do seletor da Visão geral (filtro no cliente)
        'type_options'    => TypePhase::selectorTypes(),
        // Bloco F-1b: link de projeto/tarefa de quem não tem o Painel
        // (Url::project) chega aqui com ?project=ID[&task=ID]
        // Bloco L: sem projeto/tarefa na URL, o favorito da tela (Tipo,
        // Projeto, concluídas) vira o estado inicial — a URL sempre vence.
        'initial_project' => max(0, (int) ($_GET['project'] ?? ($favMt['project'] ?? 0))),
        'initial_task'    => max(0, (int) ($_GET['task'] ?? 0)),
        'initial_type'    => isset($_GET['project']) ? '' : (string) ($favMt['type'] ?? ''),
        'initial_done'    => !isset($_GET['project']) && ($favMt['done'] ?? '') === '1',
    ]
);

Html::footer();

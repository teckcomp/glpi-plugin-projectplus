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
use GlpiPlugin\Projectplus\TypePhase;
use GlpiPlugin\Projectplus\Url;

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
    $fwd = [];
    if (($_GET['scope'] ?? '') === 'mine') {
        $fwd['scope'] = 'mine';
    }
    if (isset($_GET['type']) && $_GET['type'] !== '') {
        $fwd['type'] = $_GET['type'];
    }
    Html::redirect(Url::to('front/projectkanban.php')
        . ($fwd === [] ? '' : ('?' . http_build_query($fwd))));
}

// Escopo (Etapa 8, Bloco 3): board cheio abre no PESSOAL (minhas tarefas);
// quem tem direito de escopo amplia via ?scope=all.
$scopeMode           = Scope::mode();
$scopeMyTaskIds      = Scope::myTaskIds($scopeMode);      // personal
$scopeTaskProjectIds = Scope::taskProjectIds($scopeMode); // managed
$scopeCanExpand      = Scope::canExpand();
$scopeIsExpanded     = Scope::isExpanded();

// Tipo de projeto (Etapa 9): as COLUNAS deste board são o conjunto de fases
// do tipo, e os cartões só as tarefas de projetos daquele tipo. Sem seletor,
// um setor veria as 25-30 fases da instância inteira. Não existe modo
// "união" quando há conjunto por tipo configurado — visão que cruza
// departamentos é Visão geral / Timeline / Relatórios.
$typeOptions = TypePhase::selectorTypes();
$typeId      = TypePhase::resolveRequestedType(Scope::projectIds($scopeMode));
$typeUnion   = !TypePhase::hasCustomSets();

// O botão de escopo precisa PRESERVAR o tipo escolhido (e vice-versa), senão
// clicar em "Ver só os meus" jogaria o usuário de volta ao tipo padrão.
$scopeQuery = [];
if ($typeId !== null) {
    $scopeQuery['type'] = $typeId;
} elseif ($typeUnion && ($_GET['type'] ?? '') === 'all') {
    $scopeQuery['type'] = 'all';
}
if ($scopeIsExpanded) {
    $scopeQuery['scope'] = 'mine';
}
$scopeToggleUrl = Url::to('front/kanban.php')
    . ($scopeQuery === [] ? '' : ('?' . http_build_query($scopeQuery)));

Html::header(
    __('Kanban', 'projectplus'),
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
    '@projectplus/kanban.html.twig',
    [
        'plugin_web_dir' => Url::base(),
        'glpi_root'      => $CFG_GLPI['root_doc'] ?? '',
        'kanban'         => Kanban::getBoardData(null, $scopeMyTaskIds, $scopeTaskProjectIds, $typeId),
        'can_templates'  => Session::haveRight('config', UPDATE),
        'nav'             => Access::sidebar(),
        // Bloco 4 (ajuste 4b.1): caminho de IDA para o board de projetos —
        // sem ele, quem tem os dois Kanbans só chegaria lá pela URL, porque
        // a sidebar aponta para o board de tarefas.
        'can_project_kanban' => ProjectKanban::canAccess(),
        'scope_can_expand'  => $scopeCanExpand,
        'scope_is_expanded' => $scopeIsExpanded,
        'scope_toggle_url'  => $scopeToggleUrl,
        // Etapa 9 — seletor de tipo (vocabulário das colunas)
        'type_options'      => $typeOptions,
        'type_selected'     => $typeId,
        'type_union'        => $typeUnion,
        'type_can_config'   => Session::haveRight('config', UPDATE),
        // Preserva o ?scope=mine ao trocar de tipo (o seletor é um form GET).
        'type_scope'        => (($_GET['scope'] ?? '') === 'mine') ? 'mine' : '',
        // Etapa 7, Bloco 2a — arrastar-e-soltar: quem pode editar tarefa
        // arrasta o cartão entre colunas (muda a fase). Token inicial para
        // a 1ª chamada AJAX (ajax/task.php action=kanban_move); o JS
        // rotaciona a cada resposta.
        'can_edit'       => Session::haveRight('projecttask', UPDATE) || Session::haveRight('project', UPDATE),
        'csrf_token'     => Session::getNewCSRFToken(),
    ]
);

Html::footer();

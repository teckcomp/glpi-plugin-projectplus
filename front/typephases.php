<?php

/**
 * ProjectPlus — tela "Fases por tipo de projeto" (Etapa 9).
 *
 * Administra a tabela de mapeamento `glpi_plugin_projectplus_typephases`:
 * quais fases pertencem a cada tipo de projeto e em que ordem as colunas
 * aparecem nos Kanbans.
 *
 * Direito: o NATIVO `config` (UPDATE) — é vocabulário da instância, não dado
 * de projeto, então segue a mesma porta da tela de Configuração e não
 * inventa um 12º direito próprio.
 *
 * Lição nº 9: cada variável usada no Twig é enumerada explicitamente aqui.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Access;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\I18nJs;
use GlpiPlugin\Projectplus\TypePhase;
use GlpiPlugin\Projectplus\Url;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('config', UPDATE);

$data  = TypePhase::adminData();
$types = TypePhase::projectTypes();

// Qual conjunto está sendo editado (0 = conjunto padrão).
$editId = isset($_GET['type']) && preg_match('/^\d+$/', (string) $_GET['type'])
    ? (int) $_GET['type']
    : TypePhase::DEFAULT_TYPE;

// Tipo inexistente na URL cai no conjunto padrão em vez de dar tela vazia.
if ($editId !== TypePhase::DEFAULT_TYPE && !isset($types[$editId])) {
    $editId = TypePhase::DEFAULT_TYPE;
}

// Conjunto em edição, já resolvido pela cascata (tipo -> padrão -> todas).
$current = TypePhase::statesFor($editId);
$isMapped = TypePhase::isMapped($editId);

// Linhas do editor: as fases DO CONJUNTO primeiro, na ordem gravada
// (marcadas), depois as demais fases da instância (desmarcadas). Como o
// formulário envia os checkboxes na ORDEM DO DOM, reordenar as linhas é o
// que define a coluna `ordem` — não existe campo numérico de posição.
$rows = [];
foreach ($current as $sid => $s) {
    $rows[] = [
        'id'          => (int) $sid,
        'name'        => $s['name'],
        'color'       => $s['color'],
        'is_finished' => !empty($s['is_finished']),
        'selected'    => true,
    ];
}
foreach ($data['states'] as $s) {
    if (!isset($current[$s['id']])) {
        $rows[] = [
            'id'          => (int) $s['id'],
            'name'        => $s['name'],
            'color'       => $s['color'],
            'is_finished' => !empty($s['is_finished']),
            'selected'    => false,
        ];
    }
}

// Opções do "copiar fases de outro tipo": todo conjunto MENOS o que está
// sendo editado (copiar de si mesmo não faria nada).
$copySources = [];
foreach ($data['sets'] as $set) {
    if ((int) $set['id'] !== $editId) {
        $copySources[] = [
            'id'     => (int) $set['id'],
            'name'   => $set['name'],
            'phases' => count($set['phases']),
        ];
    }
}

// Conjuntos sem fase finalizadora — o mesmo aviso do diagnóstico, aqui na
// tela onde ele se resolve.
$noFinished = [];
foreach (TypePhase::setsWithoutFinished() as $set) {
    $noFinished[] = $set['name'];
}

$editName = $editId === TypePhase::DEFAULT_TYPE
    ? __('Conjunto padrão', 'projectplus')
    : ($types[$editId] ?? ('#' . $editId));

Html::header(
    __('Fases por tipo de projeto', 'projectplus'),
    '', // \Html::header ignora o 2o argumento no GLPI 11 (Bloco 4a)
    'tools',
    Dashboard::class
);

// Dicionario de traducao do JavaScript (Etapa 6, Bloco 3b): imprime o
// <script type="application/json" id="pp-i18n"> que public/js/i18n.js le.
I18nJs::render();

TemplateRenderer::getInstance()->display(
    '@projectplus/typephases.html.twig',
    [
        'plugin_web_dir'  => Url::base(),
        'glpi_root'       => $CFG_GLPI['root_doc'] ?? '',
        'nav'             => Access::sidebar(),
        'can_templates'   => Session::haveRight('config', UPDATE),
        'sets'            => $data['sets'],
        'rows'            => $rows,
        'edit_id'         => $editId,
        'edit_name'       => $editName,
        'edit_is_default' => $editId === TypePhase::DEFAULT_TYPE,
        'edit_is_mapped'  => $isMapped,
        'copy_sources'    => $copySources,
        'no_finished'     => $noFinished,
        'has_states'      => $rows !== [],
        'form_action'     => Url::to('front/typephase.form.php'),
        'self_url'        => Url::to('front/typephases.php'),
        'csrf_token'      => Session::getNewCSRFToken(),
    ]
);

Html::footer();

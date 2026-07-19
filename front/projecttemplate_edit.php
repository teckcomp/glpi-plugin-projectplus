<?php

/**
 * ProjectPlus — editor visual de modelos (Etapa 4, itens 1 e 2).
 *
 * ?id=0 (ou sem id) -> criar modelo do zero
 * ?id=NN            -> editar modelo existente
 *
 * A árvore é montada no cliente (public/js/projectplus.js →
 * initTemplateEditor) e enviada como JSON por POST para
 * projecttemplate.form.php (action savejson). O JSON embutido aqui é a
 * estrutura atual do modelo (vazia no modo "criar").
 *
 * ACESSO: 'config' UPDATE (super-admin).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Projectplus\Dashboard;
use GlpiPlugin\Projectplus\Templates;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('config', UPDATE);

$id  = (int) ($_GET['id'] ?? 0);
$tpl = $id > 0 ? Templates::getForEdit($id) : null;

if ($id > 0 && $tpl === null) {
    Session::addMessageAfterRedirect(__('Modelo não encontrado', 'projectplus'), false, ERROR);
    Html::redirect(Plugin::getWebDir('projectplus') . '/front/projecttemplates.php');
}

Html::header(
    __('Modelos', 'projectplus'),
    $_SERVER['PHP_SELF'],
    'tools',
    Dashboard::class
);

$refData = Templates::getEditorRefData();

TemplateRenderer::getInstance()->display(
    '@projectplus/projecttemplate_edit.html.twig',
    [
        'plugin_web_dir' => Plugin::getWebDir('projectplus'),
        'glpi_root'      => $CFG_GLPI['root_doc'] ?? '',
        'can_templates'  => true,
        'tpl_id'         => $tpl['id'] ?? 0,
        'tpl_name'       => $tpl['name'] ?? '',
        'tpl_comment'    => $tpl['comment'] ?? '',
        'structure_json' => $tpl['structure'] ?? '{"project":{},"tasks":[],"subprojects":[]}',
        'refdata_json'   => json_encode(
            $refData,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ),
        'is_new'         => $tpl === null,
        'csrf_token'     => Session::getNewCSRFToken(),
    ]
);

Html::footer();

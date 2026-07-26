<?php

/**
 * ProjectPlus — ações da tela "Modelos" (Etapa 4).
 *
 * POST save=1         projects_id, name, [comment]         -> salva projeto existente como modelo (com subprojetos)
 * POST savejson=1     id, name, [comment], structure       -> cria/edita modelo pelo editor visual
 * POST delete=1       id                                    -> exclui modelo
 * POST instantiate=1  template_id, name, start_date         -> cria projeto a partir do modelo (clonagem completa)
 *
 * ACESSO: 'config' UPDATE (super-admin). CSRF validado pelo core em POST.
 */

use GlpiPlugin\Projectplus\TemplateCloner;
use GlpiPlugin\Projectplus\Templates;
use GlpiPlugin\Projectplus\Url;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkRight('plugin_projectplus_templates', UPDATE);

$backUrl = Url::to('front/projecttemplates.php');

if (isset($_POST['save'])) {
    $result = Templates::saveFromProject(
        (int) ($_POST['projects_id'] ?? 0),
        (string) ($_POST['name'] ?? ''),
        (string) ($_POST['comment'] ?? '')
    );

    Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
    Html::redirect($backUrl);
} elseif (isset($_POST['savejson'])) {
    $result = Templates::saveStructure(
        (int) ($_POST['id'] ?? 0),
        (string) ($_POST['name'] ?? ''),
        (string) ($_POST['comment'] ?? ''),
        (string) ($_POST['structure'] ?? '')
    );

    Session::addMessageAfterRedirect($result['message'], true, $result['ok'] ? INFO : ERROR);
    if (!$result['ok']) {
        // Volta ao editor mantendo o que estava sendo editado
        $editUrl = Url::to('front/projecttemplate_edit.php');
        $id      = (int) ($_POST['id'] ?? 0);
        Html::redirect($editUrl . ($id > 0 ? '?id=' . $id : ''));
    }
    Html::redirect($backUrl);
} elseif (isset($_POST['delete'])) {
    Templates::delete((int) ($_POST['id'] ?? 0));
    Session::addMessageAfterRedirect(__('Modelo excluído', 'projectplus'), true, INFO);
    Html::redirect($backUrl);
} elseif (isset($_POST['instantiate'])) {
    $name      = trim((string) ($_POST['name'] ?? ''));
    $startDate = (string) ($_POST['start_date'] ?? '');

    if ($name === '' || $startDate === '') {
        Session::addMessageAfterRedirect(
            __('Informe o nome do projeto e a data de início', 'projectplus'),
            false,
            ERROR
        );
        Html::redirect($backUrl);
    }

    $result = TemplateCloner::instantiate(
        (int) ($_POST['template_id'] ?? 0),
        $name,
        $startDate,
        ['entities_id' => Session::getActiveEntity()]
    );

    if (!empty($result['ok']) && !empty($result['projects_id'])) {
        Session::addMessageAfterRedirect($result['message'], true, INFO);
        Html::redirect($CFG_GLPI['root_doc'] . '/front/project.form.php?id=' . (int) $result['projects_id']);
    }

    Session::addMessageAfterRedirect(
        $result['message'] ?? __('Falha ao criar o projeto', 'projectplus'),
        false,
        ERROR
    );
    Html::redirect($backUrl);
} else {
    Html::redirect($backUrl);
}

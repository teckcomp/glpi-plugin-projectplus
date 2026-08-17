<?php

/**
 * ProjectPlus — POST da tela "Fases por tipo de projeto" (Etapa 9).
 *
 * Ações:
 *   save  — grava o conjunto do tipo. A `ordem` de cada fase é a POSIÇÃO do
 *           checkbox no POST: o navegador envia os campos na ordem do DOM, e
 *           a tela reordena as linhas, então não existe campo numérico de
 *           posição para o usuário errar.
 *   copy  — copia o conjunto EFETIVO de outro tipo (resolve dois tipos com o
 *           mesmo fluxo sem estrutura extra).
 *   clear — apaga o conjunto do tipo, que volta a herdar o padrão.
 *
 * CSRF: o core valida o POST sozinho em includes.php — NUNCA chamar
 * Session::checkCSRF aqui (dupla validação falha).
 */

use GlpiPlugin\Projectplus\TypePhase;
use GlpiPlugin\Projectplus\Url;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

$typeId = isset($_POST['projecttypes_id']) && preg_match('/^\d+$/', (string) $_POST['projecttypes_id'])
    ? (int) $_POST['projecttypes_id']
    : TypePhase::DEFAULT_TYPE;

$back = Url::to('front/typephases.php') . '?type=' . $typeId;

$typeName = $typeId === TypePhase::DEFAULT_TYPE
    ? __('Conjunto padrão', 'projectplus')
    : (TypePhase::projectTypes()[$typeId] ?? ('#' . $typeId));

// --- create_type / create_state (Rodada 3) ---------------------------------
// Criação direto na tela: tipo em `glpi_projecttypes`, fase em
// `glpi_projectstates` — ambos vocabulário NATIVO da instância (a lista de
// fases é global e única — lição 59). A porta de direito é a mesma da tela
// (`config` UPDATE), então não há elevação de privilégio aqui.

/** @var \DBmysql $DB */
global $DB;

if (isset($_POST['create_type'])) {
    $name = trim((string) ($_POST['new_type_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 255) {
        Session::addMessageAfterRedirect(
            __('Informe o nome do novo tipo', 'projectplus'),
            true,
            ERROR
        );
        Html::redirect($back);
    }

    $dup = $DB->request([
        'COUNT' => 'cpt',
        'FROM'  => 'glpi_projecttypes',
        'WHERE' => ['name' => $name],
    ])->current();
    if ((int) ($dup['cpt'] ?? 0) > 0) {
        Session::addMessageAfterRedirect(
            sprintf(__('Já existe um tipo chamado "%s"', 'projectplus'), $name),
            true,
            ERROR
        );
        Html::redirect($back);
    }

    $type  = new ProjectType();
    $newId = $type->add(['name' => $name]);
    if (!$newId) {
        Session::addMessageAfterRedirect(
            __('Falha ao criar o tipo de projeto', 'projectplus'),
            true,
            ERROR
        );
        Html::redirect($back);
    }

    Session::addMessageAfterRedirect(
        sprintf(
            __('Tipo "%s" criado — ele herda o conjunto padrão até você salvar um conjunto próprio', 'projectplus'),
            $name
        ),
        true,
        INFO
    );
    // Abre direto no conjunto do tipo recém-criado.
    Html::redirect(Url::to('front/typephases.php') . '?type=' . (int) $newId);
}

if (isset($_POST['create_state'])) {
    $name = trim((string) ($_POST['new_state_name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 255) {
        Session::addMessageAfterRedirect(
            __('Informe o nome da nova fase', 'projectplus'),
            true,
            ERROR
        );
        Html::redirect($back);
    }

    $color = (string) ($_POST['new_state_color'] ?? '');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = '#065a82';
    }

    $dup = $DB->request([
        'COUNT' => 'cpt',
        'FROM'  => 'glpi_projectstates',
        'WHERE' => ['name' => $name],
    ])->current();
    if ((int) ($dup['cpt'] ?? 0) > 0) {
        Session::addMessageAfterRedirect(
            sprintf(__('Já existe uma fase chamada "%s"', 'projectplus'), $name),
            true,
            ERROR
        );
        Html::redirect($back);
    }

    $state = new ProjectState();
    $newId = $state->add([
        'name'        => $name,
        'color'       => $color,
        'is_finished' => empty($_POST['new_state_finished']) ? 0 : 1,
    ]);
    if (!$newId) {
        Session::addMessageAfterRedirect(
            __('Falha ao criar a fase', 'projectplus'),
            true,
            ERROR
        );
        Html::redirect($back);
    }

    Session::addMessageAfterRedirect(
        sprintf(
            __('Fase "%s" criada — ela aparece desmarcada na lista; marque-a e salve o conjunto para usá-la', 'projectplus'),
            $name
        ),
        true,
        INFO
    );
    Html::redirect($back);
}

if (isset($_POST['clear'])) {
    TypePhase::clearType($typeId);
    Session::addMessageAfterRedirect(
        sprintf(
            __('Conjunto de "%s" removido — o tipo volta a usar o conjunto padrão', 'projectplus'),
            $typeName
        ),
        true,
        INFO
    );
    Html::redirect($back);
}

if (isset($_POST['copy'])) {
    $sourceId = isset($_POST['source_type']) && preg_match('/^\d+$/', (string) $_POST['source_type'])
        ? (int) $_POST['source_type']
        : -1;

    if ($sourceId < 0 || $sourceId === $typeId) {
        Session::addMessageAfterRedirect(
            __('Escolha um tipo de origem diferente para copiar as fases', 'projectplus'),
            true,
            ERROR
        );
        Html::redirect($back);
    }

    $copied = TypePhase::copyFrom($sourceId, $typeId);
    Session::addMessageAfterRedirect(
        sprintf(
            __('%1$d fase(s) copiada(s) para "%2$s"', 'projectplus'),
            $copied,
            $typeName
        ),
        true,
        $copied > 0 ? INFO : ERROR
    );
    Html::redirect($back);
}

// --- save ------------------------------------------------------------------
$stateIds = $_POST['states'] ?? [];
if (!is_array($stateIds)) {
    $stateIds = [];
}

// Conjunto vazio no tipo PADRÃO deixaria o plugin sem vocabulário nenhum: a
// cascata cairia em "todas as fases", o que não é o que o usuário pediu, mas
// principalmente é uma tela sem sentido. Melhor bloquear e explicar.
if ($stateIds === [] && $typeId === TypePhase::DEFAULT_TYPE) {
    Session::addMessageAfterRedirect(
        __('O conjunto padrão precisa de ao menos uma fase', 'projectplus'),
        true,
        ERROR
    );
    Html::redirect($back);
}

$saved = TypePhase::setForType($typeId, $stateIds);

Session::addMessageAfterRedirect(
    $saved > 0
        ? sprintf(
            __('%1$d fase(s) gravada(s) para "%2$s"', 'projectplus'),
            $saved,
            $typeName
        )
        : sprintf(
            __('Conjunto de "%s" removido — o tipo volta a usar o conjunto padrão', 'projectplus'),
            $typeName
        ),
    true,
    INFO
);

Html::redirect($back);

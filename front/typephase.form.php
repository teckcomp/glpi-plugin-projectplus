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

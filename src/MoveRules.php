<?php

namespace GlpiPlugin\Projectplus;

/**
 * O-3 (28/10/2026) — comentário e novo prazo ao MOVER no Kanban (tarefas e
 * projetos). Decisões do Claudio:
 *   - comentário ao soltar o cartão, configurável (`move_comment`:
 *     0 desligado, 1 opcional, 2 obrigatório — padrão obrigatório);
 *   - cartão com prazo estourado exige um NOVO fim planejado ao ser solto
 *     (`move_new_deadline`, padrão ligado) — menos quando vai para uma fase
 *     finalizadora (está sendo concluído);
 *   - vale nos dois Kanbans, só no arrastar (modal, painel e ficha nativa
 *     mudam a fase sem perguntar).
 *
 * O comentário vai para a mesma tabela do 💬 (TaskComment), com a marca
 * "[Movida para X]" / "[Movido para X]", e avisa como qualquer comentário.
 * A parte de decisão (check, commentText) é pura — o harness a exercita.
 */
class MoveRules
{
    public const COMMENT_OFF      = 0;
    public const COMMENT_OPTIONAL = 1;
    public const COMMENT_REQUIRED = 2;

    /** mesmo limite do 💬 (ajax/comment.php) */
    public const COMMENT_MAX = 4000;

    /** Regras em vigor (Configuração). Vão também no payload do board. */
    public static function config(): array
    {
        $c    = Config::get();
        $mode = (int) ($c['move_comment'] ?? self::COMMENT_REQUIRED);
        return [
            'comment'  => max(self::COMMENT_OFF, min(self::COMMENT_REQUIRED, $mode)),
            'deadline' => !empty($c['move_new_deadline']),
        ];
    }

    /** Prazo estourado — a MESMA régua do cartão (Deadline 'dark'). */
    public static function isLate(?string $planStart, ?string $realStart, ?string $planEnd, int $percent): bool
    {
        return Deadline::compute($planStart, $realStart, $planEnd, $percent)['state'] === 'dark';
    }

    /**
     * Confere o que veio do POST.
     *
     * @return array{error: ?string, comment: string, end: ?string, need_end: bool}
     */
    public static function check(
        array $rules,
        string $comment,
        string $endRaw,
        bool $late,
        bool $toFinished,
        ?string $planStart,
        string $today
    ): array {
        $comment = trim(str_replace(["\r\n", "\r"], "\n", $comment));
        $endRaw  = trim($endRaw);
        $needEnd = !empty($rules['deadline']) && $late && !$toFinished;
        $out     = ['error' => null, 'comment' => '', 'end' => null, 'need_end' => $needEnd];
        $mode    = (int) ($rules['comment'] ?? self::COMMENT_OFF);

        if ($mode !== self::COMMENT_OFF) {
            if ($comment === '' && $mode === self::COMMENT_REQUIRED) {
                $out['error'] = __('Escreva um comentário para mover.', 'projectplus');
                return $out;
            }
            if (mb_strlen($comment, 'UTF-8') > self::COMMENT_MAX) {
                $out['error'] = sprintf(__('O comentário passou do limite de %d caracteres.', 'projectplus'), self::COMMENT_MAX);
                return $out;
            }
            $out['comment'] = $comment;
        }

        if ($needEnd) {
            if ($endRaw === '') {
                $out['error'] = __('O prazo deste item já passou: informe o novo fim planejado.', 'projectplus');
                return $out;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endRaw) || !checkdate((int) substr($endRaw, 5, 2), (int) substr($endRaw, 8, 2), (int) substr($endRaw, 0, 4))) {
                $out['error'] = __('Data inválida', 'projectplus');
                return $out;
            }
            if ($endRaw < $today) {
                $out['error'] = __('O novo fim planejado não pode ser antes de hoje.', 'projectplus');
                return $out;
            }
            $start = $planStart ? substr($planStart, 0, 10) : '';
            if ($start !== '' && $endRaw < $start) {
                $out['error'] = __('O novo fim planejado não pode ser antes do início planejado.', 'projectplus');
                return $out;
            }
            $out['end'] = $endRaw;
        }

        return $out;
    }

    /**
     * Texto do comentário gravado. '' = nada a gravar (comentário vazio e
     * sem prazo novo).
     */
    public static function commentText(bool $isProject, string $phaseName, ?string $endLabel, string $comment): string
    {
        if ($comment === '' && ($endLabel === null || $endLabel === '')) {
            return '';
        }
        $tag = sprintf(
            $isProject ? __('Movido para %s', 'projectplus') : __('Movida para %s', 'projectplus'),
            $phaseName
        );
        if ($endLabel !== null && $endLabel !== '') {
            $tag .= ' · ' . sprintf(__('novo fim planejado %s', 'projectplus'), $endLabel);
        }
        return '[' . $tag . ']' . ($comment !== '' ? ' ' . $comment : '');
    }

    /** Nome da fase para a marca (0 = "Sem fase"). */
    public static function phaseName(int $stateId): string
    {
        if ($stateId <= 0) {
            return __('Sem fase', 'projectplus');
        }
        $states = Dashboard::getStatesMap();
        return (string) ($states[$stateId]['name'] ?? ('#' . $stateId));
    }
}

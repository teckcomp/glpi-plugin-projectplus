<?php

namespace GlpiPlugin\Projectplus;

use Glpi\RichText\RichText;

/**
 * Descrição de projeto e de tarefa (Bloco O-2e, 27/10/2026).
 *
 * O dado é o campo NATIVO `content` (glpi_projects / glpi_projecttasks). No
 * GLPI 11 ele é texto rico: a ficha nativa usa o TinyMCE e grava HTML; o
 * plugin (Novo projeto, Modelos) gravava texto puro — o core aceita os dois
 * (RichText::normalizeHtmlContent faz nl2br no texto puro).
 *
 * Decisão do Claudio: o Project+ edita em TEXTO SIMPLES. Gravação sai como
 * HTML mínimo (<p> por parágrafo, <br> por linha), que a ficha nativa abre
 * igual. Descrição com formatação de verdade (negrito, lista, imagem…) é
 * mostrada com a formatação; editar aqui avisa que ela se perde (`rich`).
 *
 * Porta única: payload() para as telas, fromText() para gravar.
 */
class Description
{
    /** limite do texto digitado (o campo é TEXT — 64 KB com o HTML) */
    public const MAX_LEN = 20000;

    /** Sem texto visível nem imagem. */
    public static function isEmpty(?string $content): bool
    {
        $content = (string) $content;
        if (trim($content) === '') {
            return true;
        }
        if (preg_match('/<img\b/i', $content)) {
            return false;
        }
        $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        return trim($text) === '';
    }

    /**
     * HTML que o texto simples produz sem perda: só <p>/</p> sem atributo e
     * <br>. Texto puro (sem tag nenhuma) também é simples.
     */
    public static function isSimple(string $content): bool
    {
        $rest = (string) preg_replace('#</?p>|<br\s*/?>#i', '', $content);
        return !preg_match('#<[a-z!/]#i', $rest);
    }

    /** Conteúdo gravado → texto para o campo de edição. */
    public static function toText(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (!RichText::isRichTextHtmlContent($content)) {
            return trim($content); // texto puro gravado direto (Novo projeto antigo)
        }
        $t = (string) preg_replace('#\s*<br\s*/?>\s*#i', "\n", $content);
        $t = (string) preg_replace('#\s*</(p|div|li|h[1-6]|blockquote|pre|tr)>\s*#i', "\n\n", $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\u{00A0}", ' ', $t);
        $t = (string) preg_replace("/[ \t]+\n/", "\n", $t);
        $t = (string) preg_replace("/\n{3,}/", "\n\n", $t);
        return trim($t);
    }

    /**
     * Texto simples digitado → HTML gravado. Linha em branco separa
     * parágrafos; quebra simples vira <br>. Vazio → ''.
     */
    public static function fromText(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '';
        }
        $out = [];
        foreach (preg_split("/\n[ \t]*\n+/", $text) as $par) {
            $par = trim($par);
            if ($par === '') {
                continue;
            }
            $lines = array_map(
                static fn ($l) => htmlspecialchars(rtrim($l), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                explode("\n", $par)
            );
            $out[] = '<p>' . implode('<br>', $lines) . '</p>';
        }
        return implode('', $out);
    }

    /** Texto grande demais para gravar? */
    public static function tooLong(string $text): bool
    {
        return mb_strlen($text, 'UTF-8') > self::MAX_LEN;
    }

    /**
     * O que as telas recebem: `html` já SANITIZADO pelo core (vai por
     * innerHTML), `text` para o campo de edição, `rich` = editar aqui perde
     * formatação, `empty`.
     */
    public static function payload(?string $content): array
    {
        $content = (string) $content;
        if (self::isEmpty($content)) {
            return ['empty' => true, 'html' => '', 'text' => '', 'rich' => false];
        }
        return [
            'empty' => false,
            'html'  => RichText::getSafeHtml($content),
            'text'  => self::toText($content),
            'rich'  => RichText::isRichTextHtmlContent($content) && !self::isSimple($content),
        ];
    }

    /**
     * O-2e: trecho em UMA linha corrida para o cartão do Kanban (o CSS corta
     * em 2 linhas). Texto puro — o cartão monta por textContent. '' = sem.
     */
    public static function excerpt(?string $content, int $max = 240): string
    {
        $content = (string) $content;
        if (self::isEmpty($content)) {
            return '';
        }
        $t = trim((string) preg_replace('/\s+/u', ' ', self::toText($content)));
        if (mb_strlen($t, 'UTF-8') > $max) {
            $t = rtrim(mb_substr($t, 0, $max - 1, 'UTF-8')) . '…';
        }
        return $t;
    }

    /**
     * O-2f (28/10/2026) — descrição OBRIGATÓRIA (Configuração `desc_required`,
     * padrão ligado). Decisão do Claudio: exigida na CRIAÇÃO (projeto e
     * tarefa) e ao MUDAR DE FASE quando o item ainda não tem nenhuma; quem já
     * tem não é cobrado. A finalização em cascata (O-2d) e a ficha nativa
     * ficam de fora; tarefas copiadas de modelo herdam a do modelo.
     */
    public static function required(): bool
    {
        return !empty(Config::get()['desc_required']);
    }

    /**
     * Porta da mudança de fase (parte pura). `$posted` = descrição enviada
     * junto (caixa do mover no Kanban); null/vazia = não veio.
     *
     * @return array{need: bool, error: ?string, html: ?string}
     */
    public static function gate(bool $required, ?string $content, int $oldState, int $newState, ?string $posted): array
    {
        $out = ['need' => false, 'error' => null, 'html' => null];
        if (!$required || $newState === $oldState || !self::isEmpty($content)) {
            return $out;
        }
        $out['need'] = true;
        $text = trim((string) $posted);
        if ($text === '') {
            $out['error'] = __('Preencha a descrição antes de mudar a fase.', 'projectplus');
            return $out;
        }
        if (self::tooLong($text)) {
            $out['error'] = sprintf(__('A descrição passou do limite de %d caracteres.', 'projectplus'), self::MAX_LEN);
            return $out;
        }
        $out['html'] = self::fromText($text);
        return $out;
    }

    /** Só o "tem ou não tem" (ícone do cartão). */
    public static function has(?string $content): bool
    {
        return !self::isEmpty($content);
    }
}

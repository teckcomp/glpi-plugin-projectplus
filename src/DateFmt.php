<?php

namespace GlpiPlugin\Projectplus;

use Html;

/**
 * Porta ÚNICA de formatação de data do plugin (Etapa 6, Bloco 4c).
 *
 * O PROBLEMA QUE ISTO RESOLVE — até aqui o plugin escrevia `d/m/Y` fixo em
 * ~25 pontos de PHP/Twig e em 3 funções de JavaScript. Para quem não usa o
 * formato brasileiro, as datas não ficavam feias: ficavam **erradas de forma
 * indetectável**. `07/06/2026` é 7 de junho para um usuário configurado em
 * `d-m-Y` e 6 de julho para um em `m-d-Y`, e nada na tela denuncia a
 * diferença. Depois do Bloco 3b o plugin já fala inglês, mas continuava
 * datando em português.
 *
 * O QUE O GLPI 11 OFERECE (conferido no core) — exatamente três formatos,
 * em `Toolbox::getDateFormats()`:
 *
 *     0 => 'Y-m-d'   (padrão de fábrica)
 *     1 => 'd-m-Y'
 *     2 => 'm-d-Y'
 *
 * A escolha do usuário fica em `$_SESSION['glpidate_format']` e é aplicada
 * por `Html::convDate()` / `Html::convDateTime()`. **Nenhum dos três usa
 * barra** — por isso, depois deste bloco, uma instalação em Português
 * (Brasil) mostra `26-07-2026` e não `26/07/2026`. Quem quiser dia-mês-ano
 * escolhe DD-MM-YYYY em Preferências; quem ficar no padrão de fábrica vê
 * `2026-07-26`.
 *
 * SEM SESSÃO (cron, CLI) — `glpidate_format` não existe e o core cai no
 * formato 0, ISO. É o desejável para e-mail de alerta: `2026-07-26` não é
 * ambíguo para nenhum destinatário.
 *
 * PARA VOLTAR AO FORMATO FIXO BRASILEIRO basta trocar o corpo de
 * `phpFormat()` por `return 'd/m/Y';` — o resto do plugin não muda, porque
 * ninguém mais formata data por conta própria. É a razão de este helper
 * existir mesmo se a decisão fosse a oposta.
 *
 * Nos templates Twig NÃO se usa esta classe: o core já expõe os filtros
 * `|formatted_date` e `|formatted_datetime`, que chamam o mesmo
 * `Html::convDate()`.
 */
final class DateFmt
{
    /** O que se mostra no lugar de uma data ausente. */
    public const EMPTY_MARK = '—';

    /**
     * Máscara PHP correspondente à preferência do usuário.
     *
     * Espelha o `match` de `Html::convDate()`, de propósito, em vez de
     * chamar `Toolbox::getDateFormat()`: ali o tipo `'php'` devolve
     * RÓTULOS TRADUZIDOS para o combo de Preferências (`DD-MM-YYYY`), não
     * máscara; a máscara está sob o tipo `'js'`, e qualquer outro tipo
     * lança `RuntimeException`. Nome confuso o bastante para virar bug.
     *
     * Ponto único de troca caso o plugin volte a fixar `d/m/Y`.
     */
    public static function phpFormat(): string
    {
        return match ((int) ($_SESSION['glpidate_format'] ?? 0)) {
            1       => 'd-m-Y',   // DD-MM-YYYY
            2       => 'm-d-Y',   // MM-DD-YYYY
            default => 'Y-m-d',   // padrão de fábrica
        };
    }

    /**
     * Data (sem hora) no formato do usuário.
     *
     * Aceita `Y-m-d`, `Y-m-d H:i:s`, string vazia e null.
     */
    public static function date($value, string $empty = self::EMPTY_MARK): string
    {
        $value = self::clean($value);
        if ($value === null) {
            return $empty;
        }

        $out = Html::convDate($value);

        return ($out === null || $out === '') ? $empty : (string) $out;
    }

    /**
     * Data e hora (`H:i`) no formato do usuário.
     *
     * `Html::convDateTime()` recorta a hora por posição na string
     * (`substr($time, 11, 5)`), então só funciona com `Y-m-d H:i:s`
     * completo — uma data seca devolveria a hora em branco. Aqui a
     * entrada é normalizada antes.
     */
    public static function dateTime($value, string $empty = self::EMPTY_MARK): string
    {
        $value = self::clean($value);
        if ($value === null) {
            return $empty;
        }

        if (strlen($value) === 10) {
            $value .= ' 00:00:00';
        }

        $out = Html::convDateTime($value);

        return ($out === null || trim((string) $out) === '') ? $empty : (string) $out;
    }

    /** Agora, no formato do usuário (rodapé de relatório, e-mail). */
    public static function now(): string
    {
        return self::dateTime(date('Y-m-d H:i:s'), '');
    }

    /**
     * Máscara para o JavaScript.
     *
     * Vai junto do dicionário de tradução, na chave `d` do payload de
     * `I18nJs` — a ponte do Bloco 3b já chega aos 10 pontos que precisam
     * dela, então não faz sentido abrir um segundo canal.
     */
    public static function jsFormat(): string
    {
        return self::phpFormat();
    }

    /**
     * Normaliza o que chega: null, vazio e as datas-zero do MySQL viram
     * null; o resto vira string aparada.
     */
    private static function clean($value): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (
            $value === ''
            || $value === 'NULL'
            || str_starts_with($value, '0000-00-00')
        ) {
            return null;
        }

        return $value;
    }
}

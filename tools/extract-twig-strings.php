<?php

/**
 * ProjectPlus — extrator de strings traduzíveis dos templates Twig.
 *
 * POR QUE ISSO EXISTE: o `xgettext` não sabe ler Twig. Ele extrai as chamadas
 * `__()` dos arquivos .php e ignora completamente os .html.twig, onde está
 * quase metade do texto do plugin. Este script varre os templates, encontra as
 * chamadas de tradução e escreve um FRAGMENTO .pot que depois é unido ao .pot
 * dos PHP com `msgcat` (ver tools/update-locales.sh).
 *
 * Escrito em PHP de propósito: um servidor GLPI sempre tem PHP; pode não ter
 * python3.
 *
 * Uso:
 *   php tools/extract-twig-strings.php <dir-templates> <arquivo-saida.pot> [dominio]
 *
 * Formas reconhecidas (aspas simples, como em todo o plugin):
 *   __('texto', 'projectplus')
 *   _n('singular', 'plural', <expr>, 'projectplus')
 *
 * @license GPL-2.0-or-later
 */

$templatesDir = $argv[1] ?? null;
$outFile      = $argv[2] ?? null;
$domain       = $argv[3] ?? 'projectplus';

if ($templatesDir === null || $outFile === null) {
    fwrite(STDERR, "uso: php extract-twig-strings.php <dir-templates> <saida.pot> [dominio]\n");
    exit(2);
}
if (!is_dir($templatesDir)) {
    fwrite(STDERR, "erro: diretorio nao encontrado: {$templatesDir}\n");
    exit(2);
}

// Literal de string do Twig entre aspas simples, com escape \' e \\ aceitos.
$lit = "'((?:[^'\\\\]|\\\\.)*)'";

$reSingular = '/__\(\s*' . $lit . '\s*,\s*\'' . preg_quote($domain, '/') . '\'\s*\)/u';
$rePlural   = '/_n\(\s*' . $lit . '\s*,\s*' . $lit . '\s*,.*?,\s*\'' . preg_quote($domain, '/') . '\'\s*\)/u';

/** @var array<string, array{plural: ?string, refs: string[]}> */
$entries = [];

$files = glob(rtrim($templatesDir, '/') . '/*.twig') ?: [];
sort($files);

$scanned = 0;
foreach ($files as $file) {
    $scanned++;
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "aviso: nao consegui ler {$file}\n");
        continue;
    }
    // Referência relativa à raiz do plugin (tools/ está um nível abaixo).
    $relDir = basename(rtrim($templatesDir, '/'));

    foreach ($lines as $i => $line) {
        $ref = $relDir . '/' . basename($file) . ':' . ($i + 1);

        if (preg_match_all($reSingular, $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $id = twigUnescape($hit[1]);
                if (!isset($entries[$id])) {
                    $entries[$id] = ['plural' => null, 'refs' => []];
                }
                $entries[$id]['refs'][] = $ref;
            }
        }
        if (preg_match_all($rePlural, $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $id  = twigUnescape($hit[1]);
                $pl  = twigUnescape($hit[2]);
                if (!isset($entries[$id])) {
                    $entries[$id] = ['plural' => $pl, 'refs' => []];
                }
                $entries[$id]['plural'] = $pl;
                $entries[$id]['refs'][] = $ref;
            }
        }
    }
}

ksort($entries, SORT_STRING);

$out  = "# Fragmento gerado por tools/extract-twig-strings.php — NAO EDITAR A MAO.\n";
$out .= "msgid \"\"\nmsgstr \"\"\n";
$out .= "\"MIME-Version: 1.0\\n\"\n";
$out .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
$out .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
$out .= "\"Plural-Forms: nplurals=2; plural=(n > 1);\\n\"\n\n";

foreach ($entries as $id => $data) {
    $out .= '#: ' . implode(' ', array_unique($data['refs'])) . "\n";
    $out .= 'msgid ' . poQuote($id) . "\n";
    if ($data['plural'] !== null) {
        $out .= 'msgid_plural ' . poQuote($data['plural']) . "\n";
        $out .= "msgstr[0] \"\"\n";
        $out .= "msgstr[1] \"\"\n\n";
    } else {
        $out .= "msgstr \"\"\n\n";
    }
}

if (file_put_contents($outFile, $out) === false) {
    fwrite(STDERR, "erro: nao consegui escrever {$outFile}\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "extract-twig-strings: %d template(s), %d string(s) unica(s) -> %s\n",
    $scanned,
    count($entries),
    $outFile
));
exit(0);

/**
 * Desfaz os escapes do literal Twig ('\'' e '\\').
 */
function twigUnescape(string $raw): string
{
    return str_replace(["\\'", '\\\\'], ["'", '\\'], $raw);
}

/**
 * Escreve a string no formato do arquivo .po (sempre entre aspas duplas).
 */
function poQuote(string $s): string
{
    $s = str_replace(['\\', '"', "\t", "\r"], ['\\\\', '\\"', '\\t', '\\r'], $s);
    if (!str_contains($s, "\n")) {
        return '"' . $s . '"';
    }
    // Multilinha: primeira linha vazia e uma linha por quebra (padrao gettext).
    $parts = explode("\n", $s);
    $last  = array_pop($parts);
    $res   = "\"\"\n";
    foreach ($parts as $p) {
        $res .= '"' . $p . '\\n"' . "\n";
    }
    return $res . '"' . $last . '"';
}

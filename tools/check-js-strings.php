<?php

/**
 * ProjectPlus — confere o dicionário de tradução do JavaScript (Bloco 3b).
 *
 * O JS chama __('texto') / _n('sing','plur',n) e busca a tradução pela CHAVE
 * em PT-BR no dicionário montado por src/I18nJs.php. Se as duas listas saírem
 * de sincronia, nada quebra — a string só deixa de traduzir, em silêncio. É
 * exatamente o tipo de defeito que ninguém percebe até o cliente reclamar.
 *
 * Este script fecha esse buraco comparando os dois lados:
 *
 *   1. chave usada no JS que NÃO existe em I18nJs::map()  -> erro
 *   2. chave em I18nJs::map() que NENHUM JS usa           -> aviso
 *   3. tradução com aspas duplas nos .po                  -> erro
 *      (o texto entra em atributos HTML montados por concatenação;
 *       uma " na tradução partiria o atributo ao meio)
 *
 * Não precisa do GLPI: __() e _n() são substituídos por stubs de identidade,
 * porque aqui só interessam as CHAVES, não a tradução.
 *
 * Uso:
 *   php tools/check-js-strings.php
 *
 * Saída: 0 = tudo certo; 1 = erro.
 *
 * @license GPL-2.0-or-later
 */

$root = dirname(__DIR__);

// ---------------------------------------------------------------- stubs
if (!function_exists('__')) {
    function __(string $s, string $domain = ''): string
    {
        return $s;
    }
}
if (!function_exists('_n')) {
    function _n(string $singular, string $plural, int $n, string $domain = ''): string
    {
        return $n === 1 ? $singular : $plural;
    }
}

require_once $root . '/src/I18nJs.php';

use GlpiPlugin\Projectplus\I18nJs;

$map      = I18nJs::map();
$dictS    = array_keys($map['s'] ?? []);
$dictP    = array_keys($map['p'] ?? []);
$dictAll  = array_merge($dictS, $dictP);

// ------------------------------------------------ chaves usadas nos .js
$jsDir = $root . '/public/js';
$files = glob($jsDir . '/*.js');
sort($files);

$used     = [];   // chave => [arquivos]
$reString = "'((?:[^'\\\\]|\\\\.)*)'";

foreach ($files as $file) {
    $code = file_get_contents($file);
    $name = basename($file);

    // __('texto'[, args])
    if (preg_match_all('/\b__\(\s*' . $reString . '/u', $code, $m)) {
        foreach ($m[1] as $key) {
            $used[stripcslashes($key)][$name] = true;
        }
    }

    // _n('singular', 'plural', n[, args])
    if (preg_match_all('/\b_n\(\s*' . $reString . '\s*,\s*' . $reString . '/u', $code, $m)) {
        foreach ($m[1] as $i => $sing) {
            $key = I18nJs::pkey(stripcslashes($sing), stripcslashes($m[2][$i]));
            $used[$key][$name] = true;
        }
    }

    // _list('a|b|c', 12) — lista traduzida (meses da timeline)
    if (preg_match_all('/\b_list\(\s*' . $reString . '/u', $code, $m)) {
        foreach ($m[1] as $key) {
            $used[stripcslashes($key)][$name] = true;
        }
    }

    // i.t('texto') — chamadas diretas ao runtime (hidenative*.js)
    if (preg_match_all('/\bi\.t\(\s*' . $reString . '/u', $code, $m)) {
        foreach ($m[1] as $key) {
            $used[stripcslashes($key)][$name] = true;
        }
    }

    // nativeLabels('Custos', […]) — 1º argumento é a chave PT-BR
    if (preg_match_all('/\bnativeLabels\(\s*' . $reString . '/u', $code, $m)) {
        foreach ($m[1] as $key) {
            $used[stripcslashes($key)][$name] = true;
        }
    }
}

$errors   = [];
$warnings = [];

// ------------------------------------------------------------- 1) faltando
foreach ($used as $key => $where) {
    if (!in_array($key, $dictAll, true)) {
        $shown = str_replace("\0", ' | ', $key);
        $errors[] = sprintf(
            'usada em %s mas AUSENTE de I18nJs::map(): "%s"',
            implode(', ', array_keys($where)),
            $shown
        );
    }
}

// -------------------------------------------------------------- 2) sobrando
foreach ($dictAll as $key) {
    if (!isset($used[$key])) {
        $warnings[] = 'no dicionario mas nenhum .js usa: "' . str_replace("\0", ' | ', $key) . '"';
    }
}

// ------------------------------------------------------ 3) aspas duplas nos .po
foreach (glob($root . '/locales/*.po') as $po) {
    $lines = file($po, FILE_IGNORE_NEW_LINES);
    $keys  = array_flip(array_map(
        static fn(string $k): string => str_replace("\0", '", "', $k),
        $dictAll
    ));
    $current = null;
    foreach ($lines as $line) {
        if (preg_match('/^msgid "(.*)"$/', $line, $m)) {
            $current = stripcslashes($m[1]);
            continue;
        }
        if (preg_match('/^msgstr(?:\[\d+\])? "(.*)"$/', $line, $m)) {
            if ($current !== null && isset($used[$current]) && str_contains($m[1], '\\"')) {
                $errors[] = sprintf(
                    '%s: traducao de "%s" tem aspas duplas — quebraria o atributo HTML',
                    basename($po),
                    $current
                );
            }
        }
    }
}

// ------------------------------------------------------------------- saida
printf("arquivos .js analisados : %d\n", count($files));
printf("chaves usadas no JS     : %d\n", count($used));
printf("chaves no dicionario    : %d (%d simples + %d plurais)\n", count($dictAll), count($dictS), count($dictP));

foreach ($warnings as $w) {
    echo "AVISO  {$w}\n";
}
foreach ($errors as $e) {
    echo "ERRO   {$e}\n";
}

if ($errors !== []) {
    printf("\nFALHOU: %d erro(s), %d aviso(s).\n", count($errors), count($warnings));
    exit(1);
}

printf("\nOK: dicionario e JavaScript em sincronia (%d aviso(s)).\n", count($warnings));
exit(0);

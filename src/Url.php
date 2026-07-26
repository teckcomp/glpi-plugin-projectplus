<?php

namespace GlpiPlugin\Projectplus;

/**
 * Helper ÚNICO de URL do plugin (Etapa 6, Bloco 4a).
 *
 * MOTIVO — `Plugin::getWebDir()` está DEPRECATED no GLPI 11. A primeira
 * linha do método no core 11.0.6 é:
 *
 *     Toolbox::deprecated('All plugins resources should be accessed from
 *                          the `/plugins/` path.');
 *
 * ou seja, cada chamada gravava uma linha de aviso no log — e o plugin
 * chamava o método 26 vezes, várias delas por página. O log de
 * homologação enchia de ruído e escondia erro de verdade.
 *
 * POR QUE `/plugins/` É SEGURO MESMO EM INSTALAÇÃO PELO MARKETPLACE —
 * o `getWebDir()` devolvia `/marketplace/<key>` quando o plugin estava
 * na pasta do marketplace, e por isso parecia arriscado fixar
 * `/plugins/`. Não é: no GLPI 11 quem resolve a rota é o
 * `PluginsRouterListener`, que casa o caminho contra
 * `Plugin::PLUGIN_RESOURCE_PATTERN`:
 *
 *     #^/(?:plugins|marketplace)/(?<plugin_key>[^/]+)(?<plugin_resource>/.*|)$#
 *
 * O plugin é localizado pela CHAVE, não pela pasta física — os dois
 * prefixos levam ao mesmo lugar. É exatamente o que a mensagem de
 * deprecação manda fazer. Isso importa para a Etapa 6, Bloco 5
 * (catálogo oficial), quando o plugin passará a ser instalado pelo
 * marketplace.
 *
 * NÃO USAR `$_SERVER['PHP_SELF']` como alternativa: no front controller
 * do GLPI 11 ele vale sempre `/index.php` (lição 44).
 */
final class Url
{
    /**
     * Chave/diretório do plugin. É a mesma string usada pelo roteador.
     */
    public const KEY = 'projectplus';

    /**
     * Raiz web do plugin, sem barra no fim.
     *
     * Ex.: `/glpi/plugins/projectplus`
     */
    public static function base(): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/' . self::KEY;
    }

    /**
     * URL de um recurso do plugin.
     *
     * Aceita o caminho com ou sem barra inicial, para que a troca das
     * chamadas antigas (`getWebDir() . '/front/x.php'`) seja literal.
     *
     * Ex.: `Url::to('front/dashboard.php')`
     *      `Url::to('/ajax/task.php')`
     */
    public static function to(string $path): string
    {
        return self::base() . '/' . ltrim($path, '/');
    }
}

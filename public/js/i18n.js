/**
 * ProjectPlus — i18n do lado do cliente (Etapa 6, Bloco 3b).
 *
 * O GLPI 11 não tem runtime de tradução em JavaScript (não existe Jed nem
 * dgettext no core). Aqui a tradução já vem PRONTA do servidor: src/I18nJs.php
 * imprime <script type="application/json" id="pp-i18n"> com o dicionário do
 * idioma do usuário, e este arquivo só faz a consulta.
 *
 * A CHAVE É O TEXTO EM PT-BR (mesma decisão do msgid no Bloco 3a). Se o
 * dicionário não estiver na página — ou se a chave não existir nele — t()
 * devolve a própria chave e a tela fica em português. Nunca em branco,
 * nunca quebrada.
 *
 * API (window.ProjectPlusI18n):
 *   t('Salvar')                        -> 'Save'
 *   t('Subtarefa da tarefa: %s', nome) -> 'Subtask of: Instalar switch'
 *   tn('%d tarefa', '%d tarefas', 3, 3) -> '3 tasks'
 *
 * Regra de plural: forma 1 quando n === 1, forma 2 nos demais casos. Vale
 * para pt_BR e en_GB, que são os idiomas do plugin; um idioma com mais de
 * duas formas precisaria da fórmula Plural-Forms do catálogo.
 *
 * Este arquivo é carregado ANTES dos demais JS do plugin (ver setup.php).
 *
 * @license GPL-2.0-or-later
 */
(function () {
    'use strict';

    var DICT_ID     = 'pp-i18n';
    var PLURAL_SEP  = '\u0000';   // mesma convenção do .mo e de I18nJs::pkey()
    var cache       = null;

    // Lê o dicionário uma única vez por página. Payload ausente, quebrado ou
    // com formato inesperado vira dicionário vazio (fallback = chave PT-BR).
    function dict() {
        if (cache !== null) {
            return cache;
        }
        cache = { s: {}, p: {} };

        var el = document.getElementById(DICT_ID);
        if (!el) {
            return cache;
        }
        try {
            var parsed = JSON.parse(el.textContent);
            if (parsed && typeof parsed === 'object') {
                if (parsed.s && typeof parsed.s === 'object') { cache.s = parsed.s; }
                if (parsed.p && typeof parsed.p === 'object') { cache.p = parsed.p; }
            }
        } catch (e) {
            /* mantém vazio */
        }
        return cache;
    }

    function has(obj, key) {
        return Object.prototype.hasOwnProperty.call(obj, key);
    }

    // Substitui %s / %d na ordem dos argumentos; %% vira % literal.
    // Sem argumentos, devolve o texto intacto (não mexe em "50%").
    function format(text, args) {
        if (!args.length) {
            return text;
        }
        var i = 0;
        return String(text).replace(/%[sd%]/g, function (m) {
            if (m === '%%') { return '%'; }
            var v = args[i++];
            return (v === undefined || v === null) ? '' : String(v);
        });
    }

    function t(msgid) {
        var d = dict();
        var s = has(d.s, msgid) ? d.s[msgid] : msgid;
        return format(s, Array.prototype.slice.call(arguments, 1));
    }

    function tn(singular, plural, n) {
        var d     = dict();
        var forms = has(d.p, singular + PLURAL_SEP + plural) ? d.p[singular + PLURAL_SEP + plural] : null;
        var idx   = (Number(n) === 1) ? 0 : 1;
        var s;

        if (forms && forms[idx] !== undefined && forms[idx] !== null) {
            s = forms[idx];
        } else {
            s = (idx === 0) ? singular : plural;
        }
        return format(s, Array.prototype.slice.call(arguments, 3));
    }

    // Lista traduzida a partir de uma string "a|b|c" (usada nos meses da
    // timeline). Devolve o fallback se a tradução vier com outro tamanho.
    function tlist(msgid, expected) {
        var parts = String(t(msgid)).split('|');
        if (expected && parts.length !== expected) {
            parts = String(msgid).split('|');
        }
        return parts;
    }

    window.ProjectPlusI18n = {
        t: t,
        tn: tn,
        tlist: tlist,
        // só para os testes isolados (jsdom): força reler o #pp-i18n
        _reset: function () { cache = null; }
    };
})();

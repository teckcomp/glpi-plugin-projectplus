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
    // Os TRES formatos do GLPI 11 (Toolbox::getDateFormats). Nenhum usa barra.
    var FORMATS     = ['Y-m-d', 'd-m-Y', 'm-d-Y'];
    var DEFAULT_FMT = 'Y-m-d';    // padrao de fabrica do GLPI
    var cache       = null;

    // Lê o dicionário uma única vez por página. Payload ausente, quebrado ou
    // com formato inesperado vira dicionário vazio (fallback = chave PT-BR).
    function dict() {
        if (cache !== null) {
            return cache;
        }
        cache = { s: {}, p: {}, d: DEFAULT_FMT };

        var el = document.getElementById(DICT_ID);
        if (!el) {
            return cache;
        }
        try {
            var parsed = JSON.parse(el.textContent);
            if (parsed && typeof parsed === 'object') {
                if (parsed.s && typeof parsed.s === 'object') { cache.s = parsed.s; }
                if (parsed.p && typeof parsed.p === 'object') { cache.p = parsed.p; }
                // Mascara de data (Bloco 4c). Aceita so os tres formatos que
                // o GLPI 11 oferece; qualquer outra coisa cai no padrao.
                if (FORMATS.indexOf(parsed.d) !== -1) { cache.d = parsed.d; }
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

    // ------------------------------------------------------------------
    // Data (Bloco 4c)
    //
    // O servidor manda a preferencia do usuario junto do dicionario; aqui
    // so se aplica a mascara. Entrada esperada: 'YYYY-MM-DD' ou
    // 'YYYY-MM-DD HH:MM:SS' (o que o MySQL devolve). Entrada que nao casa
    // com isso volta inalterada — nunca 'NaN' nem 'undefined' na tela.
    // ------------------------------------------------------------------

    var ISO_RE = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/;

    function parts(value) {
        if (!value) { return null; }
        var m = ISO_RE.exec(String(value));
        return m ? m : null;
    }

    function applyMask(m) {
        var y = m[1], mo = m[2], d = m[3];
        switch (dict().d) {
            case 'd-m-Y': return d + '-' + mo + '-' + y;
            case 'm-d-Y': return mo + '-' + d + '-' + y;
            default:      return y + '-' + mo + '-' + d;
        }
    }

    // Data sem hora. Devolve o marcador quando nao ha data.
    function fmtDate(value, empty) {
        var m = parts(value);
        if (!m) { return value ? String(value) : (empty === undefined ? '—' : empty); }
        return applyMask(m);
    }

    // Data com hora (HH:MM). Sem a parte de hora, comporta-se como fmtDate.
    function fmtDateTime(value, empty) {
        var m = parts(value);
        if (!m) { return value ? String(value) : (empty === undefined ? '—' : empty); }
        var out = applyMask(m);
        if (m[4] !== undefined && m[5] !== undefined) {
            out += ' ' + m[4] + ':' + m[5];
        }
        return out;
    }

    window.ProjectPlusI18n = {
        t: t,
        tn: tn,
        tlist: tlist,
        fmtDate: fmtDate,
        fmtDateTime: fmtDateTime,
        dateFormat: function () { return dict().d; },
        // só para os testes isolados (jsdom): força reler o #pp-i18n
        _reset: function () { cache = null; }
    };
})();

/**
 * ProjectPlus — oculta a aba "Custos" NATIVA na ficha do projeto.
 *
 * Este arquivo só é carregado quando a opção "hide_native_costs" está
 * ativa em Configurações (padrão: ligada). O plugin é a fonte única de
 * custos (abas próprias com autor); nada é removido do core — ao
 * desativar o plugin ou a opção, a aba nativa volta a aparecer.
 */
(function () {
    'use strict';

    // Rotulos da aba NATIVA nos idiomas que o plugin traduz. O texto da aba
    // vem do CORE do GLPI, entao muda com o idioma do usuario: em ingles a
    // aba "Custos" se chama "Costs" e o filtro por texto deixava de casar —
    // a aba nativa reaparecia e o plugin perdia o papel de fonte unica.
    function nativeLabels(ptBr, others) {
        var out = [ptBr].concat(others || []);
        var i = window.ProjectPlusI18n;
        if (i) {
            var tr = i.t(ptBr);
            if (out.indexOf(tr) === -1) { out.push(tr); }
        }
        return out;
    }

    // A aba do core aparece como "Custos" ou "Custos 3" (com contador). A do
    // plugin e "Custos (ProjectPlus)" e nunca casa, porque o resto do texto
    // nao e um numero.
    function matchesTab(text, labels) {
        for (var i = 0; i < labels.length; i++) {
            var label = labels[i];
            if (text === label) { return true; }
            if (text.indexOf(label) === 0) {
                var rest = text.slice(label.length).trim();
                if (rest !== '' && /^\d+$/.test(rest)) { return true; }
            }
        }
        return false;
    }

    function hideNativeProjectCostTab() {
        if (!/\/front\/project\.form\.php/.test(window.location.pathname)) { return; }

        var hide = function () {
            // 1) Links de aba que apontam para a classe ProjectCost do CORE.
            //    A aba do plugin (GlpiPlugin\Projectplus\ProjectCost) também
            //    contém "ProjectCost" no link — por isso o filtro "Projectplus".
            document.querySelectorAll(
                'a[href*="ProjectCost"], a[data-bs-target*="ProjectCost"], a[id*="ProjectCost"]'
            ).forEach(function (a) {
                var ref = (a.getAttribute('href') || '') + ' '
                    + (a.getAttribute('data-bs-target') || '') + ' ' + (a.id || '');
                if (/projectplus/i.test(ref)) { return; } // aba do plugin: mantém
                (a.closest('li') || a).style.display = 'none';
            });
            // 2) Reforço: aba cujo texto é exatamente o rótulo NATIVO de
            //    custos (+ contador), sem confundir com a "Custos
            //    (ProjectPlus)". O rótulo do core muda com o idioma do
            //    usuário: vem traduzido no dicionário #pp-i18n quando ele
            //    está na página, e "Custos" é o padrão.
            var labels = nativeLabels('Custos', ['Costs']);
            document.querySelectorAll('.nav-tabs a, .nav a[data-bs-toggle="tab"]').forEach(function (a) {
                var txt = (a.textContent || '').trim();
                if (matchesTab(txt, labels)) {
                    (a.closest('li') || a).style.display = 'none';
                }
            });
        };

        hide();
        // As abas do GLPI 11 podem montar depois do DOM pronto
        setTimeout(hide, 600);
        setTimeout(hide, 1800);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hideNativeProjectCostTab);
    } else {
        hideNativeProjectCostTab();
    }
})();

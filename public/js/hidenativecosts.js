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
            // 2) Reforço: aba cujo texto é exatamente "Custos" (+ contador),
            //    sem confundir com a "Custos (ProjectPlus)"
            document.querySelectorAll('.nav-tabs a, .nav a[data-bs-toggle="tab"]').forEach(function (a) {
                var txt = (a.textContent || '').trim();
                if (/^Custos\s*\d*$/.test(txt)) {
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

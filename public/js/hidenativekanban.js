/**
 * ProjectPlus — oculta a aba "Kanban" NATIVA na ficha do projeto
 * (Etapa 7, Bloco 1.1).
 *
 * Mesmo mecanismo de public/js/hidenativecosts.js para a aba "Custos":
 * só entra quando a opção "hide_native_kanban" está ativa em
 * Configurações (padrão: ligada). Nada é removido do core — ao
 * desativar o plugin ou a opção, a aba nativa volta a aparecer. A aba
 * do plugin ("Kanban (ProjectPlus)") assume o papel de fonte única.
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

    function hideNativeKanbanTab() {
        if (!/\/front\/project\.form\.php/.test(window.location.pathname)) { return; }

        var hide = function () {
            // Aba nativa cujo texto é exatamente o rótulo NATIVO (+ eventual
            // contador) — a aba do plugin usa o rótulo "Kanban (ProjectPlus)"
            // e nunca bate. O rótulo do core muda com o idioma do usuário:
            // vem traduzido no dicionário #pp-i18n.
            var labels = nativeLabels('Kanban', []);
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
        document.addEventListener('DOMContentLoaded', hideNativeKanbanTab);
    } else {
        hideNativeKanbanTab();
    }
})();

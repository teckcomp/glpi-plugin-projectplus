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

    function hideNativeKanbanTab() {
        if (!/\/front\/project\.form\.php/.test(window.location.pathname)) { return; }

        var hide = function () {
            // Aba nativa cujo texto é exatamente "Kanban" (+ eventual
            // contador) — a aba do plugin usa o rótulo "Kanban
            // (ProjectPlus)" e nunca bate nesse regex.
            document.querySelectorAll('.nav-tabs a, .nav a[data-bs-toggle="tab"]').forEach(function (a) {
                var txt = (a.textContent || '').trim();
                if (/^Kanban\s*\d*$/.test(txt)) {
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

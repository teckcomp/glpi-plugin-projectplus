/**
 * ProjectPlus — Bloco L (21/10/2026): botão ☆/★ "Favoritar filtros".
 *
 * ★ = os filtros atuais da tela são os favoritados (clique remove);
 * ☆ = não são (clique grava os atuais como favorito, substituindo o anterior).
 *
 * Filtros de URL: o servidor manda `current` já normalizado (lista branca de
 * Favorite::SCREENS); ao gravar envia-se location.search e o servidor
 * normaliza de novo. Filtros só de navegador: elementos marcados com
 * data-pp-fav-ui="chave" — checkbox → "1"/"0", select → valor, grupo
 * segmentado → data-pp-lane do botão ativo.
 *
 * SEM STRINGS PRÓPRIAS: rótulos e mensagens vêm do Twig em data-*.
 * Carregado pelo partial _favorite.html.twig; idempotente.
 */
(function () {
    'use strict';
    if (window.ProjectPlusFavorite) {
        return;
    }

    function norm(obj) {
        const keys = Object.keys(obj || {}).filter(function (k) {
            return obj[k] !== null && obj[k] !== undefined && String(obj[k]) !== '';
        }).sort();
        return JSON.stringify(keys.map(function (k) { return [k, String(obj[k])]; }));
    }

    function collectUi(doc) {
        const out = {};
        doc.querySelectorAll('[data-pp-fav-ui]').forEach(function (el) {
            const k = el.getAttribute('data-pp-fav-ui');
            if (el.type === 'checkbox') {
                out[k] = el.checked ? '1' : '';
            } else if (el.tagName === 'SELECT') {
                out[k] = el.value || '';
            } else {
                const act = el.querySelector('.pp-seg__btn--active');
                out[k] = act ? (act.getAttribute('data-pp-lane') || '') : '';
            }
        });
        return out;
    }

    // Valor vazio = sem filtro (mesma regra do servidor). "0" NÃO é vazio:
    // em Minhas tarefas, Tipo 0 é "Sem tipo"; caixa desmarcada já vem "".
    function uiForCompare(ui) {
        const out = {};
        Object.keys(ui || {}).forEach(function (k) {
            const v = String(ui[k]);
            if (v !== '') { out[k] = v; }
        });
        return out;
    }

    function bind(btn) {
        if (btn.dataset.ppFavBound) {
            return;
        }
        btn.dataset.ppFavBound = '1';
        let fav;
        try { fav = JSON.parse(btn.getAttribute('data-pp-fav')); } catch (e) { return; }
        const doc = btn.ownerDocument;
        const label = btn.querySelector('span');
        const icon = btn.querySelector('i');

        function isSaved() {
            if (!fav.saved) { return false; }
            return norm(fav.saved.qs) === norm(fav.current) &&
                norm(uiForCompare(fav.saved.ui)) === norm(uiForCompare(collectUi(doc)));
        }
        function paint() {
            const on = isSaved();
            btn.classList.toggle('pp-fav-btn--on', on);
            if (label) { label.textContent = btn.getAttribute(on ? 'data-label-on' : 'data-label-off') || ''; }
            btn.title = btn.getAttribute(on ? 'data-title-on' : 'data-title-off') || '';
            if (icon) { icon.className = on ? 'ti ti-star-filled' : 'ti ti-star'; }
        }

        btn.addEventListener('click', function () {
            const on = isSaved();
            const body = new URLSearchParams();
            body.set('action', on ? 'clear' : 'save');
            body.set('screen', fav.screen);
            body.set('qs', doc.defaultView.location.search || '');
            body.set('ui', JSON.stringify(on ? {} : uiForCompare(collectUi(doc))));
            body.set('_glpi_csrf_token', fav.csrf || '');
            btn.disabled = true;
            doc.defaultView.fetch(fav.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': fav.csrf || '' },
                body: body,
            }).then(function (r) { return r.json(); }).then(function (resp) {
                if (resp && resp.csrf) { fav.csrf = resp.csrf; }
                if (resp && resp.ok) {
                    fav.saved = resp.saved || null;
                } else {
                    doc.defaultView.alert(btn.getAttribute('data-msg-error') || '');
                }
            }).catch(function () {
                doc.defaultView.alert(btn.getAttribute('data-msg-error') || '');
            }).then(function () {
                btn.disabled = false;
                paint();
            });
        });

        // Filtros de navegador mudam sem recarregar: repinta o ★/☆.
        doc.addEventListener('change', paint);
        doc.addEventListener('click', function (ev) {
            if (ev.target.closest && ev.target.closest('[data-pp-fav-ui]')) { paint(); }
        });
        doc.addEventListener('pp-fav-refresh', paint);
        paint();
    }

    const ProjectPlusFavorite = {
        init: function (doc) {
            (doc || document).querySelectorAll('.pp-fav-btn').forEach(bind);
        },
        _test: { norm: norm, collectUi: collectUi, uiForCompare: uiForCompare },
    };
    window.ProjectPlusFavorite = ProjectPlusFavorite;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { ProjectPlusFavorite.init(document); });
    } else {
        ProjectPlusFavorite.init(document);
    }
})();

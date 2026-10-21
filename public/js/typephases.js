/**
 * ProjectPlus — tela "Fases por tipo de projeto" (Etapa 9).
 *
 * Faz UMA coisa só: reordenar as linhas da tabela de fases com as setas
 * ↑/↓. A ordem das colunas do Kanban é a ordem das linhas na tela, porque o
 * navegador envia os campos de um formulário na ORDEM DO DOM — então mover a
 * <tr> já muda a `ordem` gravada, sem nenhum campo numérico para o usuário
 * errar e sem estado paralelo em JavaScript.
 *
 * DEGRADAÇÃO: sem JavaScript a tela continua funcionando — os checkboxes
 * salvam normalmente e a ordem fica a que já estava. Nada aqui é obrigatório
 * para gravar.
 *
 * SEM STRINGS PRÓPRIAS de propósito: todo rótulo (inclusive os `title` das
 * setas) vem do Twig, já traduzido pelo servidor. Por isso este arquivo NÃO
 * precisa do dicionário do #pp-i18n nem entra em src/I18nJs.php.
 *
 * Carregado só na tela front/typephases.php (não é global).
 */
(function () {
    'use strict';

    const ProjectPlusTypePhases = {};

    /** Reflete "marcada / não marcada" na linha, só para leitura visual. */
    function paintRow(tr) {
        const box = tr.querySelector('input[type="checkbox"]');
        if (!box) {
            return;
        }
        tr.classList.toggle('pp-tp-row--off', !box.checked);
    }

    /**
     * Move a linha uma posição para cima ou para baixo dentro do <tbody>.
     * `insertBefore`/`nextSibling` preservam o estado dos campos (o elemento
     * é movido, não recriado — recriar perderia o "checked").
     */
    function move(tr, dir) {
        const body = tr.parentNode;
        if (!body) {
            return;
        }
        if (dir === 'up') {
            const prev = tr.previousElementSibling;
            if (prev) {
                body.insertBefore(tr, prev);
            }
            return;
        }
        const next = tr.nextElementSibling;
        if (next) {
            body.insertBefore(next, tr);
        }
    }

    /**
     * Bloco K (21/10/2026): edição inline de uma fase (nome, cor, finalizadora).
     * Uma linha por vez. Os campos pertencem ao form #pp-tp-editform (atributo
     * form=) e nascem disabled; abrir a edição habilita só os da linha e grava
     * o id no hidden do form. Cancelar restaura os valores originais e
     * desabilita de novo. Sem JS a tela segue como antes (só o lápis não age).
     */
    function editRow(tr, on) {
        const idEl = document.querySelector('[data-pp-role="edit-id"]');
        tr.querySelectorAll('.pp-tp-view').forEach(function (el) { el.hidden = on; });
        tr.querySelectorAll('.pp-tp-editing').forEach(function (el) { el.hidden = !on; });
        tr.querySelectorAll('.pp-tp-editing input').forEach(function (inp) {
            if (!on) {
                if (inp.type === 'checkbox') {
                    inp.checked = inp.defaultChecked;
                } else {
                    inp.value = inp.defaultValue;
                }
            }
            inp.disabled = !on;
        });
        tr.classList.toggle('pp-tp-row--editing', on);
        if (idEl) {
            idEl.value = on ? (tr.getAttribute('data-pp-state') || '0') : '0';
        }
        if (on) {
            const name = tr.querySelector('.pp-tp-editing input[type="text"]');
            if (name) { name.focus(); name.select(); }
        }
    }
    function closeAllEdits(body) {
        body.querySelectorAll('tr.pp-tp-row--editing').forEach(function (tr) { editRow(tr, false); });
    }

    ProjectPlusTypePhases.init = function () {
        const root = document.getElementById('projectplus-typephases');
        if (!root) {
            return;
        }

        const body = root.querySelector('[data-pp-role="tp-rows"]');
        if (!body) {
            return;
        }

        Array.prototype.forEach.call(body.querySelectorAll('tr'), paintRow);

        // Delegação: um listener só, e continua valendo para linha movida.
        body.addEventListener('click', function (ev) {
            const editBtn = ev.target.closest('[data-pp-edit]');
            if (editBtn) {
                ev.preventDefault();
                closeAllEdits(body);
                const tr = editBtn.closest('tr');
                if (tr) { editRow(tr, true); }
                return;
            }
            const cancelBtn = ev.target.closest('[data-pp-edit-cancel]');
            if (cancelBtn) {
                ev.preventDefault();
                const tr = cancelBtn.closest('tr');
                if (tr) { editRow(tr, false); }
                return;
            }
            const btn = ev.target.closest('[data-pp-move]');
            if (!btn) {
                return;
            }
            ev.preventDefault();
            const tr = btn.closest('tr');
            if (tr) {
                move(tr, btn.getAttribute('data-pp-move'));
            }
        });

        body.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                const tr = ev.target.closest('tr.pp-tp-row--editing');
                if (tr) { ev.preventDefault(); editRow(tr, false); }
            }
        });

        body.addEventListener('change', function (ev) {
            const box = ev.target;
            if (box && box.type === 'checkbox' && box.name === 'states[]') {
                const tr = box.closest('tr');
                if (tr) {
                    paintRow(tr);
                }
            }
        });
    };

    // Exposto só para os testes isolados (jsdom).
    ProjectPlusTypePhases._test = { move: move, paintRow: paintRow, editRow: editRow };

    window.ProjectPlusTypePhases = ProjectPlusTypePhases;
})();

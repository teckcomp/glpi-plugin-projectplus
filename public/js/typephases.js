/**
 * ProjectPlus — tela "Fases por tipo de projeto" (Etapa 9; layout novo no Bloco N-8c).
 *
 * A ordem das colunas do Kanban é a ordem das linhas na tela: o navegador
 * envia os `states[]` LIGADOS na ORDEM DO DOM, então mover a <tr> já muda a
 * `ordem` gravada — sem campo numérico e sem estado paralelo em JavaScript.
 *
 * N-8c: a tabela tem dois grupos separados por uma linha `tp-sep` — antes
 * dela as fases do conjunto (numeradas, com ↑/↓), depois as de fora.
 * Ligar o interruptor "Usar" leva a linha para o FIM do conjunto; desligar
 * leva para o começo do grupo de fora. ↑/↓ nunca atravessam o separador.
 * Toda mudança renumera e redesenha a "Prévia das colunas".
 *
 * DEGRADAÇÃO: sem JavaScript a tela continua gravando (interruptores são
 * checkboxes; a ordem fica a que já estava).
 *
 * SEM STRINGS PRÓPRIAS: os rótulos da prévia vêm em data-label-* do Twig, já
 * traduzidos. Não entra em src/I18nJs.php. Carregado só nesta tela.
 */
(function () {
    'use strict';

    const ProjectPlusTypePhases = {};

    function useBox(tr) {
        return tr ? tr.querySelector('input[name="states[]"]') : null;
    }

    function isPhaseRow(tr) {
        return !!(tr && tr.hasAttribute && tr.hasAttribute('data-pp-state'));
    }

    function isOn(tr) {
        const box = useBox(tr);
        return !!(box && box.checked);
    }

    /** Reflete "ligada / desligada" na linha, só para leitura visual. */
    function paintRow(tr) {
        if (!useBox(tr)) {
            return;
        }
        tr.classList.toggle('pp-tp-row--off', !isOn(tr));
    }

    function separator(body) {
        return body.querySelector('[data-pp-role="tp-sep"]');
    }

    /**
     * Move a linha uma posição dentro do grupo LIGADO. `insertBefore`
     * preserva o estado dos campos (o elemento é movido, não recriado).
     */
    function move(tr, dir) {
        const body = tr.parentNode;
        if (!body || !isOn(tr)) {
            return false;
        }
        if (dir === 'up') {
            const prev = tr.previousElementSibling;
            if (isPhaseRow(prev) && isOn(prev)) {
                body.insertBefore(tr, prev);
                return true;
            }
            return false;
        }
        const next = tr.nextElementSibling;
        if (isPhaseRow(next) && isOn(next)) {
            body.insertBefore(next, tr);
            return true;
        }
        return false;
    }

    /** Ligou: vai para o fim do conjunto (antes do separador). Desligou: logo depois dele. */
    function placeByUse(tr) {
        const body = tr.parentNode;
        const sep = body ? separator(body) : null;
        if (!sep) {
            return;
        }
        if (isOn(tr)) {
            body.insertBefore(tr, sep);
        } else {
            body.insertBefore(tr, sep.nextSibling);
        }
    }

    /** Números 1..N nas ligadas, "—" nas de fora; ↑ da primeira e ↓ da última desabilitados. */
    function renumber(body) {
        const on = [];
        Array.prototype.forEach.call(body.querySelectorAll('tr[data-pp-state]'), function (tr) {
            const num = tr.querySelector('[data-pp-role="tp-num"]');
            const ligada = isOn(tr);
            if (ligada) {
                on.push(tr);
            }
            if (num) {
                num.textContent = ligada ? String(on.length) : '—';
            }
            tr.querySelectorAll('[data-pp-move]').forEach(function (b) { b.disabled = !ligada; });
        });
        if (on.length) {
            const up = on[0].querySelector('[data-pp-move="up"]');
            const down = on[on.length - 1].querySelector('[data-pp-move="down"]');
            if (up) { up.disabled = true; }
            if (down) { down.disabled = true; }
        }
        return on;
    }

    /** Redesenha a prévia das colunas a partir das linhas ligadas, na ordem da tela. */
    function renderPreview(root, onRows) {
        const box = root.querySelector('[data-pp-role="tp-preview"]');
        if (!box) {
            return;
        }
        const lblCol = box.getAttribute('data-label-col') || '%d';
        const lblFin = box.getAttribute('data-label-fin') || '';
        while (box.firstChild) {
            box.removeChild(box.firstChild);
        }
        if (!onRows.length) {
            const empty = document.createElement('span');
            empty.className = 'pp-rp-none';
            empty.textContent = box.getAttribute('data-label-empty') || '';
            box.appendChild(empty);
            return;
        }
        onRows.forEach(function (tr, i) {
            const col = document.createElement('div');
            col.className = 'pp-tp-col';
            const color = tr.getAttribute('data-pp-color') || '';
            if (color) {
                col.style.setProperty('--pp-phase-color', color);
            }
            const b = document.createElement('b');
            b.textContent = tr.getAttribute('data-pp-name') || '';
            const s = document.createElement('span');
            s.textContent = tr.getAttribute('data-pp-finished') === '1'
                ? lblFin
                : lblCol.replace('%d', String(i + 1));
            col.appendChild(b);
            col.appendChild(s);
            box.appendChild(col);
        });
    }

    function refresh(root, body) {
        renderPreview(root, renumber(body));
    }

    /**
     * Bloco K (21/10/2026): edição inline de uma fase (nome, cor, finalizadora).
     * Uma linha por vez. Os campos pertencem ao form #pp-tp-editform (atributo
     * form=) e nascem disabled; abrir a edição habilita só os da linha e grava
     * o id no hidden do form. Cancelar restaura os valores originais.
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

        // "Novo tipo" / "Nova fase" do cabeçalho levam ao campo e dão foco.
        root.addEventListener('click', function (ev) {
            const a = ev.target.closest('[data-pp-focus]');
            if (!a) {
                return;
            }
            const field = document.getElementById(a.getAttribute('data-pp-focus'));
            if (field) {
                ev.preventDefault();
                if (typeof field.scrollIntoView === 'function') {
                    field.scrollIntoView({ block: 'center' });
                }
                field.focus();
            }
        });

        const body = root.querySelector('[data-pp-role="tp-rows"]');
        if (!body) {
            return;
        }

        Array.prototype.forEach.call(body.querySelectorAll('tr[data-pp-state]'), paintRow);
        refresh(root, body);

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
            if (tr && move(tr, btn.getAttribute('data-pp-move'))) {
                refresh(root, body);
                btn.focus();
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
            if (box && box.name === 'states[]') {
                const tr = box.closest('tr');
                if (tr) {
                    paintRow(tr);
                    placeByUse(tr);
                    refresh(root, body);
                    box.focus();
                }
            }
        });
    };

    // Exposto só para os testes isolados (jsdom).
    ProjectPlusTypePhases._test = {
        move: move, paintRow: paintRow, editRow: editRow,
        placeByUse: placeByUse, renumber: renumber, renderPreview: renderPreview,
    };

    window.ProjectPlusTypePhases = ProjectPlusTypePhases;
})();

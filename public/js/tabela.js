/**
 * Visão "Tabela principal": recolher grupos e mudar a situação direto na linha.
 * A mudança usa o mesmo endpoint do Kanban (front/ajax.php), então vale a mesma regra da ficha: se for recusada,
 * a linha volta ao que era e o motivo aparece num aviso. Em caso de sucesso a lista de próximas situações é refeita.
 */
(() => {
    const table = document.querySelector('.mkt-table[data-endpoint]');
    if (!table) return;
    const endpoint = table.dataset.endpoint;
    const csrf = () => (typeof window.getAjaxCsrfToken === 'function' ? window.getAjaxCsrfToken() : '');

    function flash(text, ok) {
        let el = document.querySelector('.mkt-flash');
        if (!el) {
            el = document.createElement('div');
            el.style.cssText = 'position:fixed;left:50%;top:1rem;transform:translateX(-50%);z-index:2100;max-width:90vw;box-shadow:0 8px 24px rgba(0,0,0,.25)';
            document.body.appendChild(el);
        }
        el.className = `alert mkt-flash ${ok ? 'alert-success' : 'alert-danger'}`;
        el.textContent = text;
        el.hidden = false;
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.hidden = true; }, 5000);
    }

    // recolher e expandir grupos
    table.addEventListener('click', (ev) => {
        const btn = ev.target.closest('[data-toggle]');
        if (!btn) return;
        const grp = btn.closest('.mkt-grp');
        const closed = grp.classList.toggle('closed');
        btn.setAttribute('aria-expanded', closed ? 'false' : 'true');
    });

    function rebuild(select, data) {
        select.innerHTML = '';
        const add = (key, label, color, selected) => {
            const o = document.createElement('option');
            o.value = key; o.textContent = label; o.dataset.color = color;
            if (selected) o.selected = true;
            select.appendChild(o);
        };
        add(data.status, data.label, data.color, true);
        (data.next || []).forEach((n) => add(n.key, n.label, n.color, false));
        select.style.setProperty('--c', data.color);
        select.dataset.prev = data.status;
        if (!(data.next || []).length) {
            const span = document.createElement('span');
            span.className = 'mkt-st ro';
            span.style.setProperty('--c', data.color);
            span.textContent = data.label;
            select.replaceWith(span);
        }
    }

    table.addEventListener('focusin', (ev) => {
        const s = ev.target.closest('select.mkt-st');
        if (s && !s.dataset.prev) s.dataset.prev = s.value;
    });

    table.addEventListener('change', async (ev) => {
        const select = ev.target.closest('select.mkt-st');
        if (!select) return;
        const prev = select.dataset.prev || select.querySelector('option[selected]')?.value;
        const to = select.value;
        select.disabled = true;
        try {
            const res = await fetch(endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': csrf(), 'Accept': 'application/json'},
                body: new URLSearchParams({do: 'transition', id: select.dataset.id, to}).toString(),
            });
            const data = await res.json().catch(() => ({ok: false, error: 'Resposta inválida do servidor.'}));
            if (!data.ok) throw new Error(data.error || 'Não foi possível mudar a situação.');
            const row = select.closest('.mkt-row');
            const fill = row.querySelector('.mkt-tl span');
            if (fill) fill.style.width = `${data.progress}%`;
            rebuild(select, data);
            flash(`Situação alterada para "${data.label}".`, true);
        } catch (e) {
            select.value = prev;
            select.style.setProperty('--c', select.querySelector(`option[value="${prev}"]`)?.dataset.color || '');
            flash(e.message, false);
        } finally {
            select.disabled = false;
        }
    });
})();

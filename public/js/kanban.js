/**
 * Quadro Kanban: arrastar um cartão para outra coluna muda a situação da demanda (mesmas regras da ficha:
 * transições permitidas, briefing completo para planejar, aprovador para enviar a aprovação etc.).
 * Se o servidor recusar, o cartão volta e o motivo aparece num aviso.
 */
(() => {
    const board = document.querySelector('.mkt-board[data-endpoint]');
    if (!board) return;
    const endpoint = board.dataset.endpoint;
    const canDrag = board.dataset.drag === '1';
    let dragged = null;
    let origin = null;

    const csrf = () => (typeof window.getAjaxCsrfToken === 'function' ? window.getAjaxCsrfToken() : '');

    function flash(text, ok) {
        let el = document.querySelector('.mkt-flash');
        if (!el) {
            el = document.createElement('div');
            el.className = 'alert mkt-flash';
            el.style.cssText = 'position:fixed;left:50%;top:1rem;transform:translateX(-50%);z-index:2100;max-width:90vw;box-shadow:0 8px 24px rgba(0,0,0,.25)';
            document.body.appendChild(el);
        }
        el.className = `alert mkt-flash ${ok ? 'alert-success' : 'alert-danger'}`;
        el.textContent = text;
        el.hidden = false;
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.hidden = true; }, 5000);
    }

    function updateCounts() {
        board.querySelectorAll('.mkt-col').forEach((col) => {
            col.querySelector('.n').textContent = col.querySelectorAll('.mkt-card').length;
        });
    }

    if (!canDrag) return;

    board.addEventListener('dragstart', (ev) => {
        const card = ev.target.closest('.mkt-card');
        if (!card) return;
        dragged = card;
        origin = card.parentElement;
        card.classList.add('dragging');
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', card.dataset.id);
    });
    board.addEventListener('dragend', () => {
        dragged?.classList.remove('dragging');
        board.querySelectorAll('.mkt-col.over').forEach((c) => c.classList.remove('over'));
        dragged = null;
    });
    board.addEventListener('dragover', (ev) => {
        const col = ev.target.closest('.mkt-col');
        if (!col || !dragged) return;
        ev.preventDefault();
        board.querySelectorAll('.mkt-col.over').forEach((c) => { if (c !== col) c.classList.remove('over'); });
        col.classList.add('over');
    });
    board.addEventListener('drop', async (ev) => {
        const col = ev.target.closest('.mkt-col');
        if (!col || !dragged) return;
        ev.preventDefault();
        col.classList.remove('over');
        const card = dragged;
        const body = col.querySelector('.mkt-col-body');
        if (body === origin) return;
        const from = origin;
        body.appendChild(card);
        updateCounts();
        const form = new URLSearchParams({do: 'transition', id: card.dataset.id, to: col.dataset.status});
        try {
            const res = await fetch(endpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': csrf(), 'Accept': 'application/json'},
                body: form.toString(),
            });
            const data = await res.json().catch(() => ({ok: false, error: 'Resposta inválida do servidor.'}));
            if (!data.ok) throw new Error(data.error || 'Não foi possível mudar a situação.');
            card.style.setProperty('--c', getComputedStyle(col).getPropertyValue('--c'));
            flash(`Situação alterada para "${data.label}".`, true);
        } catch (e) {
            from.appendChild(card);
            updateCounts();
            flash(e.message, false);
        }
    });
})();

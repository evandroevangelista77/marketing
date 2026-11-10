/**
 * Projetos e planos: árvore da visão em tabela (projeto → planos → atividades), recolher grupos e busca rápida.
 */
(() => {
    const root = document.querySelector('.mkt-dash, .mkt-ptable, .mkt-pgroup');
    if (!root) return;

    // recolher/expandir grupos (pilares) e ramos da árvore
    document.addEventListener('click', (ev) => {
        const grpBtn = ev.target.closest('.mkt-ptable [data-toggle]');
        if (grpBtn) {
            const closed = grpBtn.closest('.mkt-grp').classList.toggle('closed');
            grpBtn.setAttribute('aria-expanded', closed ? 'false' : 'true');
            return;
        }
        const exp = ev.target.closest('[data-exp]');
        if (exp) {
            const node = exp.closest('.mkt-node');
            const branch = node && node.querySelector(':scope > .mkt-branch');
            if (!branch) return;
            const open = branch.hidden;
            branch.hidden = !open;
            node.classList.toggle('open', open);
            exp.setAttribute('aria-expanded', open ? 'true' : 'false');
            return;
        }
        if (ev.target.closest('[data-expand-all]') || ev.target.closest('[data-collapse-all]')) {
            const open = !!ev.target.closest('[data-expand-all]');
            document.querySelectorAll('.mkt-ptable .mkt-node').forEach((n) => {
                const b = n.querySelector(':scope > .mkt-branch');
                const x = n.querySelector(':scope > .mkt-row [data-exp]');
                if (!b || !x) return;
                b.hidden = !open;
                n.classList.toggle('open', open);
                x.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }
    });

    // busca rápida: filtra cartões ou linhas da tabela; na tabela, abre a árvore dos projetos que casam
    const q = document.getElementById('mkt-q');
    if (q) {
        q.addEventListener('input', () => {
            const term = q.value.trim().toLowerCase();
            document.querySelectorAll('[data-q]').forEach((el) => {
                el.hidden = term !== '' && !el.dataset.q.includes(term);
            });
            document.querySelectorAll('[data-q-group]').forEach((g) => {
                g.hidden = term !== '' && !g.querySelector('[data-q]:not([hidden])');
            });
        });
        q.closest('form').addEventListener('submit', (ev) => { if (document.activeElement === q) ev.preventDefault(); });
    }
})();

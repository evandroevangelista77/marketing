/**
 * Tema das páginas do plugin: Automático (segue o GLPI), Diurno (claro) ou Noturno (escuro).
 * A preferência vem de <meta name="mkt-theme"> (gravada por usuário no servidor) e é aplicada no <html> antes do conteúdo.
 *  - Diurno: tira os atributos do tema escuro do GLPI (data-glpi-theme / data-glpi-theme-dark) e marca data-bs-theme="light",
 *    o que devolve a aparência clara padrão do GLPI; a classe mkt-light esconde a marca d'água e ajusta o que for do plugin.
 *  - Noturno: se o GLPI do usuário for claro, aplica a paleta escura do plugin (classe mkt-dark).
 * Os botões [data-mkt-theme] do topo das páginas trocam o tema na hora e gravam a escolha.
 */
(() => {
    const html = document.documentElement;
    const meta = document.querySelector('meta[name="mkt-theme"]');
    const orig = {
        glpiTheme: html.getAttribute('data-glpi-theme'),
        glpiDark: html.getAttribute('data-glpi-theme-dark'),
        bs: html.getAttribute('data-bs-theme'),
    };

    function setAttr(name, value) {
        if (value === null || value === undefined) html.removeAttribute(name); else html.setAttribute(name, value);
    }

    function apply(pref) {
        html.classList.remove('mkt-light', 'mkt-dark');
        if (pref === 'light') {
            html.removeAttribute('data-glpi-theme');
            html.removeAttribute('data-glpi-theme-dark');
            html.setAttribute('data-bs-theme', 'light');
            html.classList.add('mkt-light');
        } else {
            setAttr('data-glpi-theme', orig.glpiTheme);
            setAttr('data-glpi-theme-dark', orig.glpiDark);
            setAttr('data-bs-theme', orig.bs);
            if (pref === 'dark' && orig.glpiDark === null) {
                html.setAttribute('data-bs-theme', 'dark');
                html.classList.add('mkt-dark');
            }
        }
        document.querySelectorAll('[data-mkt-theme]').forEach((b) => b.classList.toggle('on', b.dataset.mktTheme === pref));
    }

    let current = (meta && meta.content) || 'auto';
    apply(current);
    document.addEventListener('DOMContentLoaded', () => apply(current));

    document.addEventListener('click', async (ev) => {
        const btn = ev.target.closest('[data-mkt-theme]');
        if (!btn) return;
        const pref = btn.dataset.mktTheme;
        if (pref === current) return;
        current = pref;
        apply(pref);
        try {
            const root = (window.CFG_GLPI && window.CFG_GLPI.root_doc) || '';
            await fetch(`${root}/plugins/marketing/front/theme.php`, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest',
                    'X-Glpi-Csrf-Token': typeof window.getAjaxCsrfToken === 'function' ? window.getAjaxCsrfToken() : '', 'Accept': 'application/json'},
                body: new URLSearchParams({key: 'theme', value: pref}).toString(),
            });
        } catch (e) { /* a escolha vale nesta página mesmo sem rede */ }
        // os gráficos do painel desenham com as cores do tema: recarrega para redesenhar
        if (document.querySelector('[data-mkt-chart]')) location.reload();
    });
})();

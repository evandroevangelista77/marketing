/** Seletores encadeados Pilar → Projeto → Plano de ação: cada nível mostra só o que pertence ao nível de cima. */
(() => {
    document.querySelectorAll('.mkt-structure').forEach((box) => {
        const pillar = box.querySelector('[data-lvl=pillar]');
        const project = box.querySelector('[data-lvl=project]');
        const plan = box.querySelector('[data-lvl=plan]');
        if (!pillar || !project || !plan) return;

        const filter = (select, attr, value) => {
            [...select.options].forEach((o) => {
                if (!o.value) return;
                const ok = !value || o.dataset[attr] === value;
                o.hidden = !ok;
                o.disabled = !ok;
            });
            const cur = select.selectedOptions[0];
            if (cur && cur.value && cur.disabled) select.value = '';
        };
        const refresh = () => {
            filter(project, 'pillar', pillar.value);
            filter(plan, 'project', project.value);
        };
        pillar.addEventListener('change', refresh);
        project.addEventListener('change', () => {
            // escolher um projeto já define o pilar dele
            const o = project.selectedOptions[0];
            if (o && o.dataset.pillar && o.dataset.pillar !== '0') pillar.value = o.dataset.pillar;
            refresh();
        });
        plan.addEventListener('change', () => {
            const o = plan.selectedOptions[0];
            if (o && o.dataset.project && o.dataset.project !== '0') {
                project.value = o.dataset.project;
                const po = project.selectedOptions[0];
                if (po && po.dataset.pillar && po.dataset.pillar !== '0') pillar.value = po.dataset.pillar;
            }
            refresh();
        });
        refresh();
    });
})();

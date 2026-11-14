/**
 * KPI's → Visão geral: medidor do índice, pontuação por grupo, evolução, tendências principais, mapa de calor e minigráficos
 * (Apache ECharts, que acompanha o GLPI). Os dados vêm do JSON #mkt-kpi-data.
 */
(() => {
    const node = document.getElementById('mkt-kpi-data');
    if (!node) return;
    const D = JSON.parse(node.textContent);
    // corta os meses iniciais sem nenhum dado (mantém pelo menos 6 meses) para os gráficos não começarem vazios
    (() => {
        const n = D.labels.length;
        let first = 0;
        while (first < n - 6 && D.monthly && D.monthly[first] === null && (D.series?.entradas?.[first] || 0) === 0 && (D.series?.backlog?.[first] || 0) === 0) first++;
        if (first === 0) return;
        const cut = (a) => a.slice(first);
        D.labels = cut(D.labels);
        D.monthly = D.monthly ? cut(D.monthly) : D.monthly;
        Object.keys(D.series || {}).forEach((k) => { D.series[k] = cut(D.series[k]); });
        Object.keys(D.spark || {}).forEach((k) => { D.spark[k] = cut(D.spark[k]); });
        (D.groupsMonthly || []).forEach((g) => { g.values = cut(g.values); });
    })();

    // ---- gaveta lateral: cartões, números-chave, destaques, mapa de calor e botão "Ver itens"
    document.addEventListener('click', async (ev) => {
        const el = ev.target.closest('.mkt-drill');
        if (!el || !window.OakDrawer) return;
        ev.preventDefault();
        const q = new URLSearchParams();
        if (el.dataset.k) { q.set('k', el.dataset.k); q.set('from', D.from); q.set('to', D.to); }
        else if (el.dataset.g) { q.set('g', el.dataset.g); q.set('m', el.dataset.m); }
        else return;
        OakDrawer.open({title: 'Carregando…', icon: 'ti-loader', color: BLUE, html: '<div class="text-secondary">Buscando os itens…</div>'});
        try {
            const res = await fetch(`${D.drill}?${q}`, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            const data = await res.json();
            if (!data.ok) throw new Error('negado');
            OakDrawer.open({title: data.title, icon: data.icon, color: data.color, html: `<div class="text-secondary small mb-2">${data.sub}</div>${data.html}`});
        } catch (e) {
            OakDrawer.open({title: 'Não foi possível carregar', icon: 'ti-alert-triangle', color: '#dc2626', html: '<div class="text-secondary">Tente novamente em instantes.</div>'});
        }
    });
    const RED = '#dc2626', AMBER = '#f59e0b', GREEN = '#16a34a', BLUE = '#2563eb', SLATE = '#94a3b8';
    const idxColor = (s) => (s === null || s === undefined ? SLATE : s >= 85 ? GREEN : s >= 65 ? AMBER : RED);
    const hex2rgba = (h, a) => { const n = parseInt(h.slice(1), 16); return `rgba(${(n >> 16) & 255},${(n >> 8) & 255},${n & 255},${a})`; };

    function init() {
        if (typeof echarts === 'undefined') return false;
        const text = getComputedStyle(document.body).color || '#334155';
        const muted = 'rgba(148,163,184,.95)';
        const grid = 'rgba(148,163,184,.22)';
        const charts = [];
        const mk = (id, opt) => { const el = document.getElementById(id); if (!el) return null; const c = echarts.init(el, null, {renderer: 'canvas'}); c.setOption(opt); charts.push(c); return c; };
        const axisBase = {
            x: {type: 'category', data: D.labels, axisLine: {lineStyle: {color: grid}}, axisTick: {show: false}, axisLabel: {color: muted, fontSize: 10, interval: 0, hideOverlap: true}},
        };
        const tip = {trigger: 'axis', confine: true, backgroundColor: 'rgba(15,23,42,.92)', borderWidth: 0, textStyle: {color: '#f1f5f9', fontSize: 12}};
        const yVal = (extra = {}) => Object.assign({type: 'value', axisLabel: {color: muted, fontSize: 10}, splitLine: {lineStyle: {color: grid}}, axisLine: {show: false}}, extra);

        if (document.getElementById('kpi-gauge')) {
        // ---- índice geral (medidor com faixas de cor)
        mk('kpi-gauge', {series: [
            {type: 'gauge', startAngle: 200, endAngle: -20, min: 0, max: 100, radius: '98%', center: ['50%', '62%'],
                axisLine: {lineStyle: {width: 16, color: [[0.65, hex2rgba(RED, 0.30)], [0.85, hex2rgba(AMBER, 0.34)], [1, hex2rgba(GREEN, 0.34)]]}},
                progress: {show: false}, pointer: {show: true, length: '62%', width: 5, itemStyle: {color: text}}, anchor: {show: true, size: 12, itemStyle: {color: text}},
                axisTick: {show: false}, splitLine: {show: false},
                axisLabel: {distance: 22, color: muted, fontSize: 10, formatter: (v) => ([0, 65, 85, 100].includes(v) ? v : '')},
                detail: {valueAnimation: true, fontSize: 38, fontWeight: 700, offsetCenter: [0, '52%'], color: idxColor(D.overall), formatter: () => (D.overall === null ? '—' : Math.round(D.overall))},
                title: {show: false}, data: [{value: D.overall === null ? 0 : D.overall}]},
            {type: 'gauge', startAngle: 200, endAngle: -20, min: 0, max: 100, radius: '98%', center: ['50%', '62%'], pointer: {show: false}, axisLine: {show: false}, axisTick: {show: false}, splitLine: {show: false}, axisLabel: {show: false}, detail: {show: false},
                progress: {show: true, width: 16, roundCap: false, itemStyle: {color: idxColor(D.overall)}}, data: [{value: D.overall === null ? 0 : D.overall}]},
        ]});

        // ---- pontuação por grupo (radar: período atual x anterior)
        const wrap = (n) => (n.length > 16 ? n.replace(' e ', ' e\n').replace(' de ', ' de\n').replace(' dos ', ' dos\n') : n);
        mk('kpi-radar', {
            tooltip: {confine: true, backgroundColor: 'rgba(15,23,42,.92)', borderWidth: 0, textStyle: {color: '#f1f5f9'}},
            radar: {center: ['50%', '52%'], radius: '66%', shape: 'polygon', startAngle: 90, splitNumber: 4,
                indicator: D.groups.map((g) => ({name: g.name, max: 100})), axisName: {color: text, fontSize: 11, fontWeight: 600, formatter: wrap},
                axisLine: {lineStyle: {color: grid}}, splitLine: {lineStyle: {color: grid}},
                splitArea: {areaStyle: {color: ['rgba(220,38,38,.07)', 'rgba(245,158,11,.06)', 'rgba(22,163,74,.05)', 'rgba(22,163,74,.09)']}}},
            series: [{type: 'radar', symbol: 'circle', symbolSize: 7, data: [
                {name: 'Período anterior', value: D.groups.map((g) => (g.prev === null ? 0 : g.prev)), lineStyle: {color: SLATE, type: 'dashed', width: 1.6}, itemStyle: {color: SLATE}, areaStyle: {color: 'rgba(148,163,184,.08)'}},
                {name: 'Período atual', value: D.groups.map((g) => (g.score === null ? 0 : g.score)), lineStyle: {color: BLUE, width: 2.6}, itemStyle: {color: BLUE, borderColor: '#fff', borderWidth: 1.5}, areaStyle: {color: 'rgba(37,99,235,.24)'},
                    label: {show: true, color: text, fontSize: 11, fontWeight: 700, distance: 4, formatter: (p) => Math.round(p.value)}},
            ]}],
        });

        // ---- evolução do índice com faixas
        mk('kpi-trend', {
            grid: {left: 36, right: 16, top: 16, bottom: 26}, tooltip: tip, xAxis: axisBase.x, yAxis: yVal({min: 0, max: 100, interval: 25}),
            series: [{type: 'line', smooth: 0.25, data: D.monthly, connectNulls: false, symbol: 'circle', symbolSize: 8, lineStyle: {width: 3, color: BLUE}, itemStyle: {color: BLUE, borderColor: '#fff', borderWidth: 2},
                areaStyle: {color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{offset: 0, color: hex2rgba(BLUE, 0.28)}, {offset: 1, color: hex2rgba(BLUE, 0.02)}])},
                label: {show: true, position: 'top', color: text, fontSize: 10, fontWeight: 600, formatter: (p) => (p.value === null ? '' : Math.round(p.value))},
                markArea: {silent: true, data: [[{yAxis: 0, itemStyle: {color: hex2rgba(RED, 0.06)}}, {yAxis: 65}], [{yAxis: 65, itemStyle: {color: hex2rgba(AMBER, 0.07)}}, {yAxis: 85}], [{yAxis: 85, itemStyle: {color: hex2rgba(GREEN, 0.08)}}, {yAxis: 100}]]},
                markLine: {silent: true, symbol: 'none', lineStyle: {color: GREEN, type: 'dashed', width: 1}, label: {formatter: 'meta 85', color: GREEN, fontSize: 10}, data: [{yAxis: 85}]}}],
        });

        // ---- tendências principais
        const S = D.series;
        mk('kpi-flow', {
            grid: {left: 36, right: 12, top: 28, bottom: 26}, tooltip: tip, legend: {top: 0, right: 0, textStyle: {color: muted, fontSize: 11}, itemWidth: 10, itemHeight: 10},
            xAxis: axisBase.x, yAxis: yVal(),
            series: [
                {name: 'Recebidas', type: 'bar', barMaxWidth: 16, data: S.entradas, itemStyle: {color: '#6366f1', borderRadius: [4, 4, 0, 0]}},
                {name: 'Concluídas', type: 'bar', barMaxWidth: 16, data: S.concluidas, itemStyle: {color: GREEN, borderRadius: [4, 4, 0, 0]}},
            ],
        });
        mk('kpi-otd', {
            grid: {left: 40, right: 12, top: 14, bottom: 26}, tooltip: Object.assign({}, tip, {valueFormatter: (v) => (v === null ? '—' : v + '%')}), xAxis: axisBase.x, yAxis: yVal({min: 0, max: 100, axisLabel: {color: muted, fontSize: 10, formatter: '{value}%'}}),
            series: [{name: 'No prazo', type: 'line', smooth: 0.2, data: S.otd, symbolSize: 8, lineStyle: {width: 3, color: GREEN}, itemStyle: {color: GREEN, borderColor: '#fff', borderWidth: 2},
                areaStyle: {color: hex2rgba(GREEN, 0.10)}, label: {show: true, position: 'top', color: text, fontSize: 10, formatter: (p) => (p.value === null ? '' : Math.round(p.value) + '%')},
                markLine: {silent: true, symbol: 'none', lineStyle: {color: GREEN, type: 'dashed'}, label: {formatter: 'meta ' + (D.targets.otd ?? 90) + '%', color: GREEN, fontSize: 10}, data: [{yAxis: D.targets.otd ?? 90}]}}],
        });
        mk('kpi-backlog', {
            grid: {left: 36, right: 12, top: 14, bottom: 26}, tooltip: tip, xAxis: axisBase.x, yAxis: yVal(),
            series: [{name: 'Backlog', type: 'line', smooth: 0.2, data: S.backlog, symbolSize: 7, lineStyle: {width: 3, color: '#f97316'}, itemStyle: {color: '#f97316', borderColor: '#fff', borderWidth: 2},
                areaStyle: {color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{offset: 0, color: hex2rgba('#f97316', 0.30)}, {offset: 1, color: hex2rgba('#f97316', 0.02)}])},
                label: {show: true, position: 'top', color: text, fontSize: 10, formatter: (p) => (p.value === null ? '' : Math.round(p.value))}}],
        });
        mk('kpi-late', {
            grid: {left: 40, right: 12, top: 28, bottom: 26}, tooltip: Object.assign({}, tip, {valueFormatter: (v) => (v === null ? '—' : v + '%')}), legend: {top: 0, right: 0, textStyle: {color: muted, fontSize: 11}, itemWidth: 10, itemHeight: 10},
            xAxis: axisBase.x, yAxis: yVal({axisLabel: {color: muted, fontSize: 10, formatter: '{value}%'}}),
            series: [
                {name: 'Atrasadas', type: 'line', smooth: 0.2, data: S.atrasadas_pct, symbolSize: 7, lineStyle: {width: 3, color: RED}, itemStyle: {color: RED}, areaStyle: {color: hex2rgba(RED, 0.08)}},
                {name: 'Adiadas', type: 'line', smooth: 0.2, data: S.adiadas_pct, symbolSize: 7, lineStyle: {width: 3, color: '#d97706'}, itemStyle: {color: '#d97706'}},
            ],
        });

        }

        // ---- minigráficos dos cartões
        document.querySelectorAll('[data-spark]').forEach((el) => {
            const key = el.dataset.spark;
            const vals = D.spark[key] || [];
            if (vals.filter((v) => v !== null).length < 2) { el.innerHTML = '<div class="text-secondary small text-center pt-2" style="opacity:.6">sem série histórica</div>'; return; }
            const col = (getComputedStyle(el.closest('.mkt-kcard, .mkt-ind-row')).getPropertyValue('--c') || BLUE).trim();
            const target = D.targets[key];
            const c = echarts.init(el, null, {renderer: 'canvas'});
            c.setOption({
                grid: {left: 2, right: 6, top: 6, bottom: 2}, xAxis: {type: 'category', show: false, data: D.labels}, yAxis: {type: 'value', show: false, scale: true},
                tooltip: {trigger: 'axis', confine: true, backgroundColor: 'rgba(15,23,42,.92)', borderWidth: 0, textStyle: {color: '#f1f5f9', fontSize: 11}},
                series: [{type: 'line', smooth: 0.25, showSymbol: false, data: vals, connectNulls: true, lineStyle: {width: 2.2, color: col},
                    areaStyle: {color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{offset: 0, color: hex2rgba(col.startsWith('#') ? col : BLUE, 0.28)}, {offset: 1, color: hex2rgba(col.startsWith('#') ? col : BLUE, 0.02)}])},
                    markPoint: {symbol: 'circle', symbolSize: 7, itemStyle: {color: col, borderColor: '#fff', borderWidth: 1.5}, data: [{coord: [vals.length - 1, vals[vals.length - 1]]}], label: {show: false}},
                    markLine: target === null || target === undefined ? undefined : {silent: true, symbol: 'none', lineStyle: {color: SLATE, type: 'dashed', width: 1}, label: {show: false}, data: [{yAxis: target}]}}],
            });
            charts.push(c);
        });

        const ro = new ResizeObserver(() => charts.forEach((c) => c.resize()));
        ro.observe(document.querySelector('.page-body') || document.body);
        return true;
    }
    let tries = 0;
    (function wait() { if (!init() && tries++ < 40) setTimeout(wait, 150); })();
})();

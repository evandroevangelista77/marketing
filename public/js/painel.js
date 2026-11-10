/**
 * Gráficos executivos do Painel de Demandas (Apache ECharts, que o GLPI já traz em /lib/echarts.js).
 * Os dados vêm do JSON #mkt-chart-data (não é script executável, então passa na CSP). Clicar em uma fatia, coluna
 * ou barra abre a gaveta lateral (OakDrawer) com as demandas. As cores de texto e linhas seguem o tema do GLPI
 * (claro ou escuro) e os gráficos se redimensionam com o cartão.
 */
(() => {
    const dataEl = document.getElementById('mkt-chart-data');
    if (!dataEl) return;
    let D;
    try { D = JSON.parse(dataEl.textContent); } catch (e) { return; }

    // ---------- tema ----------
    const body = getComputedStyle(document.body);
    const rgb = (body.color.match(/\d+(\.\d+)?/g) || [30, 41, 59]).slice(0, 3).map(Number);
    const alpha = (a) => `rgba(${rgb[0]}, ${rgb[1]}, ${rgb[2]}, ${a})`;
    const TEXT = alpha(0.92);
    const MUTED = alpha(0.62);
    const GRID = alpha(0.1);
    const FONT = body.fontFamily || 'sans-serif';
    const dark = (rgb[0] + rgb[1] + rgb[2]) / 3 > 140; // texto claro = tema escuro
    const SURFACE = dark ? '#0f172a' : '#ffffff';

    const hex2rgba = (hex, a) => {
        const h = hex.replace('#', '');
        return `rgba(${parseInt(h.slice(0, 2), 16)}, ${parseInt(h.slice(2, 4), 16)}, ${parseInt(h.slice(4, 6), 16)}, ${a})`;
    };
    const vgrad = (c, a = 0.3, b = 0.01) => new echarts.graphic.LinearGradient(0, 0, 0, 1, [{offset: 0, color: hex2rgba(c, a)}, {offset: 1, color: hex2rgba(c, b)}]);
    const hgrad = (c) => new echarts.graphic.LinearGradient(0, 0, 1, 0, [{offset: 0, color: hex2rgba(c, 0.72)}, {offset: 1, color: c}]);
    const open = (key) => { if (key && window.OakDrawer) window.OakDrawer.fromTemplate(key); };
    const num = (v) => String(v).replace('.', ',');

    const base = {
        textStyle: {fontFamily: FONT, color: TEXT},
        animationDuration: 900,
        animationEasing: 'cubicOut',
        tooltip: {
            backgroundColor: dark ? 'rgba(15,23,42,.96)' : 'rgba(255,255,255,.98)',
            borderColor: GRID, borderWidth: 1, padding: [9, 13],
            textStyle: {color: dark ? '#e2e8f0' : '#1e293b', fontFamily: FONT, fontSize: 12},
            extraCssText: 'border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.38);',
        },
    };
    const axisBase = {
        axisLine: {lineStyle: {color: GRID}}, axisTick: {show: false},
        axisLabel: {color: MUTED, fontSize: 11}, splitLine: {lineStyle: {color: GRID, type: 'dashed'}},
    };
    const legendBase = {top: 0, right: 4, icon: 'circle', itemWidth: 9, itemHeight: 9, itemGap: 14, textStyle: {color: MUTED, fontSize: 12}};
    const empty = (msg) => ({...base, graphic: [{type: 'text', left: 'center', top: 'middle', style: {text: msg, fill: MUTED, fontSize: 13, fontFamily: FONT}}]});
    const sum = (items) => items.reduce((a, i) => a + i.value, 0);
    /** Gavetas por linha dos gráficos de barras horizontais: tipo de gráfico => lista alinhada ao eixo de categorias. */
    const rowDrawers = {};

    /** Barras horizontais finas com trilha e valor + participação. Maior no topo. */
    function hbar(items, unit = '', share = true, key = '') {
        if (!items.length || sum(items) === 0) return empty('Sem dados no período');
        const total = sum(items);
        const list = items.slice().reverse();
        return {
            ...base,
            grid: {left: 12, right: 64, top: 8, bottom: 8, containLabel: true},
            tooltip: {...base.tooltip, trigger: 'axis', axisPointer: {type: 'none'}, formatter: (p) => {
                const it = list[p[0].dataIndex];
                return `<b>${it.name}</b><br/>${p[0].marker} ${num(it.value)}${unit}${share ? ` &nbsp;·&nbsp; ${Math.round(it.value / total * 100)}%` : ''}${it.n ? `<br/><span style="opacity:.7">${it.n} demanda(s)</span>` : ''}`;
            }},
            xAxis: {type: 'value', show: false, max: (v) => v.max * 1.12},
            yAxis: {type: 'category', data: list.map((i) => i.name), axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: TEXT, fontSize: 12, width: 124, overflow: 'truncate'}},
            series: [{
                type: 'bar', barWidth: 10, showBackground: true, backgroundStyle: {color: alpha(0.06), borderRadius: 5},
                data: list.map((i) => ({value: i.value, drawer: i.drawer, itemStyle: {color: hgrad(i.color), borderRadius: 5}})),
                label: {show: true, position: 'right', color: TEXT, fontSize: 12, fontWeight: 700, formatter: (p) => `${num(p.value)}${unit}${share ? ' · ' + Math.round(p.value / total * 100) + '%' : ''}`},
                emphasis: {itemStyle: {shadowBlur: 10, shadowColor: 'rgba(0,0,0,.4)'}},
            }],
        };
    }

    // ---------- definições ----------
    const builders = {
        spark(el) {
            const values = D.spark[el.dataset.k] || [];
            const c = el.dataset.c || '#6366f1';
            return {
                animationDuration: 700,
                grid: {left: 0, right: 0, top: 4, bottom: 0},
                xAxis: {type: 'category', show: false, boundaryGap: false, data: values.map((_, i) => i)},
                yAxis: {type: 'value', show: false, min: 0},
                series: [{type: 'line', data: values, smooth: 0.4, symbol: 'none', lineStyle: {width: 2, color: c}, areaStyle: {color: vgrad(c, 0.35, 0)}, silent: true}],
            };
        },
        status() {
            const slices = D.status.filter((s) => s.value > 0);
            if (!slices.length) return empty('Sem demandas no período');
            return {
                ...base,
                tooltip: {...base.tooltip, trigger: 'item', formatter: (p) => `${p.marker} ${p.name}<br/><b style="font-size:15px">${p.value}</b> &nbsp;(${p.percent}%)`},
                graphic: [
                    {type: 'text', left: 'center', top: '40%', style: {text: String(D.total), fontSize: 38, fontWeight: 800, fill: TEXT, fontFamily: FONT}},
                    {type: 'text', left: 'center', top: '57%', style: {text: 'demandas', fontSize: 12, fill: MUTED, fontFamily: FONT}},
                ],
                series: [{
                    type: 'pie', radius: ['64%', '86%'], center: ['50%', '50%'], avoidLabelOverlap: true, padAngle: 2,
                    itemStyle: {borderRadius: 7, borderColor: SURFACE, borderWidth: 2},
                    label: {show: false}, labelLine: {show: false},
                    emphasis: {scaleSize: 7, itemStyle: {shadowBlur: 18, shadowColor: 'rgba(0,0,0,.35)'}},
                    data: slices.map((s) => ({name: s.name, value: s.value, drawer: s.drawer, itemStyle: {color: s.color}})),
                }],
            };
        },
        trend() {
            const w = D.weeks;
            if (!w.labels.length) return empty('Sem dados no período');
            const mk = (name, color, values) => ({
                name, type: 'line', smooth: 0.35, symbol: 'circle', symbolSize: 8, showSymbol: values.length <= 14, data: values,
                lineStyle: {width: 3, color}, itemStyle: {color, borderColor: SURFACE, borderWidth: 2},
                areaStyle: {color: vgrad(color, 0.3, 0.01)}, emphasis: {focus: 'series', scale: 1.4},
            });
            return {
                ...base,
                grid: {left: 8, right: 18, top: 44, bottom: 8, containLabel: true},
                legend: legendBase,
                tooltip: {...base.tooltip, trigger: 'axis', axisPointer: {type: 'line', lineStyle: {color: MUTED, type: 'dashed'}}},
                xAxis: {...axisBase, type: 'category', boundaryGap: false, data: w.labels, splitLine: {show: false}, name: 'semana iniciada em', nameLocation: 'middle', nameGap: 30, nameTextStyle: {color: MUTED, fontSize: 11}},
                yAxis: {...axisBase, type: 'value', minInterval: 1, axisLine: {show: false}},
                series: [mk('Entradas', '#6366f1', w.entries), mk('Concluídas', '#22c55e', w.done)],
            };
        },
        stages() {
            if (!D.stages.length) return empty('Ainda sem histórico de etapas');
            const items = D.stages.slice().sort((a, b) => b.value - a.value);
            return hbar(items, ' d', false, 'stages');
        },
        aging() {
            if (sum(D.aging) === 0) return empty('Nenhuma demanda em aberto');
            return {
                ...base,
                grid: {left: 6, right: 6, top: 30, bottom: 4, containLabel: true},
                tooltip: {...base.tooltip, trigger: 'axis', axisPointer: {type: 'shadow', shadowStyle: {color: alpha(0.05)}}},
                xAxis: {...axisBase, type: 'category', data: D.aging.map((a) => a.name), splitLine: {show: false}},
                yAxis: {...axisBase, type: 'value', minInterval: 1, axisLine: {show: false}},
                series: [{
                    type: 'bar', barWidth: '52%', data: D.aging.map((a) => ({value: a.value, drawer: a.drawer, itemStyle: {color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [{offset: 0, color: a.color}, {offset: 1, color: hex2rgba(a.color, 0.55)}]), borderRadius: [8, 8, 0, 0]}})),
                    label: {show: true, position: 'top', fontWeight: 800, fontSize: 13, color: TEXT},
                    emphasis: {itemStyle: {shadowBlur: 14, shadowColor: 'rgba(0,0,0,.35)'}},
                }],
            };
        },
        score() {
            const g = D.gauges.slice().reverse(); // o primeiro indicador fica no anel de fora
            return {
                ...base,
                tooltip: {...base.tooltip, trigger: 'item', formatter: (p) => `<b>${g[p.dataIndex].name}</b><br/>${p.marker} ${g[p.dataIndex].value === null ? 'sem dados' : g[p.dataIndex].value + '%'}`},
                graphic: [
                    {type: 'text', left: 'center', top: '41%', style: {text: D.score_index === null ? '—' : D.score_index + '%', fontSize: 28, fontWeight: 800, fill: TEXT, fontFamily: FONT}},
                    {type: 'text', left: 'center', top: '55%', style: {text: 'índice geral', fontSize: 11, fill: MUTED, fontFamily: FONT}},
                ],
                polar: {radius: ['34%', '98%'], center: ['50%', '50%']},
                angleAxis: {max: 100, show: false, startAngle: 90},
                radiusAxis: {type: 'category', show: false, data: g.map((x) => x.name)},
                series: [{
                    type: 'bar', coordinateSystem: 'polar', roundCap: true, barWidth: 13, barCategoryGap: '34%',
                    showBackground: true, backgroundStyle: {color: alpha(0.07)},
                    data: g.map((x) => ({value: x.value === null ? 0 : x.value, drawer: x.drawer, itemStyle: {color: x.color}})),
                    emphasis: {itemStyle: {shadowBlur: 12, shadowColor: 'rgba(0,0,0,.4)'}},
                }],
            };
        },
        types() { return hbar(D.types, '', true, 'types'); },
        origins() { return hbar(D.origins || [], '', true, 'origins'); },
        pillars() { return hbar(D.pillars || [], '', true, 'pillars'); },
        projects() { return hbar(D.projects || [], '', false, 'projects'); },
        priority() { return hbar(D.priority, '', true, 'priority'); },
        load() {
            const L = D.load;
            if (!L.names.length) return empty('Sem dados no período');
            const idx = L.names.map((_, i) => L.names.length - 1 - i); // maior carga no topo
            const keys = [['open', 'Em aberto', '#3b82f6'], ['overdue', 'Atrasadas', '#ef4444'], ['done', 'Concluídas', '#22c55e']];
            rowDrawers.load = idx.map((i) => L.drawers[i]);
            const totals = idx.map((i) => keys.reduce((a, [k]) => a + L[k][i], 0));
            const series = keys.map(([k, name, color]) => ({
                name, type: 'bar', stack: 'carga', barWidth: 16, itemStyle: {color},
                data: idx.map((i) => {
                    // só a primeira e a última peça não vazias da barra recebem cantos arredondados
                    const vals = keys.map(([kk]) => L[kk][i]);
                    const nz = vals.map((v, j) => (v > 0 ? j : -1)).filter((j) => j >= 0);
                    const me = keys.findIndex(([kk]) => kk === k);
                    const r = [me === nz[0] ? 8 : 0, me === nz[nz.length - 1] ? 8 : 0, me === nz[nz.length - 1] ? 8 : 0, me === nz[0] ? 8 : 0];
                    return {value: L[k][i], drawer: L.drawers[i], itemStyle: {color, borderRadius: r, borderColor: SURFACE, borderWidth: 1}};
                }),
                label: {show: true, color: '#fff', fontWeight: 700, fontSize: 11, formatter: (p) => (p.value > 0 ? p.value : '')},
                emphasis: {focus: 'series'},
            }));
            return {
                ...base,
                grid: {left: 12, right: 24, top: 34, bottom: 6, containLabel: true},
                legend: {...legendBase, data: ['Em aberto', 'Atrasadas', 'Concluídas']},
                tooltip: {...base.tooltip, trigger: 'axis', axisPointer: {type: 'none'}, formatter: (ps) => `<b>${ps[0].axisValue}</b><br/>` + ps.filter((p) => p.seriesName !== 'Total').map((p) => `${p.marker} ${p.seriesName}: <b>${p.value}</b>`).join('<br/>')},
                xAxis: {type: 'value', minInterval: 1, show: false, max: (v) => v.max * 1.08},
                yAxis: {type: 'category', data: idx.map((i) => L.names[i]), axisLine: {show: false}, axisTick: {show: false}, axisLabel: {color: TEXT, fontSize: 12, width: 150, overflow: 'truncate'}},
                series,
            };
        },
    };

    /** Clique em qualquer ponto da linha (rótulo, barra ou trilha) abre a gaveta da categoria. */
    function rowClick(chart, el, drawers) {
        const zr = chart.getZr();
        const rowAt = (ev) => {
            const grid = chart.getModel().getComponent('grid', 0).coordinateSystem.getRect();
            if (ev.offsetY < grid.y || ev.offsetY > grid.y + grid.height) return -1;
            const idx = Math.round(chart.convertFromPixel({yAxisIndex: 0}, ev.offsetY));
            return idx >= 0 && idx < drawers.length ? idx : -1;
        };
        zr.on('click', (ev) => { const i = rowAt(ev); if (i >= 0) open(drawers[i]); });
        zr.on('mousemove', (ev) => { el.style.cursor = rowAt(ev) >= 0 ? 'pointer' : 'default'; });
        zr.on('globalout', () => { el.style.cursor = 'default'; });
    }

    // ---------- montagem ----------
    const charts = [];
    function init() {
        if (typeof echarts === 'undefined') return false;
        document.querySelectorAll('[data-mkt-chart]').forEach((el) => {
            const build = builders[el.dataset.mktChart];
            if (!build) return;
            const chart = echarts.init(el, null, {renderer: 'canvas'});
            chart.setOption(build(el));
            const kind = el.dataset.mktChart;
            if (rowDrawers[kind]) {
                rowClick(chart, el, rowDrawers[kind]);
            } else if (kind !== 'spark') {
                chart.on('click', (p) => open(p.data && p.data.drawer));
                chart.on('mouseover', (p) => { el.style.cursor = p.data && p.data.drawer ? 'pointer' : 'default'; });
                chart.on('globalout', () => { el.style.cursor = 'default'; });
            }
            charts.push(chart);
        });
        const ro = new ResizeObserver(() => charts.forEach((c) => c.resize()));
        document.querySelectorAll('.mkt-cc, .mkt-kpi').forEach((c) => ro.observe(c));
        return true;
    }

    // o ECharts do GLPI é carregado no rodapé: espera o carregamento da página
    const start = () => {
        let tries = 0;
        const t = setInterval(() => { if (init() || ++tries > 40) clearInterval(t); }, 100);
    };
    if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
})();

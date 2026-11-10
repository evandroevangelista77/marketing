<?php

/**
 * Painel de Demandas (visão executiva): filtros de data (e tipo, área, responsável, situação), cartões com variação
 * sobre o período anterior e minigráfico, gráficos executivos (Apache ECharts, que o GLPI já traz): situação,
 * tendência, funil de conversão, tempo médio por etapa (gargalos), envelhecimento, placar, tipo, prioridade e carga.
 * Clicar em cartão, fatia ou barra abre a gaveta lateral com as demandas; o PDF executivo tem os mesmos números.
 * Cartões e gráficos se ajustam à largura disponível (container queries). Mostra só o que o usuário pode ver.
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Dashboard;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Settings;
use GlpiPlugin\Marketing\Structure;
use GlpiPlugin\Marketing\Ui;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$e = fn ($v) => Ui::e($v);
$f = Dashboard::filters($_GET);
$d = Dashboard::build($f);
$cards = Dashboard::cards($d);
$ch = Dashboard::charts($d);
$flow = Dashboard::flow($d);
$colors = Settings::statusColors();
$rows = $d['rows'];
$types = $d['types'];
$q = Dashboard::query($f);
$prevF = Dashboard::previous($f);
$pd = $prevF ? Dashboard::build($prevF) : null;
$hasPrev = $pd !== null && count($pd['rows']) > 0; // sem demandas no período anterior não há o que comparar

/** Lista de demandas dentro da gaveta. */
$listHtml = function (array $ids, string $lead, array $notes = []) use ($rows, $colors, $e): string {
    $html = "<div class='mkt-dr-lead'>" . $e($lead) . "</div>";
    if (!$ids) {
        return $html . "<div class='text-secondary'>Nenhuma demanda neste filtro.</div>";
    }
    foreach ($ids as $id) {
        $r = $rows[$id];
        $c = $colors[$r['status']] ?? '#94a3b8';
        $who = (int) $r['users_id_assigned'] > 0 ? getUserName((int) $r['users_id_assigned']) : 'sem responsável';
        $deadline = $r['deadline'] ? Ui::date($r['deadline']) : 'sem prazo';
        $late = $r['overdue'] ? " <span class='text-danger fw-bold'>atrasada</span>" : '';
        $extra = $r['days_to_complete'] !== null ? ' · concluída em ' . str_replace('.', ',', (string) $r['days_to_complete']) . ' dia(s)' : '';
        $html .= "<div class='mkt-dr-item' style='--oc:$c'><div class='d-flex justify-content-between gap-2'><div>"
            . "<a class='t' href='" . $e(Ui::url('/front/demand.php?id=' . $id)) . "'>" . $e($r['code'] . ' · ' . $r['name']) . "</a>"
            . "<div class='m'>" . Ui::badge(Demand::STATUSES[$r['status']] ?? $r['status'], $c) . " " . $e($who) . " · prazo " . $e($deadline) . $late . $e($extra) . "</div></div>"
            . "<div class='text-end small text-secondary'>" . $e(Demand::PRIORITIES[(int) $r['priority']] ?? '') . "</div></div>"
            . (isset($notes[$id]) ? "<div class='mkt-dr-notes'>" . implode('', $notes[$id]) . "</div>" : '') . "</div>";
    }
    return $html . "<div class='mkt-dr-total'><span>Total</span><span>" . count($ids) . "</span></div>";
};

$templates = '';
$tpl = function (string $key, string $title, string $icon, string $color, array $ids, string $lead, array $notes = []) use (&$templates, $e, $listHtml): void {
    $templates .= "<template id='dr-" . $e($key) . "' data-title='" . $e($title) . "' data-icon='" . $e($icon) . "' data-color='" . $e($color) . "'><div>" . $listHtml($ids, $lead, $notes) . "</div></template>";
};
$card = fn (string $title, string $body, string $class = '', string $sub = '') => "<section class='card mkt-cc $class'><div class='card-header'><div><h3 class='card-title'>" . $e($title) . "</h3>"
    . ($sub !== '' ? "<div class='text-secondary small'>" . $e($sub) . "</div>" : '') . "</div></div><div class='card-body'>$body</div></section>";

// ---------------------------------------------------------------- variação sobre o período anterior
/** Valor numérico de cada cartão no período, para comparar: chave => [atual, anterior, direção boa (up|down|null)]. */
$numeric = function (array $data, string $key) {
    return match ($key) {
        'avg'    => $data['avg_days'],
        'ontime' => $data['ontime_pct'],
        default  => isset($data['ids'][$key]) ? count($data['ids'][$key]) : null,
    };
};
$good = ['total' => null, 'open' => null, 'done' => 'up', 'overdue' => 'down', 'attention' => 'down', 'approval' => null, 'unassigned' => 'down', 'slo' => 'down', 'avg' => 'down', 'ontime' => 'up', 'external' => null, 'internal' => null, 'project' => null];
$deltaHtml = function (string $key) use ($pd, $hasPrev, $d, $numeric, $good, $e): string {
    if (!$hasPrev) {
        return '';
    }
    $cur = $numeric($d, $key);
    $prev = $numeric($pd, $key);
    $delta = Dashboard::delta($cur === null ? null : (float) $cur, $prev === null ? null : (float) $prev);
    // sem nada no período anterior não há o que comparar (evita mostrar "+100%" enganoso)
    if ($delta === null || ((float) $prev === 0.0 && (float) $cur > 0.0)) {
        return "<div class='mkt-kpi-delta flat'><span>sem base no período anterior</span></div>";
    }
    $dir = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat');
    $tone = $dir === 'flat' || $good[$key] === null ? 'flat' : ($dir === $good[$key] ? 'good' : 'bad');
    $arrow = $dir === 'up' ? '▲' : ($dir === 'down' ? '▼' : '■');
    return "<div class='mkt-kpi-delta $tone'><b>$arrow " . ($delta > 0 ? '+' : '') . $delta . "%</b> <span>vs. período anterior</span></div>";
};

// ---------------------------------------------------------------- dados dos gráficos (JSON para o ECharts) e gavetas
$data = [
    'total' => count($rows), 'status' => [], 'weeks' => ['labels' => [], 'entries' => [], 'done' => []], 'gauges' => [], 'aging' => [], 'types' => [], 'priority' => [],
    'origins' => [], 'pillars' => [], 'projects' => [], 'stages' => [], 'load' => ['names' => [], 'drawers' => [], 'open' => [], 'overdue' => [], 'done' => []],
];

$legend = '';
foreach (Demand::STATUSES as $key => $label) {
    $ids = array_keys(array_filter($rows, fn ($r) => $r['status'] === $key));
    $tpl('st-' . $key, $label, 'ti-flag', $colors[$key], $ids, 'Demandas na situação "' . $label . '".');
    $data['status'][] = ['name' => $label, 'value' => (int) $d['by_status'][$key], 'color' => $colors[$key], 'drawer' => 'st-' . $key];
    $legend .= "<li class='mkt-seg' data-drawer='st-" . $e($key) . "' tabindex='0' role='button'><i style='background:" . $e($colors[$key]) . "'></i><span>" . $e($label) . "</span><b>" . (int) $d['by_status'][$key] . "</b></li>";
}
foreach ($ch['weeks'] as $w) {
    $data['weeks']['labels'][] = $w['label'];
    $data['weeks']['entries'][] = (int) $w['entries'];
    $data['weeks']['done'][] = (int) $w['done'];
}
foreach ($flow['stages'] as $s) {
    $data['stages'][] = ['name' => $s['name'], 'value' => $s['days'], 'n' => $s['n'], 'color' => $colors[$s['status']], 'drawer' => 'st-' . $s['status']];
}
// resultados de SLO por demanda (gavetas do placar e dos cartões de SLO)
$sloStates = ['met' => ['cumprido', '#22c55e'], 'breached' => ['cumprido fora do prazo', '#eab308'], 'overdue' => ['vencido', '#ef4444'], 'running' => ['no prazo', '#64748b']];
$sloBuckets = [];
$sloIds = [];
$sloNotes = [];
foreach ($d['slo_eval'] as $did => $bySlo) {
    foreach ($bySlo as $sid => $res) {
        if ($res['state'] === 'na' || !isset($d['slos'][$sid])) {
            continue;
        }
        $sloBuckets[$sid][$res['state']][] = $did;
        $sloIds[$did] = $did;
        $sloNotes[$did][] = "<div class='mkt-dr-slo' style='--c:" . $sloStates[$res['state']][1] . "'><b>" . $e($d['slos'][$sid]['name']) . "</b>: " . $e($sloStates[$res['state']][0])
            . " · prazo " . $e(Ui::date($res['due'])) . ($res['end'] ? ' · feito em ' . $e(Ui::date($res['end'])) : '') . "</div>";
    }
}
$ontimeNotes = [];
foreach ($d['ids']['ontime'] as $oid) {
    $ontimeNotes[$oid][] = "<div class='mkt-dr-slo' style='--c:#22c55e'>Entregue até o prazo (" . $e(Ui::date($rows[$oid]['deadline'])) . ")</div>";
}
foreach ($d['ids']['late_done'] as $oid) {
    $ontimeNotes[$oid][] = "<div class='mkt-dr-slo' style='--c:#ef4444'>Entregue depois do prazo (" . $e(Ui::date($rows[$oid]['deadline'])) . ")</div>";
}
$tpl('sc-0', 'Entregas no prazo', 'ti-target-arrow', '#14b8a6', array_merge($d['ids']['ontime'], $d['ids']['late_done']), 'Concluídas que tinham prazo, com o resultado de cada uma.', $ontimeNotes);
$tpl('sc-1', 'SLOs cumpridos', 'ti-hourglass-high', '#eab308', array_values($sloIds), 'Demandas medidas pelos SLOs ativos, com o resultado de cada SLO.', $sloNotes);
$tpl('sc-2', 'Taxa de conclusão', 'ti-circle-check', '#6366f1', $d['ids']['done'], 'Demandas concluídas no período.');
$data['gauges'] = [
    ['name' => 'Entregas no prazo', 'value' => $d['ontime_pct'], 'color' => '#14b8a6', 'note' => 'das concluídas com prazo', 'drawer' => 'sc-0'],
    ['name' => 'SLOs cumpridos', 'value' => $ch['slo_pct'], 'color' => '#eab308', 'note' => $d['slos'] ? 'entre os SLOs ativos' : 'nenhum SLO ativo', 'drawer' => 'sc-1'],
    ['name' => 'Taxa de conclusão', 'value' => $ch['completion_pct'], 'color' => '#6366f1', 'note' => 'concluídas / total sem canceladas', 'drawer' => 'sc-2'],
];
$valid = array_filter(array_column($data['gauges'], 'value'), fn ($v) => $v !== null);
$data['score_index'] = $valid ? (int) round(array_sum($valid) / count($valid)) : null;
$i = 0;
foreach ($ch['aging'] as $label => $a) {
    $tpl('age-' . $i, 'Em aberto há ' . $label, 'ti-hourglass', $a['color'], $a['ids'], 'Demandas em aberto cuja entrada foi há ' . $label . '.');
    $data['aging'][] = ['name' => $label, 'value' => count($a['ids']), 'color' => $a['color'], 'drawer' => 'age-' . $i];
    $i++;
}
arsort($d['by_type']);
foreach ($d['by_type'] as $tid => $n) {
    $name = $tid > 0 ? ($types[$tid]['name'] ?? 'Tipo removido') : 'Sem tipo';
    $color = $tid > 0 ? ($types[$tid]['color'] ?? '#94a3b8') : '#94a3b8';
    $ids = array_keys(array_filter($rows, fn ($r) => (int) $r['types_id'] === $tid));
    $tpl('ty-' . $tid, $name, 'ti-tag', $color, $ids, 'Demandas do tipo "' . $name . '".');
    $data['types'][] = ['name' => $name, 'value' => $n, 'color' => $color, 'drawer' => 'ty-' . $tid];
}
foreach ($ch['priority'] as $p => [$color, $ids]) {
    $label = Demand::PRIORITIES[$p];
    $tpl('pr-' . $p, 'Prioridade ' . mb_strtolower($label), 'ti-flag-3', $color, $ids, 'Demandas em aberto com prioridade ' . mb_strtolower($label) . '.');
    $data['priority'][] = ['name' => $label, 'value' => count($ids), 'color' => $color, 'drawer' => 'pr-' . $p];
}
foreach ([Demand::ORIGIN_EXTERNAL => 'external', Demand::ORIGIN_INTERNAL => 'internal'] as $origin => $key) {
    $ids = $d['ids'][$key];
    $tpl('or-' . $key, Demand::ORIGINS[$origin], 'ti-inbox', Demand::ORIGIN_COLORS[$origin], $ids, 'Demandas com origem "' . Demand::ORIGINS[$origin] . '".');
    $data['origins'][] = ['name' => Demand::ORIGINS[$origin], 'value' => count($ids), 'color' => Demand::ORIGIN_COLORS[$origin], 'drawer' => 'or-' . $key];
}
$pillarsAll = Structure::pillars();
$projectsAll = Structure::projects();
$byPillar = $d['by_pillar'];
uasort($byPillar, fn ($a, $b) => count($b) <=> count($a));
foreach ($byPillar as $pid => $ids) {
    $name = $pid > 0 ? ($pillarsAll[$pid]['name'] ?? 'Pilar removido') : 'Sem pilar';
    $color = $pid > 0 ? ($pillarsAll[$pid]['color'] ?? '#94a3b8') : '#94a3b8';
    $tpl('pl-' . $pid, $name, 'ti-columns-3', $color, $ids, 'Demandas e atividades do pilar "' . $name . '".');
    $data['pillars'][] = ['name' => $name, 'value' => count($ids), 'color' => $color, 'drawer' => 'pl-' . $pid];
}
$byProject = $d['by_project'];
uasort($byProject, fn ($a, $b) => count($b) <=> count($a));
foreach (array_slice($byProject, 0, 10, true) as $pid => $ids) {
    $name = $projectsAll[$pid]['name'] ?? 'Projeto removido';
    $pr = Structure::progress(array_intersect_key($rows, array_flip($ids)));
    $tpl('pj-' . $pid, $name, 'ti-hierarchy-2', '#14b8a6', $ids, 'Atividades do projeto "' . $name . '" (progresso ' . ($pr === null ? '—' : $pr . '%') . ').');
    $data['projects'][] = ['name' => $name, 'value' => count($ids), 'color' => '#14b8a6', 'drawer' => 'pj-' . $pid, 'n' => count($ids)];
}
$loadRows = $ch['assignees'];
uasort($loadRows, fn ($a, $b) => (count($b['open'] ?? []) + count($b['overdue'] ?? [])) <=> (count($a['open'] ?? []) + count($a['overdue'] ?? [])));
foreach (array_slice($loadRows, 0, 12, true) as $uid => $a) {
    $name = $uid > 0 ? getUserName($uid) : 'Sem responsável';
    $tpl('as-' . $uid, $name, 'ti-user', '#6366f1', $a['all'], 'Todas as demandas de ' . $name . ' no período.');
    $data['load']['names'][] = $name;
    $data['load']['drawers'][] = 'as-' . $uid;
    $data['load']['open'][] = count($a['open'] ?? []);
    $data['load']['overdue'][] = count($a['overdue'] ?? []);
    $data['load']['done'][] = count($a['done'] ?? []);
}
$loadHeight = max(240, count($data['load']['names']) * 44 + 90);
$data['spark'] = ['total' => $data['weeks']['entries'], 'done' => $data['weeks']['done']];

// ---------------------------------------------------------------- página
Ui::header('Painel de Demandas', 'dashboard');
Html::requireJs('charts'); // ECharts que acompanha o GLPI (carregado no rodapé)
echo "<div class='mkt-dash'>";
echo Ui::title(
    'Painel de Demandas',
    'Demandas que entraram de ' . ($f['from'] ? Ui::date($f['from']) : 'o início') . ' a ' . ($f['to'] ? Ui::date($f['to']) : 'hoje')
        . ($hasPrev ? ', comparadas com ' . Ui::date($prevF['from']) . ' a ' . Ui::date($prevF['to']) : '') . '. Clique em um cartão, fatia ou barra para ver as demandas.',
    "<a class='btn btn-primary' href='" . $e(Ui::url('/front/report.php?' . $q)) . "'><i class='ti ti-file-type-pdf me-1'></i>Exportar PDF executivo</a>"
);

$typeOptions = [];
foreach ($types as $tid => $t) {
    $typeOptions[$tid] = $t['name'];
}
echo "<form method='get' class='card card-body mb-3'><div class='mkt-filters'>";
echo "<div><label for='f-from'>De</label><input id='f-from' class='form-control' type='date' name='from' value='" . $e($f['from']) . "'></div>";
echo "<div><label for='f-to'>Até</label><input id='f-to' class='form-control' type='date' name='to' value='" . $e($f['to']) . "'></div>";
echo "<div><label>Tipo</label>" . Ui::select('type', $typeOptions, $f['types_id'] ?: '', '', true) . "</div>";
echo "<div><label>Responsável</label>" . Ui::select('assignee', Ui::userOptions(), $f['assignee'] ?: '', '', true) . "</div>";
echo "<div><label>Situação</label>" . Ui::select('status', Demand::STATUSES, $f['status'], '', true) . "</div>";
echo "<div><label>Origem</label>" . Ui::select('origin', Demand::ORIGINS, $f['origin'], '', true) . "</div>";
echo "<div><label>Pilar</label>" . Ui::select('pillar', Structure::pillarOptions(false), $f['pillar'] ?: '', '', true) . "</div>";
echo "<div><label>Projeto</label>" . Ui::select('project', Structure::projectOptions(false), $f['project'] ?: '', '', true) . "</div>";
echo "<div><label>Área</label><input class='form-control' name='area' value='" . $e($f['area']) . "' placeholder='Todas'></div>";
echo "<div class='mkt-filter-actions'><button class='btn btn-primary'><i class='ti ti-filter me-1'></i>Filtrar</button> <a class='btn btn-outline-secondary' href='" . $e(Ui::url('/front/dashboard.php')) . "'>Limpar</a></div>";
echo "</div><div class='mkt-quick mt-2'>";
foreach (Dashboard::RANGES as $key => [$label]) {
    echo "<a class='" . ($f['range'] === (string) $key ? 'on' : '') . "' href='" . $e(Ui::url('/front/dashboard.php?range=' . $key)) . "'>" . $e($label) . "</a>";
}
echo "</div></form>";

echo "<div class='mkt-kpis'>";
foreach ($cards as $key => $c) {
    $tpl($key, $c['title'], $c['icon'], $c['color'], $c['ids'], $c['lead']);
    $spark = isset($data['spark'][$key]) && count($data['spark'][$key]) > 1 ? "<div class='mkt-sparkline' data-mkt-chart='spark' data-k='" . $e($key) . "' data-c='" . $e($c['color']) . "'></div>" : '';
    echo "<button type='button' class='mkt-kpi mkt-click' style='--c:" . $e($c['color']) . "' data-drawer='" . $e($key) . "'>"
        . "<div class='mkt-kpi-label'><i class='ti " . $e($c['icon']) . " me-1'></i>" . $e($c['title']) . "</div>"
        . "<div class='mkt-kpi-value'>" . $e($c['value']) . "</div><div class='mkt-kpi-sub'>" . $e($c['sub']) . "</div>" . $deltaHtml($key) . $spark . "</button>";
}
echo "</div>";

echo "<div class='mkt-section-title'><i class='ti ti-chart-dots-3'></i> Gráficos executivos</div><div class='mkt-charts'>";

echo $card('Demandas por situação', "<div class='mkt-split'><div class='mkt-plot' data-mkt-chart='status'></div><ul class='mkt-legend'>$legend</ul></div>", '', 'Distribuição atual no período');
echo $card('Entradas × concluídas por semana', "<div class='mkt-plot grow' data-mkt-chart='trend'></div>", 'span2', 'Semanas do período filtrado (até 26)');

$funnelSub = $flow['funnel'][0]['value'] > 0 ? $flow['funnel'][4]['pct'] . '% das demandas chegam à conclusão' : 'Quantas demandas chegaram a cada etapa';
// funil em barras centradas: largura proporcional ao total recebido, conversão entre etapas e gaveta por etapa
$fnColors = ['#6366f1', '#3b82f6', '#0ea5e9', '#14b8a6', '#22c55e'];
$fnMax = max(1, (int) $flow['funnel'][0]['value']);
$fn = "<div class='mkt-funnel'>";
$worst = null;
foreach ($flow['funnel'] as $k => $st) {
    $tpl('fn-' . $k, 'Chegaram a: ' . $st['name'], 'ti-filter', $fnColors[$k], $st['ids'], 'Demandas que chegaram à etapa "' . $st['name'] . '" ou além.');
    if ($k > 0) {
        $prevN = (int) $flow['funnel'][$k - 1]['value'];
        $step = $st['step'];
        $tone = $step === null ? 'flat' : ($step >= 80 ? 'good' : ($step >= 50 ? 'warn' : 'bad'));
        $lost = max(0, $prevN - (int) $st['value']);
        if ($step !== null && $prevN > 0 && ($worst === null || $step < $worst['step'])) {
            $worst = ['step' => $step, 'from' => $flow['funnel'][$k - 1]['name'], 'to' => $st['name']];
        }
        $fn .= "<div class='mkt-fn-step $tone'><i class='ti ti-arrow-narrow-down'></i> " . ($step === null ? '—' : $step . '% avançam') . ($lost > 0 ? "<em>−$lost</em>" : '') . "</div>";
    }
    $w = max(30, (int) round($st['value'] / $fnMax * 100));
    $fn .= "<div class='mkt-fn-row mkt-seg' data-drawer='fn-$k' tabindex='0' role='button' style='--w:{$w}%;--c:" . $e($fnColors[$k]) . "'><div class='mkt-fn-label'>" . $e($st['name']) . "</div>"
        . "<div class='mkt-fn-track'><div class='mkt-fn-bar'><b>" . (int) $st['value'] . "</b><span>" . (int) $st['pct'] . "%</span></div></div></div>";
}
$fn .= "<div class='mkt-fn-foot'>" . ($flow['funnel'][0]['value'] > 0
    ? "Conversão total <b>" . (int) $flow['funnel'][4]['pct'] . "%</b>" . ($worst && $worst['step'] < 100 ? " · maior perda: <b>" . $e($worst['from']) . " → " . $e($worst['to']) . "</b> (" . (int) $worst['step'] . "% avançam)" : '')
    : 'Sem demandas no período') . "</div></div>";
echo $card('Funil de conversão', $fn, '', $funnelSub);
$b = $flow['bottleneck'];
echo $card('Tempo médio por etapa', "<div class='mkt-plot' data-mkt-chart='stages'></div>", '', $b ? 'Maior gargalo: ' . $b['name'] . ' (' . str_replace('.', ',', (string) $b['days']) . ' dias)' : 'Dias que as demandas ficam em cada situação');
echo $card('Envelhecimento das abertas', "<div class='mkt-plot' data-mkt-chart='aging'></div>", '', 'Há quantos dias estão em aberto');

$score = '';
foreach ($data['gauges'] as $g) {
    $score .= "<li class='mkt-seg' data-drawer='" . $e($g['drawer']) . "' tabindex='0' role='button'><i style='background:" . $e($g['color']) . "'></i><div><div class='mkt-score-n'>" . $e($g['name']) . "</div><div class='mkt-score-d text-secondary'>" . $e($g['note']) . "</div></div><b>" . ($g['value'] === null ? '—' : (int) $g['value'] . '%') . "</b></li>";
}
echo $card('Placar de desempenho', "<div class='mkt-split mkt-score'><div class='mkt-plot' data-mkt-chart='score'></div><ul class='mkt-scorelist'>$score</ul></div>", '', 'Entregas, SLOs e conclusão');
echo $card('Origem do trabalho', "<div class='mkt-plot' data-mkt-chart='origins'></div>", '', 'Solicitações de outras áreas × atividades do Marketing');
echo $card('Demandas por pilar', "<div class='mkt-plot' data-mkt-chart='pillars'></div>", '', 'Participação de cada frente');
echo $card('Projetos com mais atividades', "<div class='mkt-plot' data-mkt-chart='projects'></div>", '', 'Os 10 maiores no período');
echo $card('Demandas por tipo', "<div class='mkt-plot' data-mkt-chart='types'></div>", '', 'Participação no total');
echo $card('Prioridade das abertas', "<div class='mkt-plot' data-mkt-chart='priority'></div>", '', 'Demandas em aberto por prioridade');
echo $card('Carga por responsável', "<div class='mkt-plot' style='height:{$loadHeight}px' data-mkt-chart='load'></div>", 'span-load', 'Em aberto, atrasadas e concluídas');

$sloBody = '';
foreach ($d['slos'] as $sid => $slo) {
    $sum = $d['slo_summary'][$sid] ?? [];
    $cnt = array_map(fn ($k) => (int) ($sum[$k] ?? 0), array_keys($sloStates));
    $cnt = array_combine(array_keys($sloStates), $cnt);
    $measured = $cnt['met'] + $cnt['breached'] + $cnt['overdue'];
    $pct = $measured > 0 ? (int) round($cnt['met'] / $measured * 100) : null;
    $tone = $pct === null ? 'flat' : ($pct >= 90 ? 'good' : ($pct >= 70 ? 'warn' : 'bad'));
    $total = max(1, array_sum($cnt));
    $bar = '';
    $legendChips = '';
    foreach ($sloStates as $state => [$label, $color]) {
        $n = $cnt[$state];
        if ($n === 0) {
            continue;
        }
        $key = 'slo-' . $sid . '-' . $state;
        $tpl($key, $slo['name'] . ': ' . $label, 'ti-hourglass-high', $color, $sloBuckets[$sid][$state] ?? [], 'Demandas com o SLO "' . $slo['name'] . '" ' . $label . '.', $sloNotes);
        $bar .= "<span class='mkt-seg' data-drawer='" . $e($key) . "' tabindex='0' role='button' title='" . $e($label . ': ' . $n) . "' style='width:" . round($n / $total * 100, 2) . "%;background:$color'>" . ($n / $total >= 0.08 ? $n : '') . "</span>";
        $legendChips .= "<button type='button' class='mkt-chip mkt-seg' data-drawer='" . $e($key) . "' style='--c:$color'><i></i>" . $e($label) . " <b>$n</b></button>";
    }
    $to = implode(' ou ', array_map(fn ($k) => Demand::STATUSES[$k] ?? $k, array_filter(explode(',', $slo['to_statuses']))));
    $sloBody .= "<div class='mkt-slo'><div class='mkt-slo-head'><div><div class='mkt-slo-name'>" . $e($slo['name']) . "</div><div class='text-secondary small'>"
        . (int) $slo['limit_days'] . " dia(s) útil(eis) · de " . $e(Demand::STATUSES[$slo['from_status']] ?? $slo['from_status']) . " a " . $e($to) . "</div></div>"
        . "<div class='mkt-slo-pct $tone'>" . ($pct === null ? '—' : $pct . '%') . "<small>cumprido</small></div></div>"
        . "<div class='mkt-slo-bar'>" . ($bar ?: "<span style='width:100%;background:transparent'></span>") . "</div><div class='mkt-slo-chips'>$legendChips</div></div>";
}
if (!$d['slos']) {
    $sloBody = "<div class='text-center text-secondary py-4'>Nenhum SLO ativo. Ative em Configurações → SLOs depois da homologação.</div>";
}
echo $card('SLOs', "<div class='mkt-slos'>$sloBody</div>", 'span-slo', 'Cumprimento por SLO (clique para ver as demandas)');

echo "</div>"; // .mkt-charts
echo "</div>"; // .mkt-dash
echo $templates;
echo "<script type='application/json' id='mkt-chart-data'>" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . "</script>";
echo Ui::script('painel.js');
Html::footer();

<?php

/**
 * KPIs, OKRs e Book executivo. Exclusivo do gerente de Marketing (direito "Book executivo").
 * Abas: Visão geral, Indicadores, OKRs, Ofensores e recuperação, Books (histórico, geração, envio automático) e Metas.
 * O CSRF é validado pelo GLPI em todo POST (não chame checkCSRF aqui).
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Book;
use GlpiPlugin\Marketing\Kpi;
use GlpiPlugin\Marketing\Okr;
use GlpiPlugin\Marketing\Settings;
use GlpiPlugin\Marketing\Structure;
use GlpiPlugin\Marketing\Ui;

Session::checkLoginUser();
if (!Access::canExecutive()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
global $DB;

$e = fn ($v) => Ui::e($v);
$me = (int) Session::getLoginUserID();
$msg = fn (string $t, bool $ok = true) => Session::addMessageAfterRedirect($t, false, $ok ? INFO : ERROR);
$go = fn (string $q) => Html::redirect(Ui::url('/front/kpi.php' . $q));

// ---------------------------------------------------------------- download do book
if (isset($_GET['download'])) {
    $row = Book::get((int) $_GET['download']);
    if (!$row || !is_file(Book::path($row))) {
        throw new \Glpi\Exception\Http\NotFoundHttpException();
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . Book::fileName($row) . '"');
    header('Content-Length: ' . (string) filesize(Book::path($row)));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile(Book::path($row));
    exit;
}

// ---------------------------------------------------------------- ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = $_POST;
    $do = (string) ($p['do'] ?? '');
    switch ($do) {
        case 'targets_save':
            $t = [];
            foreach (array_keys(Kpi::catalog()) as $k) {
                $in = $p['t'][$k] ?? [];
                $t[$k] = ['target' => ($in['target'] ?? '') === '' ? null : str_replace(',', '.', (string) $in['target']), 'warn' => ($in['warn'] ?? '') === '' ? null : str_replace(',', '.', (string) $in['warn'])];
            }
            Settings::save(['kpi_targets' => !empty($p['reset']) ? '{}' : json_encode($t)]);
            $msg(!empty($p['reset']) ? 'Metas restauradas para o padrão.' : 'Metas salvas.');
            $go('?tab=metas');
            break;
        case 'okr_save':
            $id = Okr::save((int) ($p['id'] ?? 0), $p);
            $msg($id ? 'Objetivo salvo.' : 'Informe o nome e o período (data final depois da inicial).', (bool) $id);
            $go('?tab=okrs');
            break;
        case 'okr_delete':
            Okr::delete((int) ($p['id'] ?? 0));
            $msg('Objetivo removido.');
            $go('?tab=okrs');
            break;
        case 'kr_save':
            $id = Okr::saveKr((int) ($p['okrs_id'] ?? 0), (int) ($p['id'] ?? 0), $p);
            $msg($id ? 'Resultado-chave salvo.' : 'Preencha o nome, a meta e o indicador ou projeto do resultado-chave.', (bool) $id);
            $go('?tab=okrs');
            break;
        case 'kr_delete':
            Okr::deleteKr((int) ($p['okrs_id'] ?? 0), (int) ($p['id'] ?? 0));
            $msg('Resultado-chave removido.');
            $go('?tab=okrs');
            break;
        case 'book_settings':
            Settings::save([
                'book_monthly' => !empty($p['book_monthly']) ? '1' : '0',
                'book_annual' => !empty($p['book_annual']) ? '1' : '0',
                'book_annual_when' => ($p['book_annual_when'] ?? '') === 'jan1' ? 'jan1' : 'dec31',
                'book_recipients' => implode(',', array_values(array_unique(array_filter(array_map('intval', (array) ($p['recipients'] ?? [])))))),
                'book_emails' => mb_substr(trim((string) ($p['emails'] ?? '')), 0, 1000),
            ]);
            if (!empty($_FILES['logo']['name'])) {
                $err = Book::saveLogo($_FILES['logo']);
                $err ? $msg($err, false) : $msg('Logotipo salvo.');
            }
            $msg('Configuração do envio salva.');
            $go('?tab=books');
            break;
        case 'book_generate':
            $kind = (string) ($p['kind'] ?? 'monthly');
            if ($kind === 'annual') {
                [$from, $to, $label] = Book::yearPeriod(max(2000, min(2100, (int) ($p['year'] ?? date('Y')))));
            } elseif ($kind === 'manual') {
                $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['from'] ?? '')) ? $p['from'] : date('Y-m-01');
                $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['to'] ?? '')) ? $p['to'] : date('Y-m-d');
                $label = 'de ' . date('d/m/Y', strtotime($from)) . ' a ' . date('d/m/Y', strtotime($to));
            } else {
                $m = preg_match('/^\d{4}-\d{2}$/', (string) ($p['month'] ?? '')) ? $p['month'] : date('Y-m');
                [$from, $to, $label] = Book::monthPeriod($m);
                $kind = 'monthly';
            }
            if ($to < $from) {
                $msg('A data final precisa ser depois da inicial.', false);
                $go('?tab=books');
            }
            @set_time_limit(300);
            $id = Book::generate($kind, $from, $to, $label, $me);
            if ($id && !empty($p['send'])) {
                $msg(Book::send($id));
            }
            $msg($id ? 'Book gerado: ' . $label . '.' : 'Não foi possível gerar o book (verifique a pasta de documentos do GLPI).', (bool) $id);
            $go('?tab=books');
            break;
        case 'book_send':
            $msg(Book::send((int) ($p['id'] ?? 0)));
            $go('?tab=books');
            break;
        case 'book_delete':
            Book::delete((int) ($p['id'] ?? 0));
            $msg('Book removido.');
            $go('?tab=books');
            break;
    }
    $go('');
}

// ---------------------------------------------------------------- período
$tab = in_array($_GET['tab'] ?? '', ['visao', 'indicadores', 'okrs', 'ofensores', 'books', 'metas'], true) ? $_GET['tab'] : 'visao';
$per = (string) ($_GET['p'] ?? date('Y-m'));
if (preg_match('/^Y:(\d{4})$/', $per, $m)) {
    [$from, $to, $label] = Book::yearPeriod((int) $m[1]);
    $kindLabel = 'anual';
} elseif ($per === '90d') {
    $from = date('Y-m-d', strtotime('-89 day'));
    $to = date('Y-m-d');
    $label = 'últimos 90 dias';
    $kindLabel = 'dias';
} else {
    $per = preg_match('/^\d{4}-\d{2}$/', $per) ? $per : date('Y-m');
    [$from, $to, $label] = Book::monthPeriod($per);
    $kindLabel = 'mensal';
}
$users = Ui::userOptions();

Ui::header("KPI's", 'kpis');
echo Ui::title("KPI's", 'Indicadores do Marketing, OKRs, planos de recuperação e o book executivo. Acesso exclusivo do gerente de Marketing.',
    "<form method='post' action='" . $e(Ui::url('/front/kpi.php')) . "' class='d-flex gap-2'>" . Ui::csrf() . "<input type='hidden' name='do' value='book_generate'>" . (
        $kindLabel === 'anual' ? "<input type='hidden' name='kind' value='annual'><input type='hidden' name='year' value='" . (int) substr($from, 0, 4) . "'>"
        : ($kindLabel === 'dias' ? "<input type='hidden' name='kind' value='manual'><input type='hidden' name='from' value='" . $e($from) . "'><input type='hidden' name='to' value='" . $e($to) . "'>"
        : "<input type='hidden' name='kind' value='monthly'><input type='hidden' name='month' value='" . $e($per) . "'>")
    ) . "<button class='btn btn-primary'><i class='ti ti-file-type-pdf me-1'></i>Gerar book executivo (PDF)</button></form>");

$tabs = ['visao' => ['Visão geral', 'ti-layout-dashboard'], 'indicadores' => ['Indicadores', 'ti-list-details'], 'okrs' => ['OKRs', 'ti-target'], 'ofensores' => ['Ofensores e recuperação', 'ti-alert-triangle'], 'books' => ['Books', 'ti-books'], 'metas' => ['Metas', 'ti-adjustments']];
echo "<nav class='mkt-viewtabs'>";
foreach ($tabs as $k => [$l, $ic]) {
    echo "<a class='" . ($tab === $k ? 'on' : '') . "' href='" . $e(Ui::url('/front/kpi.php?tab=' . $k . '&p=' . urlencode($per))) . "'><i class='ti $ic me-1'></i>" . $e($l) . "</a>";
}
echo "</nav>";

// seletor de período (abas que o usam)
$needsPeriod = in_array($tab, ['visao', 'indicadores', 'ofensores'], true);
if ($needsPeriod) {
    echo "<form method='get' class='card card-body mb-3'><input type='hidden' name='tab' value='" . $e($tab) . "'><div class='mkt-filters'><div><label>Período</label><select class='form-select' name='p' onchange='this.form.submit()'>";
    $opts = ['90d' => 'Últimos 90 dias'];
    for ($i = 0; $i < 18; $i++) {
        $mm = date('Y-m', strtotime("first day of -$i month"));
        $opts[$mm] = Book::monthPeriod($mm)[2] . ($i === 0 ? ' (mês atual, parcial)' : '');
    }
    foreach ([(int) date('Y'), (int) date('Y') - 1, (int) date('Y') - 2] as $y) {
        $opts['Y:' . $y] = 'Ano de ' . $y;
    }
    foreach ($opts as $v => $l) {
        echo "<option value='" . $e($v) . "'" . ($v === $per ? ' selected' : '') . ">" . $e($l) . "</option>";
    }
    echo "</select></div><div class='text-secondary small pb-2'>Fluxo medido de " . $e(Ui::date($from)) . " a " . $e(Ui::date(min($to, date('Y-m-d')))) . "; estoque (backlog, atrasadas) na data final. Comparado com o período anterior de mesma duração.</div></div></form>";
}

// dados do período (abas com indicadores)
if ($needsPeriod) {
    $len = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
    if ($kindLabel === 'anual') {
        $pFrom = date('Y-m-d', strtotime('-1 year', strtotime($from)));
        $pTo = date('Y-m-d', strtotime('-1 year', strtotime($to)));
    } elseif ($kindLabel === 'mensal') {
        $pTo = date('Y-m-d', strtotime('-1 day', strtotime($from)));
        $pFrom = date('Y-m-01', strtotime($pTo));
    } else {
        $pTo = date('Y-m-d', strtotime('-1 day', strtotime($from)));
        $pFrom = date('Y-m-d', strtotime('-' . ($len - 1) . ' day', strtotime($pTo)));
    }
    Kpi::resetCache();
    $vals = Kpi::values($from, $to);
    $prev = Kpi::values($pFrom, $pTo);
    $sum = Kpi::summarize($vals, $prev);
    $series = Kpi::series(date('Y-m', strtotime(min($to, date('Y-m-d')))), 12);
    $mn = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $labels12 = array_map(fn ($m) => $mn[(int) substr($m, 5, 2)] . ((int) substr($m, 5, 2) === 1 ? '/' . substr($m, 2, 2) : ''), array_keys($series));
}
$trendIcon = ['better' => ["<i class='ti ti-trending-up'></i>", '#16a34a'], 'worse' => ["<i class='ti ti-trending-down'></i>", '#dc2626'], 'flat' => ["<i class='ti ti-minus'></i>", '#94a3b8'], 'up' => ["<i class='ti ti-arrow-up-right'></i>", '#94a3b8'], 'down' => ["<i class='ti ti-arrow-down-right'></i>", '#94a3b8']];
$stPill = fn (string $st) => "<span class='badge' style='background:" . Kpi::STATUS_COLORS[$st] . ";color:#fff'>" . $e(Kpi::STATUS_LABELS[$st]) . "</span>";

// ================================================================= VISÃO GERAL
if ($tab === 'visao') {
    $cat = Kpi::targets();
    $emptyMonth = fn (array $mv) => ((float) ($mv['entradas'] ?? 0)) === 0.0 && ((float) ($mv['backlog'] ?? 0)) === 0.0;
    $monthlyOverall = [];
    $monthlyGroups = array_fill_keys(array_keys(Kpi::GROUPS), []);
    foreach ($series as $m => $mv) {
        $ms = Kpi::summarize($mv);
        $empty = $emptyMonth($mv);
        $monthlyOverall[] = $empty ? null : $ms['overall'];
        foreach ($ms['groups'] as $gid => $g) {
            $monthlyGroups[$gid][] = $empty ? null : $g['score'];
        }
    }
    $prevSum = Kpi::summarize($prev);
    $chart = [
        'overall' => $sum['overall'], 'prevOverall' => $prevSum['overall'], 'labels' => $labels12, 'monthly' => $monthlyOverall, 'groups' => [], 'groupsMonthly' => [], 'spark' => [], 'targets' => [], 'series' => [],
    ];
    foreach ($sum['groups'] as $gid => $g) {
        $chart['groups'][] = ['id' => $gid, 'name' => $g['name'], 'score' => $g['score'], 'prev' => $prevSum['groups'][$gid]['score'] ?? null, 'color' => $g['color']];
        $chart['groupsMonthly'][] = ['name' => $g['name'], 'values' => $monthlyGroups[$gid]];
    }
    foreach ($sum['rows'] as $k => $r) {
        $chart['spark'][$k] = array_values(array_map(fn ($mv) => $mv[$k] ?? null, $series));
        $chart['targets'][$k] = $r['target'];
    }
    foreach (['entradas', 'concluidas', 'otd', 'atrasadas_pct', 'adiadas_pct', 'backlog', 'lead_time', 'vazao'] as $k) {
        $chart['series'][$k] = $chart['spark'][$k];
    }
    $chart['from'] = $from;
    $chart['to'] = $to;
    $chart['drill'] = Ui::url('/front/kpi_drill.php');
    echo "<script type='application/json' id='mkt-kpi-data'>" . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>";

    $cnt = ['green' => 0, 'amber' => 0, 'red' => 0];
    foreach ($sum['rows'] as $r) {
        if ($r['score'] !== null) {
            $cnt[$r['status']]++;
        }
    }
    $overall = $sum['overall'];
    $dl = ($overall !== null && $prevSum['overall'] !== null) ? $overall - $prevSum['overall'] : null;
    $idxCol = $overall === null ? '#94a3b8' : ($overall >= 85 ? '#16a34a' : ($overall >= 65 ? '#f59e0b' : '#dc2626'));
    $idxLbl = $overall === null ? 'Sem metas' : ($overall >= 85 ? 'Desempenho na meta' : ($overall >= 65 ? 'Atenção' : 'Crítico'));

    // ---- faixa 0: números-chave do período
    echo "<div class='mkt-stats'>";
    foreach ([['entradas', 'ti-inbox'], ['concluidas', 'ti-circle-check'], ['backlog', 'ti-stack-2'], ['otd', 'ti-target-arrow'], ['atrasadas_pct', 'ti-alarm'], ['lead_time', 'ti-clock-hour-4']] as [$k, $ic]) {
        $r = $sum['rows'][$k];
        $col = Kpi::STATUS_COLORS[$r['status']];
        $dv = ($r['value'] !== null && $r['prev'] !== null) ? $r['value'] - $r['prev'] : null;
        $good = $dv === null ? null : (($r['dir'] === 'info') ? null : (($dv >= 0) === ($r['dir'] === 'up')));
        echo "<div class='mkt-stat mkt-drill' data-k='$k' style='--c:$col'><div class='mkt-stat-ic'><i class='ti $ic'></i></div><div class='mkt-stat-body'><div class='mkt-stat-name'>" . $e($r['name']) . "</div><div class='mkt-stat-val'>" . $e(Kpi::format($r, $r['value'])) . "</div>"
            . "<div class='mkt-stat-delta'" . ($good === null ? '' : " style='color:" . ($good ? '#16a34a' : '#dc2626') . "'") . ">" . ($dv === null ? 'sem período anterior' : (($dv >= 0 ? '▲ ' : '▼ ') . $e(Kpi::format($r, abs($dv))) . ' vs anterior')) . "</div></div></div>";
    }
    echo "</div>";

    // ---- faixa 1: índice, grupos, evolução
    echo "<div class='mkt-vg'>";
    echo "<div class='mkt-panel mkt-vg-index'><div class='mkt-panel-title'>Índice geral de desempenho</div><div class='mkt-gauge' id='kpi-gauge'></div>"
        . "<div class='mkt-vg-status' style='color:$idxCol'>" . $e($idxLbl) . "</div>"
        . ($dl !== null ? "<div class='mkt-vg-delta " . ($dl >= 0 ? 'pos' : 'neg') . "'><i class='ti " . ($dl >= 0 ? 'ti-trending-up' : 'ti-trending-down') . "'></i> " . ($dl >= 0 ? '+' : '') . (int) $dl . " pts vs período anterior (" . (int) $prevSum['overall'] . ")</div>" : '')
        . "<div class='mkt-chips'><span class='mkt-chip' style='--c:#16a34a'><b>{$cnt['green']}</b> na meta</span><span class='mkt-chip' style='--c:#f59e0b'><b>{$cnt['amber']}</b> atenção</span><span class='mkt-chip' style='--c:#dc2626'><b>{$cnt['red']}</b> críticos</span></div></div>";
    echo "<div class='mkt-panel mkt-vg-groups'><div class='mkt-panel-title'>Pontuação por grupo</div><div class='mkt-chart' id='kpi-radar' style='height:320px'></div><div class='mkt-legend'><span><i style='background:#2563eb'></i>Período atual</span><span><i style='background:#94a3b8'></i>Período anterior</span></div></div>";
    echo "<div class='mkt-panel mkt-vg-trend'><div class='mkt-panel-title'>Evolução do índice (12 meses)</div><div class='mkt-chart' id='kpi-trend' style='height:290px'></div></div>";
    echo "</div>";

    // ---- faixa 2: leitura, destaques e alertas
    $scored = array_filter($sum['rows'], fn ($r) => $r['score'] !== null);
    $best = $scored;
    usort($best, fn ($a, $b) => $b['score'] <=> $a['score']);
    $best = array_slice(array_filter($best, fn ($r) => $r['status'] === 'green'), 0, 3);
    $worst = $scored;
    usort($worst, fn ($a, $b) => $a['score'] <=> $b['score']);
    $worst = array_slice(array_filter($worst, fn ($r) => $r['status'] !== 'green'), 0, 3);
    $nar = Kpi::narrative($sum, $label);
    $miniCard = function (array $r, string $col) use ($e) {
        return "<div class='mkt-mini mkt-drill' data-k='" . $e($r['key']) . "' style='--c:$col'><div class='mkt-mini-name'>" . $e($r['name']) . "<small>" . $e(Kpi::GROUPS[$r['group']][0]) . "</small></div><div class='mkt-mini-val'>" . $e(Kpi::format($r, $r['value'])) . "<small>meta " . $e(Kpi::formatTarget($r)) . "</small></div></div>";
    };
    echo "<div class='mkt-vg2'><div class='mkt-panel mkt-read'><div class='mkt-panel-title'><i class='ti ti-file-text me-1'></i>Leitura do período</div>" . implode('', array_map(fn ($l) => "<p>" . $e($l) . "</p>", $nar ?: ['Sem dados no período.'])) . "</div>"
        . "<div class='mkt-panel'><div class='mkt-panel-title' style='color:#16a34a'><i class='ti ti-circle-check me-1'></i>Destaques positivos</div>" . ($best ? implode('', array_map(fn ($r) => $miniCard($r, '#16a34a'), $best)) : "<div class='text-secondary small'>Nenhum indicador na meta ainda.</div>") . "</div>"
        . "<div class='mkt-panel'><div class='mkt-panel-title' style='color:#dc2626'><i class='ti ti-alert-triangle me-1'></i>Alertas prioritários</div>" . ($worst ? implode('', array_map(fn ($r) => $miniCard($r, Kpi::STATUS_COLORS[$r['status']]), $worst)) : "<div class='text-secondary small'>Nenhum indicador fora da meta.</div>") . "</div></div>";

    // ---- faixa 3: tendências principais
    echo "<div class='mkt-section-title'><i class='ti ti-chart-line'></i> Tendências principais</div><div class='mkt-vg4'>"
        . "<div class='mkt-panel'><div class='mkt-panel-title'>Recebidas x concluídas por mês</div><div class='mkt-chart' id='kpi-flow' style='height:250px'></div></div>"
        . "<div class='mkt-panel'><div class='mkt-panel-title'>Entregas no prazo (%) e meta</div><div class='mkt-chart' id='kpi-otd' style='height:250px'></div></div>"
        . "<div class='mkt-panel'><div class='mkt-panel-title'>Backlog em aberto no fim de cada mês</div><div class='mkt-chart' id='kpi-backlog' style='height:250px'></div></div>"
        . "<div class='mkt-panel'><div class='mkt-panel-title'>Atrasadas e adiadas (% do backlog)</div><div class='mkt-chart' id='kpi-late' style='height:250px'></div></div></div>";
    // ---- mapa de calor (grade HTML): grupos x meses, com índice geral
    $first = 0;
    $nM = count($labels12);
    while ($first < $nM - 6 && $monthlyOverall[$first] === null) {
        $first++;
    }
    $cols = $nM - $first;
    $cell = function (?float $v, string $g, string $mm, bool $bold = false) {
        if ($v === null) {
            return "<div class='mkt-hm-cell nodata'>—</div>";
        }
        $c = $v >= 85 ? '#16a34a' : ($v >= 65 ? '#f59e0b' : '#dc2626');
        return "<div class='mkt-hm-cell mkt-drill" . ($bold ? ' strong' : '') . "' data-g='$g' data-m='$mm' style='--c:$c' title='" . (int) round($v) . " pontos · clique para ver os itens'>" . (int) round($v) . "</div>";
    };
    echo "<div class='mkt-panel mb-3'><div class='d-flex flex-wrap align-items-center gap-2 mb-2'><div class='mkt-panel-title mb-0'>Mapa de calor — pontuação dos grupos mês a mês</div>"
        . "<div class='mkt-hm-legend ms-auto'><span><i style='background:#16a34a'></i>Na meta (85+)</span><span><i style='background:#f59e0b'></i>Atenção (65–84)</span><span><i style='background:#dc2626'></i>Crítico (&lt; 65)</span><span><i class='nodata'></i>Sem dados</span></div></div>"
        . "<div class='mkt-hm' style='--cols:$cols'><div class='mkt-hm-corner'></div>";
    for ($i = $first; $i < $nM; $i++) {
        echo "<div class='mkt-hm-col" . ($i === $nM - 1 ? ' now' : '') . "'>" . $e($labels12[$i]) . "</div>";
    }
    foreach ($sum['groups'] as $gid => $g) {
        echo "<div class='mkt-hm-row'><span class='dot' style='background:" . $e($g['color']) . "'></span>" . $e($g['name']) . "</div>";
        for ($i = $first; $i < $nM; $i++) {
            echo $cell($monthlyGroups[$gid][$i] ?? null, $gid, array_keys($series)[$i]);
        }
    }
    echo "<div class='mkt-hm-row total'>Índice geral</div>";
    for ($i = $first; $i < $nM; $i++) {
        echo $cell($monthlyOverall[$i] ?? null, 'all', array_keys($series)[$i], true);
    }
    echo "</div></div>";

    // ---- cartões por grupo
    echo "<div class='mkt-section-title'><i class='ti ti-layout-grid'></i> Indicadores por grupo</div>";
    foreach ($sum['groups'] as $gid => $g) {
        $rows = array_filter($sum['rows'], fn ($r) => $r['group'] === $gid);
        $sc = $g['score'];
        $gc = $sc === null ? '#94a3b8' : ($sc >= 85 ? '#16a34a' : ($sc >= 65 ? '#f59e0b' : '#dc2626'));
        echo "<div class='mkt-group'><div class='mkt-group-head' style='--g:" . $e($g['color']) . "'><span class='mkt-ring' style='--p:" . (int) ($sc ?? 0) . ";--c:$gc'><b>" . ($sc === null ? '—' : (int) $sc) . "</b></span>"
            . "<div><div class='mkt-group-name'>" . $e($g['name']) . "</div><div class='mkt-group-sub'>{$g['green']} na meta · {$g['amber']} em atenção · {$g['red']} críticos · " . ($g['count'] - count($g['scores'])) . " informativos</div></div></div><div class='mkt-kpi-grid'>";
        foreach ($rows as $k => $r) {
            $col = Kpi::STATUS_COLORS[$r['status']];
            [$ic, $tc] = $trendIcon[$r['trend']];
            $chipTxt = $r['prev'] === null ? 'sem anterior' : 'ant. ' . Kpi::format($r, $r['prev']);
            echo "<div class='mkt-kcard mkt-drill' data-k='" . $e($k) . "' style='--c:" . $e($col) . "' title='Clique para ver os itens deste indicador'>"
                . "<div class='mkt-kcard-top'><div class='mkt-kcard-name'>" . $e($r['name']) . "</div>" . ($r['status'] !== 'none' ? "<span class='mkt-dot' style='background:$col' title='" . $e(Kpi::STATUS_LABELS[$r['status']]) . "'></span>" : '') . "</div>"
                . "<div class='mkt-kcard-val'>" . $e(Kpi::format($r, $r['value'])) . "</div>"
                . "<div class='mkt-kcard-meta'><span>Meta " . $e(Kpi::formatTarget($r)) . "</span><span style='color:$tc'>$ic " . $e($chipTxt) . "</span></div>"
                . "<div class='mkt-kspark' data-spark='" . $e($k) . "'></div></div>";
        }
        echo "</div></div>";
    }
}

// ================================================================= INDICADORES
if ($tab === 'indicadores') {
    $mn = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $chart = ['labels' => array_map(fn ($m) => $mn[(int) substr($m, 5, 2)] . ((int) substr($m, 5, 2) === 1 ? '/' . substr($m, 2, 2) : ''), array_keys($series)), 'spark' => [], 'targets' => [], 'series' => [], 'monthly' => [], 'groupsMonthly' => []];
    foreach ($sum['rows'] as $k => $r) {
        $chart['spark'][$k] = array_values(array_map(fn ($mv) => $mv[$k] ?? null, $series));
        $chart['targets'][$k] = $r['target'];
    }
    $chart['from'] = $from;
    $chart['to'] = $to;
    $chart['drill'] = Ui::url('/front/kpi_drill.php');
    echo "<script type='application/json' id='mkt-kpi-data'>" . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>";
    $cnt = ['green' => 0, 'amber' => 0, 'red' => 0, 'none' => 0];
    foreach ($sum['rows'] as $r) {
        $cnt[$r['status']]++;
    }
    echo "<div class='mkt-ind-bar'><div class='mkt-filterchips' id='ind-filters'>"
        . "<button type='button' class='on' data-f='all'>Todos <b>" . count($sum['rows']) . "</b></button>"
        . "<button type='button' data-f='red' style='--c:#dc2626'>Críticos <b>{$cnt['red']}</b></button>"
        . "<button type='button' data-f='amber' style='--c:#f59e0b'>Atenção <b>{$cnt['amber']}</b></button>"
        . "<button type='button' data-f='green' style='--c:#16a34a'>Na meta <b>{$cnt['green']}</b></button>"
        . "<button type='button' data-f='none' style='--c:#94a3b8'>Informativos <b>{$cnt['none']}</b></button></div>"
        . "<input type='search' class='form-control mkt-ind-search' id='ind-search' placeholder='Buscar indicador…' autocomplete='off'>"
        . "<div class='ms-auto small text-secondary'>Clique na linha para ver a fórmula e por que o indicador importa.</div></div>";
    echo "<div class='mkt-ind'><div class='mkt-ind-head'><div>Indicador</div><div class='c-r'>Realizado</div><div class='c-c'>Meta</div><div>Atingimento</div><div class='c-c'>Anterior</div><div class='c-c'>Status</div><div class='c-c'>12 meses</div></div>";
    foreach ($sum['groups'] as $gid => $g) {
        $rows = array_filter($sum['rows'], fn ($r) => $r['group'] === $gid);
        $sc = $g['score'];
        $gc = $sc === null ? '#94a3b8' : ($sc >= 85 ? '#16a34a' : ($sc >= 65 ? '#f59e0b' : '#dc2626'));
        echo "<section class='mkt-ind-group' data-group><div class='mkt-ind-grouphead' style='--g:" . $e($g['color']) . "'><span class='mkt-ring' style='--p:" . (int) ($sc ?? 0) . ";--c:$gc'><b>" . ($sc === null ? '—' : (int) $sc) . "</b></span>"
            . "<div><div class='mkt-group-name'>" . $e($g['name']) . "</div><div class='mkt-group-sub'>{$g['green']} na meta · {$g['amber']} em atenção · {$g['red']} críticos · " . ($g['count'] - count($g['scores'])) . " informativos</div></div></div>";
        foreach ($rows as $k => $r) {
            $col = Kpi::STATUS_COLORS[$r['status']];
            $dv = ($r['value'] !== null && $r['prev'] !== null) ? $r['value'] - $r['prev'] : null;
            $better = ($dv === null || $r['dir'] === 'info') ? null : (abs($dv) < 0.0001 ? null : (($dv >= 0) === ($r['dir'] === 'up')));
            $dcol = $better === null ? '#94a3b8' : ($better ? '#16a34a' : '#dc2626');
            $score = $r['score'];
            echo "<div class='mkt-ind-row' data-status='" . $e($r['status']) . "' data-name='" . $e(mb_strtolower($r['name'])) . "' style='--c:" . $e($col) . "'>"
                . "<div class='mkt-ind-main'><div class='mkt-ind-name'><i class='ti ti-chevron-right mkt-ind-caret'></i><span>" . $e($r['name']) . "</span></div>"
                . "<div class='c-r mkt-ind-val'>" . $e(Kpi::format($r, $r['value'])) . "</div>"
                . "<div class='c-c mkt-ind-meta'>" . $e(Kpi::formatTarget($r)) . "</div>"
                . "<div class='mkt-ind-att'>" . ($score === null ? "<span class='text-secondary small'>informativo</span>" : "<div class='mkt-attbar'><span style='width:" . (int) $score . "%'></span></div><b>" . (int) $score . "</b>") . "</div>"
                . "<div class='c-c mkt-ind-prev'><div>" . ($r['prev'] === null ? '—' : $e(Kpi::format($r, $r['prev']))) . "</div>" . ($dv === null ? '' : "<small style='color:$dcol'>" . ($dv >= 0 ? '▲' : '▼') . ' ' . $e(Kpi::format($r, abs($dv))) . "</small>") . "</div>"
                . "<div class='c-c'><span class='mkt-pill' style='--c:" . $e($col) . "'>" . $e(Kpi::STATUS_LABELS[$r['status']]) . "</span></div>"
                . "<div class='mkt-ind-spark' data-spark='" . $e($k) . "'></div></div>"
                . "<div class='mkt-ind-detail' hidden><div class='mkt-ind-act'><button type='button' class='btn btn-sm btn-primary mkt-drill' data-k='" . $e($k) . "'><i class='ti ti-list-search me-1'></i>Ver itens deste indicador</button></div><div><b>Como é calculado</b><br>" . $e($r['formula']) . ($r['cur'] ? " <span class='text-secondary'>(retrato do dia)</span>" : '') . "</div><div><b>Por que importa</b><br>" . $e($r['why']) . "</div>"
                . "<div><b>Meta e limite de atenção</b><br>" . ($r['dir'] === 'info' ? 'Indicador informativo (sem meta).' : $e(Kpi::formatTarget($r) . ' · atenção até ' . Kpi::format($r, (float) $r['warn']))) . "</div></div></div>";
        }
        echo "</section>";
    }
    echo "</div>";
    Html::requireJs('charts');
    echo Ui::script('kpi.js');
    echo "<script>(function(){var rows=[].slice.call(document.querySelectorAll('.mkt-ind-row')),f='all',q='';"
        . "function apply(){rows.forEach(function(r){var ok=(f==='all'||r.dataset.status===f)&&(q===''||r.dataset.name.indexOf(q)>-1);r.hidden=!ok;});document.querySelectorAll('[data-group]').forEach(function(g){g.hidden=!g.querySelector('.mkt-ind-row:not([hidden])');});}"
        . "document.getElementById('ind-filters').addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;f=b.dataset.f;this.querySelectorAll('button').forEach(function(x){x.classList.toggle('on',x===b);});apply();});"
        . "document.getElementById('ind-search').addEventListener('input',function(){q=this.value.trim().toLowerCase();apply();});"
        . "document.querySelector('.mkt-ind').addEventListener('click',function(e){var m=e.target.closest('.mkt-ind-main');if(!m)return;var d=m.nextElementSibling;d.hidden=!d.hidden;m.classList.toggle('open',!d.hidden);});})();</script>";
}

// ================================================================= OKRs
if ($tab === 'okrs') {
    $okrs = Okr::all();
    $pillars = Structure::pillarOptions(false);
    $projOpts = Structure::projectOptions(false);
    $kpiOpts = [];
    foreach (Kpi::catalog() as $k => $def) {
        $kpiOpts[$k] = $def['name'] . ' (' . Kpi::GROUPS[$def['group']][0] . ')';
    }
    $okrForm = fn (array $o) => "<form method='post' action='" . $e(Ui::url('/front/kpi.php')) . "'>" . Ui::csrf() . "<input type='hidden' name='do' value='okr_save'><input type='hidden' name='id' value='" . (int) ($o['id'] ?? 0) . "'>"
        . "<div class='row g-2'><div class='col-md-4'><label class='form-label'>Objetivo *</label><input class='form-control' name='name' value='" . $e($o['name'] ?? '') . "' required placeholder='Ex.: Entregar com previsibilidade'></div>"
        . "<div class='col-md-2'><label class='form-label'>Início *</label><input class='form-control' type='date' name='date_from' value='" . $e($o['date_from'] ?? date('Y-m-01')) . "' required></div>"
        . "<div class='col-md-2'><label class='form-label'>Fim *</label><input class='form-control' type='date' name='date_to' value='" . $e($o['date_to'] ?? date('Y-12-31')) . "' required></div>"
        . "<div class='col-md-2'><label class='form-label'>Responsável</label>" . Ui::select('users_id', $users, $o['users_id'] ?? $me, '', true, 'Ninguém') . "</div>"
        . "<div class='col-md-2'><label class='form-label'>Pilar (opcional)</label>" . Ui::select('pillars_id', $pillars, $o['pillars_id'] ?? 0, '', true, 'Todos') . "</div></div>"
        . "<div class='row g-2 mt-1'><div class='col-md-10'><label class='form-label'>Descrição</label><input class='form-control' name='description' value='" . $e($o['description'] ?? '') . "'></div>"
        . "<div class='col-md-2 d-flex align-items-end'><label class='form-check'><input class='form-check-input' type='checkbox' name='is_archived' value='1'" . (!empty($o['is_archived']) ? ' checked' : '') . "><span class='form-check-label'>Arquivado</span></label></div></div>"
        . "<div class='mt-2'><button class='btn btn-primary'>Salvar objetivo</button></div></form>";
    $krForm = fn (int $okrId, array $k) => "<form method='post' action='" . $e(Ui::url('/front/kpi.php')) . "' class='border rounded p-2 mb-2'>" . Ui::csrf() . "<input type='hidden' name='do' value='kr_save'><input type='hidden' name='okrs_id' value='$okrId'><input type='hidden' name='id' value='" . (int) ($k['id'] ?? 0) . "'>"
        . "<div class='row g-2'><div class='col-md-3'><label class='form-label small'>Resultado-chave *</label><input class='form-control form-control-sm' name='name' value='" . $e($k['name'] ?? '') . "' required placeholder='Ex.: Entregas no prazo ≥ 90%'></div>"
        . "<div class='col-md-2'><label class='form-label small'>Como medir</label>" . Ui::select('kind', Okr::KINDS, $k['kind'] ?? 'kpi') . "</div>"
        . "<div class='col-md-3'><label class='form-label small'>Indicador (se automático)</label>" . Ui::select('kpi_key', $kpiOpts, $k['kpi_key'] ?? '', '', true, '—') . "</div>"
        . "<div class='col-md-2'><label class='form-label small'>Projeto (se projeto)</label>" . Ui::select('projects_id', $projOpts, $k['projects_id'] ?? 0, '', true, '—') . "</div>"
        . "<div class='col-md-1'><label class='form-label small'>Peso</label><input class='form-control form-control-sm' type='number' min='1' max='10' name='weight' value='" . (int) ($k['weight'] ?? 1) . "'></div></div>"
        . "<div class='row g-2 mt-1'><div class='col-md-2'><label class='form-label small'>Valor inicial</label><input class='form-control form-control-sm' name='start_value' value='" . $e($k['start_value'] ?? '0') . "'></div>"
        . "<div class='col-md-2'><label class='form-label small'>Meta *</label><input class='form-control form-control-sm' name='target_value' value='" . $e($k['target_value'] ?? '') . "'></div>"
        . "<div class='col-md-2'><label class='form-label small'>Atual (se manual)</label><input class='form-control form-control-sm' name='current_value' value='" . $e($k['current_value'] ?? '') . "'></div>"
        . "<div class='col-md-6 d-flex align-items-end gap-2'><button class='btn btn-sm btn-outline-primary'>Salvar resultado-chave</button></div></div></form>";
    if (!$okrs) {
        echo "<div class='card card-body mb-3 text-secondary'>Nenhum OKR cadastrado. Crie um objetivo e acrescente resultados-chave medidos pelos indicadores do plugin, por projetos ou por valores informados à mão.</div>";
    }
    foreach ($okrs as $o) {
        $ev = Okr::evaluate($o);
        $col = Kpi::STATUS_COLORS[$ev['status']];
        echo "<div class='card mb-3'><div class='card-header'><div><h3 class='card-title mb-0'>" . $e($o['name']) . ($o['is_archived'] ? " <span class='text-secondary small'>(arquivado)</span>" : '') . "</h3>"
            . "<div class='small text-secondary'>" . $e(Ui::date($o['date_from']) . ' a ' . Ui::date($o['date_to'])) . " · " . (int) $ev['elapsed'] . "% do período decorrido · responsável " . $e($o['users_id'] ? getUserName((int) $o['users_id']) : '—') . "</div></div>"
            . "<div class='ms-auto text-end' style='min-width:220px'><div class='fw-bold' style='color:$col;font-size:1.4rem'>" . ($ev['progress'] === null ? '—' : (int) $ev['progress'] . '%') . "</div>" . $stPill($ev['status']) . "</div></div><div class='card-body'>"
            . "<div class='mkt-okrbar mb-3'><span style='width:" . (int) ($ev['progress'] ?? 0) . "%;background:$col'></span><i style='left:" . (int) $ev['elapsed'] . "%' title='Tempo decorrido'></i></div>";
        foreach ($ev['krs'] as $kr) {
            $kc = Kpi::STATUS_COLORS[$kr['status']];
            $def = $kr['kind'] === 'kpi' ? (Kpi::catalog()[$kr['kpi_key']] ?? null) : null;
            $fmt = fn ($v) => $v === null ? '—' : ($def ? Kpi::format($def, (float) $v) : rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ','));
            echo "<div class='d-flex flex-wrap align-items-center gap-3 py-1 border-bottom'><span class='dot' style='background:$kc'></span><div class='flex-grow-1'><b>" . $e($kr['name']) . "</b> <span class='text-secondary small'>" . $e(Okr::KINDS[$kr['kind']]) . ($def ? ' · ' . $e($def['name']) : '') . " · peso " . (int) $kr['weight'] . "</span></div>"
                . "<div class='small'>inicial " . $e($fmt($kr['start_value'])) . " → <b>atual " . $e($fmt($kr['current'])) . "</b> → meta " . $e($fmt($kr['target_value'])) . "</div>"
                . "<div class='mkt-okrbar sm'><span style='width:" . (int) ($kr['progress'] ?? 0) . "%;background:$kc'></span></div><div class='fw-bold' style='min-width:3rem;color:$kc'>" . ($kr['progress'] === null ? '—' : (int) $kr['progress'] . '%') . "</div>"
                . "<details><summary class='small text-primary' style='cursor:pointer'>editar</summary>" . $krForm((int) $o['id'], $kr)
                . "<form method='post' action='" . $e(Ui::url('/front/kpi.php')) . "'>" . Ui::csrf() . "<input type='hidden' name='do' value='kr_delete'><input type='hidden' name='okrs_id' value='" . (int) $o['id'] . "'><input type='hidden' name='id' value='" . (int) $kr['id'] . "'><button class='btn btn-sm btn-outline-danger' onclick=\"return confirm('Remover este resultado-chave?')\">Remover</button></form></details></div>";
        }
        echo "<details class='mt-3'><summary class='fw-bold' style='cursor:pointer'><i class='ti ti-plus me-1'></i>Adicionar resultado-chave</summary><div class='mt-2'>" . $krForm((int) $o['id'], []) . "</div></details>"
            . "<details class='mt-2'><summary class='small text-secondary' style='cursor:pointer'>Editar objetivo</summary><div class='mt-2'>" . $okrForm($o)
            . "<form method='post' action='" . $e(Ui::url('/front/kpi.php')) . "' class='mt-2'>" . Ui::csrf() . "<input type='hidden' name='do' value='okr_delete'><input type='hidden' name='id' value='" . (int) $o['id'] . "'><button class='btn btn-sm btn-outline-danger' onclick=\"return confirm('Remover o objetivo e seus resultados-chave?')\">Remover objetivo</button></form></div></details></div></div>";
    }
    echo "<div class='card card-body'><h4>Novo objetivo</h4>" . $okrForm([]) . "<div class='text-secondary small mt-2'><b>Como medir:</b> <i>Indicador</i> usa o valor do KPI no período do objetivo; <i>Projeto</i> usa a execução efetiva do projeto (meta padrão 100); <i>Valor à mão</i> usa o campo “Atual”. O progresso é (atual − inicial) ÷ (meta − inicial). O semáforo compara o progresso com o tempo decorrido: verde se não estiver mais de 10 p.p. atrás, âmbar até 25 p.p., vermelho além.</div></div>";
}

// ================================================================= OFENSORES
if ($tab === 'ofensores') {
    $offs = Kpi::offenders($sum['rows'], date('Y-m-d'));
    echo "<div class='alert alert-info py-2 small'><div>Prazos recomendados em <b>dias úteis</b> a partir de hoje (" . $e(Ui::date(date('Y-m-d'))) . "): crítico 20, alto 30, médio 40 (higiene de dados: metade). A meta intermediária é o ponto médio entre o valor atual e a meta, na metade do prazo.</div></div>";
    echo "<h3 class='mkt-section-title'>Indicadores fora da meta</h3>";
    if (!$offs) {
        echo "<div class='card card-body text-success'><b>Nenhum indicador fora da meta no período.</b> Mantenha o acompanhamento e revise as metas.</div>";
    }
    foreach ($offs as $o) {
        [$sl, $sc] = Kpi::SEVERITY[$o['severity']];
        $def = Kpi::catalog()[$o['key']];
        echo "<div class='card mb-2' style='border-left:4px solid $sc'><div class='card-body py-2'><div class='d-flex flex-wrap align-items-center gap-3'><span class='badge' style='background:$sc;color:#fff'>" . $e($sl) . "</span>"
            . "<b>" . $e($o['name']) . "</b><span class='text-secondary small'>" . $e(Kpi::GROUPS[$o['group']][0]) . "</span>"
            . "<span class='ms-auto'>Realizado <b style='color:$sc'>" . $e(Kpi::format($def, $o['value'])) . "</b> · meta " . $e(Kpi::formatTarget($def)) . "</span></div>"
            . "<div class='row mt-2'><div class='col-md-4 small'><div class='text-secondary text-uppercase' style='font-size:.68rem'>Por que importa</div>" . $e($o['why']) . "</div>"
            . "<div class='col-md-5 small'><div class='text-secondary text-uppercase' style='font-size:.68rem'>Ações recomendadas</div><ol class='mb-0 ps-3'>" . implode('', array_map(fn ($a) => "<li>" . $e($a) . "</li>", $o['actions'])) . "</ol></div>"
            . "<div class='col-md-3 small'><div class='text-secondary text-uppercase' style='font-size:.68rem'>Responsável e prazos</div><b>" . $e($o['owner']) . "</b><br>Revisão: " . $e(Ui::date($o['review_date'])) . "<br>Intermediário: " . $e(Ui::date($o['mid_date'])) . " (" . $e(Kpi::format($def, $o['mid_value'])) . ")<br><b style='color:$sc'>Prazo final: " . $e(Ui::date($o['due_date'])) . "</b></div></div></div></div>";
    }
    $dem = Kpi::offenderDemands(date('Y-m-d'), 30);
    echo "<h3 class='mkt-section-title mt-4'>Demandas ofensoras e prazo recomendado</h3><div class='card'><div class='table-responsive'><table class='table card-table table-sm'><thead><tr><th>Demanda</th><th>Responsável</th><th>Prior.</th><th>Prazo atual</th><th>Motivos</th><th>Prazo recomendado</th><th>Ação recomendada</th></tr></thead><tbody>";
    foreach ($dem as $r) {
        echo "<tr><td><a href='" . $e(Ui::url('/front/demand.php?id=' . $r['id'])) . "'>" . $e($r['code']) . "</a> " . $e($r['name']) . "</td><td class='small'>" . $e($r['assignee'] ? getUserName($r['assignee']) : '—') . "</td><td>" . (int) $r['priority'] . "</td><td class='small'>" . $e(Ui::date($r['deadline'])) . "</td>"
            . "<td class='small text-warning'>" . $e(implode('; ', $r['reasons'])) . "</td><td class='fw-bold'>" . $e(Ui::date($r['recommended'])) . " <span class='text-secondary small'>(" . (int) $r['du'] . " d.u.)</span></td><td class='small'>" . $e($r['action']) . "</td></tr>";
    }
    if (!$dem) {
        echo "<tr><td colspan='7' class='text-center text-secondary'>Nenhuma demanda ofensora.</td></tr>";
    }
    echo "</tbody></table></div></div>";
    $prj = array_filter(Kpi::projectRisks(), fn ($r) => $r['risk']);
    echo "<h3 class='mkt-section-title mt-4'>Projetos em risco</h3><div class='card'><div class='table-responsive'><table class='table card-table table-sm'><thead><tr><th>Projeto</th><th>Responsável</th><th>Execução</th><th>Cronograma</th><th>Desvio</th><th>Atrasadas</th><th>Replanejar até</th></tr></thead><tbody>";
    foreach ($prj as $r) {
        echo "<tr><td><a href='" . $e(Ui::url('/front/project.php?id=' . $r['id'])) . "'>" . $e($r['name']) . "</a></td><td class='small'>" . $e($r['owner'] ? getUserName($r['owner']) : '—') . "</td><td>" . ($r['effective'] === null ? '—' : (int) $r['effective'] . '%') . "</td><td>" . ($r['expected'] === null ? '—' : (int) $r['expected'] . '%') . "</td>"
            . "<td class='text-danger fw-bold'>" . ($r['deviation'] === null ? '—' : (int) $r['deviation'] . ' p.p.') . "</td><td>" . (int) $r['late'] . "</td><td class='fw-bold'>" . $e(Ui::date($r['recommended'])) . "</td></tr>";
    }
    if (!$prj) {
        echo "<tr><td colspan='7' class='text-center text-secondary'>Nenhum projeto em risco.</td></tr>";
    }
    echo "</tbody></table></div></div>";
}

// ================================================================= BOOKS
if ($tab === 'books') {
    $books = iterator_to_array($DB->request(['FROM' => Book::table(), 'ORDER' => 'id DESC', 'LIMIT' => 60]), false);
    echo "<div class='row g-3 mb-3'><div class='col-lg-5'><form method='post' action='" . $e(Ui::url('/front/kpi.php')) . "' class='card card-body h-100'>" . Ui::csrf() . "<input type='hidden' name='do' value='book_generate'><h4>Gerar book agora</h4>"
        . "<div class='mb-2'><label class='form-label'>Tipo</label><select class='form-select' name='kind' id='book-kind'><option value='monthly'>Mensal (um mês)</option><option value='annual'>Anual (um ano)</option><option value='manual'>Período livre</option></select></div>"
        . "<div class='mb-2 book-f book-monthly'><label class='form-label'>Mês</label><select class='form-select' name='month'>";
    for ($i = 0; $i < 24; $i++) {
        $mm = date('Y-m', strtotime("first day of -$i month"));
        echo "<option value='" . $e($mm) . "'>" . $e(Book::monthPeriod($mm)[2]) . "</option>";
    }
    echo "</select></div><div class='mb-2 book-f book-annual' hidden><label class='form-label'>Ano</label><select class='form-select' name='year'>";
    for ($y = (int) date('Y'); $y >= (int) date('Y') - 4; $y--) {
        echo "<option value='$y'>$y</option>";
    }
    echo "</select></div><div class='row g-2 mb-2 book-f book-manual' hidden><div class='col'><label class='form-label'>De</label><input class='form-control' type='date' name='from' value='" . $e(date('Y-m-01')) . "'></div><div class='col'><label class='form-label'>Até</label><input class='form-control' type='date' name='to' value='" . $e(date('Y-m-d')) . "'></div></div>"
        . "<label class='form-check mb-3'><input class='form-check-input' type='checkbox' name='send' value='1'><span class='form-check-label'>Enviar também aos destinatários configurados</span></label>"
        . "<button class='btn btn-primary'><i class='ti ti-file-type-pdf me-1'></i>Gerar book executivo (PDF)</button>"
        . "<div class='text-secondary small mt-2'>A geração leva alguns segundos. O book fica no histórico e pode ser baixado e reenviado.</div></form></div>";
    $rec = Book::recipients();
    $mgrOpts = '';
    $selected = array_filter(array_map('intval', explode(',', Settings::get('book_recipients', ''))));
    foreach ($users as $uid => $uname) {
        $mgrOpts .= "<option value='" . (int) $uid . "'" . (in_array((int) $uid, $selected, true) ? ' selected' : '') . ">" . $e($uname) . "</option>";
    }
    echo "<div class='col-lg-7'><form method='post' action='" . $e(Ui::url('/front/kpi.php')) . "' enctype='multipart/form-data' class='card card-body h-100'>" . Ui::csrf() . "<input type='hidden' name='do' value='book_settings'><h4>Envio automático</h4>"
        . "<div class='row g-3'><div class='col-md-6'><label class='form-check'><input class='form-check-input' type='checkbox' name='book_monthly' value='1'" . (Settings::get('book_monthly', '1') === '1' ? ' checked' : '') . "><span class='form-check-label'><b>Book mensal</b>: todo dia 1º, com o mês anterior</span></label>"
        . "<label class='form-check mt-2'><input class='form-check-input' type='checkbox' name='book_annual' value='1'" . (Settings::get('book_annual', '1') === '1' ? ' checked' : '') . "><span class='form-check-label'><b>Book anual</b>: no fim do ano</span></label>"
        . "<div class='mt-2'><label class='form-label small'>Quando enviar o anual</label><select class='form-select form-select-sm' name='book_annual_when'><option value='dec31'" . (Settings::get('book_annual_when', 'dec31') === 'dec31' ? ' selected' : '') . ">Em 31 de dezembro (ano corrente)</option><option value='jan1'" . (Settings::get('book_annual_when', 'dec31') === 'jan1' ? ' selected' : '') . ">Em 1º de janeiro (ano que terminou)</option></select></div>"
        . "<div class='mt-3'><label class='form-label small'>Logotipo da capa (PNG ou JPG)</label><input class='form-control form-control-sm' type='file' name='logo' accept='.png,.jpg,.jpeg'>" . (Settings::get('book_logo', '') !== '' && is_file(dirname(Book::dir()) . '/' . basename(Settings::get('book_logo', ''))) ? "<div class='small text-success mt-1'>Logotipo próprio configurado.</div>" : "<div class='small text-secondary mt-1'>Usando o logotipo padrão da Oakmont em todas as páginas. Envie um arquivo para trocar.</div>") . "</div></div>"
        . "<div class='col-md-6'><label class='form-label'>Destinatários (usuários do GLPI)</label><select class='form-select' name='recipients[]' multiple size='6'>$mgrOpts</select>"
        . "<label class='form-label small mt-2'>Outros e-mails (um por linha)</label><textarea class='form-control form-control-sm' name='emails' rows='2'>" . $e(Settings::get('book_emails', '')) . "</textarea></div></div>"
        . "<div class='mt-3 d-flex align-items-center gap-3'><button class='btn btn-primary'>Salvar</button><div class='small text-secondary'>Próximo mensal: <b>01/" . date('m/Y', strtotime('first day of next month')) . "</b> · e-mails válidos configurados: <b>" . count($rec['emails']) . "</b>. O envio por e-mail exige as notificações por e-mail ligadas no GLPI; sem elas, o book fica no histórico e os destinatários recebem o aviso na tela.</div></div></form></div></div>";
    echo "<div class='card'><div class='card-header'><h3 class='card-title'>Histórico de books</h3></div><div class='table-responsive'><table class='table card-table table-sm'><thead><tr><th>Período</th><th>Tipo</th><th>Índice</th><th>Gerado em</th><th>Por</th><th>Tamanho</th><th>Envio</th><th></th></tr></thead><tbody>";
    foreach ($books as $b) {
        echo "<tr><td class='fw-bold'>" . $e($b['label']) . "</td><td>" . $e(Book::KINDS[$b['kind']] ?? $b['kind']) . "</td><td>" . ($b['score'] === null ? '—' : "<b>" . (int) $b['score'] . "</b>/100") . "</td><td class='small'>" . $e(Ui::datetime($b['date_creation'])) . "</td>"
            . "<td class='small'>" . $e($b['users_id'] ? getUserName((int) $b['users_id']) : 'Automático') . "</td><td class='small'>" . $e(number_format($b['size'] / 1024, 0, ',', '.')) . " KB</td>"
            . "<td class='small'>" . $e($b['send_status'] ?? '—') . ($b['sent_at'] ? ' · ' . $e(Ui::datetime($b['sent_at'])) : '') . "</td><td class='text-end text-nowrap'>"
            . "<a class='btn btn-sm btn-outline-primary' href='" . $e(Ui::url('/front/kpi.php?download=' . (int) $b['id'])) . "'><i class='ti ti-download'></i> PDF</a> "
            . "<form method='post' action='" . $e(Ui::url('/front/kpi.php')) . "' class='d-inline'>" . Ui::csrf() . "<input type='hidden' name='do' value='book_send'><input type='hidden' name='id' value='" . (int) $b['id'] . "'><button class='btn btn-sm btn-outline-secondary' title='Enviar aos destinatários'><i class='ti ti-send'></i></button></form> "
            . "<form method='post' action='" . $e(Ui::url('/front/kpi.php')) . "' class='d-inline'>" . Ui::csrf() . "<input type='hidden' name='do' value='book_delete'><input type='hidden' name='id' value='" . (int) $b['id'] . "'><button class='btn btn-sm btn-outline-danger' onclick=\"return confirm('Remover este book?')\"><i class='ti ti-trash'></i></button></form></td></tr>";
    }
    if (!$books) {
        echo "<tr><td colspan='8' class='text-center text-secondary'>Nenhum book gerado ainda.</td></tr>";
    }
    echo "</tbody></table></div></div>";
    echo "<script>(function(){var k=document.getElementById('book-kind');if(!k)return;function s(){document.querySelectorAll('.book-f').forEach(function(x){x.hidden=!x.classList.contains('book-'+k.value);});}k.addEventListener('change',s);s();})();</script>";
}

// ================================================================= METAS
if ($tab === 'metas') {
    echo "<div class='alert alert-info py-2 small'><div>Defina a <b>meta</b> (verde) e o <b>limite de atenção</b> (âmbar) de cada indicador. Em indicadores “maior é melhor” a meta é um mínimo e o limite fica abaixo dela; em “menor é melhor” a meta é um máximo e o limite fica acima. Deixe em branco para voltar ao padrão. Indicadores informativos não têm meta.</div></div>";
    echo "<form method='post' action='" . $e(Ui::url('/front/kpi.php')) . "' class='card'>" . Ui::csrf() . "<input type='hidden' name='do' value='targets_save'><div class='table-responsive'><table class='table card-table table-sm'><thead><tr><th>Indicador</th><th>Grupo</th><th>Sentido</th><th>Unidade</th><th>Meta</th><th>Limite de atenção</th><th>Padrão</th></tr></thead><tbody>";
    $cat = Kpi::targets();
    $def0 = Kpi::catalog();
    $custom = json_decode(Settings::get('kpi_targets', '{}'), true) ?: [];
    foreach ($cat as $k => $d) {
        if ($d['dir'] === 'info') {
            continue;
        }
        $unit = ['%' => '%', 'd' => 'dias', 'n' => 'qtd.', 'pp' => 'p.p.'][$d['unit']];
        echo "<tr><td class='fw-bold'>" . $e($d['name']) . "</td><td class='small'>" . $e(Kpi::GROUPS[$d['group']][0]) . "</td><td class='small'>" . ($d['dir'] === 'up' ? 'maior é melhor' : 'menor é melhor') . "</td><td class='small'>$unit</td>"
            . "<td><input class='form-control form-control-sm' style='width:90px' name='t[$k][target]' value='" . $e($custom[$k]['target'] ?? '') . "' placeholder='" . $e($def0[$k]['target']) . "'></td>"
            . "<td><input class='form-control form-control-sm' style='width:90px' name='t[$k][warn]' value='" . $e($custom[$k]['warn'] ?? '') . "' placeholder='" . $e($def0[$k]['warn']) . "'></td>"
            . "<td class='small text-secondary'>meta " . $e($def0[$k]['target']) . " · limite " . $e($def0[$k]['warn']) . "</td></tr>";
    }
    echo "</tbody></table></div><div class='card-footer d-flex gap-2'><button class='btn btn-primary'>Salvar metas</button><button class='btn btn-outline-secondary' name='reset' value='1' onclick=\"return confirm('Restaurar todas as metas para o padrão?')\">Restaurar padrão</button></div></form>";
}

if ($tab === 'visao') {
    Html::requireJs('charts');
    echo Ui::script('kpi.js');
}
Html::footer();

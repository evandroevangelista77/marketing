<?php

/**
 * Gaveta lateral dos KPI's: devolve (JSON) o HTML com as demandas, projetos, planos ou marcos que compõem um indicador no período
 * (parâmetros k, from, to) ou os ofensores de um grupo num mês (g, m). Exclusivo do gerente (direito "Book executivo").
 */

use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\Book;
use GlpiPlugin\Marketing\Demand;
use GlpiPlugin\Marketing\Kpi;
use GlpiPlugin\Marketing\Settings;
use GlpiPlugin\Marketing\Ui;

Session::checkLoginUser();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!Access::canExecutive()) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    return;
}

$e = fn ($v) => Ui::e($v);
$colors = Settings::statusColors();
$date = fn ($v, $d) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : $d;

/** Lista de itens (demandas, projetos, planos ou marcos) em HTML. */
$list = function (array $d) use ($e, $colors): string {
    $h = '';
    if ($d['note'] !== '') {
        $h .= "<p class='text-secondary small mb-2'>" . $e($d['note']) . "</p>";
    }
    $h .= "<div class='small mb-2'><b>" . (int) $d['total'] . "</b> item(ns)" . ($d['total'] > count($d['rows']) ? ' (mostrando ' . count($d['rows']) . ')' : '') . "</div>";
    if (!$d['rows']) {
        return $h . "<div class='text-secondary'>Nenhum item neste recorte.</div>";
    }
    foreach ($d['rows'] as $r) {
        if ($d['kind'] === 'demands') {
            $st = (string) $r['status'];
            $dl = !empty($r['deadline']) ? Ui::date($r['deadline']) : 'sem prazo';
            $h .= "<a class='mkt-dr-item' href='" . $e(Ui::url('/front/demand.php?id=' . (int) $r['id'])) . "'><div class='d-flex gap-2 align-items-center'><b class='mkt-dr-code'>" . $e($r['code']) . "</b>"
                . "<span class='mkt-dr-st' style='--c:" . $e($colors[$st] ?? '#94a3b8') . "'>" . $e(Demand::STATUSES[$st] ?? $st) . "</span><span class='ms-auto small text-secondary'>" . $e($dl) . "</span></div>"
                . "<div class='mkt-dr-name'>" . $e($r['name']) . "</div>"
                . "<div class='small text-secondary d-flex gap-2 align-items-center'>" . Ui::avatar((int) $r['assignee']) . "<span>" . $e($r['assignee'] ? getUserName((int) $r['assignee']) : 'sem responsável') . "</span></div>"
                . ($r['detail'] !== '' ? "<div class='mkt-dr-detail" . (!empty($r['bad']) ? ' bad' : '') . "'>" . $e($r['detail']) . "</div>" : '') . "</a>";
        } else {
            $url = $d['kind'] === 'plans' ? '/front/plan.php?id=' : '/front/project.php?id=';
            $h .= "<a class='mkt-dr-item' href='" . $e(Ui::url($url . (int) $r['id'])) . "'><div class='mkt-dr-name'>" . $e($r['name']) . "</div>"
                . "<div class='mkt-dr-detail" . (!empty($r['risk']) ? ' bad' : '') . "'>" . $e($r['detail']) . "</div></a>";
        }
    }
    return $h;
};

$cat = Kpi::targets();
$from = $date($_GET['from'] ?? '', date('Y-m-01'));
$to = $date($_GET['to'] ?? '', date('Y-m-d'));
$k = (string) ($_GET['k'] ?? '');
$g = (string) ($_GET['g'] ?? '');

if ($k !== '' && isset($cat[$k])) {
    Kpi::resetCache();
    $vals = Kpi::values($from, $to);
    $def = $cat[$k];
    $st = Kpi::status($def, $vals[$k]);
    $col = Kpi::STATUS_COLORS[$st];
    $drill = Kpi::drill($k, $from, $to);
    $html = "<div class='mkt-dr-summary' style='--c:$col'><div><div class='small text-secondary'>Realizado</div><div class='mkt-dr-big'>" . $e(Kpi::format($def, $vals[$k])) . "</div></div>"
        . "<div><div class='small text-secondary'>Meta</div><b>" . $e(Kpi::formatTarget($def)) . "</b></div><div><span class='badge' style='background:$col;color:#fff'>" . $e(Kpi::STATUS_LABELS[$st]) . "</span></div></div>"
        . "<p class='small mb-3'>" . $e($def['formula']) . "</p>" . $list($drill);
    echo json_encode(['ok' => true, 'title' => $def['name'], 'sub' => Ui::date($from) . ' a ' . Ui::date(min($to, date('Y-m-d'))), 'color' => $col, 'icon' => 'ti-target-arrow', 'html' => $html], JSON_UNESCAPED_UNICODE);
    return;
}

if ($g !== '') {
    $m = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
    [$mf, $mt, $label] = Book::monthPeriod($m);
    Kpi::resetCache();
    $vals = Kpi::values($mf, $mt, false);
    $sum = Kpi::summarize($vals);
    $groups = $g === 'all' ? array_keys(Kpi::GROUPS) : [$g];
    $gname = $g === 'all' ? 'Índice geral' : (Kpi::GROUPS[$g][0] ?? $g);
    $score = $g === 'all' ? $sum['overall'] : ($sum['groups'][$g]['score'] ?? null);
    $col = $score === null ? '#94a3b8' : ($score >= 85 ? '#16a34a' : ($score >= 65 ? '#f59e0b' : '#dc2626'));
    $html = "<div class='mkt-dr-summary' style='--c:$col'><div><div class='small text-secondary'>Pontuação em " . $e($label) . "</div><div class='mkt-dr-big'>" . ($score === null ? '—' : (int) $score) . "<small> / 100</small></div></div></div>";
    $any = false;
    foreach ($groups as $gid) {
        foreach ($sum['rows'] as $key => $r) {
            if ($r['group'] !== $gid || !in_array($r['status'], ['red', 'amber'], true)) {
                continue;
            }
            $any = true;
            $drill = Kpi::drill($key, $mf, $mt, 8);
            $sc = Kpi::STATUS_COLORS[$r['status']];
            $html .= "<div class='mkt-dr-kpi' style='--c:$sc'><div class='d-flex align-items-center gap-2'><b>" . $e($r['name']) . "</b><span class='ms-auto fw-bold' style='color:$sc'>" . $e(Kpi::format($r, $r['value'])) . "</span></div>"
                . "<div class='small text-secondary mb-2'>" . ($g === 'all' ? $e(Kpi::GROUPS[$r['group']][0]) . ' · ' : '') . "meta " . $e(Kpi::formatTarget($r)) . "</div>" . $list($drill) . "</div>";
        }
    }
    if (!$any) {
        $html .= "<div class='text-secondary'>Nenhum indicador fora da meta neste grupo e mês.</div>";
    }
    echo json_encode(['ok' => true, 'title' => $gname . ' · ' . $label, 'sub' => 'Indicadores fora da meta e os itens que mais pesam', 'color' => $col, 'icon' => 'ti-layout-grid', 'html' => $html], JSON_UNESCAPED_UNICODE);
    return;
}
http_response_code(422);
echo json_encode(['ok' => false]);

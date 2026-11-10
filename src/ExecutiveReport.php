<?php

namespace GlpiPlugin\Marketing;

/**
 * PDF executivo (A4 paisagem): capa com período e filtros, visão executiva (cartões, situação, tipo, responsável,
 * SLOs), demandas críticas e o detalhamento completo de cada demanda (briefing, datas, pessoas, evidências,
 * aprovações, atualizações e trilha). Usa os mesmos dados do painel (Dashboard::build): os números batem.
 */
class ExecutiveReport
{
    private const NAVY = [15, 23, 42];
    private const BLUE = [37, 99, 235];
    private const MUTED = [100, 116, 139];
    private const LIGHT = [241, 245, 249];
    private const LINE = [203, 213, 225];
    private const W = 273; // largura útil (A4 paisagem, margens de 12)

    private ReportPdf $pdf;
    /** @var array<string, mixed> */
    private array $d;
    /** @var array<int, array<string, array<int, array<string, mixed>>>> */
    private array $details;

    /** @param array<string, mixed> $d resultado de Dashboard::build */
    public static function pdf(array $d, string $author): string
    {
        return (new self($d, $author))->build();
    }

    private string $author;

    private function __construct(array $d, string $author)
    {
        $this->d = $d;
        $this->author = $author;
        $this->details = Dashboard::details(array_keys($d['rows']));
        $this->pdf = new ReportPdf('L', 'mm', 'A4', true, 'UTF-8');
    }

    /** Remove o que a fonte padrão do PDF não desenha (setas, emojis). */
    private static function t($v): string
    {
        $s = str_replace(['→', '⚙', '↔'], ['>', '', '<>'], (string) $v);
        return (string) preg_replace('/[^\x{0009}\x{000A}\x{0020}-\x{00FF}\x{2013}\x{2014}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}\x{2026}]/u', '', $s);
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function user(int $id): string
    {
        return $id > 0 ? self::t(getUserName($id)) : '—';
    }

    private function date(?string $d): string
    {
        return $d ? date('d/m/Y', strtotime($d)) : '—';
    }

    private function datetime(?string $d): string
    {
        return $d ? date('d/m/Y H:i', strtotime($d)) : '—';
    }

    // ------------------------------------------------------------------ montagem

    private function build(): string
    {
        $p = $this->pdf;
        $p->SetCreator('Gestão de Demandas');
        $p->SetAuthor($this->author);
        $p->SetTitle('Relatório executivo — Gestão de Demandas');
        $p->setPrintHeader(false);
        $p->setPrintFooter(true);
        $p->footerText = 'Gestão de Demandas · Marketing · gerado em ' . $this->datetime($this->d['generated']);
        $p->SetMargins(12, 12, 12);
        $p->SetAutoPageBreak(true, 14);

        $this->cover();
        $this->overview();
        $this->charts();
        $this->critical();
        $this->detailing();
        return $p->Output('relatorio.pdf', 'S');
    }

    private function heading(string $text, string $sub = ''): void
    {
        $p = $this->pdf;
        $p->SetFont('helvetica', 'B', 15);
        $p->SetTextColor(...self::NAVY);
        $p->Cell(self::W, 8, self::t($text), 0, 1);
        if ($sub !== '') {
            $p->SetFont('helvetica', '', 9);
            $p->SetTextColor(...self::MUTED);
            $p->MultiCell(self::W, 4.5, self::t($sub), 0, 'L');
        }
        $p->SetDrawColor(...self::BLUE);
        $p->SetLineWidth(0.6);
        $p->Line(12, $p->GetY() + 1, 12 + 40, $p->GetY() + 1);
        $p->Ln(4);
    }

    private function cover(): void
    {
        $p = $this->pdf;
        $f = $this->d['filters'];
        $p->AddPage();
        $p->SetFillColor(...self::NAVY);
        $p->Rect(0, 0, 297, 210, 'F');
        $p->SetFillColor(...self::BLUE);
        $p->Rect(12, 62, 3, 38, 'F');
        $p->SetTextColor(148, 163, 184);
        $p->SetFont('helvetica', 'B', 10);
        $p->SetXY(20, 58);
        $p->Cell(0, 6, 'MARKETING · GESTÃO DE DEMANDAS', 0, 1);
        $p->SetTextColor(255, 255, 255);
        $p->SetFont('helvetica', 'B', 30);
        $p->SetX(20);
        $p->Cell(0, 14, 'Relatório executivo', 0, 1);
        $p->SetFont('helvetica', '', 13);
        $p->SetTextColor(203, 213, 225);
        $p->SetX(20);
        $p->Cell(0, 8, 'Demandas de ' . ($f['from'] ? $this->date($f['from']) : 'o início') . ' a ' . ($f['to'] ? $this->date($f['to']) : 'hoje'), 0, 1);

        $filters = [];
        if ($f['types_id'] > 0) {
            $filters[] = 'Tipo: ' . ($this->d['types'][$f['types_id']]['name'] ?? $f['types_id']);
        }
        if ($f['assignee'] > 0) {
            $filters[] = 'Responsável: ' . getUserName($f['assignee']);
        }
        if ($f['status'] !== '') {
            $filters[] = 'Situação: ' . Demand::STATUSES[$f['status']];
        }
        if ($f['area'] !== '') {
            $filters[] = 'Área: ' . $f['area'];
        }
        if (($f['origin'] ?? '') !== '') {
            $filters[] = 'Origem: ' . Demand::ORIGIN_SHORT[$f['origin']];
        }
        if (($f['pillar'] ?? 0) > 0) {
            $filters[] = 'Pilar: ' . (Structure::pillars()[$f['pillar']]['name'] ?? $f['pillar']);
        }
        if (($f['project'] ?? 0) > 0) {
            $filters[] = 'Projeto: ' . (Structure::projects()[$f['project']]['name'] ?? $f['project']);
        }
        $p->SetFont('helvetica', '', 10);
        $p->SetTextColor(148, 163, 184);
        $p->SetX(20);
        $p->Cell(0, 7, self::t($filters ? 'Filtros: ' . implode(' · ', $filters) : 'Sem filtros adicionais'), 0, 1);
        $p->SetXY(20, 170);
        $p->Cell(0, 6, self::t('Gerado em ' . $this->datetime($this->d['generated']) . ' por ' . $this->author . ' · ' . count($this->d['rows']) . ' demanda(s)'), 0, 1);
    }

    private function overview(): void
    {
        $p = $this->pdf;
        $p->AddPage();
        $this->heading('Visão executiva', 'Indicadores do período e dos filtros escolhidos. Os mesmos números do painel.');

        // cartões em duas linhas de cinco
        $cards = array_values(Dashboard::cards($this->d));
        $cw = (self::W - 4 * 4) / 5;
        $y0 = $p->GetY();
        foreach ($cards as $i => $c) {
            $x = 12 + ($i % 5) * ($cw + 4);
            $y = $y0 + intdiv($i, 5) * 26;
            $col = self::rgb($c['color']);
            $p->SetFillColor(...self::LIGHT);
            $p->SetDrawColor(...self::LINE);
            $p->RoundedRect($x, $y, $cw, 22, 1.5, '1111', 'DF');
            $p->SetFillColor(...$col);
            $p->Rect($x, $y, 1.6, 22, 'F');
            $p->SetXY($x + 4, $y + 2);
            $p->SetFont('helvetica', 'B', 7.5);
            $p->SetTextColor(...self::MUTED);
            $p->Cell($cw - 6, 4, self::t(mb_strtoupper($c['title'])), 0, 1);
            $p->SetXY($x + 4, $y + 7);
            $p->SetFont('helvetica', 'B', 17);
            $p->SetTextColor(...self::NAVY);
            $p->Cell($cw - 6, 8, self::t($c['value']), 0, 1);
            $p->SetXY($x + 4, $y + 16);
            $p->SetFont('helvetica', '', 7);
            $p->SetTextColor(...self::MUTED);
            $p->Cell($cw - 6, 4, self::t($c['sub']), 0, 1);
        }
        $p->SetY($y0 + 2 * 26 + 2);

        // situação e tipo lado a lado
        $y = $p->GetY();
        $total = max(1, count($this->d['rows']));
        $colors = Settings::statusColors();
        $this->miniTable(12, $y, 130, 'Demandas por situação', ['Situação', 'Qtde', '%'], array_map(
            fn ($k, $n) => [Demand::STATUSES[$k], (string) $n, round($n / $total * 100) . '%', $colors[$k]],
            array_keys($this->d['by_status']),
            $this->d['by_status']
        ));
        $typeRows = [];
        foreach ($this->d['by_type'] as $tid => $n) {
            $typeRows[] = [$tid > 0 ? ($this->d['types'][$tid]['name'] ?? 'Tipo removido') : 'Sem tipo', (string) $n, round($n / $total * 100) . '%', $tid > 0 ? ($this->d['types'][$tid]['color'] ?? '#94a3b8') : '#94a3b8'];
        }
        $this->miniTable(150, $y, 135, 'Demandas por tipo', ['Tipo', 'Qtde', '%'], $typeRows);

        // carga por responsável e SLOs
        $p->AddPage();
        $this->heading('Equipe e SLOs');
        $y = $p->GetY();
        $load = [];
        uasort($this->d['by_assignee'], fn ($a, $b) => ($b['open'] ?? 0) <=> ($a['open'] ?? 0));
        foreach ($this->d['by_assignee'] as $uid => $n) {
            $load[] = [$uid > 0 ? getUserName($uid) : 'Sem responsável', (string) (int) ($n['open'] ?? 0) . ' em aberto', (string) (int) $n['total'] . ' total', '#6366f1'];
        }
        $this->miniTable(12, $y, 130, 'Carga por responsável', ['Responsável', 'Aberto', 'Total'], $load);
        $slo = [];
        foreach ($this->d['slos'] as $sid => $s) {
            $r = $this->d['slo_summary'][$sid] ?? [];
            $slo[] = [$s['name'], ((int) ($r['met'] ?? 0)) . ' ok · ' . ((int) ($r['breached'] ?? 0) + (int) ($r['overdue'] ?? 0)) . ' fora', (int) $s['limit_days'] . ' d úteis', '#eab308'];
        }
        if (!$slo) {
            $slo[] = ['Nenhum SLO ativo', '—', '—', '#94a3b8'];
        }
        $this->miniTable(150, $y, 135, 'SLOs', ['SLO', 'Resultado', 'Prazo'], $slo);
    }

    /** Página de gráficos executivos: rosca por situação, tendência semanal, envelhecimento e indicadores. */
    private function charts(): void
    {
        $p = $this->pdf;
        $ch = Dashboard::charts($this->d);
        $colors = Settings::statusColors();
        $p->AddPage();
        $this->heading('Gráficos executivos', 'Situação das demandas, tendência semanal, idade das demandas em aberto e indicadores de desempenho.');
        $y0 = $p->GetY() + 2;

        // ---- rosca por situação
        $p->SetFont('helvetica', 'B', 10);
        $p->SetTextColor(...self::NAVY);
        $p->SetXY(12, $y0);
        $p->Cell(130, 6, 'Demandas por situação', 0, 1);
        $cx = 42;
        $cy = $y0 + 38;
        $r = 28;
        $total = array_sum($this->d['by_status']);
        $angle = 0.0;
        if ($total > 0) {
            foreach ($this->d['by_status'] as $k => $n) {
                if ($n <= 0) {
                    continue;
                }
                $end = $angle + $n / $total * 360;
                $p->SetFillColor(...self::rgb($colors[$k]));
                $p->SetDrawColor(255, 255, 255);
                $p->SetLineWidth(0.4);
                if ($n === $total) {
                    $p->Circle($cx, $cy, $r, 0, 360, 'DF');
                } else {
                    $p->PieSector($cx, $cy, $r, $angle, $end, 'DF', true, 90);
                }
                $angle = $end;
            }
        } else {
            $p->SetFillColor(...self::LIGHT);
            $p->Circle($cx, $cy, $r, 0, 360, 'F');
        }
        $p->SetFillColor(255, 255, 255);
        $p->Circle($cx, $cy, $r * 0.58, 0, 360, 'F');
        $p->SetFont('helvetica', 'B', 18);
        $p->SetTextColor(...self::NAVY);
        $p->SetXY($cx - 14, $cy - 6);
        $p->Cell(28, 8, (string) $total, 0, 1, 'C');
        $p->SetFont('helvetica', '', 7.5);
        $p->SetTextColor(...self::MUTED);
        $p->SetXY($cx - 14, $cy + 2);
        $p->Cell(28, 4, 'demandas', 0, 1, 'C');
        $ly = $y0 + 12;
        foreach (Demand::STATUSES as $k => $label) {
            $p->SetFillColor(...self::rgb($colors[$k]));
            $p->Rect(78, $ly + 1, 3, 3, 'F');
            $p->SetFont('helvetica', '', 8);
            $p->SetTextColor(...self::NAVY);
            $p->SetXY(83, $ly);
            $p->Cell(40, 5, $label, 0, 0);
            $p->SetFont('helvetica', 'B', 8);
            $p->Cell(12, 5, (string) $this->d['by_status'][$k], 0, 1, 'R');
            $ly += 5.3;
        }

        // ---- tendência semanal (linhas)
        $p->SetFont('helvetica', 'B', 10);
        $p->SetTextColor(...self::NAVY);
        $p->SetXY(152, $y0);
        $p->Cell(130, 6, 'Entradas × concluídas por semana', 0, 1);
        $bx = 160;
        $by = $y0 + 10;
        $bw = 118;
        $bh = 50;
        $weeks = $ch['weeks'];
        $max = 1;
        foreach ($weeks as $w) {
            $max = max($max, $w['entries'], $w['done']);
        }
        $top = $max <= 5 ? 5 : (int) (ceil($max / 5) * 5);
        $p->SetDrawColor(...self::LINE);
        $p->SetLineWidth(0.2);
        for ($g = 0; $g <= 4; $g++) {
            $gy = $by + $bh - $bh * $g / 4;
            $p->Line($bx, $gy, $bx + $bw, $gy);
            $p->SetFont('helvetica', '', 6.5);
            $p->SetTextColor(...self::MUTED);
            $p->SetXY($bx - 8, $gy - 1.8);
            $p->Cell(7, 3.6, (string) round($top * $g / 4), 0, 0, 'R');
        }
        $n = count($weeks);
        if ($n > 0) {
            $px = fn (int $i) => $bx + ($n > 1 ? $i * $bw / ($n - 1) : $bw / 2);
            $py = fn (int $v) => $by + $bh - $v / $top * $bh;
            foreach ([['entries', [99, 102, 241]], ['done', [34, 197, 94]]] as [$key, $rgb]) {
                $p->SetDrawColor(...$rgb);
                $p->SetFillColor(...$rgb);
                $p->SetLineWidth(0.7);
                for ($i = 1; $i < $n; $i++) {
                    $p->Line($px($i - 1), $py($weeks[$i - 1][$key]), $px($i), $py($weeks[$i][$key]));
                }
                foreach ($weeks as $i => $w) {
                    $p->Circle($px($i), $py($w[$key]), 0.9, 0, 360, 'F');
                }
            }
            $every = max(1, (int) ceil($n / 8));
            $p->SetFont('helvetica', '', 6.5);
            $p->SetTextColor(...self::MUTED);
            foreach ($weeks as $i => $w) {
                if ($i % $every === 0 || $i === $n - 1) {
                    $p->SetXY($px($i) - 7, $by + $bh + 1.5);
                    $p->Cell(14, 3.6, $w['label'], 0, 0, 'C');
                }
            }
        }
        $p->SetFillColor(99, 102, 241);
        $p->Rect($bx, $by + $bh + 9, 3, 3, 'F');
        $p->SetFont('helvetica', '', 8);
        $p->SetTextColor(...self::NAVY);
        $p->SetXY($bx + 4.5, $by + $bh + 8.4);
        $p->Cell(24, 4, 'Entradas', 0, 0);
        $p->SetFillColor(34, 197, 94);
        $p->Rect($bx + 32, $by + $bh + 9, 3, 3, 'F');
        $p->SetXY($bx + 36.5, $by + $bh + 8.4);
        $p->Cell(24, 4, 'Concluídas', 0, 0);

        // ---- envelhecimento das abertas (colunas)
        $y1 = $y0 + 84;
        $p->SetFont('helvetica', 'B', 10);
        $p->SetTextColor(...self::NAVY);
        $p->SetXY(12, $y1);
        $p->Cell(130, 6, 'Envelhecimento das demandas em aberto', 0, 1);
        $ageMax = 1;
        foreach ($ch['aging'] as $a) {
            $ageMax = max($ageMax, count($a['ids']));
        }
        $colW = 18;
        $gap = 6;
        $baseY = $y1 + 50;
        $i = 0;
        foreach ($ch['aging'] as $label => $a) {
            $x = 16 + $i * ($colW + $gap);
            $v = count($a['ids']);
            $h = $v > 0 ? max(2, $v / $ageMax * 36) : 0;
            $p->SetFillColor(...self::rgb($a['color']));
            if ($h > 0) {
                $p->RoundedRect($x, $baseY - $h, $colW, $h, 1.2, '1100', 'F');
            }
            $p->SetFont('helvetica', 'B', 8);
            $p->SetTextColor(...self::NAVY);
            $p->SetXY($x, $baseY - $h - 5);
            $p->Cell($colW, 4, (string) $v, 0, 0, 'C');
            $p->SetFont('helvetica', '', 7);
            $p->SetTextColor(...self::MUTED);
            $p->SetXY($x - 3, $baseY + 1.5);
            $p->Cell($colW + 6, 4, self::t($label), 0, 0, 'C');
            $i++;
        }
        $p->SetDrawColor(...self::LINE);
        $p->Line(14, $baseY, 14 + 5 * ($colW + $gap) - $gap + 4, $baseY);

        // ---- indicadores de desempenho (barras de progresso)
        $p->SetFont('helvetica', 'B', 10);
        $p->SetTextColor(...self::NAVY);
        $p->SetXY(152, $y1);
        $p->Cell(130, 6, 'Indicadores de desempenho', 0, 1);
        $gauges = [
            ['Entregas no prazo', $this->d['ontime_pct'], [20, 184, 166]],
            ['SLOs cumpridos', $ch['slo_pct'], [234, 179, 8]],
            ['Taxa de conclusão', $ch['completion_pct'], [34, 197, 94]],
        ];
        $gy = $y1 + 10;
        foreach ($gauges as [$label, $pct, $rgb]) {
            $p->SetFont('helvetica', '', 8.5);
            $p->SetTextColor(...self::NAVY);
            $p->SetXY(152, $gy);
            $p->Cell(60, 5, $label, 0, 0);
            $p->SetFont('helvetica', 'B', 9);
            $p->Cell(70, 5, $pct === null ? '—' : $pct . '%', 0, 1, 'R');
            $p->SetFillColor(...self::LIGHT);
            $p->RoundedRect(152, $gy + 6, 130, 4, 2, '1111', 'F');
            if ($pct !== null && $pct > 0) {
                $p->SetFillColor(...$rgb);
                $p->RoundedRect(152, $gy + 6, max(3, 130 * $pct / 100), 4, 2, '1111', 'F');
            }
            $gy += 16;
        }
    }

    /** Tabela compacta com uma marca de cor na primeira coluna. Linha = [col1, col2, col3, cor]. */
    private function miniTable(float $x, float $y, float $w, string $title, array $head, array $rows): void
    {
        $p = $this->pdf;
        $p->SetXY($x, $y);
        $p->SetFont('helvetica', 'B', 10);
        $p->SetTextColor(...self::NAVY);
        $p->Cell($w, 6, self::t($title), 0, 1);
        $c1 = $w * 0.58;
        $c2 = $w * 0.22;
        $c3 = $w - $c1 - $c2;
        $p->SetX($x);
        $p->SetFont('helvetica', 'B', 8);
        $p->SetFillColor(...self::NAVY);
        $p->SetTextColor(255, 255, 255);
        $p->Cell($c1, 6, ' ' . self::t($head[0]), 0, 0, 'L', true);
        $p->Cell($c2, 6, self::t($head[1]), 0, 0, 'R', true);
        $p->Cell($c3, 6, self::t($head[2]) . ' ', 0, 1, 'R', true);
        $p->SetFont('helvetica', '', 8.5);
        $alt = false;
        foreach ($rows as $r) {
            $p->SetX($x);
            $p->SetFillColor(...($alt ? self::LIGHT : [255, 255, 255]));
            $p->SetTextColor(...self::NAVY);
            $yy = $p->GetY();
            $p->Cell($c1, 5.6, '      ' . self::t($r[0]), 0, 0, 'L', true);
            $p->Cell($c2, 5.6, self::t($r[1]), 0, 0, 'R', true);
            $p->Cell($c3, 5.6, self::t($r[2]) . ' ', 0, 1, 'R', true);
            $p->SetFillColor(...self::rgb($r[3] ?? '#94a3b8'));
            $p->Rect($x + 2, $yy + 1.6, 2.4, 2.4, 'F');
            $alt = !$alt;
        }
    }

    private function critical(): void
    {
        $p = $this->pdf;
        $ids = array_values(array_unique(array_merge($this->d['ids']['overdue'], $this->d['ids']['attention'], $this->d['ids']['slo'], $this->d['ids']['unassigned'])));
        $p->AddPage();
        $this->heading('Demandas que pedem atenção', 'Atrasadas, em risco ou bloqueadas, fora do SLO ou sem responsável.');
        if (!$ids) {
            $p->SetFont('helvetica', '', 10);
            $p->SetTextColor(...self::MUTED);
            $p->Cell(self::W, 6, 'Nenhuma demanda pede atenção neste período.', 0, 1);
            return;
        }
        $cols = [['Código', 26], ['Título', 82], ['Situação', 30], ['Responsável', 38], ['Prazo', 24], ['Motivo', 73]];
        $this->tableHead($cols);
        $alt = false;
        foreach ($ids as $id) {
            $r = $this->d['rows'][$id];
            $why = [];
            if ($r['overdue']) {
                $why[] = 'atrasada';
            }
            if (in_array($r['status'], [Demand::STATUS_AT_RISK, Demand::STATUS_BLOCKED], true)) {
                $why[] = mb_strtolower(Demand::STATUSES[$r['status']]);
            }
            if ($r['slo_bad']) {
                $why[] = 'fora do SLO';
            }
            if ($r['open'] && (int) $r['users_id_assigned'] === 0) {
                $why[] = 'sem responsável';
            }
            $this->tableRow($cols, [$r['code'], $r['name'], Demand::STATUSES[$r['status']], $this->user((int) $r['users_id_assigned']), $this->date($r['deadline']), implode(', ', $why)], $alt);
            $alt = !$alt;
        }
    }

    /** @param array<int, array{0: string, 1: float}> $cols */
    private function tableHead(array $cols): void
    {
        $p = $this->pdf;
        $p->SetFont('helvetica', 'B', 8);
        $p->SetFillColor(...self::NAVY);
        $p->SetTextColor(255, 255, 255);
        foreach ($cols as $i => [$label, $w]) {
            $p->Cell($w, 6, ' ' . self::t($label), 0, $i === count($cols) - 1 ? 1 : 0, 'L', true);
        }
    }

    /** @param array<int, array{0: string, 1: float}> $cols @param string[] $cells */
    private function tableRow(array $cols, array $cells, bool $alt): void
    {
        $p = $this->pdf;
        if ($p->GetY() > 185) {
            $p->AddPage();
            $this->tableHead($cols);
        }
        // as cores vêm depois do cabeçalho repetido: ele deixa fundo escuro e texto branco ligados
        $p->SetFont('helvetica', '', 8);
        $p->SetTextColor(...self::NAVY);
        $p->SetFillColor(...($alt ? self::LIGHT : [255, 255, 255]));
        foreach ($cols as $i => [, $w]) {
            $text = self::t($cells[$i] ?? '');
            while ($text !== '' && $p->GetStringWidth(' ' . $text) > $w - 1) {
                $text = mb_substr($text, 0, -2);
                $cut = true;
            }
            $p->Cell($w, 5.4, ' ' . $text . (!empty($cut) ? '…' : ''), 0, $i === count($cols) - 1 ? 1 : 0, 'L', true);
            $cut = false;
        }
    }

    // ------------------------------------------------------------------ detalhamento de cada demanda

    private function detailing(): void
    {
        $p = $this->pdf;
        $p->AddPage();
        $this->heading('Detalhamento das demandas', count($this->d['rows']) . ' demanda(s) com todos os dados: briefing, datas, pessoas, evidências, aprovações, atualizações e trilha.');
        if (!$this->d['rows']) {
            $p->SetFont('helvetica', '', 10);
            $p->Cell(self::W, 6, 'Nenhuma demanda no período.', 0, 1);
            return;
        }
        $colors = Settings::statusColors();
        foreach ($this->d['rows'] as $id => $r) {
            $this->demandBlock($id, $r, $colors);
        }
    }

    /** @param array<string, mixed> $r */
    private function demandBlock(int $id, array $r, array $colors): void
    {
        $p = $this->pdf;
        $det = $this->details[$id] ?? [];
        if ($p->GetY() > 150) {
            $p->AddPage();
        }
        // faixa de título
        $col = self::rgb($colors[$r['status']] ?? '#94a3b8');
        $p->SetFillColor(...self::NAVY);
        $p->SetTextColor(255, 255, 255);
        $p->SetFont('helvetica', 'B', 10);
        $p->Cell(self::W - 46, 7, ' ' . self::t($r['code'] . ' · ' . $r['name']), 0, 0, 'L', true);
        $p->SetFillColor(...$col);
        $p->SetFont('helvetica', 'B', 8.5);
        $p->Cell(46, 7, self::t(Demand::STATUSES[$r['status']]) . ' ', 0, 1, 'R', true);

        $type = (int) $r['types_id'] > 0 ? ($this->d['types'][(int) $r['types_id']]['name'] ?? '—') : '—';
        $facts = [
            ['Tipo', $type], ['Área', $r['area'] ?: '—'], ['Prioridade', Demand::PRIORITIES[(int) $r['priority']] ?? '—'],
            ['Solicitante', $this->user((int) $r['requester_users_id'])], ['Responsável', $this->user((int) $r['users_id_assigned'])], ['Aprovador', $this->user((int) $r['users_id_approver'])],
            ['Enviada em', $this->datetime($r['sent_at'] ?: $r['date_creation'])], ['Prazo desejado', $this->date($r['desired_date'])], ['Prazo confirmado', $this->date($r['confirmed_date'])],
            ['Aceite', $this->datetime($r['accepted_at'])], ['Concluída em', $this->datetime($r['completed_at'])], ['Atraso', $r['overdue'] ? 'Sim' : 'Não'],
            ['Origem', Demand::ORIGIN_SHORT[$r['origin']] ?? 'Externa'],
            ['Pilar', (int) $r['pillars_id'] > 0 ? (Structure::pillars()[(int) $r['pillars_id']]['name'] ?? '—') : '—'],
            ['Projeto · plano', ((int) $r['mkt_projects_id'] > 0 ? (Structure::projects()[(int) $r['mkt_projects_id']]['name'] ?? '—') : '—') . ((int) $r['plans_id'] > 0 ? ' · ' . (Structure::plans()[(int) $r['plans_id']]['name'] ?? '') : '')],
        ];
        $cw = self::W / 3;
        $p->SetFillColor(...self::LIGHT);
        foreach (array_chunk($facts, 3) as $line) {
            $y = $p->GetY();
            foreach ($line as $i => [$label, $value]) {
                $p->SetXY(12 + $i * $cw, $y);
                $p->SetFont('helvetica', 'B', 7.5);
                $p->SetTextColor(...self::MUTED);
                $p->Cell(26, 5, self::t($label), 0, 0, 'L', true);
                $p->SetFont('helvetica', '', 8.5);
                $p->SetTextColor(...self::NAVY);
                $p->Cell($cw - 26, 5, ' ' . self::t($value), 0, 0, 'L', true);
            }
            $p->SetY($y + 5.4);
        }

        foreach ([['Objetivo', 'objective'], ['Público', 'audience'], ['Entrega', 'deliverable'], ['Contexto', 'context'], ['Exceção de conclusão', 'exception_note']] as [$label, $key]) {
            if (trim((string) $r[$key]) !== '') {
                $this->textRow($label, (string) $r[$key]);
            }
        }
        if (!empty($det['evidences'])) {
            $lines = [];
            foreach ($det['evidences'] as $e) {
                $lines[] = '[' . $e['kind'] . '] ' . $e['name'] . ($e['url'] ? ' — ' . $e['url'] : '') . ($e['comment'] ? ' (' . $e['comment'] . ')' : '') . ' · ' . $this->user((int) $e['users_id']) . ', ' . $this->date($e['date_creation']);
            }
            $this->textRow('Evidências', implode("\n", $lines));
        }
        if (!empty($det['approvals'])) {
            $labels = ['waiting' => 'aguardando', 'accepted' => 'aprovada', 'refused' => 'recusada'];
            $lines = [];
            foreach ($det['approvals'] as $a) {
                $lines[] = $this->user((int) $a['users_id_approver']) . ': ' . ($labels[$a['status']] ?? $a['status']) . ($a['date_decision'] ? ' em ' . $this->datetime($a['date_decision']) : '') . ($a['comment'] ? ' — ' . $a['comment'] : '');
            }
            $this->textRow('Aprovações', implode("\n", $lines));
        }
        if (!empty($det['notes'])) {
            $lines = [];
            foreach (array_slice($det['notes'], -8) as $n) {
                $lines[] = $this->datetime($n['date_creation']) . ' · ' . ($n['kind'] === 'system' ? 'Sistema' : $this->user((int) $n['users_id'])) . ': ' . $n['body'];
            }
            $this->textRow('Atualizações', implode("\n", $lines));
        }
        if (!empty($det['events'])) {
            $lines = [];
            foreach ($det['events'] as $ev) {
                // atribuições guardam o id do usuário; mostra o nome
                $shown = fn (string $v) => $ev['event'] === 'assign' ? ((int) $v > 0 ? $this->user((int) $v) : '—') : (Demand::STATUSES[$v] ?? $v);
                $lines[] = $this->datetime($ev['date_creation']) . ' · ' . $this->user((int) $ev['users_id']) . ' · '
                    . $shown((string) $ev['old_value']) . ($ev['old_value'] !== '' && $ev['new_value'] !== '' ? ' > ' : '') . $shown((string) $ev['new_value']) . ' [' . $ev['event'] . ']' . ($ev['note'] && $ev['event'] !== 'assign' ? ' ' . $ev['note'] : '');
            }
            $this->textRow('Trilha', implode("\n", array_slice($lines, -12)));
        }
        $p->Ln(4);
    }

    private function textRow(string $label, string $text): void
    {
        $p = $this->pdf;
        $p->SetFont('helvetica', 'B', 7.5);
        $p->SetTextColor(...self::MUTED);
        $y = $p->GetY();
        $p->SetXY(12, $y);
        $p->Cell(26, 4.6, self::t($label), 0, 0, 'L');
        $p->SetFont('helvetica', '', 8.5);
        $p->SetTextColor(...self::NAVY);
        $p->MultiCell(self::W - 26, 4.6, self::t($text), 0, 'L', false, 1);
    }
}

<?php

namespace GlpiPlugin\Marketing;

/**
 * PDF do Book Executivo (A4 paisagem): capa, sumário executivo com índice geral, painel por grupo de indicadores com gráficos,
 * tendência de 12 meses, OKRs, projetos e planos de ação, ofensores com plano e prazos de recuperação, cronograma e metodologia.
 * O anual acrescenta a evolução mês a mês e as recomendações para o ano seguinte. Gráficos desenhados em vetor (TCPDF).
 */
class BookPdf
{
    private const NAVY = [15, 23, 42];
    private const BLUE = [37, 99, 235];
    private const MUTED = [100, 116, 139];
    private const LIGHT = [241, 245, 249];
    private const LINE = [203, 213, 225];
    private const W = 273;
    private const MONTHS = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    private ReportPdf $p;
    /** @var array<string, mixed> */
    private array $d;
    private string $author;
    private ?string $logo;
    private float $logoRatio = 2.4;
    private bool $inToc = false;

    public static function render(array $d, string $author, ?string $logo = null): string
    {
        return (new self($d, $author, $logo))->build();
    }

    private function __construct(array $d, string $author, ?string $logo)
    {
        $this->d = $d;
        $this->author = $author;
        $this->logo = $logo;
        $this->logoRatio = 2.4;
        if ($logo && ($info = @getimagesize($logo))) {
            $this->logoRatio = $info[0] / max(1, $info[1]);
        }
        $this->p = new ReportPdf('L', 'mm', 'A4', true, 'UTF-8');
    }

    // ------------------------------------------------------------------ utilidades

    private static function t($v): string
    {
        $s = str_replace(['→', '⚙', '↔', '≥', '≤', '−', '÷', '×'], ['>', '', '<>', '>=', '<=', '-', '/', 'x'], (string) $v);
        return (string) preg_replace('/[^\x{0009}\x{000A}\x{0020}-\x{00FF}\x{2013}\x{2014}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}\x{2026}]/u', '', $s);
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function mix(array $c, float $a): array
    {
        return [(int) (255 - (255 - $c[0]) * $a), (int) (255 - (255 - $c[1]) * $a), (int) (255 - (255 - $c[2]) * $a)];
    }

    private function dt(?string $d): string
    {
        return $d ? date('d/m/Y', strtotime($d)) : '—';
    }

    private function user(int $id): string
    {
        return $id > 0 ? self::t(getUserName($id)) : 'A definir';
    }

    /** Cor do índice (0–100): verde ≥ 85, âmbar ≥ 65, vermelho abaixo. */
    private static function idx(?float $s): array
    {
        if ($s === null) {
            return self::rgb('#94a3b8');
        }
        return self::rgb($s >= 85 ? '#16a34a' : ($s >= 65 ? '#f59e0b' : '#dc2626'));
    }

    private function color(array $c): void
    {
        $this->p->SetTextColor(...$c);
    }

    /** Texto em posição fixa, cortado com reticências para caber em $w. */
    private function txt(float $x, float $y, float $w, string $text, float $size = 9, bool $bold = false, array $color = self::NAVY, string $align = 'L'): void
    {
        $p = $this->p;
        $p->SetFont('helvetica', $bold ? 'B' : '', $size);
        $text = self::t($text);
        if ($p->GetStringWidth($text) > $w) {
            // corta no último espaço que cabe (ou no último caractere) e fecha com reticências
            $cut = $text;
            while (mb_strlen($cut) > 1 && $p->GetStringWidth(rtrim($cut) . '...') > $w) {
                $cut = mb_substr($cut, 0, -1);
            }
            $sp = mb_strrpos($cut, ' ');
            if ($sp !== false && $sp > mb_strlen($cut) * 0.6) {
                $cut = mb_substr($cut, 0, $sp);
            }
            $text = rtrim($cut, " ,;:-") . '...';
        }
        $p->SetTextColor(...$color);
        $p->SetXY($x, $y);
        $p->Cell($w, $size * 0.45, $text, 0, 0, $align);
    }

    /** Parágrafo com quebra de linha. Devolve o Y final. */
    private function para(float $x, float $y, float $w, string $text, float $size = 9, array $color = self::NAVY, bool $bold = false, float $lh = 0): float
    {
        $p = $this->p;
        $p->SetFont('helvetica', $bold ? 'B' : '', $size);
        $p->SetTextColor(...$color);
        $p->SetXY($x, $y);
        $p->MultiCell($w, $lh ?: $size * 0.5, self::t($text), 0, 'L', false, 1);
        return $p->GetY();
    }

    private function box(float $x, float $y, float $w, float $h, array $fill = [255, 255, 255], bool $border = true, float $r = 2): void
    {
        $p = $this->p;
        $p->SetFillColor(...$fill);
        $p->SetDrawColor(...self::LINE);
        $p->SetLineWidth(0.2);
        $p->RoundedRect($x, $y, $w, $h, $r, '1111', $border ? 'DF' : 'F');
    }

    /** Cabeçalho de todas as páginas: logotipo à esquerda, título da seção e período, filete azul. */
    private function pageHeader(string $title, string $sub = ''): void
    {
        $p = $this->p;
        $p->SetFillColor(255, 255, 255);
        $p->Rect(0, 0, 297, 17, 'F');
        $p->SetFillColor(...self::BLUE);
        $p->Rect(0, 16.3, 297, 0.9, 'F');
        $p->SetFillColor(...self::NAVY);
        $p->Rect(0, 0, 5, 17.2, 'F');
        $tx = 12;
        if ($this->logo) {
            $h = 12;
            try {
                $p->Image($this->logo, 11, 2.5, $h * $this->logoRatio, $h, '', '', '', false, 200);
                $tx = 11 + $h * $this->logoRatio + 5;
                $p->SetDrawColor(...self::LINE);
                $p->SetLineWidth(0.3);
                $p->Line($tx - 2.5, 3.5, $tx - 2.5, 13.5);
            } catch (\Throwable $e) {
                $tx = 12;
            }
        }
        $this->txt($tx, 4.6, 170, $title, 13, true, self::NAVY);
        $this->txt(190, 3.4, 95, 'KPIs Marketing', 9, true, self::BLUE, 'R');
        $this->txt(190, 8.4, 95, ($this->d['kind'] === 'annual' ? 'Balanço anual' : 'Relatório mensal') . ' · ' . $this->d['label'], 7.5, false, self::MUTED, 'R');
        if ($sub !== '') {
            $this->txt(12, 19.6, self::W, $sub, 8.5, false, self::MUTED);
        }
        $p->SetY(24);
    }

    private function page(string $title, string $sub = ''): void
    {
        $this->p->AddPage();
        if (!str_contains($title, 'continuação')) {
            $this->p->Bookmark(self::t($title), 0, 0);
        }
        $this->pageHeader($title, $sub);
    }

    // ------------------------------------------------------------------ gráficos

    /** Medidor semicircular de 0 a 100. */
    private function gauge(float $cx, float $cy, float $r, ?float $score, string $caption = '', float $thick = 0.28): void
    {
        $p = $this->p;
        $p->SetFillColor(226, 232, 240);
        $p->PieSector($cx, $cy, $r, 0, 180, 'F', true, 180);
        if ($score !== null && $score > 0) {
            $p->SetFillColor(...self::idx($score));
            $p->PieSector($cx, $cy, $r, 0, max(1, 180 * min(100, $score) / 100), 'F', true, 180);
        }
        $p->SetFillColor(255, 255, 255);
        $p->PieSector($cx, $cy, $r * (1 - $thick), 0, 180, 'F', true, 180);
        $size = $r * 1.25;
        $this->txt($cx - $r, $cy - $size * 0.45 - 0.6, $r * 2, $score === null ? '—' : (string) (int) round($score), $size, true, self::idx($score), 'C');
        if ($caption !== '') {
            $this->txt($cx - $r, $cy + 1.5, $r * 2, $caption, 7.5, false, self::MUTED, 'C');
        }
    }

    private function dot(float $x, float $y, float $r, array $c): void
    {
        $this->p->SetFillColor(...$c);
        $this->p->Circle($x, $y, $r, 0, 360, 'F');
    }

    private function sparkline(float $x, float $y, float $w, float $h, array $values, array $color, ?float $target = null): void
    {
        $p = $this->p;
        $pts = array_values(array_filter($values, fn ($v) => $v !== null));
        if (count($pts) < 2) {
            $this->txt($x, $y + $h / 2 - 1, $w, 'sem série', 6.5, false, self::MUTED, 'C');
            return;
        }
        $all = $target !== null ? array_merge($pts, [$target]) : $pts;
        $min = min($all);
        $max = max($all);
        if ($max - $min < 0.0001) {
            $max = $min + 1;
        }
        $n = count($values);
        $coords = [];
        foreach ($values as $i => $v) {
            if ($v !== null) {
                $coords[] = [$x + $w * $i / max(1, $n - 1), $y + $h - ($v - $min) / ($max - $min) * $h];
            }
        }
        if ($target !== null) {
            $ty = $y + $h - ($target - $min) / ($max - $min) * $h;
            $p->SetLineStyle(['width' => 0.2, 'color' => [148, 163, 184], 'dash' => '1,1']);
            $p->Line($x, $ty, $x + $w, $ty);
            $p->SetLineStyle(['dash' => 0]);
        }
        $p->SetLineStyle(['width' => 0.5, 'color' => $color, 'cap' => 'round', 'join' => 'round']);
        for ($i = 1; $i < count($coords); $i++) {
            $p->Line($coords[$i - 1][0], $coords[$i - 1][1], $coords[$i][0], $coords[$i][1]);
        }
        $last = end($coords);
        $this->dot($last[0], $last[1], 0.9, $color);
    }

    /**
     * Gráfico de linhas com grade, eixos, legenda e linha de meta opcional.
     *
     * @param array<int, array{name: string, color: array, values: array<int, ?float>, dash?: bool}> $series
     * @param string[] $labels
     */
    private function lineChart(float $x, float $y, float $w, float $h, string $title, array $series, array $labels, ?float $target = null, string $unit = ''): void
    {
        $p = $this->p;
        $this->box($x, $y, $w, $h);
        $this->txt($x + 3, $y + 2.5, $w - 6, $title, 9, true);
        $lx = $x + 3;
        foreach ($series as $s) {
            $this->dot($lx + 1, $y + 9.3, 1, $s['color']);
            $this->txt($lx + 3, $y + 7.6, 40, $s['name'], 7, false, self::MUTED);
            $lx += 6 + $p->GetStringWidth(self::t($s['name'])) + 4;
        }
        $px = $x + 14;
        $py = $y + 13;
        $pw = $w - 18;
        $ph = $h - 13 - 9;
        $vals = [];
        foreach ($series as $s) {
            foreach ($s['values'] as $v) {
                if ($v !== null) {
                    $vals[] = $v;
                }
            }
        }
        if ($target !== null) {
            $vals[] = $target;
        }
        if (!$vals) {
            $this->txt($px, $py + $ph / 2, $pw, 'Sem dados no período', 8, false, self::MUTED, 'C');
            return;
        }
        $min = min(0, min($vals));
        $max = max($vals);
        $step = pow(10, floor(log10(max(0.0001, $max - $min))));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if (($max - $min) / ($step * $m) <= 5) {
                $step *= $m;
                break;
            }
        }
        $max = ceil($max / $step) * $step;
        $min = floor($min / $step) * $step;
        if ($max - $min < 0.0001) {
            $max = $min + 1;
        }
        $p->SetLineStyle(['width' => 0.15, 'color' => [226, 232, 240], 'dash' => 0]);
        for ($g = $min; $g <= $max + 0.0001; $g += $step) {
            $gy = $py + $ph - ($g - $min) / ($max - $min) * $ph;
            $p->Line($px, $gy, $px + $pw, $gy);
            $this->txt($x + 1, $gy - 1.4, 11.5, (str_contains($f = number_format($g, $step < 1 ? 1 : 0, ',', '.'), ',') ? rtrim(rtrim($f, '0'), ',') : $f) . $unit, 6.2, false, self::MUTED, 'R');
        }
        $n = max(1, count($labels) - 1);
        foreach ($labels as $i => $lb) {
            $this->txt($px + $pw * $i / $n - 6, $py + $ph + 1.5, 12, $lb, 6.2, false, self::MUTED, 'C');
        }
        if ($target !== null) {
            $ty = $py + $ph - ($target - $min) / ($max - $min) * $ph;
            $p->SetLineStyle(['width' => 0.3, 'color' => [22, 163, 74], 'dash' => '2,1.5']);
            $p->Line($px, $ty, $px + $pw, $ty);
            $p->SetLineStyle(['dash' => 0]);
            $this->txt($px + $pw - 22, $ty - 3.4, 22, 'meta', 6.2, true, [22, 163, 74], 'R');
        }
        foreach ($series as $s) {
            $prev = null;
            foreach ($s['values'] as $i => $v) {
                if ($v === null) {
                    $prev = null;
                    continue;
                }
                $cx = $px + $pw * $i / $n;
                $cy = $py + $ph - ($v - $min) / ($max - $min) * $ph;
                if ($prev) {
                    $p->SetLineStyle(['width' => 0.55, 'color' => $s['color'], 'cap' => 'round', 'join' => 'round']);
                    $p->Line($prev[0], $prev[1], $cx, $cy);
                }
                $this->dot($cx, $cy, 0.85, $s['color']);
                $prev = [$cx, $cy];
            }
        }
        $p->SetLineStyle(['width' => 0.2, 'color' => self::LINE, 'dash' => 0]);
    }

    /** Barras verticais agrupadas (uma série = uma cor). */
    private function barChart(float $x, float $y, float $w, float $h, string $title, array $series, array $labels, string $unit = ''): void
    {
        $p = $this->p;
        $this->box($x, $y, $w, $h);
        $this->txt($x + 3, $y + 2.5, $w - 6, $title, 9, true);
        $lx = $x + 3;
        foreach ($series as $s) {
            $this->dot($lx + 1, $y + 9.3, 1, $s['color']);
            $this->txt($lx + 3, $y + 7.6, 40, $s['name'], 7, false, self::MUTED);
            $lx += 6 + $p->GetStringWidth(self::t($s['name'])) + 4;
        }
        $px = $x + 14;
        $py = $y + 13;
        $pw = $w - 18;
        $ph = $h - 13 - 9;
        $max = 0;
        foreach ($series as $s) {
            foreach ($s['values'] as $v) {
                $max = max($max, (float) $v);
            }
        }
        if ($max <= 0) {
            $this->txt($px, $py + $ph / 2, $pw, 'Sem dados no período', 8, false, self::MUTED, 'C');
            return;
        }
        $step = pow(10, floor(log10($max)));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($max / ($step * $m) <= 5) {
                $step *= $m;
                break;
            }
        }
        $max = ceil($max / $step) * $step;
        $p->SetLineStyle(['width' => 0.15, 'color' => [226, 232, 240], 'dash' => 0]);
        for ($g = 0; $g <= $max + 0.0001; $g += $step) {
            $gy = $py + $ph - $g / $max * $ph;
            $p->Line($px, $gy, $px + $pw, $gy);
            $this->txt($x + 1, $gy - 1.4, 11.5, number_format($g, $step < 1 ? 1 : 0, ',', '.') . $unit, 6.2, false, self::MUTED, 'R');
        }
        $n = count($labels);
        $slot = $pw / max(1, $n);
        $bw = min(7, $slot * 0.72 / max(1, count($series)));
        foreach ($labels as $i => $lb) {
            $x0 = $px + $slot * $i + ($slot - $bw * count($series)) / 2;
            foreach ($series as $k => $s) {
                $v = (float) ($s['values'][$i] ?? 0);
                $bh = $v / $max * $ph;
                $p->SetFillColor(...$s['color']);
                if ($bh > 0.2) {
                    $p->Rect($x0 + $k * $bw, $py + $ph - $bh, $bw - 0.4, $bh, 'F');
                }
            }
            $this->txt($px + $slot * $i - 1, $py + $ph + 1.5, $slot + 2, $lb, 6.2, false, self::MUTED, 'C');
        }
    }

    /** Barras horizontais com rótulo e valor. @param array<int, array{label: string, value: float, color?: array, note?: string}> $rows */
    private function hbars(float $x, float $y, float $w, float $rowH, array $rows, float $max, float $labelW = 55, ?float $mark = null): void
    {
        $p = $this->p;
        foreach ($rows as $i => $r) {
            $yy = $y + $i * $rowH;
            $this->txt($x, $yy + 0.6, $labelW - 2, $r['label'], 7.5, false, self::NAVY);
            $bw = $w - $labelW - 18;
            $p->SetFillColor(226, 232, 240);
            $p->RoundedRect($x + $labelW, $yy + 1, $bw, $rowH - 3, 1, '1111', 'F');
            $v = max(0, $r['value']);
            if ($v > 0) {
                $p->SetFillColor(...($r['color'] ?? self::BLUE));
                $p->RoundedRect($x + $labelW, $yy + 1, max(1.5, $bw * min(1, $v / max(0.0001, $max))), $rowH - 3, 1, '1111', 'F');
            }
            if ($mark !== null) {
                $mx = $x + $labelW + $bw * min(1, $mark / max(0.0001, $max));
                $p->SetLineStyle(['width' => 0.35, 'color' => [220, 38, 38], 'dash' => 0]);
                $p->Line($mx, $yy + 0.2, $mx, $yy + $rowH - 1.2);
            }
            $this->txt($x + $labelW + $bw + 1.5, $yy + 0.6, 16, $r['note'] ?? (string) round($r['value']), 7.5, true);
        }
    }

    private function donut(float $cx, float $cy, float $r, array $parts, float $thick = 0.42): void
    {
        $p = $this->p;
        $total = array_sum(array_column($parts, 'value'));
        if ($total <= 0) {
            $p->SetFillColor(226, 232, 240);
            $p->Circle($cx, $cy, $r, 0, 360, 'F');
        } else {
            $a = 0.0;
            foreach ($parts as $pt) {
                $sweep = 360 * $pt['value'] / $total;
                if ($sweep > 0.3) {
                    $p->SetFillColor(...$pt['color']);
                    $p->PieSector($cx, $cy, $r, $a, min(360, $a + $sweep), 'F', true, 90);
                }
                $a += $sweep;
            }
        }
        $p->SetFillColor(255, 255, 255);
        $p->Circle($cx, $cy, $r * (1 - $thick), 0, 360, 'F');
    }

    /** Radar de 3 ou mais eixos com valores de 0 a 100. @param string[] $labels @param array<int, ?float> $values */
    private function radar(float $cx, float $cy, float $r, array $labels, array $values): void
    {
        $p = $this->p;
        $n = count($labels);
        $p->SetLineStyle(['width' => 0.15, 'color' => [203, 213, 225], 'dash' => 0]);
        foreach ([0.25, 0.5, 0.75, 1.0] as $k) {
            $pts = [];
            for ($i = 0; $i < $n; $i++) {
                $a = -M_PI / 2 + 2 * M_PI * $i / $n;
                $pts[] = $cx + cos($a) * $r * $k;
                $pts[] = $cy + sin($a) * $r * $k;
            }
            $p->Polygon($pts, 'D');
        }
        $poly = [];
        for ($i = 0; $i < $n; $i++) {
            $a = -M_PI / 2 + 2 * M_PI * $i / $n;
            $p->Line($cx, $cy, $cx + cos($a) * $r, $cy + sin($a) * $r);
            $v = ($values[$i] ?? 0) / 100;
            $poly[] = $cx + cos($a) * $r * $v;
            $poly[] = $cy + sin($a) * $r * $v;
            $lx = $cx + cos($a) * ($r + 6);
            $ly = $cy + sin($a) * ($r + 5);
            $this->txt($lx - 20, $ly - 1.5, 40, $labels[$i], 6.8, false, self::NAVY, 'C');
        }
        $p->SetAlpha(0.28);
        $p->SetFillColor(...self::BLUE);
        $p->Polygon($poly, 'F');
        $p->SetAlpha(1);
        $p->SetLineStyle(['width' => 0.6, 'color' => self::BLUE, 'dash' => 0]);
        $p->Polygon($poly, 'D');
        for ($i = 0; $i < $n; $i++) {
            $this->dot($poly[$i * 2], $poly[$i * 2 + 1], 0.9, self::BLUE);
        }
    }

    // ------------------------------------------------------------------ montagem

    private function build(): string
    {
        $p = $this->p;
        $p->SetCreator('Gestão de Demandas');
        $p->SetAuthor($this->author);
        $p->SetTitle('KPIs Marketing — ' . $this->d['label']);
        $p->setPrintHeader(false);
        $p->setPrintFooter(true);
        $p->footerText = 'KPIs Marketing · Oakmont Group · ' . self::t($this->d['label']) . ' · confidencial · gerado em ' . date('d/m/Y H:i', strtotime($this->d['generated']));
        $p->SetMargins(12, 16, 12);
        $p->SetAutoPageBreak(false);

        $this->cover();
        $this->summary();
        $this->decisions();
        $this->scorecard();
        foreach (array_keys(Kpi::GROUPS) as $gid) {
            $this->groupPage($gid);
        }
        $this->trends();
        if ($this->d['kind'] === 'annual') {
            $this->yearEvolution();
        }
        $this->okrs();
        $this->projects();
        $this->offenders();
        $this->recovery();
        $this->demandOffenders();
        $this->timeline();
        if ($this->d['kind'] === 'annual') {
            $this->nextYear();
        }
        $this->methodology();
        // o sumário é criado no fim e o TCPDF o move para a página 2
        $p->addTOCPage();
        $this->pageHeader('Sumário', 'Conteúdo deste book. Cada seção abre com a explicação do que mostra e de como ler.');
        $p->SetY(26);
        $p->endTOCPage();
        $p->SetFont('helvetica', '', 10);
        $p->SetTextColor(...self::NAVY);
        $p->addTOC(2, 'helvetica', '.', 'Sumário', '', self::NAVY);
        return $p->Output('kpis-marketing.pdf', 'S');
    }

    private function cover(): void
    {
        $p = $this->p;
        $d = $this->d;
        $p->AddPage();
        $p->SetFillColor(255, 255, 255);
        $p->Rect(0, 0, 297, 210, 'F');
        // painel escuro à esquerda e faixa de destaque
        $p->SetFillColor(...self::NAVY);
        $p->Rect(0, 0, 190, 210, 'F');
        $p->SetFillColor(...self::BLUE);
        $p->Rect(0, 0, 7, 210, 'F');
        $p->SetFillColor(30, 58, 138);
        $p->Rect(190, 0, 107, 210, 'F');
        if ($this->logo) {
            $this->box(24, 22, 92, 44, [255, 255, 255], false, 3);
            try {
                $p->Image($this->logo, 28, 25, 84, 38, '', '', '', true, 300, '', false, false, 0, 'CM');
            } catch (\Throwable $e) {
                // logotipo ilegível: a capa segue sem ele
            }
        }
        $this->txt(24, 84, 160, 'OAKMONT GROUP · MARKETING', 10, true, [148, 163, 184]);
        $this->txt(24, 93, 165, 'KPIs Marketing', 44, true, [255, 255, 255]);
        $p->SetFillColor(...self::BLUE);
        $p->Rect(24, 114, 44, 1.4, 'F');
        $this->txt(24, 120, 160, 'Book executivo de indicadores, OKRs, planos de ação', 13, false, [226, 232, 240]);
        $this->txt(24, 127, 160, 'e recuperação de ofensores', 13, false, [226, 232, 240]);
        $this->txt(24, 144, 160, mb_strtoupper($d['label']), 20, true, [255, 255, 255]);
        $this->txt(24, 156, 160, 'Período: ' . $this->dt($d['from']) . ' a ' . $this->dt($d['to']) . ' · ' . ($d['kind'] === 'annual' ? 'Balanço anual' : ($d['kind'] === 'monthly' ? 'Relatório mensal' : 'Relatório sob demanda')), 10, false, [148, 163, 184]);
        $this->txt(24, 184, 160, 'Gerado em ' . date('d/m/Y H:i', strtotime($d['generated'])) . ' · ' . $this->author, 8.5, false, [203, 213, 225]);
        $this->txt(24, 190, 160, 'Documento confidencial · uso exclusivo da gerência de Marketing', 8.5, false, [148, 163, 184]);
        // índice geral no painel azul
        $score = $d['sum']['overall'];
        $this->txt(196, 70, 95, 'ÍNDICE GERAL DE DESEMPENHO', 9, true, [191, 219, 254], 'C');
        $p->SetFillColor(255, 255, 255);
        $p->PieSector(243.5, 130, 36, 0, 180, 'F', true, 180);
        $p->SetFillColor(226, 232, 240);
        $p->PieSector(243.5, 130, 36, 0, 180, 'F', true, 180);
        if ($score !== null) {
            $p->SetFillColor(...self::idx($score));
            $p->PieSector(243.5, 130, 36, 0, max(1, 180 * $score / 100), 'F', true, 180);
        }
        $p->SetFillColor(30, 58, 138);
        $p->PieSector(243.5, 130, 25, 0, 180, 'F', true, 180);
        $this->txt(196, 108, 95, $score === null ? '—' : (string) (int) $score, 38, true, [255, 255, 255], 'C');
        $this->txt(196, 133, 95, 'de 100 pontos', 9, false, [191, 219, 254], 'C');
        $this->txt(196, 142, 95, $score === null ? 'sem metas' : ($score >= 85 ? 'Desempenho na meta' : ($score >= 65 ? 'Atenção' : 'Crítico')), 12, true, $score === null ? [191, 219, 254] : ($score >= 85 ? [134, 239, 172] : ($score >= 65 ? [253, 224, 71] : [252, 165, 165])), 'C');
        $cnt = ['green' => 0, 'amber' => 0, 'red' => 0];
        foreach ($d['sum']['rows'] as $r) {
            if ($r['score'] !== null) {
                $cnt[$r['status']]++;
            }
        }
        $x = 206;
        foreach ([['green', 'na meta'], ['amber', 'atenção'], ['red', 'críticos']] as [$st, $lb]) {
            $this->dot($x + 3, 166, 2, self::rgb(Kpi::STATUS_COLORS[$st]));
            $this->txt($x - 4, 171, 14, (string) $cnt[$st], 12, true, [255, 255, 255], 'C');
            $this->txt($x - 8, 177.5, 22, $lb, 7, false, [191, 219, 254], 'C');
            $x += 31;
        }
    }

    private function summary(): void
    {
        $d = $this->d;
        $sum = $d['sum'];
        $this->page('Sumário executivo', 'O que aconteceu no período, como o Marketing está em relação às metas e onde agir primeiro.');
        // medidor + radar
        $this->box(12, 24, 78, 82);
        $this->txt(15, 26.5, 72, 'Índice geral de desempenho', 9, true);
        $this->gauge(51, 78, 29, $sum['overall'], 'de 100 pontos');
        $prevOverall = Kpi::summarize($d['prev'])['overall'];
        if ($prevOverall !== null && $sum['overall'] !== null) {
            $dlt = $sum['overall'] - $prevOverall;
            $this->txt(15, 90, 72, ($dlt >= 0 ? '+' : '') . (int) $dlt . ' pts vs período anterior (' . (int) $prevOverall . ')', 8, true, $dlt >= 0 ? [22, 163, 74] : [220, 38, 38], 'C');
        }
        $this->txt(15, 97, 72, 'Verde: 85 ou mais · âmbar: 65 ou mais · vermelho: abaixo', 6.5, false, self::MUTED, 'C');
        $this->box(94, 24, 92, 82);
        $this->txt(97, 26.5, 86, 'Pontuação por grupo de indicadores', 9, true);
        $labels = [];
        $vals = [];
        foreach ($sum['groups'] as $g) {
            $labels[] = $g['name'];
            $vals[] = $g['score'] ?? 0;
        }
        $this->radar(140, 68, 25, $labels, $vals);
        // narrativa
        $this->box(190, 24, 95, 82, self::LIGHT);
        $this->txt(193, 26.5, 90, 'Leitura do período', 9, true);
        $y = 33;
        foreach ($d['narrative'] as $line) {
            $y = $this->para(193, $y, 89, $line, 7.8, self::NAVY, false, 3.5) + 2;
        }
        // destaques e alertas
        $rows = array_filter($sum['rows'], fn ($r) => $r['score'] !== null);
        $best = $rows;
        usort($best, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($b['trend'] === 'better' ? 1 : -1));
        $best = array_slice(array_filter($best, fn ($r) => $r['status'] === 'green'), 0, 3);
        $worst = $rows;
        usort($worst, fn ($a, $b) => $a['score'] <=> $b['score']);
        $worst = array_slice(array_filter($worst, fn ($r) => $r['status'] !== 'green'), 0, 3);
        $this->txt(12, 110, 130, 'Destaques positivos', 10, true, [22, 163, 74]);
        $this->txt(150, 110, 130, 'Alertas prioritários', 10, true, [220, 38, 38]);
        foreach ([[12, $best, '#16a34a'], [150, $worst, '#dc2626']] as [$x0, $list, $col]) {
            if (!$list) {
                $this->txt($x0, 119, 130, $x0 === 12 ? 'Nenhum indicador na meta ainda.' : 'Nenhum indicador fora da meta.', 8.5, false, self::MUTED);
            }
            foreach (array_values($list) as $i => $r) {
                $yy = 117 + $i * 24;
                $this->box($x0, $yy, 123, 21, [255, 255, 255]);
                $p = $this->p;
                $p->SetFillColor(...self::rgb($col));
                $p->Rect($x0, $yy + 1, 1.6, 19, 'F');
                $this->txt($x0 + 4, $yy + 2, 80, $r['name'], 8.5, true);
                $this->txt($x0 + 4, $yy + 8, 90, Kpi::GROUPS[$r['group']][0] . ' · meta ' . Kpi::formatTarget($r, true), 7, false, self::MUTED);
                $this->txt($x0 + 4, $yy + 13.5, 90, ($r['prev'] !== null ? 'Anterior: ' . Kpi::format($r, $r['prev']) : 'Sem período anterior'), 7, false, self::MUTED);
                $this->txt($x0 + 84, $yy + 3, 36, Kpi::format($r, $r['value']), 15, true, self::rgb($col), 'R');
                $this->txt($x0 + 84, $yy + 13, 36, Kpi::STATUS_LABELS[$r['status']], 7.5, true, self::rgb($col), 'R');
            }
        }
        // contadores
        $cnt = ['green' => 0, 'amber' => 0, 'red' => 0];
        foreach ($rows as $r) {
            $cnt[$r['status']]++;
        }
        $x = 12;
        foreach ([['green', 'indicadores na meta'], ['amber', 'em atenção'], ['red', 'críticos']] as [$st, $lb]) {
            $this->box($x, 190, 60, 11, self::LIGHT, false);
            $this->dot($x + 5, 195.5, 2, self::rgb(Kpi::STATUS_COLORS[$st]));
            $this->txt($x + 10, 192.2, 48, $cnt[$st] . ' ' . $lb, 8.5, true);
            $x += 64;
        }
        $this->txt(210, 192.2, 75, count($d['offenders']) . ' ofensores com plano de recuperação (seção 8)', 8, false, self::MUTED, 'R');
    }

    private function decisions(): void
    {
        $d = $this->d;
        $this->page('Prioridades e decisões da gerência', 'O que pede ação imediata, quem lidera e até quando. Resumo executivo dos planos de recuperação das próximas seções.');
        $p = $this->p;
        $offs = array_slice($d['offenders'], 0, 5);
        $this->txt(12, 24, 150, 'Cinco prioridades de recuperação', 10.5, true);
        if (!$offs) {
            $this->box(12, 32, 160, 30, [240, 253, 244], false);
            $this->txt(18, 42, 150, 'Nenhum indicador fora da meta', 12, true, [22, 163, 74]);
        }
        foreach ($offs as $i => $o) {
            [$sl, $sc] = Kpi::SEVERITY[$o['severity']];
            $col = self::rgb($sc);
            $def = Kpi::targets()[$o['key']];
            $y = 32 + $i * 33;
            $this->box(12, $y, 165, 30);
            $p->SetFillColor(...$col);
            $p->Rect(12, $y + 1.5, 2, 27, 'F');
            $p->SetFillColor(...$col);
            $p->Circle(22, $y + 8, 4.2, 0, 360, 'F');
            $this->txt(17.8, $y + 5.6, 8.4, (string) ($i + 1), 11, true, [255, 255, 255], 'C');
            $this->txt(29, $y + 3, 100, $o['name'], 10, true);
            $this->txt(29, $y + 9.5, 100, Kpi::GROUPS[$o['group']][0] . ' · ' . $sl, 7.5, false, self::MUTED);
            $this->txt(130, $y + 2.4, 44, Kpi::format($def, $o['value']), 17, true, $col, 'R');
            $this->txt(130, $y + 11, 44, 'meta ' . Kpi::formatTarget($def, true), 7.5, false, self::MUTED, 'R');
            $this->txt(18, $y + 16, 156, 'AÇÃO IMEDIATA', 6.3, true, self::MUTED);
            $this->para(18, $y + 19.3, 106, $o['actions'][0] ?? '', 7.6, self::NAVY, false, 3.3);
            $this->txt(128, $y + 16, 46, 'RESPONSÁVEL · PRAZO FINAL', 6.3, true, self::MUTED, 'R');
            $this->txt(128, $y + 19.6, 46, $o['owner'], 7.5, true, self::NAVY, 'R');
            $this->txt(128, $y + 24.2, 46, $this->dt($o['due_date']), 8.5, true, $col, 'R');
        }
        // demandas críticas
        $this->txt(184, 24, 100, 'Demandas que mais pesam', 10.5, true);
        foreach (array_slice($d['offenderDemands'], 0, 6) as $i => $r) {
            $y = 32 + $i * 19;
            $this->box(184, $y, 101, 17);
            $this->txt(187, $y + 2, 24, $r['code'], 7.3, true, self::BLUE);
            $this->txt(208, $y + 2, 74, $r['name'], 7.3, true);
            $this->txt(187, $y + 7.8, 96, implode('; ', array_slice($r['reasons'], 0, 2)), 6.4, false, [180, 83, 9]);
            $this->txt(187, $y + 12.2, 96, 'Prazo recomendado ' . $this->dt($r['recommended']) . ' (' . $r['du'] . ' d.u.) · ' . $this->user($r['assignee']), 6.6, true, self::NAVY);
        }
        // decisões sugeridas
        $v = $d['vals'];
        $asks = [];
        if (($v['reincidentes'] ?? 0) >= 1) {
            $asks[] = 'Aprovar o replanejamento das ' . (int) $v['reincidentes'] . ' demanda(s) adiada(s) 2 vezes ou mais, com prazo final único.';
        }
        if (($v['sobrecarga'] ?? 0) >= 1) {
            $asks[] = 'Autorizar a redistribuição de carga das ' . (int) $v['sobrecarga'] . ' pessoa(s) sobrecarregada(s).';
        }
        if (($v['atrasadas_pct'] ?? 0) > 25) {
            $asks[] = 'Convocar uma reunião de recuperação das demandas atrasadas (' . (int) $v['atrasadas_pct'] . '% do backlog).';
        }
        if (($v['sem_responsavel'] ?? 0) > 10) {
            $asks[] = 'Definir o responsável padrão da triagem e atribuir as demandas sem dono.';
        }
        if (($v['proj_risco'] ?? 0) > 25) {
            $asks[] = 'Rever o cronograma dos projetos em risco com seus responsáveis.';
        }
        if (!$asks) {
            $asks[] = 'Nenhuma decisão crítica pendente: manter o acompanhamento mensal e revisar as metas.';
        }
        $this->box(184, 148, 101, 50, self::LIGHT, false);
        $this->txt(188, 150.5, 94, 'Decisões sugeridas à gerência', 9, true);
        $yy = 157;
        foreach (array_slice($asks, 0, 4) as $a) {
            $this->dot(190, $yy + 1.6, 1, self::BLUE);
            $yy = $this->para(193, $yy, 89, $a, 7.2, self::NAVY, false, 3.1) + 1.4;
        }
    }

    private function scorecard(): void
    {
        $d = $this->d;
        $this->page('Scorecard por grupo', 'Cada grupo reúne os indicadores de um tema. A pontuação é a média da pontuação de cada indicador (100 = na meta).');
        $i = 0;
        $prev = Kpi::summarize($d['prev']);
        foreach ($d['sum']['groups'] as $gid => $g) {
            $col = $i % 4;
            $row = intdiv($i, 4);
            $x = 12 + $col * 69;
            $y = 26 + $row * 84;
            $this->box($x, $y, 66, 80);
            $this->p->SetFillColor(...self::rgb($g['color']));
            $this->p->Rect($x, $y + 2, 1.8, 76, 'F');
            $this->txt($x + 5, $y + 3, 58, $g['name'], 9, true);
            $this->gauge($x + 33, $y + 38, 22, $g['score'], 'pontos');
            $this->txt($x + 4, $y + 46, 58, $g['green'] . ' na meta · ' . $g['amber'] . ' atenção · ' . $g['red'] . ' crítico', 7, false, self::MUTED, 'C');
            $pg = $prev['groups'][$gid]['score'] ?? null;
            if ($pg !== null && $g['score'] !== null) {
                $dl = $g['score'] - $pg;
                $cc = $dl >= 0 ? [22, 163, 74] : [220, 38, 38];
                $this->txt($x + 4, $y + 52, 58, ($dl >= 0 ? '+' : '') . (int) $dl . ' pts vs anterior', 7.5, true, $cc, 'C');
                $this->p->SetFillColor(...$cc);
                $this->p->Polygon($dl >= 0 ? [$x + 12, $y + 55, $x + 15.4, $y + 55, $x + 13.7, $y + 52.2] : [$x + 12, $y + 52.2, $x + 15.4, $y + 52.2, $x + 13.7, $y + 55], 'F');
            }
            $worst = array_filter($d['sum']['rows'], fn ($r) => $r['group'] === $gid && $r['score'] !== null);
            usort($worst, fn ($a, $b) => $a['score'] <=> $b['score']);
            $w = reset($worst);
            if ($w) {
                $this->txt($x + 4, $y + 60, 58, 'Maior desvio:', 6.5, false, self::MUTED);
                $this->txt($x + 4, $y + 64, 58, $w['name'], 7.5, true, self::rgb(Kpi::STATUS_COLORS[$w['status']]));
                $this->txt($x + 4, $y + 69, 58, Kpi::format($w, $w['value']) . ' (meta ' . Kpi::formatTarget($w, true) . ')', 7, false, self::NAVY);
            }
            $i++;
        }
        // legenda do cálculo
        $this->box(12 + 3 * 69, 26 + 84, 66, 80, self::LIGHT, false);
        $this->txt(12 + 3 * 69 + 4, 26 + 87, 58, 'Como ler', 9, true);
        $this->para(12 + 3 * 69 + 4, 26 + 94, 58, "Cada indicador recebe de 0 a 100: 100 quando está na meta; 50 no limite de atenção; abaixo disso, cai até 0.\n\nO grupo é a média dos seus indicadores e o índice geral é a média dos grupos.\n\nIndicadores informativos (sem meta) não entram na pontuação.", 7.3, self::NAVY, false, 3.3);
    }

    private function groupPage(string $gid): void
    {
        $d = $this->d;
        [$gname, $gcolor] = Kpi::GROUPS[$gid];
        $desc = [
            'volume' => 'Quanto trabalho entra, quanto sai e qual o tamanho e a idade da fila.',
            'prazo' => 'Pontualidade das entregas, atrasos e a frequência de reprogramação de prazos.',
            'fluxo' => 'Velocidade ponta a ponta e as esperas do processo: triagem, aprovação, bloqueios.',
            'qualidade' => 'Rastreabilidade e qualidade das entregas: evidência, aceite, exceções e retrabalho.',
            'slo' => 'Cumprimento dos acordos de nível de serviço homologados.',
            'projetos' => 'Execução dos projetos e planos de ação frente ao cronograma (retrato do dia de geração do book).',
            'pessoas' => 'Carga de trabalho da equipe e risco de sobrecarga ou dependência de pessoas.',
        ][$gid];
        $this->page($gname, $desc);
        $rows = array_values(array_filter($d['sum']['rows'], fn ($r) => $r['group'] === $gid));
        $g = $d['sum']['groups'][$gid];
        // cabeçalho do grupo
        $this->gauge(24, 40, 11, $g['score'], '', 0.32);
        $this->txt(38, 29, 60, 'Pontuação do grupo: ' . ($g['score'] === null ? 'sem metas' : (int) $g['score'] . ' / 100'), 9.5, true);
        $this->txt(38, 35, 130, $g['green'] . ' na meta · ' . $g['amber'] . ' em atenção · ' . $g['red'] . ' críticos · ' . ($g['count'] - count($g['scores'])) . ' informativos', 8, false, self::MUTED);
        // tabela
        $x = 12;
        $y = 48;
        $cols = [['Indicador e o que mede', 82], ['Realizado', 19], ['Meta', 17], ['Anterior', 17], ['Status', 18], ['12 meses', 24]];
        $p = $this->p;
        $p->SetFillColor(...self::NAVY);
        $p->Rect($x, $y, 177, 7, 'F');
        $cx = $x;
        foreach ($cols as [$h, $w]) {
            $this->txt($cx + 1.5, $y + 1.6, $w - 2, $h, 7.5, true, [255, 255, 255], $h === 'Indicador e o que mede' ? 'L' : 'C');
            $cx += $w;
        }
        $y += 7;
        $rowH = count($rows) > 7 ? 12.4 : 14;
        $labels = array_keys($d['series']);
        foreach ($rows as $i => $r) {
            if ($i % 2 === 1) {
                $p->SetFillColor(248, 250, 252);
                $p->Rect($x, $y, 177, $rowH, 'F');
            }
            $this->txt($x + 1.5, $y + 1.2, 80, $r['name'], 8, true);
            $this->para($x + 1.5, $y + 5.2, 80, $r['formula'], 5.8, self::MUTED, false, 2.5);
            $col = self::rgb(Kpi::STATUS_COLORS[$r['status']]);
            $this->txt($x + 82, $y + $rowH / 2 - 2.2, 19, Kpi::format($r, $r['value']), 9.5, true, $col, 'C');
            $this->txt($x + 101, $y + $rowH / 2 - 1.5, 17, Kpi::formatTarget($r, true), 7.5, false, self::NAVY, 'C');
            $this->txt($x + 118, $y + $rowH / 2 - 1.5, 17, $r['prev'] === null ? '—' : Kpi::format($r, $r['prev']), 7.5, false, self::MUTED, 'C');
            $tc = ['better' => [22, 163, 74], 'worse' => [220, 38, 38]][$r['trend']] ?? [148, 163, 184];
            $dirUp = ($r['value'] !== null && $r['prev'] !== null) ? $r['value'] > $r['prev'] : null;
            $this->p->SetFillColor(...self::mix($col, 0.18));
            $this->p->RoundedRect($x + 136.5, $y + $rowH / 2 - 3, 15, 6, 1.5, '1111', 'F');
            $this->txt($x + 136.5, $y + $rowH / 2 - 1.6, 15, Kpi::STATUS_LABELS[$r['status']], 6.2, true, $col, 'C');
            $ax = $x + 154;
            $ay = $y + $rowH / 2;
            $p->SetFillColor(...$tc);
            if ($dirUp === null || $r['trend'] === 'flat') {
                $p->Rect($ax - 1.4, $ay - 0.3, 2.8, 0.7, 'F');
            } else {
                $p->Polygon($dirUp ? [$ax - 1.6, $ay + 1.2, $ax + 1.6, $ay + 1.2, $ax, $ay - 1.4] : [$ax - 1.6, $ay - 1.2, $ax + 1.6, $ay - 1.2, $ax, $ay + 1.4], 'F');
            }
            $series = [];
            foreach ($d['series'] as $m => $mv) {
                $series[] = $mv[$r['key']] ?? null;
            }
            $this->sparkline($x + 157, $y + 2.5, 18, $rowH - 5, $series, $col, $r['target']);
            $p->SetDrawColor(...self::LINE);
            $p->SetLineWidth(0.1);
            $p->Line($x, $y + $rowH, $x + 177, $y + $rowH);
            $y += $rowH;
        }
        // gráficos à direita
        $this->groupCharts($gid, 193, 26, 92);
        // por que importa
        $yb = $y + 4;
        if ($yb < 180) {
            $this->box(12, $yb, 177, min(26, 198 - $yb), self::LIGHT, false);
            $this->txt(15, $yb + 1.5, 170, 'Por que acompanhar', 8, true);
            $why = [];
            foreach ($rows as $r) {
                if ($r['status'] !== 'green' && $r['status'] !== 'none') {
                    $why[] = $r['name'] . ': ' . $r['why'];
                }
            }
            $this->para(15, $yb + 6, 171, $why ? implode('  ', array_slice($why, 0, 3)) : 'Todos os indicadores com meta deste grupo estão na meta. Mantenha o acompanhamento mensal.', 7, self::NAVY, false, 3.1);
        }
    }

    private function groupCharts(string $gid, float $x, float $y, float $w): void
    {
        $d = $this->d;
        $labels = array_map(fn ($m) => self::MONTHS[(int) substr($m, 5, 2)], array_keys($d['series']));
        $ser = fn (string $k) => array_map(fn ($mv) => $mv[$k] ?? null, array_values($d['series']));
        $cat = Kpi::targets();
        switch ($gid) {
            case 'volume':
                $this->barChart($x, $y, $w, 78, 'Recebidas x concluídas por mês', [
                    ['name' => 'Recebidas', 'color' => self::rgb('#6366f1'), 'values' => $ser('entradas')], ['name' => 'Concluídas', 'color' => self::rgb('#22c55e'), 'values' => $ser('concluidas')]], $labels);
                $this->lineChart($x, $y + 82, $w, 78, 'Backlog em aberto (fim de cada mês)', [['name' => 'Backlog', 'color' => self::rgb('#f97316'), 'values' => $ser('backlog')]], $labels);
                break;
            case 'prazo':
                $this->lineChart($x, $y, $w, 78, 'Entregas no prazo (%)', [['name' => 'No prazo', 'color' => self::rgb('#16a34a'), 'values' => $ser('otd')]], $labels, (float) $cat['otd']['target'], '%');
                $this->lineChart($x, $y + 82, $w, 78, 'Atrasadas e adiadas (% do backlog)', [
                    ['name' => 'Atrasadas', 'color' => self::rgb('#dc2626'), 'values' => $ser('atrasadas_pct')], ['name' => 'Adiadas', 'color' => self::rgb('#d97706'), 'values' => $ser('adiadas_pct')]], $labels, null, '%');
                break;
            case 'fluxo':
                $this->lineChart($x, $y, $w, 78, 'Tempo médio de conclusão (dias)', [['name' => 'Lead time', 'color' => self::rgb('#0ea5e9'), 'values' => $ser('lead_time')]], $labels, (float) $cat['lead_time']['target']);
                $this->lineChart($x, $y + 82, $w, 78, 'Triagem e aprovação (dias)', [
                    ['name' => 'Até planejar', 'color' => self::rgb('#6366f1'), 'values' => $ser('tempo_triagem')], ['name' => 'Aprovação', 'color' => self::rgb('#ec4899'), 'values' => $ser('tempo_aprovacao')]], $labels);
                break;
            case 'qualidade':
                $this->lineChart($x, $y, $w, 78, 'Concluídas com evidência e aceite (%)', [
                    ['name' => 'Evidência', 'color' => self::rgb('#16a34a'), 'values' => $ser('com_evidencia')], ['name' => 'Aceite', 'color' => self::rgb('#0ea5e9'), 'values' => $ser('com_aceite')]], $labels, null, '%');
                $this->lineChart($x, $y + 82, $w, 78, 'Retrabalho e exceções (%)', [
                    ['name' => 'Recusadas', 'color' => self::rgb('#dc2626'), 'values' => $ser('retrabalho')], ['name' => 'Exceções', 'color' => self::rgb('#d97706'), 'values' => $ser('excecoes')]], $labels, null, '%');
                break;
            case 'slo':
                $this->lineChart($x, $y, $w, 78, 'SLOs cumpridos (%)', [['name' => 'Cumpridos', 'color' => self::rgb('#eab308'), 'values' => $ser('slo_cumprido')]], $labels, (float) $cat['slo_cumprido']['target'], '%');
                $this->box($x, $y + 82, $w, 78, self::LIGHT, false);
                $this->txt($x + 3, $y + 85, $w - 6, 'Sobre os SLOs', 9, true);
                $this->para($x + 3, $y + 92, $w - 6, Slo::all(true) ? 'O percentual considera as demandas do período em cada SLO ativo e homologado: cumprido no prazo, cumprido fora do prazo ou vencido.' : 'Nenhum SLO está ativo. Defina o prazo de cada processo, homologue com a evidência do aceite e ligue em Configurações → SLOs para medir este grupo.', 7.5, self::NAVY, false, 3.4);
                break;
            case 'projetos':
                $rows = array_slice(array_filter($d['projectRisks'], fn ($r) => $r['effective'] !== null || $r['progress'] !== null), 0, 5);
                $this->box($x, $y, $w, 78);
                $this->txt($x + 3, $y + 2.5, $w - 6, 'Execução x cronograma por projeto', 9, true);
                $yy = $y + 11;
                foreach ($rows as $r) {
                    $this->txt($x + 3, $yy, $w - 6, $r['name'], 7.3, true);
                    $this->hbars($x + 3, $yy + 3.8, $w - 6, 4.6, [['label' => 'Execução', 'value' => (float) ($r['effective'] ?? 0), 'color' => self::rgb('#22c55e'), 'note' => ($r['effective'] ?? 0) . '%']], 100, 21);
                    $this->hbars($x + 3, $yy + 8.4, $w - 6, 4.6, [['label' => 'Cronograma', 'value' => (float) ($r['expected'] ?? 0), 'color' => self::rgb('#94a3b8'), 'note' => ($r['expected'] ?? 0) . '%']], 100, 21);
                    $yy += 13.2;
                }
                if (!$rows) {
                    $this->txt($x, $y + 30, $w, 'Sem projetos ativos com atividades.', 8, false, self::MUTED, 'C');
                }
                $this->box($x, $y + 82, $w, 78, self::LIGHT, false);
                $this->txt($x + 3, $y + 85, $w - 6, 'Como ler', 9, true);
                $this->para($x + 3, $y + 92, $w - 6, "Execução = média do % de execução informado em cada atividade (concluída = 100%).\n\nCronograma = parte do período do projeto que já passou.\n\nExecução bem abaixo do cronograma indica projeto atrasado, mesmo com atividades em andamento. Mantenha o % atualizado na edição em lote do plano.", 7.5, self::NAVY, false, 3.4);
                break;
            case 'pessoas':
                $rowsL = [];
                foreach (array_slice($d['load'], 0, 10, true) as $uid => $n) {
                    $rowsL[] = ['label' => self::t(getUserName((int) $uid)), 'value' => (float) $n, 'color' => $n > Kpi::OVERLOAD ? self::rgb('#dc2626') : self::rgb('#6366f1'), 'note' => (string) $n];
                }
                $this->box($x, $y, $w, 78);
                $this->txt($x + 3, $y + 2.5, $w - 6, 'Demandas em aberto por responsável', 9, true);
                $this->hbars($x + 3, $y + 12, $w - 6, 6.2, $rowsL, max(array_merge([Kpi::OVERLOAD + 2], array_values($d['load']))), 34, (float) Kpi::OVERLOAD);
                $this->txt($x + 3, $y + 72, $w - 6, 'Linha vermelha = limite de sobrecarga (' . Kpi::OVERLOAD . ' demandas)', 6.5, false, self::MUTED);
                $this->lineChart($x, $y + 82, $w, 78, 'Demandas em aberto por pessoa (média)', [['name' => 'Média', 'color' => self::rgb('#f97316'), 'values' => $ser('wip_pessoa')]], $labels, (float) $cat['wip_pessoa']['target']);
                break;
        }
    }

    private function trends(): void
    {
        $d = $this->d;
        $this->page('Tendências de 12 meses', 'Evolução mensal dos principais indicadores. A linha verde tracejada é a meta.');
        $labels = array_map(fn ($m) => self::MONTHS[(int) substr($m, 5, 2)], array_keys($d['series']));
        $ser = fn (string $k) => array_map(fn ($mv) => $mv[$k] ?? null, array_values($d['series']));
        $cat = Kpi::targets();
        $this->lineChart(12, 26, 133, 82, 'Índice geral de desempenho (pontos)', [
            ['name' => 'Índice geral', 'color' => self::NAVY, 'values' => array_values(array_map(fn ($m) => $m['overall'], $d['monthly']))],
        ], $labels, 85);
        $this->barChart(152, 26, 133, 82, 'Recebidas x concluídas', [
            ['name' => 'Recebidas', 'color' => self::rgb('#6366f1'), 'values' => $ser('entradas')], ['name' => 'Concluídas', 'color' => self::rgb('#22c55e'), 'values' => $ser('concluidas')]], $labels);
        $this->lineChart(12, 112, 133, 82, 'Entregas no prazo (%)', [['name' => 'No prazo', 'color' => self::rgb('#16a34a'), 'values' => $ser('otd')]], $labels, (float) $cat['otd']['target'], '%');
        $this->lineChart(152, 112, 133, 82, 'Backlog em aberto (fim de cada mês)', [
            ['name' => 'Backlog', 'color' => self::rgb('#f97316'), 'values' => $ser('backlog')]], $labels);
    }

    private function yearEvolution(): void
    {
        $d = $this->d;
        $this->page('Evolução do ano mês a mês', 'Pontuação de cada grupo em cada mês (mapa de calor) e valor dos indicadores-chave.');
        $months = array_keys($d['monthly']);
        $x0 = 62;
        $cw = 18;
        $this->txt(12, 28, 48, 'Grupo / mês', 8, true, self::MUTED);
        foreach ($months as $i => $m) {
            $this->txt($x0 + $i * $cw, 28, $cw, self::MONTHS[(int) substr($m, 5, 2)], 8, true, self::MUTED, 'C');
        }
        $y = 34;
        foreach (Kpi::GROUPS as $gid => [$gname, $gcolor]) {
            $this->txt(12, $y + 1.5, 48, $gname, 8, true);
            foreach ($months as $i => $m) {
                $s = $d['monthly'][$m]['groups'][$gid] ?? null;
                $this->p->SetFillColor(...($s === null ? [241, 245, 249] : self::mix(self::idx($s), 0.85)));
                $this->p->RoundedRect($x0 + $i * $cw + 0.6, $y, $cw - 1.2, 7, 1, '1111', 'F');
                $this->txt($x0 + $i * $cw, $y + 1.4, $cw, $s === null ? '—' : (string) (int) $s, 8, true, $s === null ? self::MUTED : [255, 255, 255], 'C');
            }
            $y += 9;
        }
        $this->txt(12, $y + 1.5, 48, 'ÍNDICE GERAL', 8.5, true, self::BLUE);
        foreach ($months as $i => $m) {
            $s = $d['monthly'][$m]['overall'];
            $this->p->SetFillColor(...($s === null ? [241, 245, 249] : self::idx($s)));
            $this->p->RoundedRect($x0 + $i * $cw + 0.6, $y, $cw - 1.2, 7, 1, '1111', 'F');
            $this->txt($x0 + $i * $cw, $y + 1.4, $cw, $s === null ? '—' : (string) (int) $s, 8.5, true, [255, 255, 255], 'C');
        }
        $y += 14;
        // tabela mês a mês dos indicadores-chave
        $keys = ['entradas', 'concluidas', 'vazao', 'otd', 'atrasadas_pct', 'adiadas_pct', 'lead_time', 'backlog', 'com_aceite'];
        $cat = Kpi::targets();
        $this->p->SetFillColor(...self::NAVY);
        $this->p->Rect(12, $y, 273, 6.5, 'F');
        $this->txt(13.5, $y + 1.4, 46, 'Indicador-chave', 7.5, true, [255, 255, 255]);
        foreach ($months as $i => $m) {
            $this->txt($x0 + $i * $cw, $y + 1.4, $cw, self::MONTHS[(int) substr($m, 5, 2)], 7.5, true, [255, 255, 255], 'C');
        }
        $y += 6.5;
        foreach ($keys as $k => $key) {
            if ($k % 2 === 1) {
                $this->p->SetFillColor(248, 250, 252);
                $this->p->Rect(12, $y, 273, 7, 'F');
            }
            $this->txt(13.5, $y + 1.7, 48, $cat[$key]['name'], 7.5, true);
            foreach ($months as $i => $m) {
                $v = $d['series'][$m][$key] ?? null;
                $st = Kpi::status($cat[$key], $v);
                $this->txt($x0 + $i * $cw, $y + 1.7, $cw, Kpi::format($cat[$key], $v), 7.5, $st !== 'none', self::rgb(Kpi::STATUS_COLORS[$st]), 'C');
            }
            $y += 7;
        }
    }

    private function okrs(): void
    {
        $d = $this->d;
        $this->page('OKRs — objetivos e resultados-chave', 'Cada objetivo tem resultados-chave medidos por indicadores, projetos ou valores informados. O marcador mostra o tempo decorrido do período do objetivo.');
        if (!$d['okrs']) {
            $this->box(12, 30, 273, 50, self::LIGHT, false);
            $this->txt(18, 36, 260, 'Nenhum OKR cadastrado para este período', 12, true);
            $this->para(18, 46, 260, "Cadastre os objetivos e resultados-chave em Marketing → KPI's → OKRs. Cada resultado-chave pode ser medido automaticamente por um dos indicadores do plugin, pela execução de um projeto ou por um valor informado à mão. Os próximos books mostrarão o progresso de cada objetivo contra o tempo decorrido.", 9, self::NAVY, false, 4.4);
            return;
        }
        $y = 25;
        $p = $this->p;
        foreach ($d['okrs'] as $ev) {
            $o = $ev['okr'];
            $need = 20 + count($ev['krs']) * 10.5;
            if ($y + $need > 200) {
                $this->page('OKRs — objetivos e resultados-chave (continuação)');
                $y = 25;
            }
            $col = self::rgb(Kpi::STATUS_COLORS[$ev['status']]);
            $this->box(12, $y, 273, 17 + count($ev['krs']) * 10.5);
            $p->SetFillColor(...$col);
            $p->Rect(12, $y + 1.5, 2, 14 + count($ev['krs']) * 10.5, 'F');
            $this->txt(17, $y + 2, 175, $o['name'], 10.5, true);
            $this->txt(17, $y + 8, 175, 'Responsável: ' . $this->user((int) $o['users_id']) . ' · ' . $this->dt($o['date_from']) . ' a ' . $this->dt($o['date_to']) . ' · ' . (int) $ev['elapsed'] . '% do período decorrido', 7.5, false, self::MUTED);
            // barra do objetivo
            $bx = 205;
            $p->SetFillColor(226, 232, 240);
            $p->RoundedRect($bx, $y + 4, 62, 4.5, 1.5, '1111', 'F');
            if ($ev['progress'] !== null) {
                $p->SetFillColor(...$col);
                $p->RoundedRect($bx, $y + 4, max(1.5, 62 * $ev['progress'] / 100), 4.5, 1.5, '1111', 'F');
            }
            $p->SetLineStyle(['width' => 0.4, 'color' => self::NAVY, 'dash' => 0]);
            $p->Line($bx + 62 * $ev['elapsed'] / 100, $y + 2.5, $bx + 62 * $ev['elapsed'] / 100, $y + 10);
            $this->txt($bx + 64, $y + 3.8, 12, $ev['progress'] === null ? '—' : (int) $ev['progress'] . '%', 9, true, $col);
            $this->txt($bx, $y + 10.3, 80, Kpi::STATUS_LABELS[$ev['status']] === 'Crítico' ? 'Atrasado no objetivo' : ($ev['status'] === 'amber' ? 'Atenção' : ($ev['status'] === 'green' ? 'No ritmo' : 'Sem dados')), 7, true, $col);
            $yy = $y + 15;
            foreach ($ev['krs'] as $kr) {
                $kc = self::rgb(Kpi::STATUS_COLORS[$kr['status']]);
                $this->dot(19, $yy + 3.3, 1.3, $kc);
                $this->txt(23, $yy + 1.2, 110, $kr['name'], 8, false);
                $def = $kr['kind'] === 'kpi' ? (Kpi::targets()[$kr['kpi_key']] ?? null) : null;
                $fmt = fn ($v) => $v === null ? '—' : ($def ? Kpi::format($def, (float) $v) : rtrim(rtrim(number_format((float) $v, 1, ',', '.'), '0'), ','));
                $this->txt(135, $yy + 1.2, 70, 'Atual ' . $fmt($kr['current']) . ' · inicial ' . $fmt($kr['start_value']) . ' · meta ' . $fmt($kr['target_value']), 7.3, false, self::MUTED);
                $p->SetFillColor(226, 232, 240);
                $p->RoundedRect(205, $yy + 1.6, 62, 3.2, 1.2, '1111', 'F');
                if ($kr['progress'] !== null) {
                    $p->SetFillColor(...$kc);
                    $p->RoundedRect(205, $yy + 1.6, max(1.2, 62 * $kr['progress'] / 100), 3.2, 1.2, '1111', 'F');
                }
                $this->txt(268, $yy + 1, 15, $kr['progress'] === null ? '—' : (int) $kr['progress'] . '%', 8, true, $kc);
                $yy += 10.5;
            }
            $y += 21 + count($ev['krs']) * 10.5;
        }
    }

    private function projects(): void
    {
        $d = $this->d;
        $this->page('Projetos e planos de ação', 'Situação dos projetos ativos e dos planos de ação: execução efetiva x cronograma, atividades atrasadas e marcos próximos.');
        $p = $this->p;
        // projetos
        $this->txt(12, 24, 130, 'Projetos', 10, true);
        $y = 31;
        $cols = [['Projeto', 70], ['Responsável', 34], ['Fim previsto', 20], ['Execução', 17], ['Cronograma', 20], ['Desvio', 16], ['Atras.', 12], ['Prazo p/ replanejar', 28]];
        $p->SetFillColor(...self::NAVY);
        $p->Rect(12, $y, 217, 6.5, 'F');
        $cx = 12;
        foreach ($cols as [$h, $w]) {
            $this->txt($cx + 1, $y + 1.4, $w - 1, $h, 7, true, [255, 255, 255], $h === 'Projeto' || $h === 'Responsável' ? 'L' : 'C');
            $cx += $w;
        }
        $y += 6.5;
        $list = array_slice($d['projectRisks'], 0, 9);
        foreach ($list as $i => $r) {
            if ($i % 2 === 1) {
                $p->SetFillColor(248, 250, 252);
                $p->Rect(12, $y, 217, 7.2, 'F');
            }
            $dev = $r['deviation'];
            $col = $dev === null ? self::MUTED : ($dev < -15 ? [220, 38, 38] : ($dev < 0 ? [217, 119, 6] : [22, 163, 74]));
            $this->txt(13, $y + 1.8, 68, $r['name'], 7.5, true);
            $this->txt(83, $y + 1.8, 33, $this->user($r['owner']), 7.3, false, self::MUTED);
            $this->txt(117, $y + 1.8, 20, $this->dt($r['end']), 7.3, false, self::NAVY, 'C');
            $this->txt(137, $y + 1.8, 17, $r['effective'] === null ? '—' : $r['effective'] . '%', 7.5, true, self::NAVY, 'C');
            $this->txt(154, $y + 1.8, 20, $r['expected'] === null ? '—' : $r['expected'] . '%', 7.5, false, self::MUTED, 'C');
            $this->txt(174, $y + 1.8, 16, $dev === null ? '—' : ($dev >= 0 ? '+' : '') . (int) $dev . ' pp', 7.5, true, $col, 'C');
            $this->txt(190, $y + 1.8, 12, (string) $r['late'], 7.5, $r['late'] > 0, $r['late'] > 0 ? [220, 38, 38] : self::MUTED, 'C');
            $this->txt(202, $y + 1.8, 27, $r['risk'] ? $this->dt($r['recommended']) : 'no ritmo', 7.3, $r['risk'], $r['risk'] ? [220, 38, 38] : [22, 163, 74], 'C');
            $y += 7.2;
        }
        if (!$list) {
            $this->txt(12, $y + 4, 217, 'Nenhum projeto ativo.', 8.5, false, self::MUTED);
            $y += 10;
        }
        // marcos
        $this->box(233, 24, 52, 78, self::LIGHT, false);
        $this->txt(236, 26, 46, 'Marcos até 30 dias', 8.5, true);
        $my = 33;
        foreach (array_slice($d['milestones'], 0, 6) as $m) {
            $late = $m['due_date'] < $d['asof'];
            $this->txt(236, $my, 46, $this->dt($m['due_date']) . ($late ? ' · atrasado' : ''), 6.8, true, $late ? [220, 38, 38] : self::NAVY);
            $this->txt(236, $my + 3.4, 46, $m['name'], 6.8, false, self::NAVY);
            $this->txt(236, $my + 6.6, 46, $m['project'], 6, false, self::MUTED);
            $my += 11.4;
        }
        if (!$d['milestones']) {
            $this->txt(236, 34, 46, 'Nenhum marco pendente.', 7, false, self::MUTED);
        }
        // planos
        $y = max($y + 5, 104);
        $this->txt(12, $y, 130, 'Planos de ação (maiores desvios primeiro)', 10, true);
        $y += 7;
        $cols = [['Plano', 66], ['Projeto', 58], ['Responsável', 34], ['Ativ.', 12], ['Em aberto', 18], ['Atras.', 14], ['Execução', 18], ['Cronograma', 20], ['Fim', 22]];
        $p->SetFillColor(...self::NAVY);
        $p->Rect(12, $y, 262, 6.5, 'F');
        $cx = 12;
        foreach ($cols as [$h, $w]) {
            $this->txt($cx + 1, $y + 1.4, $w - 1, $h, 7, true, [255, 255, 255], in_array($h, ['Plano', 'Projeto', 'Responsável'], true) ? 'L' : 'C');
            $cx += $w;
        }
        $y += 6.5;
        foreach (array_slice($d['plans'], 0, 11) as $i => $r) {
            if ($i % 2 === 1) {
                $p->SetFillColor(248, 250, 252);
                $p->Rect(12, $y, 262, 6.4, 'F');
            }
            $this->txt(13, $y + 1.5, 64, $r['name'], 7.3, true);
            $this->txt(79, $y + 1.5, 56, $r['project'], 7.2, false, self::MUTED);
            $this->txt(137, $y + 1.5, 32, $this->user($r['owner']), 7.2, false, self::MUTED);
            $this->txt(169, $y + 1.5, 12, (string) $r['activities'], 7.3, false, self::NAVY, 'C');
            $this->txt(181, $y + 1.5, 18, (string) $r['open'], 7.3, false, self::NAVY, 'C');
            $this->txt(199, $y + 1.5, 14, (string) $r['late'], 7.3, $r['late'] > 0, $r['late'] > 0 ? [220, 38, 38] : self::MUTED, 'C');
            $this->txt(213, $y + 1.5, 18, $r['effective'] === null ? '—' : $r['effective'] . '%', 7.3, true, self::NAVY, 'C');
            $this->txt(231, $y + 1.5, 20, $r['expected'] === null ? '—' : $r['expected'] . '%', 7.3, false, self::MUTED, 'C');
            $this->txt(251, $y + 1.5, 22, $this->dt($r['end']), 7.2, false, self::NAVY, 'C');
            $y += 6.4;
        }
        if (!$d['plans']) {
            $this->txt(12, $y + 4, 262, 'Nenhum plano de ação ativo.', 8.5, false, self::MUTED);
        }
    }

    private function offenders(): void
    {
        $d = $this->d;
        $this->page('Ofensores: indicadores fora da meta', 'Todo indicador em atenção ou crítico, do mais grave ao menos grave, com o prazo recomendado para voltar à meta.');
        if (!$d['offenders']) {
            $this->box(12, 30, 273, 36, [240, 253, 244], false);
            $this->txt(18, 38, 260, 'Nenhum indicador fora da meta', 13, true, [22, 163, 74]);
            $this->txt(18, 48, 260, 'Todos os indicadores com meta estão no verde no período. Mantenha o acompanhamento e revise as metas periodicamente.', 9, false, self::NAVY);
            return;
        }
        $p = $this->p;
        $cols = [['Gravidade', 21], ['Indicador', 66], ['Grupo', 28], ['Realizado', 20], ['Meta', 19], ['Falta', 19], ['Tend.', 14], ['Meta intermediária', 34], ['Prazo final', 21], ['Responsável sugerido', 31]];
        $y = 25;
        $head = function () use (&$y, $cols, $p) {
            $p->SetFillColor(...self::NAVY);
            $p->Rect(12, $y, 273, 7, 'F');
            $cx = 12;
            foreach ($cols as [$h, $w]) {
                $this->txt($cx + 1, $y + 1.6, $w - 1, $h, 7, true, [255, 255, 255], in_array($h, ['Indicador', 'Grupo'], true) ? 'L' : 'C');
                $cx += $w;
            }
            $y += 7;
        };
        $head();
        $cat = Kpi::targets();
        foreach ($d['offenders'] as $i => $o) {
            if ($y > 190) {
                $this->page('Ofensores (continuação)');
                $y = 25;
                $head();
            }
            [$sl, $sc] = Kpi::SEVERITY[$o['severity']];
            $def = $cat[$o['key']];
            if ($i % 2 === 1) {
                $p->SetFillColor(248, 250, 252);
                $p->Rect(12, $y, 273, 8.2, 'F');
            }
            $p->SetFillColor(...self::mix(self::rgb($sc), 0.2));
            $p->RoundedRect(13, $y + 1.4, 19, 5.4, 1.2, '1111', 'F');
            $this->txt(13, $y + 2.5, 19, $sl, 6.8, true, self::rgb($sc), 'C');
            $this->txt(34, $y + 2.2, 64, $o['name'], 7.8, true);
            $this->txt(100, $y + 2.3, 27, Kpi::GROUPS[$o['group']][0], 6.6, false, self::MUTED);
            $this->txt(127, $y + 2.2, 19, Kpi::format($def, $o['value']), 8.3, true, self::rgb($sc), 'C');
            $this->txt(147, $y + 2.3, 18, Kpi::formatTarget($def, true), 7.2, false, self::NAVY, 'C');
            $gap = abs($o['gap']);
            $this->txt(166, $y + 2.3, 18, Kpi::format($def, $gap), 7.2, false, self::NAVY, 'C');
            $tl = $o['trend'] === 'worse' ? 'piora' : ($o['trend'] === 'better' ? 'melhora' : 'estável');
            $this->txt(185, $y + 2.2, 13, $tl, 6.8, true, $o['trend'] === 'worse' ? [220, 38, 38] : ($o['trend'] === 'better' ? [22, 163, 74] : self::MUTED), 'C');
            $this->txt(199, $y + 2.3, 34, Kpi::format($def, $o['mid_value']) . ' até ' . $this->dt($o['mid_date']), 6.8, false, self::NAVY, 'C');
            $this->txt(233, $y + 2.2, 21, $this->dt($o['due_date']), 7.8, true, self::rgb($sc), 'C');
            $this->txt(254, $y + 2.3, 31, $o['owner'], 6.8, false, self::MUTED, 'C');
            $y += 8.2;
        }
        $this->para(12, min($y + 4, 194), 273, 'Prazos em dias úteis a partir de ' . $this->dt(date('Y-m-d')) . ': crítico 20 dias, alto 30, médio 40 (indicadores de higiene de dados, metade). A meta intermediária é o ponto médio entre o valor atual e a meta, no meio do prazo.', 7, self::MUTED);
    }

    private function recovery(): void
    {
        $d = $this->d;
        if (!$d['offenders']) {
            return;
        }
        $this->page('Planos de recuperação dos ofensores', 'O que fazer, quem lidera e até quando, para cada indicador fora da meta. Revisão de acompanhamento em 5 dias úteis.');
        $y = 25;
        $cat = Kpi::targets();
        $p = $this->p;
        foreach ($d['offenders'] as $o) {
            $h = 52;
            if ($y + $h > 202) {
                $this->page('Planos de recuperação (continuação)');
                $y = 25;
            }
            [$sl, $sc] = Kpi::SEVERITY[$o['severity']];
            $col = self::rgb($sc);
            $def = $cat[$o['key']];
            $this->box(12, $y, 273, $h - 2);
            $p->SetFillColor(...$col);
            $p->Rect(12, $y + 1.5, 2.2, $h - 5, 'F');
            $this->txt(17, $y + 2, 150, $o['name'], 10.5, true);
            $this->txt(17, $y + 8, 150, Kpi::GROUPS[$o['group']][0] . ' · ' . $sl . ' · realizado ' . Kpi::format($def, $o['value']) . ' · meta ' . Kpi::formatTarget($def, true), 7.8, false, self::MUTED);
            $p->SetFillColor(...self::mix($col, 0.18));
            $p->RoundedRect(250, $y + 2, 31, 6, 1.5, '1111', 'F');
            $this->txt(250, $y + 3.2, 31, 'Prazo ' . $this->dt($o['due_date']), 7.5, true, $col, 'C');
            // colunas
            $this->txt(17, $y + 14, 80, 'POR QUE IMPORTA', 6.8, true, self::MUTED);
            $this->para(17, $y + 18, 82, $o['why'], 7.6, self::NAVY, false, 3.4);
            $this->txt(104, $y + 14, 100, 'AÇÕES RECOMENDADAS', 6.8, true, self::MUTED);
            $ay = $y + 18;
            foreach (array_slice($o['actions'], 0, 3) as $n => $a) {
                $this->txt(104, $ay, 5, ($n + 1) . '.', 7.6, true, $col);
                $ay = $this->para(109, $ay, 96, $a, 7.6, self::NAVY, false, 3.4) + 1;
            }
            $this->txt(212, $y + 14, 70, 'RESPONSÁVEL E PRAZOS', 6.8, true, self::MUTED);
            $this->txt(212, $y + 18.5, 72, $o['owner'], 7.8, true);
            $steps = [['D+5 úteis', $this->dt($o['review_date']), 'revisão de acompanhamento'], ['Intermediário', $this->dt($o['mid_date']), 'chegar a ' . Kpi::format($def, $o['mid_value'])], ['Final', $this->dt($o['due_date']), 'voltar à meta ' . Kpi::formatTarget($def, true)]];
            $sy = $y + 25;
            foreach ($steps as $k => [$a, $b, $c]) {
                $this->dot(214, $sy + 1.6, 1.2, $col);
                if ($k < 2) {
                    $p->SetLineStyle(['width' => 0.2, 'color' => self::LINE, 'dash' => 0]);
                    $p->Line(214, $sy + 3, 214, $sy + 7.4);
                }
                $this->txt(217, $sy, 66, $a . ' · ' . $b, 7.3, true);
                $this->txt(217, $sy + 3.4, 66, $c, 6.6, false, self::MUTED);
                $sy += 8.2;
            }
            $y += $h;
        }
    }

    private function demandOffenders(): void
    {
        $d = $this->d;
        $this->page('Demandas ofensoras e prazos recomendados', 'Demandas que mais pesam nos indicadores: atrasadas, adiadas várias vezes, paradas, antigas ou sem responsável.');
        $list = $d['offenderDemands'];
        if (!$list) {
            $this->txt(12, 32, 260, 'Nenhuma demanda ofensora no momento.', 11, true, [22, 163, 74]);
            return;
        }
        $p = $this->p;
        $cols = [['Código', 22], ['Demanda', 54], ['Resp.', 27], ['Prior.', 11], ['Prazo atual', 19], ['Motivos', 62], ['Prazo recomendado', 28], ['Ação recomendada', 50]];
        $y = 25;
        $head = function () use (&$y, $cols, $p) {
            $p->SetFillColor(...self::NAVY);
            $p->Rect(12, $y, 273, 7, 'F');
            $cx = 12;
            foreach ($cols as [$h, $w]) {
                $this->txt($cx + 1, $y + 1.6, $w - 1, $h, 7, true, [255, 255, 255], in_array($h, ['Demanda', 'Motivos', 'Ação recomendada', 'Resp.', 'Código'], true) ? 'L' : 'C');
                $cx += $w;
            }
            $y += 7;
        };
        $head();
        foreach ($list as $i => $r) {
            if ($y > 190) {
                $this->page('Demandas ofensoras (continuação)');
                $y = 25;
                $head();
            }
            if ($i % 2 === 1) {
                $p->SetFillColor(248, 250, 252);
                $p->Rect(12, $y, 273, 8.2, 'F');
            }
            $this->txt(13, $y + 2.6, 20, $r['code'], 7, true, self::BLUE);
            $this->para(35, $y + 1.2, 53, $r['name'], 6.8, self::NAVY, true, 2.7);
            $this->txt(89, $y + 2.6, 26, $this->user($r['assignee']), 6.8, false, self::MUTED);
            $this->txt(116, $y + 2.6, 11, (string) $r['priority'], 7.3, false, self::NAVY, 'C');
            $this->txt(127, $y + 2.6, 19, $this->dt($r['deadline']), 7, false, self::NAVY, 'C');
            $this->para(147, $y + 1.2, 61, implode('; ', $r['reasons']), 6, [180, 83, 9], false, 2.6);
            $this->txt(208, $y + 2.6, 28, $this->dt($r['recommended']) . ' (' . $r['du'] . ' d.u.)', 7.3, true, self::BLUE, 'C');
            $this->para(237, $y + 1.2, 47, $r['action'], 6, self::MUTED, false, 2.6);
            $y += 8.2;
        }
        $this->para(12, min($y + 3, 196), 273, 'Prazo recomendado em dias úteis (d) a partir de ' . $this->dt($d['asof']) . ': prioridade muito alta/alta 5; média 10; baixa 15; adiadas 2 vezes ou mais e bloqueadas 3 (replanejar e definir prazo final com o solicitante). Registre a reprogramação com justificativa na ficha.', 6.8, self::MUTED);
    }

    private function timeline(): void
    {
        $d = $this->d;
        if (!$d['offenders']) {
            return;
        }
        $this->page('Cronograma de recuperação', 'Linha do tempo dos prazos recomendados: revisão (D+5), meta intermediária e prazo final de cada ofensor.');
        $start = strtotime(date('Y-m-d'));
        $end = $start;
        foreach ($d['offenders'] as $o) {
            $end = max($end, strtotime($o['due_date']));
        }
        $span = max(1, $end - $start);
        $x0 = 92;
        $w = 172;
        $p = $this->p;
        // eixo de datas
        for ($i = 0; $i <= 6; $i++) {
            $t = $start + $span * $i / 6;
            $gx = $x0 + $w * $i / 6;
            $p->SetLineStyle(['width' => 0.15, 'color' => [226, 232, 240], 'dash' => 0]);
            $p->Line($gx, 30, $gx, 30 + min(count($d['offenders']), 17) * 9 + 4);
            $this->txt($gx - 10, 25, 20, date('d/m', (int) $t), 7, true, self::MUTED, 'C');
        }
        $y = 32;
        foreach (array_slice($d['offenders'], 0, 17) as $o) {
            $col = self::rgb(Kpi::SEVERITY[$o['severity']][1]);
            $this->txt(12, $y + 1.4, 78, $o['name'], 7.5, true);
            $bx = $x0;
            $bw = $w * (strtotime($o['due_date']) - $start) / $span;
            $p->SetFillColor(...self::mix($col, 0.25));
            $p->RoundedRect($bx, $y + 0.8, max(2, $bw), 5.2, 1.5, '1111', 'F');
            $mx = $x0 + $w * (strtotime($o['mid_date']) - $start) / $span;
            $rx = $x0 + $w * (strtotime($o['review_date']) - $start) / $span;
            $this->dot($rx, $y + 3.4, 1.1, [100, 116, 139]);
            $this->dot($mx, $y + 3.4, 1.4, [217, 119, 6]);
            $this->dot($x0 + $bw, $y + 3.4, 1.8, $col);
            $this->txt($x0 + $bw + 2.5, $y + 1.4, 22, $this->dt($o['due_date']), 6.8, true, $col);
            $y += 9;
        }
        $ly = max($y + 4, 190);
        $this->dot(14, $ly + 2, 1.1, [100, 116, 139]);
        $this->txt(17, $ly, 50, 'Revisão (D+5 úteis)', 7, false, self::MUTED);
        $this->dot(70, $ly + 2, 1.4, [217, 119, 6]);
        $this->txt(73, $ly, 60, 'Meta intermediária', 7, false, self::MUTED);
        $this->dot(125, $ly + 2, 1.8, [220, 38, 38]);
        $this->txt(128, $ly, 80, 'Prazo final (volta à meta)', 7, false, self::MUTED);
    }

    private function nextYear(): void
    {
        $d = $this->d;
        $this->page('Recomendações para o próximo ano', 'Indicadores que ficaram fora da meta em mais meses do ano: candidatos a metas, projetos e OKRs do próximo ciclo.');
        $cat = Kpi::targets();
        $bad = [];
        foreach ($cat as $key => $def) {
            if ($def['dir'] === 'info') {
                continue;
            }
            $n = 0;
            $tot = 0;
            foreach ($d['series'] as $mv) {
                $st = Kpi::status($def, $mv[$key] ?? null);
                if ($st !== 'none') {
                    $tot++;
                    $n += $st === 'green' ? 0 : 1;
                }
            }
            if ($tot >= 3 && $n / $tot >= 0.4) {
                $bad[] = ['key' => $key, 'name' => $def['name'], 'n' => $n, 'tot' => $tot, 'action' => $def['actions'][0] ?? '', 'group' => $def['group']];
            }
        }
        usort($bad, fn ($a, $b) => ($b['n'] / $b['tot']) <=> ($a['n'] / $a['tot']));
        if (!$bad) {
            $this->txt(12, 30, 260, 'Nenhum indicador ficou fora da meta em mais de 40% dos meses. Considere metas mais ambiciosas.', 10, true, [22, 163, 74]);
            return;
        }
        $y = 26;
        foreach (array_slice($bad, 0, 8) as $b) {
            $this->box(12, $y, 273, 19);
            $this->p->SetFillColor(...self::rgb(Kpi::GROUPS[$b['group']][1]));
            $this->p->Rect(12, $y + 1.5, 2, 16, 'F');
            $this->txt(17, $y + 2, 150, $b['name'], 9.5, true);
            $this->txt(17, $y + 8.5, 200, 'Fora da meta em ' . $b['n'] . ' de ' . $b['tot'] . ' meses · ' . Kpi::GROUPS[$b['group']][0], 7.5, false, self::MUTED);
            $this->txt(17, $y + 13, 255, 'Recomendação: ' . $b['action'], 7.8, false, self::NAVY);
            $this->gauge(266, $y + 14, 9, 100 * (1 - $b['n'] / $b['tot']), '', 0.34);
            $y += 21.5;
        }
    }

    private function methodology(): void
    {
        $this->page('Metodologia, metas e glossário', 'Como cada indicador é calculado, qual a meta vigente e por que ele importa. Metas editáveis em KPIs → Metas.');
        $p = $this->p;
        $cat = Kpi::targets();
        $y = 25;
        $head = function () use (&$y, $p) {
            $p->SetFillColor(...self::NAVY);
            $p->Rect(12, $y, 273, 6.5, 'F');
            $this->txt(13, $y + 1.4, 50, 'Indicador', 7, true, [255, 255, 255]);
            $this->txt(64, $y + 1.4, 100, 'Como é calculado', 7, true, [255, 255, 255]);
            $this->txt(176, $y + 1.4, 60, 'Por que importa', 7, true, [255, 255, 255]);
            $this->txt(252, $y + 1.4, 16, 'Meta', 7, true, [255, 255, 255], 'C');
            $this->txt(268, $y + 1.4, 16, 'Atenção', 7, true, [255, 255, 255], 'C');
            $y += 6.5;
        };
        $head();
        $i = 0;
        foreach ($cat as $key => $def) {
            if ($y > 190) {
                $this->page('Metodologia (continuação)');
                $y = 25;
                $head();
            }
            if ($i++ % 2 === 1) {
                $p->SetFillColor(248, 250, 252);
                $p->Rect(12, $y, 273, 9, 'F');
            }
            $this->txt(13, $y + 0.9, 49, $def['name'], 7, true);
            $this->txt(13, $y + 4.6, 49, Kpi::GROUPS[$def['group']][0], 5.8, false, self::MUTED);
            $this->para(64, $y + 0.7, 110, $def['formula'], 6.2, self::NAVY, false, 2.7);
            $this->para(176, $y + 0.7, 74, $def['why'], 6.2, self::MUTED, false, 2.7);
            $this->txt(252, $y + 2.6, 16, Kpi::formatTarget($def, true), 6.8, true, self::NAVY, 'C');
            $this->txt(268, $y + 2.6, 16, $def['dir'] === 'info' ? '—' : Kpi::format($def, (float) $def['warn']), 6.8, false, self::MUTED, 'C');
            $y += 9;
        }
        if ($y > 150) {
            $this->page('Metodologia (glossário)');
            $y = 25;
        }
        $y += 4;
        $this->box(12, $y, 273, 36, self::LIGHT, false);
        $this->txt(16, $y + 2, 260, 'Regras gerais', 9, true);
        $this->para(16, $y + 8, 265, "Fluxo (recebidas, concluídas, tempos) é medido dentro do período; estoque (backlog, atrasadas, adiadas) é medido na data final, reconstruído pelo histórico de situações e prazos. Indicadores de projetos e planos mostram o retrato do dia do envio. Prazos recomendados contam dias úteis (segunda a sexta). Semáforo: verde = na meta; âmbar = entre a meta e o limite de atenção; vermelho = além do limite. Índice geral = média da pontuação dos grupos (verde ≥ 85, âmbar ≥ 65). Os dados vêm das fichas de demanda, do histórico de eventos, das aprovações, das reprogramações e dos cadastros de projetos, planos, marcos e OKRs.", 7.3, self::NAVY, false, 3.3);
    }
}

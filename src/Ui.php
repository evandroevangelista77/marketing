<?php

namespace GlpiPlugin\Marketing;

use Html;

/**
 * Peças de interface compartilhadas pelas telas (cabeçalho, título, badge, formulários, gaveta).
 * Segue o mesmo padrão visual dos plugins VMO, CRM, PMO e RH.
 */
class Ui
{
    public const SECTOR = 'marketing';
    public const CRUMB = 'Marketing · Gestão de Demandas';

    public static function url(string $path): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/marketing' . $path;
    }

    public static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    /** Cabeçalho do GLPI + folha de estilo e gaveta do plugin. $item = chave do menu lateral. */
    public static function header(string $title, string $item): void
    {
        Html::header($title, '', self::SECTOR, $item);
        $css = dirname(__DIR__) . '/public/css/marketing.css';
        echo "<link rel='stylesheet' href='" . self::e(self::url('/css/marketing.css') . '?v=' . PLUGIN_MARKETING_VERSION . '-' . @filemtime($css)) . "'>";
        echo self::script('drawer.js');
    }

    public static function script(string $file): string
    {
        $f = dirname(__DIR__) . '/public/js/' . $file;
        return Html::script(self::url('/js/' . $file), ['version' => PLUGIN_MARKETING_VERSION . '-' . (is_file($f) ? filemtime($f) : 0)], false);
    }

    public static function title(string $title, string $subtitle = '', string $actions = ''): string
    {
        return "<div class='mkt-title'><div><div class='mkt-crumb'>" . self::e(self::CRUMB) . "</div><h2>" . self::e($title) . "</h2>"
            . ($subtitle !== '' ? "<div class='text-secondary small mkt-subtitle'>" . self::e($subtitle) . "</div>" : '')
            . "</div><div class='d-flex flex-wrap gap-2 align-items-center'>" . $actions . "</div></div>";
    }

    public static function button(string $label, string $url, string $icon = 'ti-plus', string $cls = 'btn-primary'): string
    {
        return "<a class='btn $cls' href='" . self::e($url) . "'><i class='ti $icon me-1'></i>" . self::e($label) . "</a>";
    }

    public static function badge(string $label, string $color, string $icon = ''): string
    {
        return "<span class='badge mkt-badge' style='--c:" . self::e($color) . "'>" . ($icon ? "<i class='ti $icon me-1'></i>" : '') . self::e($label) . "</span>";
    }

    /**
     * Campo CSRF dos formulários. O GLPI 11 valida o token sozinho a cada POST (não chame checkCSRF de novo:
     * o token é de uso único); o GLPI 12 não usa mais token.
     */
    public static function csrf(): string
    {
        if (version_compare(GLPI_VERSION, '12.0.0-dev', '>=')) {
            return '';
        }
        return Html::hidden('_glpi_csrf_token', ['value' => \Session::getNewCSRFToken()]);
    }

    /** Data no padrão brasileiro dd/mm/aaaa, igual em todas as telas (independe do formato do perfil do usuário). */
    public static function date(?string $d): string
    {
        $ts = $d ? strtotime($d) : false;
        return $ts ? date('d/m/Y', $ts) : '—';
    }

    /** Data e hora no padrão brasileiro dd/mm/aaaa hh:mm. */
    public static function datetime(?string $d): string
    {
        $ts = $d ? strtotime($d) : false;
        return $ts ? date('d/m/Y H:i', $ts) : '—';
    }

    /** <select> simples. $options = valor => rótulo. */
    public static function select(string $name, array $options, $selected = '', string $extra = '', bool $empty = false, string $emptyLabel = 'Todos'): string
    {
        $h = "<select class='form-select' name='" . self::e($name) . "' $extra>";
        if ($empty) {
            $h .= "<option value=''>" . self::e($emptyLabel) . "</option>";
        }
        foreach ($options as $value => $label) {
            $h .= "<option value='" . self::e($value) . "'" . ((string) $selected === (string) $value ? ' selected' : '') . ">" . self::e($label) . "</option>";
        }
        return $h . "</select>";
    }

    /** Bolinha com as iniciais da pessoa (cor estável por usuário). Sem usuário: círculo tracejado. */
    public static function avatar(int $userId): string
    {
        if ($userId <= 0) {
            return "<span class='mkt-av mkt-av-none' title='Sem responsável'><i class='ti ti-user'></i></span>";
        }
        $name = trim((string) getUserName($userId));
        $parts = preg_split('/\s+/', $name) ?: [$name];
        $ini = mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : mb_substr($parts[0], 1, 1)));
        $hue = ($userId * 47) % 360;
        return "<span class='mkt-av' style='--h:$hue' title='" . self::e($name) . "'>" . self::e($ini) . "</span>";
    }

    /** Data curta no estilo "15 set". */
    public static function shortDate(?string $d): string
    {
        $ts = $d ? strtotime($d) : false;
        if (!$ts) {
            return '—';
        }
        $m = ['', 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
        return (int) date('j', $ts) . ' ' . $m[(int) date('n', $ts)];
    }

    /**
     * Seletores encadeados Pilar → Projeto → Plano de ação. Ao escolher o pilar, o projeto e o plano mostram só os
     * que pertencem a ele (public/js/estrutura.js). Cada um vem em uma coluna de 4/12.
     */
    public static function structureSelects(array $v, string $extra = ''): string
    {
        $pillars = Structure::pillars(true);
        $projects = Structure::projects(true);
        $plans = Structure::plans(0, true);
        $h = "<div class='row mkt-structure'>";
        $h .= "<div class='col-md-4 mb-3'><label class='form-label'>Pilar / frente</label><select class='form-select' name='pillars_id' data-lvl='pillar' $extra><option value=''>Sem pilar</option>";
        foreach ($pillars as $id => $r) {
            $h .= "<option value='$id'" . ((int) ($v['pillars_id'] ?? 0) === $id ? ' selected' : '') . ">" . self::e($r['name']) . "</option>";
        }
        $h .= "</select></div><div class='col-md-4 mb-3'><label class='form-label'>Projeto</label><select class='form-select' name='mkt_projects_id' data-lvl='project' $extra><option value=''>Sem projeto (avulsa)</option>";
        foreach ($projects as $id => $r) {
            $h .= "<option value='$id' data-pillar='" . (int) $r['pillars_id'] . "'" . ((int) ($v['mkt_projects_id'] ?? 0) === $id ? ' selected' : '') . ">" . self::e($r['name']) . "</option>";
        }
        $h .= "</select></div><div class='col-md-4 mb-3'><label class='form-label'>Plano de ação</label><select class='form-select' name='plans_id' data-lvl='plan' $extra><option value=''>Sem plano</option>";
        foreach ($plans as $id => $r) {
            $h .= "<option value='$id' data-project='" . (int) $r['mkt_projects_id'] . "'" . ((int) ($v['plans_id'] ?? 0) === $id ? ' selected' : '') . ">" . self::e($r['name']) . "</option>";
        }
        return $h . "</select></div></div>" . self::script('estrutura.js');
    }

    /** Selo da origem (Externa / Interna). */
    public static function originBadge(string $origin): string
    {
        return self::badge(Demand::ORIGIN_SHORT[$origin] ?? 'Externa', Demand::ORIGIN_COLORS[$origin] ?? '#94a3b8', $origin === Demand::ORIGIN_INTERNAL ? 'ti-building' : 'ti-inbox');
    }

    /**
     * Blocos de progresso de um plano ou projeto: avanço pela situação das atividades, execução efetiva (percentual informado),
     * parte do cronograma já decorrida e o desvio entre a execução efetiva e o esperado.
     *
     * @param array<int, array<string, mixed>> $rows atividades
     */
    public static function metrics(array $rows, ?string $start, ?string $end): string
    {
        $status = Structure::progress($rows);
        $eff = Structure::effective($rows);
        [$informed, $open] = Structure::effectiveCoverage($rows);
        $exp = Structure::expected($start, $end);
        $bar = fn (?int $v, string $c) => $v === null ? "<span class='text-secondary small'>—</span>" : self::bar($v, $c);
        $h = "<div class='mkt-metric'><small title='Média do avanço de cada atividade conforme a situação no fluxo (recebida 5%, planejada 30%, em andamento 55%, aprovação 80%, concluída 100%)'>Avanço por situação</small>" . $bar($status, '#6366f1') . "</div>";
        $h .= "<div class='mkt-metric'><small title='Média do percentual de execução informado pelo responsável em cada atividade (concluída = 100%)'>Execução efetiva</small>" . $bar($eff, '#22c55e')
            . ($open ? "<span class='small text-secondary'>$informed de $open abertas com % informado</span>" : '') . "</div>";
        if ($exp !== null) {
            $h .= "<div class='mkt-metric'><small title='Parte do período (início → fim) que já passou'>Cronograma decorrido</small>" . $bar($exp, '#94a3b8');
            if ($eff !== null) {
                $d = $eff - $exp;
                $h .= "<span class='delta " . ($d < 0 ? 'neg' : 'pos') . "'>" . ($d >= 0 ? '+' : '−') . abs($d) . " p.p. " . ($d < 0 ? 'abaixo do esperado' : 'em dia ou à frente') . "</span>";
            }
            $h .= "</div>";
        }
        return $h;
    }

    /** Selo permanente "Adiada" (prazo já foi empurrado para frente ao menos uma vez), com a quantidade de reprogramações. */
    public static function postponedBadge(array $row): string
    {
        if (empty($row['is_postponed'])) {
            return '';
        }
        $n = max(1, (int) ($row['postponed_count'] ?? 1));
        $orig = !empty($row['original_date']) ? ' · prazo original ' . self::date((string) $row['original_date']) : '';
        return " <span class='mkt-postponed' title='" . self::e('Prazo reprogramado ' . $n . 'x' . $orig) . "'><i class='ti ti-calendar-pause'></i>Adiada" . ($n > 1 ? ' ×' . $n : '') . "</span>";
    }

    /** Barra de progresso fina (0–100) para projetos e planos. */
    public static function bar(?int $pct, string $color = '#6366f1'): string
    {
        if ($pct === null) {
            return "<span class='text-secondary small'>sem atividades</span>";
        }
        return "<div class='mkt-pbar' title='$pct%'><span style='width:$pct%;background:" . self::e($color) . "'></span></div><span class='small text-secondary'>$pct%</span>";
    }

    /** Lista de usuários ativos (id => nome) para filtros e campos. */
    public static function userOptions(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0], 'LIMIT' => 500]) as $row) {
            $out[(int) $row['id']] = getUserName((int) $row['id']);
        }
        asort($out);
        return $out;
    }
}

<?php

namespace GlpiPlugin\Marketing;

/**
 * OKRs do Marketing: objetivos com resultados-chave. Cada resultado-chave é medido automaticamente por um indicador (KPI),
 * pela execução efetiva de um projeto, ou informado à mão. Progresso = (atual − inicial) ÷ (meta − inicial), limitado de 0 a 100%.
 */
class Okr
{
    public const KINDS = ['kpi' => 'Indicador (automático)', 'project' => 'Execução de um projeto (automático)', 'manual' => 'Valor informado à mão'];

    public static function table(): string
    {
        return 'glpi_plugin_marketing_okrs';
    }

    public static function krTable(): string
    {
        return 'glpi_plugin_marketing_krs';
    }

    /** @return array<int, array<string, mixed>> objetivos com seus resultados-chave */
    public static function all(bool $activeOnly = false): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::table(), 'WHERE' => $activeOnly ? ['is_archived' => 0] : [], 'ORDER' => ['date_from DESC', 'id DESC']]) as $o) {
            $o['krs'] = [];
            $out[(int) $o['id']] = $o;
        }
        if ($out) {
            foreach ($DB->request(['FROM' => self::krTable(), 'WHERE' => ['okrs_id' => array_keys($out)], 'ORDER' => 'id ASC']) as $k) {
                $out[(int) $k['okrs_id']]['krs'][(int) $k['id']] = $k;
            }
        }
        return $out;
    }

    public static function save(int $id, array $in): int
    {
        global $DB;
        $date = fn ($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
        $data = [
            'name'        => mb_substr(trim((string) ($in['name'] ?? '')), 0, 200),
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 2000),
            'users_id'    => max(0, (int) ($in['users_id'] ?? 0)),
            'pillars_id'  => max(0, (int) ($in['pillars_id'] ?? 0)),
            'date_from'   => $date($in['date_from'] ?? null),
            'date_to'     => $date($in['date_to'] ?? null),
            'is_archived' => !empty($in['is_archived']) ? 1 : 0,
        ];
        if ($data['name'] === '' || !$data['date_from'] || !$data['date_to'] || $data['date_to'] < $data['date_from']) {
            return 0;
        }
        if ($id > 0) {
            $DB->update(self::table(), $data, ['id' => $id]);
            return $id;
        }
        $DB->insert(self::table(), $data + ['date_creation' => date('Y-m-d H:i:s')]);
        return (int) $DB->insertId();
    }

    public static function delete(int $id): void
    {
        global $DB;
        $DB->delete(self::krTable(), ['okrs_id' => $id]);
        $DB->delete(self::table(), ['id' => $id]);
    }

    public static function saveKr(int $okrId, int $id, array $in): int
    {
        global $DB;
        $kind = isset(self::KINDS[$in['kind'] ?? '']) ? $in['kind'] : 'kpi';
        $kpi = (string) ($in['kpi_key'] ?? '');
        $num = fn ($v) => ($v === '' || $v === null || !is_numeric(str_replace(',', '.', (string) $v))) ? null : (float) str_replace(',', '.', (string) $v);
        $data = [
            'okrs_id'      => $okrId,
            'name'         => mb_substr(trim((string) ($in['name'] ?? '')), 0, 200),
            'kind'         => $kind,
            'kpi_key'      => $kind === 'kpi' && isset(Kpi::catalog()[$kpi]) ? $kpi : '',
            'projects_id'  => $kind === 'project' ? max(0, (int) ($in['projects_id'] ?? 0)) : 0,
            'start_value'  => $num($in['start_value'] ?? null) ?? 0.0,
            'target_value' => $num($in['target_value'] ?? null),
            'current_value' => $kind === 'manual' ? $num($in['current_value'] ?? null) : null,
            'weight'       => max(1, min(10, (int) ($in['weight'] ?? 1))),
        ];
        if ($data['name'] === '' || $data['target_value'] === null || ($kind === 'kpi' && $data['kpi_key'] === '') || ($kind === 'project' && $data['projects_id'] <= 0)) {
            return 0;
        }
        if ($kind === 'project' && ($in['target_value'] ?? '') === '') {
            $data['target_value'] = 100.0;
        }
        if ($id > 0) {
            $DB->update(self::krTable(), $data, ['id' => $id, 'okrs_id' => $okrId]);
            return $id;
        }
        $DB->insert(self::krTable(), $data);
        return (int) $DB->insertId();
    }

    public static function deleteKr(int $okrId, int $id): void
    {
        global $DB;
        $DB->delete(self::krTable(), ['id' => $id, 'okrs_id' => $okrId]);
    }

    /** Valor atual de um resultado-chave (null se ainda não há dado). */
    public static function current(array $kr, array $okr, ?string $asof = null): ?float
    {
        $asof ??= date('Y-m-d');
        if ($kr['kind'] === 'manual') {
            return $kr['current_value'] === null ? null : (float) $kr['current_value'];
        }
        if ($kr['kind'] === 'project') {
            $rows = Structure::withoutSources(Structure::activities(['mkt_projects_id' => (int) $kr['projects_id']]));
            $e = Structure::effective($rows);
            return $e === null ? null : (float) $e;
        }
        $to = min($asof, $okr['date_to']);
        $vals = Kpi::values($okr['date_from'], $to);
        return $vals[$kr['kpi_key']] ?? null;
    }

    /**
     * Situação do objetivo: progresso de cada resultado-chave, progresso ponderado do objetivo, tempo decorrido e o semáforo
     * (verde: progresso ≥ tempo − 10 p.p.; âmbar: ≥ tempo − 25 p.p.; vermelho abaixo).
     *
     * @return array<string, mixed>
     */
    public static function evaluate(array $okr, ?string $asof = null): array
    {
        $asof ??= date('Y-m-d');
        $elapsed = Structure::expected($okr['date_from'], $okr['date_to'], $asof) ?? 0;
        $krs = [];
        $sum = $w = 0.0;
        foreach ($okr['krs'] as $id => $kr) {
            $cur = self::current($kr, $okr, $asof);
            $start = (float) $kr['start_value'];
            $target = (float) $kr['target_value'];
            $progress = null;
            if ($cur !== null && abs($target - $start) > 0.00001) {
                $progress = max(0.0, min(100.0, ($cur - $start) / ($target - $start) * 100));
            }
            $krs[$id] = $kr + ['current' => $cur, 'progress' => $progress, 'status' => self::status($progress, $elapsed)];
            if ($progress !== null) {
                $sum += $progress * (int) $kr['weight'];
                $w += (int) $kr['weight'];
            }
        }
        $progress = $w > 0 ? round($sum / $w, 0) : null;
        return ['okr' => $okr, 'krs' => $krs, 'progress' => $progress, 'elapsed' => $elapsed, 'status' => self::status($progress, $elapsed)];
    }

    public static function status(?float $progress, float $elapsed): string
    {
        if ($progress === null) {
            return 'none';
        }
        return $progress >= $elapsed - 10 ? 'green' : ($progress >= $elapsed - 25 ? 'amber' : 'red');
    }
}

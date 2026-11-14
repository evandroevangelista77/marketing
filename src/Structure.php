<?php

namespace GlpiPlugin\Marketing;

/**
 * Estrutura de planejamento do Marketing: pilar/frente → projeto → plano de ação → atividade (demanda) → subtarefa.
 * Pilares, projetos e planos são cadastros do plugin (sem alteração de código); as atividades são as próprias
 * demandas (coluna origin = internal) ou solicitações externas vinculadas por pillars_id / mkt_projects_id / plans_id.
 */
class Structure
{
    public const PROJECT_STATUSES = ['planning' => 'Em planejamento', 'active' => 'Em execução', 'paused' => 'Pausado', 'done' => 'Concluído'];

    public static function pillarsTable(): string
    {
        return 'glpi_plugin_marketing_pillars';
    }

    public static function projectsTable(): string
    {
        return 'glpi_plugin_marketing_mprojects';
    }

    public static function plansTable(): string
    {
        return 'glpi_plugin_marketing_plans';
    }

    public static function milestonesTable(): string
    {
        return 'glpi_plugin_marketing_milestones';
    }

    /** @return array<int, array<string, mixed>> */
    private static function rows(string $table, array $where = [], string $order = 'name ASC'): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => $where, 'ORDER' => $order]) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    // ---------------------------------------------------------------- pilares

    public static function pillars(bool $activeOnly = false): array
    {
        return self::rows(self::pillarsTable(), $activeOnly ? ['is_archived' => 0] : []);
    }

    public static function pillarOptions(bool $activeOnly = true): array
    {
        return array_map(fn ($r) => (string) $r['name'], self::pillars($activeOnly));
    }

    public static function savePillar(int $id, array $in): int
    {
        global $DB;
        $data = [
            'name'        => mb_substr(trim((string) ($in['name'] ?? '')), 0, 120),
            'color'       => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($in['color'] ?? '')) ? $in['color'] : '#6366f1',
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 1000),
            'users_id'    => max(0, (int) ($in['users_id'] ?? 0)),
            'is_archived' => !empty($in['is_archived']) ? 1 : 0,
        ];
        if ($data['name'] === '') {
            return 0;
        }
        if ($id > 0) {
            $DB->update(self::pillarsTable(), $data, ['id' => $id]);
            return $id;
        }
        $DB->insert(self::pillarsTable(), $data + ['date_creation' => date('Y-m-d H:i:s')]);
        return (int) $DB->insertId();
    }

    // ---------------------------------------------------------------- projetos

    public static function projects(bool $activeOnly = false): array
    {
        return self::rows(self::projectsTable(), $activeOnly ? ['is_archived' => 0] : []);
    }

    /** id => "Pilar · Projeto" (ou só o projeto, sem pilar). */
    public static function projectOptions(bool $activeOnly = true): array
    {
        $pillars = self::pillars();
        $out = [];
        foreach (self::projects($activeOnly) as $id => $p) {
            $out[$id] = ((int) $p['pillars_id'] > 0 && isset($pillars[(int) $p['pillars_id']]) ? $pillars[(int) $p['pillars_id']]['name'] . ' · ' : '') . $p['name'];
        }
        return $out;
    }

    public static function saveProject(int $id, array $in): int
    {
        global $DB;
        $date = fn ($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
        $status = (string) ($in['status'] ?? '');
        $data = [
            'pillars_id'  => max(0, (int) ($in['pillars_id'] ?? 0)),
            'name'        => mb_substr(trim((string) ($in['name'] ?? '')), 0, 160),
            'objective'   => mb_substr(trim((string) ($in['objective'] ?? '')), 0, 4000),
            'users_id'    => max(0, (int) ($in['users_id'] ?? 0)),
            'start_date'  => $date($in['start_date'] ?? null),
            'end_date'    => $date($in['end_date'] ?? null),
            'status'      => isset(self::PROJECT_STATUSES[$status]) ? $status : 'planning',
            'is_archived' => !empty($in['is_archived']) ? 1 : 0,
        ];
        if ($data['name'] === '') {
            return 0;
        }
        if ($id > 0) {
            $DB->update(self::projectsTable(), $data, ['id' => $id]);
            return $id;
        }
        $DB->insert(self::projectsTable(), $data + ['date_creation' => date('Y-m-d H:i:s')]);
        return (int) $DB->insertId();
    }

    // ---------------------------------------------------------------- planos de ação

    public static function plans(int $projectId = 0, bool $activeOnly = false): array
    {
        $where = [];
        if ($projectId > 0) {
            $where['mkt_projects_id'] = $projectId;
        }
        if ($activeOnly) {
            $where['is_archived'] = 0;
        }
        return self::rows(self::plansTable(), $where);
    }

    /** id => "Plano (Projeto)". */
    public static function planOptions(bool $activeOnly = true): array
    {
        $projects = self::projects();
        $out = [];
        foreach (self::plans(0, $activeOnly) as $id => $p) {
            $out[$id] = $p['name'] . ((int) $p['mkt_projects_id'] > 0 && isset($projects[(int) $p['mkt_projects_id']]) ? ' (' . $projects[(int) $p['mkt_projects_id']]['name'] . ')' : '');
        }
        return $out;
    }

    public static function savePlan(int $id, array $in): int
    {
        global $DB;
        $date = fn ($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
        $data = [
            'mkt_projects_id' => max(0, (int) ($in['mkt_projects_id'] ?? 0)),
            'name'            => mb_substr(trim((string) ($in['name'] ?? '')), 0, 160),
            'objective'       => mb_substr(trim((string) ($in['objective'] ?? '')), 0, 4000),
            'users_id'        => max(0, (int) ($in['users_id'] ?? 0)),
            'start_date'      => $date($in['start_date'] ?? null),
            'end_date'        => $date($in['end_date'] ?? null),
            'criteria'        => mb_substr(trim((string) ($in['criteria'] ?? '')), 0, 4000),
            'is_archived'     => !empty($in['is_archived']) ? 1 : 0,
        ];
        if ($data['name'] === '') {
            return 0;
        }
        if ($id > 0) {
            $DB->update(self::plansTable(), $data, ['id' => $id]);
            return $id;
        }
        $DB->insert(self::plansTable(), $data + ['source_demands_id' => max(0, (int) ($in['source_demands_id'] ?? 0)), 'date_creation' => date('Y-m-d H:i:s')]);
        return (int) $DB->insertId();
    }

    // ---------------------------------------------------------------- marcos

    public static function milestones(int $projectId): array
    {
        return self::rows(self::milestonesTable(), ['mkt_projects_id' => $projectId], 'due_date ASC, id ASC');
    }

    public static function saveMilestone(int $projectId, string $name, string $due): int
    {
        global $DB;
        $name = mb_substr(trim($name), 0, 160);
        if ($projectId <= 0 || $name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            return 0;
        }
        $DB->insert(self::milestonesTable(), ['mkt_projects_id' => $projectId, 'name' => $name, 'due_date' => $due, 'is_done' => 0]);
        return (int) $DB->insertId();
    }

    // ---------------------------------------------------------------- progresso e vínculos

    /**
     * Progresso (0–100) de um conjunto de atividades: média do avanço de cada situação (Demand::PROGRESS),
     * ignorando as canceladas. Sem atividades: null.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function progress(array $rows): ?int
    {
        $values = [];
        foreach ($rows as $r) {
            if (($r['status'] ?? '') === Demand::STATUS_CANCELLED) {
                continue;
            }
            $values[] = Demand::PROGRESS[$r['status']] ?? 0;
        }
        return $values ? (int) round(array_sum($values) / count($values)) : null;
    }

    /**
     * Execução efetiva (0–100): média do percentual efetivo de cada atividade (Demand::effectivePercent), ignorando as canceladas.
     * Diferente do avanço por situação (progress), que só depende de em que etapa do fluxo cada atividade está.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function effective(array $rows): ?int
    {
        $values = [];
        foreach ($rows as $r) {
            $v = Demand::effectivePercent($r);
            if ($v !== null) {
                $values[] = $v;
            }
        }
        return $values ? (int) round(array_sum($values) / count($values)) : null;
    }

    /** Quantas atividades abertas já tiveram o percentual de execução informado: [informadas, abertas]. */
    public static function effectiveCoverage(array $rows): array
    {
        $open = $informed = 0;
        foreach ($rows as $r) {
            if (Demand::isOpen($r)) {
                $open++;
                $informed += ($r['exec_percent'] ?? null) !== null && $r['exec_percent'] !== '' ? 1 : 0;
            }
        }
        return [$informed, $open];
    }

    /** Percentual do cronograma já decorrido (início → fim) na data $today; null sem as duas datas. */
    public static function expected(?string $start, ?string $end, ?string $today = null): ?int
    {
        $s = $start ? strtotime($start) : false;
        $e = $end ? strtotime($end) : false;
        $t = strtotime($today ?? date('Y-m-d'));
        if (!$s || !$e || $e <= $s) {
            return null;
        }
        return (int) round(max(0, min(1, ($t - $s) / ($e - $s))) * 100);
    }

    /** Demandas/atividades ligadas a um filtro (pillars_id, mkt_projects_id, plans_id ou parent_id), em ordem de prazo. */
    public static function activities(array $where): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => Demand::getTable(), 'WHERE' => Demand::restrict(['is_deleted' => 0] + $where), 'ORDER' => ['confirmed_date ASC', 'id ASC']]) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    /**
     * Tira de uma lista de atividades as demandas-mãe que viraram plano (a demanda original do plano): o avanço do
     * plano e do projeto é medido pelas atividades, e a mãe só conclui depois delas.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function withoutSources(array $rows): array
    {
        global $DB;
        $sources = [];
        foreach ($DB->request(['SELECT' => ['source_demands_id'], 'FROM' => self::plansTable(), 'WHERE' => ['source_demands_id' => ['>', 0]]]) as $r) {
            $sources[(int) $r['source_demands_id']] = true;
        }
        return array_filter($rows, fn ($k) => !isset($sources[(int) $k]), ARRAY_FILTER_USE_KEY);
    }

    /** Confere se o pilar/projeto/plano existem; zera o que não existir. Plano e projeto herdam o pilar. */
    public static function normalizeLinks(int $pillar, int $project, int $plan): array
    {
        $plans = $plan > 0 ? (self::rows(self::plansTable(), ['id' => $plan])[$plan] ?? null) : null;
        if ($plans) {
            $project = $project > 0 ? $project : (int) $plans['mkt_projects_id'];
        } else {
            $plan = 0;
        }
        $proj = $project > 0 ? (self::rows(self::projectsTable(), ['id' => $project])[$project] ?? null) : null;
        if ($proj) {
            $pillar = $pillar > 0 ? $pillar : (int) $proj['pillars_id'];
        } else {
            $project = 0;
        }
        if ($pillar > 0 && !isset(self::pillars()[$pillar])) {
            $pillar = 0;
        }
        return [$pillar, $project, $plan];
    }
}

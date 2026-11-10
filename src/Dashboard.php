<?php

namespace GlpiPlugin\Marketing;

/**
 * Dados do painel executivo e do PDF executivo. Tudo é calculado sobre as demandas que o usuário pode ver
 * (entidades ativas) e que entraram no período filtrado; os mesmos números alimentam cartões, gavetas e PDF.
 */
class Dashboard
{
    /** Atalhos de período: chave => [rótulo, dias; 0 = sem limite inicial]. */
    public const RANGES = ['7' => ['7 dias', 7], '30' => ['30 dias', 30], '90' => ['90 dias', 90], '365' => ['12 meses', 365], 'all' => ['Tudo', 0]];

    /** Normaliza os filtros recebidos (GET) para valores seguros. */
    public static function filters(array $get): array
    {
        $date = fn ($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false) ? $v : '';
        $range = (string) ($get['range'] ?? '');
        $from = $date($get['from'] ?? '');
        $to = $date($get['to'] ?? '');
        if ($from === '' && $to === '') {
            $range = isset(self::RANGES[$range]) ? $range : '90';
            $days = self::RANGES[$range][1];
            $to = date('Y-m-d');
            $from = $days > 0 ? date('Y-m-d', strtotime("-{$days} days")) : '';
        } else {
            $range = '';
        }
        $status = (string) ($get['status'] ?? '');
        return [
            'from'     => $from,
            'to'       => $to,
            'range'    => $range,
            'types_id' => max(0, (int) ($get['type'] ?? 0)),
            'area'     => mb_substr(trim((string) ($get['area'] ?? '')), 0, 120),
            'assignee' => max(0, (int) ($get['assignee'] ?? 0)),
            'status'   => isset(Demand::STATUSES[$status]) ? $status : '',
        ];
    }

    /** Parâmetros da URL para repetir os filtros em links (PDF, paginação). */
    public static function query(array $f): string
    {
        $q = ['from' => $f['from'], 'to' => $f['to'], 'type' => $f['types_id'] ?: '', 'area' => $f['area'], 'assignee' => $f['assignee'] ?: '', 'status' => $f['status']];
        return http_build_query(array_filter($q, fn ($v) => $v !== '' && $v !== null));
    }

    /** @return array<int, array<string, mixed>> demandas visíveis e filtradas, id => linha */
    public static function load(array $f): array
    {
        global $DB;
        $where = ['is_deleted' => 0] + getEntitiesRestrictCriteria(Demand::getTable(), 'entities_id', '', true);
        if ($f['from'] !== '') {
            $where[] = ['date_creation' => ['>=', $f['from'] . ' 00:00:00']];
        }
        if ($f['to'] !== '') {
            $where[] = ['date_creation' => ['<=', $f['to'] . ' 23:59:59']];
        }
        if ($f['types_id'] > 0) {
            $where['types_id'] = $f['types_id'];
        }
        if ($f['area'] !== '') {
            $where['area'] = $f['area'];
        }
        if ($f['assignee'] > 0) {
            $where['users_id_assigned'] = $f['assignee'];
        }
        if ($f['status'] !== '') {
            $where['status'] = $f['status'];
        }
        $rows = [];
        foreach ($DB->request(['FROM' => Demand::getTable(), 'WHERE' => $where, 'ORDER' => 'id DESC']) as $r) {
            $rows[(int) $r['id']] = $r;
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    public static function build(array $f): array
    {
        $rows = self::load($f);
        $today = date('Y-m-d');
        $types = Type::all();
        $slos = Slo::all(true);
        $sloEval = Slo::evaluateMany($rows);

        $byStatus = array_fill_keys(array_keys(Demand::STATUSES), 0);
        $byType = $byArea = $byAssignee = $weekly = [];
        $ids = ['total' => [], 'open' => [], 'done' => [], 'overdue' => [], 'attention' => [], 'approval' => [], 'unassigned' => [], 'slo' => [], 'ontime' => [], 'late_done' => []];
        $days = [];
        foreach ($rows as $id => &$r) {
            $r['deadline'] = Demand::deadline($r);
            $r['overdue'] = Demand::isOverdue($r, $today);
            $r['open'] = Demand::isOpen($r);
            $r['days_to_complete'] = null;
            if ($r['status'] === Demand::STATUS_DONE && $r['completed_at']) {
                $r['days_to_complete'] = max(0.0, round((strtotime($r['completed_at']) - strtotime($r['date_creation'])) / 86400, 1));
                $days[] = $r['days_to_complete'];
            }
            $r['slo_bad'] = [];
            foreach ($sloEval[$id] ?? [] as $sid => $res) {
                if (in_array($res['state'], ['breached', 'overdue'], true)) {
                    $r['slo_bad'][$sid] = $res['state'];
                }
            }
            $byStatus[$r['status']]++;
            $tkey = (int) $r['types_id'];
            $byType[$tkey] = ($byType[$tkey] ?? 0) + 1;
            $akey = $r['area'] !== null && $r['area'] !== '' ? $r['area'] : '—';
            $byArea[$akey] = ($byArea[$akey] ?? 0) + 1;
            $ukey = (int) $r['users_id_assigned'];
            $byAssignee[$ukey]['total'] = ($byAssignee[$ukey]['total'] ?? 0) + 1;
            if ($r['open']) {
                $byAssignee[$ukey]['open'] = ($byAssignee[$ukey]['open'] ?? 0) + 1;
            }
            $week = date('o-\WW', strtotime($r['date_creation']));
            $weekly[$week] = ($weekly[$week] ?? 0) + 1;

            $ids['total'][] = $id;
            if ($r['open']) {
                $ids['open'][] = $id;
                if ((int) $r['users_id_assigned'] === 0) {
                    $ids['unassigned'][] = $id;
                }
            }
            if ($r['status'] === Demand::STATUS_DONE) {
                $ids['done'][] = $id;
                if ($r['deadline']) {
                    if (substr((string) $r['completed_at'], 0, 10) <= $r['deadline']) {
                        $ids['ontime'][] = $id;
                    } else {
                        $ids['late_done'][] = $id;
                    }
                }
            }
            if ($r['overdue']) {
                $ids['overdue'][] = $id;
            }
            if (in_array($r['status'], [Demand::STATUS_AT_RISK, Demand::STATUS_BLOCKED], true)) {
                $ids['attention'][] = $id;
            }
            if ($r['status'] === Demand::STATUS_IN_APPROVAL) {
                $ids['approval'][] = $id;
            }
            if ($r['slo_bad']) {
                $ids['slo'][] = $id;
            }
        }
        unset($r);
        ksort($weekly);

        $doneWithDeadline = count($ids['ontime']) + count($ids['late_done']);
        $summary = [];
        foreach ($sloEval as $did => $bySlo) {
            foreach ($bySlo as $sid => $res) {
                $summary[$sid][$res['state']] = ($summary[$sid][$res['state']] ?? 0) + 1;
            }
        }

        return [
            'filters'     => $f,
            'rows'        => $rows,
            'ids'         => $ids,
            'by_status'   => $byStatus,
            'by_type'     => $byType,
            'by_area'     => $byArea,
            'by_assignee' => $byAssignee,
            'weekly'      => $weekly,
            'types'       => $types,
            'slos'        => $slos,
            'slo_eval'    => $sloEval,
            'slo_summary' => $summary,
            'avg_days'    => $days ? round(array_sum($days) / count($days), 1) : null,
            'ontime_pct'  => $doneWithDeadline > 0 ? (int) round(count($ids['ontime']) / $doneWithDeadline * 100) : null,
            'generated'   => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Filtros do período imediatamente anterior, com a mesma duração (para o comparativo dos cartões).
     * Sem data inicial ("Tudo"), não há período anterior.
     *
     * @return array<string, mixed>|null
     */
    public static function previous(array $f): ?array
    {
        if ($f['from'] === '' || $f['to'] === '') {
            return null;
        }
        $days = (int) round((strtotime($f['to']) - strtotime($f['from'])) / 86400) + 1;
        $prevTo = date('Y-m-d', strtotime($f['from'] . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));
        return ['from' => $prevFrom, 'to' => $prevTo, 'range' => ''] + $f;
    }

    /**
     * Variação percentual entre dois valores. Devolve null sem base de comparação.
     * Com anterior zerado e atual positivo, devolve 100 (novo).
     */
    public static function delta(?float $current, ?float $previous): ?int
    {
        if ($current === null || $previous === null) {
            return null;
        }
        if ($previous == 0.0) {
            return $current == 0.0 ? 0 : 100;
        }
        return (int) round(($current - $previous) / abs($previous) * 100);
    }

    /** Ordem das etapas do funil: estado => posição (os estados paralelos contam como a mesma etapa). */
    private const STAGE_RANK = [
        'received' => 0, 'triage' => 1, 'waiting_requester' => 1, 'planned' => 2,
        'in_progress' => 3, 'waiting_third' => 3, 'in_approval' => 3, 'at_risk' => 3, 'blocked' => 3, 'done' => 4,
    ];

    /**
     * Fluxo das demandas: funil de conversão (quantas chegaram a cada etapa) e tempo médio, em dias, que as
     * demandas ficaram em cada situação (gargalos). Usa a trilha de eventos; demandas sem trilha contam só pela
     * situação atual.
     *
     * @param array<string, mixed> $d resultado de build()
     * @return array{funnel: array<int, array<string, mixed>>, stages: array<int, array<string, mixed>>, bottleneck: ?array<string, mixed>}
     */
    public static function flow(array $d): array
    {
        global $DB;
        $rows = $d['rows'];
        $events = [];
        if ($rows) {
            foreach ($DB->request(['FROM' => Demand::getEventsTable(), 'WHERE' => ['demands_id' => array_keys($rows), 'event' => 'status'], 'ORDER' => 'id ASC']) as $ev) {
                $events[(int) $ev['demands_id']][] = $ev;
            }
        }
        $now = time();
        $reached = array_fill(0, 5, 0);
        $reachedIds = array_fill(0, 5, []);
        $sum = $cnt = [];
        foreach ($rows as $id => $r) {
            $max = self::STAGE_RANK[$r['status']] ?? 0;
            $timeline = [[Demand::STATUS_RECEIVED, strtotime($r['sent_at'] ?: $r['date_creation'])]];
            foreach ($events[$id] ?? [] as $ev) {
                $max = max($max, self::STAGE_RANK[$ev['new_value']] ?? 0);
                $timeline[] = [$ev['new_value'], strtotime($ev['date_creation'])];
            }
            for ($k = 0; $k <= $max; $k++) {
                $reached[$k]++;
                $reachedIds[$k][] = $id;
            }
            foreach ($timeline as $i => [$status, $ts]) {
                if (in_array($status, [Demand::STATUS_DONE, Demand::STATUS_CANCELLED], true)) {
                    continue;
                }
                $next = $timeline[$i + 1][1] ?? ($r['open'] ? $now : null);
                if ($next === null || $next < $ts) {
                    continue;
                }
                $sum[$status] = ($sum[$status] ?? 0) + ($next - $ts) / 86400;
                $cnt[$status] = ($cnt[$status] ?? 0) + 1;
            }
        }
        $names = ['Recebidas', 'Triadas', 'Planejadas', 'Em execução', 'Concluídas'];
        $funnel = [];
        foreach ($reached as $k => $n) {
            $funnel[] = [
                'name'  => $names[$k],
                'value' => $n,
                'pct'   => $reached[0] > 0 ? (int) round($n / $reached[0] * 100) : 0,
                // quantos da etapa anterior avançaram para esta
                'step'  => $k > 0 && $reached[$k - 1] > 0 ? (int) round($n / $reached[$k - 1] * 100) : null,
                'ids'   => $reachedIds[$k],
            ];
        }
        $stages = [];
        foreach (Demand::STATUSES as $status => $label) {
            if (isset($cnt[$status])) {
                $stages[] = ['status' => $status, 'name' => $label, 'days' => round($sum[$status] / $cnt[$status], 1), 'n' => $cnt[$status]];
            }
        }
        $bottleneck = null;
        foreach ($stages as $s) {
            if ($bottleneck === null || $s['days'] > $bottleneck['days']) {
                $bottleneck = $s;
            }
        }
        return ['funnel' => $funnel, 'stages' => $stages, 'bottleneck' => $bottleneck];
    }

    /** Faixas de idade (em dias) das demandas em aberto: rótulo => [de, até, cor]. */
    public const AGING = [
        '0–7 dias'  => [0, 7, '#22c55e'],
        '8–14'      => [8, 14, '#84cc16'],
        '15–30'     => [15, 30, '#eab308'],
        '31–60'     => [31, 60, '#f97316'],
        '60+'       => [61, 100000, '#ef4444'],
    ];

    /**
     * Séries dos gráficos executivos: tendência semanal (entradas × concluídas, até 26 semanas),
     * envelhecimento das abertas, prioridade das abertas, demandas por responsável e taxas dos medidores.
     *
     * @param array<string, mixed> $d resultado de build()
     * @return array<string, mixed>
     */
    public static function charts(array $d): array
    {
        $rows = $d['rows'];
        $now = time();
        $monday = fn (string $date) => date('Y-m-d', strtotime('monday this week', strtotime(substr($date, 0, 10))));

        // ---- tendência semanal, sem lacunas
        $entries = $done = [];
        foreach ($rows as $r) {
            $w = $monday($r['date_creation']);
            $entries[$w] = ($entries[$w] ?? 0) + 1;
            if ($r['status'] === Demand::STATUS_DONE && $r['completed_at']) {
                $c = $monday($r['completed_at']);
                $done[$c] = ($done[$c] ?? 0) + 1;
            }
        }
        $weeks = [];
        $all = array_merge(array_keys($entries), array_keys($done));
        if ($all) {
            $first = min($all);
            $last = max(max($all), $monday(date('Y-m-d')));
            // no máximo as 26 últimas semanas
            $firstTs = max(strtotime($first), strtotime('-25 weeks', strtotime($last)));
            for ($ts = $firstTs; $ts <= strtotime($last); $ts = strtotime('+1 week', $ts)) {
                $k = date('Y-m-d', $ts);
                $weeks[] = ['start' => $k, 'label' => date('d/m', $ts), 'entries' => $entries[$k] ?? 0, 'done' => $done[$k] ?? 0];
            }
        }

        // ---- envelhecimento e prioridade das abertas
        $aging = [];
        foreach (self::AGING as $label => [$from, $to, $color]) {
            $aging[$label] = ['color' => $color, 'ids' => []];
        }
        $priority = [1 => ['#ef4444', []], 2 => ['#f97316', []], 3 => ['#eab308', []], 4 => ['#3b82f6', []], 5 => ['#94a3b8', []]];
        $assignees = [];
        foreach ($rows as $id => $r) {
            $uid = (int) $r['users_id_assigned'];
            $assignees[$uid]['all'][] = $id;
            if ($r['status'] === Demand::STATUS_DONE) {
                $assignees[$uid]['done'][] = $id;
            }
            if (!$r['open']) {
                continue;
            }
            $age = (int) floor(($now - strtotime($r['date_creation'])) / 86400);
            foreach (self::AGING as $label => [$from, $to]) {
                if ($age >= $from && $age <= $to) {
                    $aging[$label]['ids'][] = $id;
                    break;
                }
            }
            $priority[max(1, min(5, (int) $r['priority']))][1][] = $id;
            if ($r['overdue']) {
                $assignees[$uid]['overdue'][] = $id;
            } else {
                $assignees[$uid]['open'][] = $id;
            }
        }

        // ---- medidores
        $totalNoCancel = count($rows) - $d['by_status'][Demand::STATUS_CANCELLED];
        $sloOk = $sloAll = 0;
        foreach ($d['slo_summary'] as $s) {
            $sloOk += (int) ($s['met'] ?? 0);
            $sloAll += (int) ($s['met'] ?? 0) + (int) ($s['breached'] ?? 0) + (int) ($s['overdue'] ?? 0);
        }

        return [
            'weeks'          => $weeks,
            'aging'          => $aging,
            'priority'       => $priority,
            'assignees'      => $assignees,
            'completion_pct' => $totalNoCancel > 0 ? (int) round(count($d['ids']['done']) / $totalNoCancel * 100) : null,
            'slo_pct'        => $sloAll > 0 ? (int) round($sloOk / $sloAll * 100) : null,
        ];
    }

    /**
     * Definição dos cartões: chave => [título, ícone, cor, valor, subtítulo, ids das demandas, explicação].
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cards(array $d): array
    {
        $n = fn (string $k) => count($d['ids'][$k]);
        $slosOn = count($d['slos']);
        return [
            'total'      => ['title' => 'Demandas no período', 'icon' => 'ti-inbox', 'color' => '#6366f1', 'value' => $n('total'), 'sub' => 'entraram no filtro', 'ids' => $d['ids']['total'],
                'lead' => 'Todas as demandas que entraram no período e nos filtros escolhidos.'],
            'open'       => ['title' => 'Em aberto', 'icon' => 'ti-progress', 'color' => '#3b82f6', 'value' => $n('open'), 'sub' => 'não concluídas nem canceladas', 'ids' => $d['ids']['open'],
                'lead' => 'Demandas que ainda não foram concluídas nem canceladas.'],
            'done'       => ['title' => 'Concluídas', 'icon' => 'ti-circle-check', 'color' => '#22c55e', 'value' => $n('done'), 'sub' => 'com evidência e aceite', 'ids' => $d['ids']['done'],
                'lead' => 'Demandas concluídas. Conclusão exige evidência e aceite do solicitante, ou exceção justificada.'],
            'overdue'    => ['title' => 'Atrasadas', 'icon' => 'ti-alarm', 'color' => '#ef4444', 'value' => $n('overdue'), 'sub' => 'prazo vencido', 'ids' => $d['ids']['overdue'],
                'lead' => 'Em aberto com prazo (confirmado, ou desejado enquanto não houver confirmação) anterior a hoje.'],
            'attention'  => ['title' => 'Em risco ou bloqueadas', 'icon' => 'ti-alert-triangle', 'color' => '#f97316', 'value' => $n('attention'), 'sub' => 'precisam de decisão', 'ids' => $d['ids']['attention'],
                'lead' => 'Demandas marcadas como "Em risco" ou "Bloqueada".'],
            'approval'   => ['title' => 'Aguardando aprovação', 'icon' => 'ti-signature', 'color' => '#ec4899', 'value' => $n('approval'), 'sub' => 'na fila dos aprovadores', 'ids' => $d['ids']['approval'],
                'lead' => 'Demandas na situação "Em aprovação".'],
            'unassigned' => ['title' => 'Sem responsável', 'icon' => 'ti-user-question', 'color' => '#a855f7', 'value' => $n('unassigned'), 'sub' => 'em aberto sem atribuição', 'ids' => $d['ids']['unassigned'],
                'lead' => 'Demandas em aberto que ainda não têm um responsável.'],
            'slo'        => ['title' => 'Fora do SLO', 'icon' => 'ti-hourglass-high', 'color' => '#eab308', 'value' => $slosOn ? $n('slo') : '—', 'sub' => $slosOn ? $slosOn . ' SLO(s) ativo(s)' : 'nenhum SLO ativo', 'ids' => $d['ids']['slo'],
                'lead' => $slosOn ? 'Demandas que estouraram o prazo de algum SLO ativo (cumpridas fora do prazo ou ainda em andamento e vencidas).' : 'Nenhum SLO está ativo. Ative em Configurações → SLOs depois da homologação.'],
            'avg'        => ['title' => 'Tempo médio de conclusão', 'icon' => 'ti-clock-hour-4', 'color' => '#0ea5e9', 'value' => $d['avg_days'] === null ? '—' : str_replace('.', ',', (string) $d['avg_days']) . ' d', 'sub' => 'da entrada à conclusão', 'ids' => $d['ids']['done'],
                'lead' => 'Média, em dias corridos, entre a entrada da demanda e a conclusão. Lista das concluídas.'],
            'ontime'     => ['title' => 'Entregas no prazo', 'icon' => 'ti-target-arrow', 'color' => '#14b8a6', 'value' => $d['ontime_pct'] === null ? '—' : $d['ontime_pct'] . '%', 'sub' => count($d['ids']['ontime']) . ' de ' . (count($d['ids']['ontime']) + count($d['ids']['late_done'])) . ' concluídas com prazo', 'ids' => array_merge($d['ids']['ontime'], $d['ids']['late_done']),
                'lead' => 'Concluídas até o prazo, entre as concluídas que tinham prazo.'],
        ];
    }

    /**
     * Detalhes completos de cada demanda (evidências, aprovações, atualizações e trilha) para o PDF.
     *
     * @param int[] $ids
     * @return array<int, array<string, array<int, array<string, mixed>>>>
     */
    public static function details(array $ids): array
    {
        global $DB;
        $out = [];
        if (!$ids) {
            return $out;
        }
        $sources = ['evidences' => Demand::getEvidencesTable(), 'approvals' => Demand::getApprovalsTable(), 'notes' => Note::getTable(), 'events' => Demand::getEventsTable()];
        foreach ($sources as $key => $table) {
            foreach ($DB->request(['FROM' => $table, 'WHERE' => ['demands_id' => $ids], 'ORDER' => 'id ASC']) as $r) {
                $out[(int) $r['demands_id']][$key][] = $r;
            }
        }
        return $out;
    }
}

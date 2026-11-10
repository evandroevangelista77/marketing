<?php

namespace GlpiPlugin\Marketing;

use Calendar;

/**
 * SLOs (objetivos de nível de serviço) do fluxo de demandas (RF-015, RF-017). Cada SLO mede o tempo entre
 * a entrada da demanda num estado ("de") e a chegada a um dos estados finais ("até"), em dias úteis,
 * pelo calendário escolhido (feriados e pausas do GLPI). Nascem desligados até a homologação (RF-017).
 */
class Slo
{
    /** Grupos de medição do debriefing (§3.5). Cada SLO é um indicador próprio: não se somam entre si. */
    public const GROUPS = ['triage' => 'Entrada e triagem', 'execution' => 'Gestão e execução', 'fronts' => 'Frentes específicas'];
    public const HOM_STATUSES = ['draft' => 'Em rascunho', 'review' => 'Em avaliação', 'approved' => 'Homologado'];
    public const APPLIES = ['external' => 'Solicitações de outras áreas', 'internal' => 'Atividades do Marketing', 'all' => 'Todas'];

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_marketing_slos';
    }

    /** @return array<int, array<string, mixed>> id => linha */
    public static function all(bool $activeOnly = false): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $activeOnly ? ['is_active' => 1] : [], 'ORDER' => 'id ASC']) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    public static function save(int $id, array $in): int
    {
        global $DB;
        $to = array_values(array_intersect(array_filter(array_map('trim', explode(',', (string) ($in['to_statuses'] ?? '')))), array_keys(Demand::STATUSES)));
        $from = (string) ($in['from_status'] ?? '');
        $date = fn ($v) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
        $hom = (string) ($in['hom_status'] ?? 'draft');
        $hom = isset(self::HOM_STATUSES[$hom]) ? $hom : 'draft';
        $evidence = mb_substr(trim((string) ($in['hom_evidence'] ?? '')), 0, 4000);
        $pause = array_values(array_intersect(array_map('strval', (array) ($in['pause_list'] ?? [])), array_keys(Demand::STATUSES)));
        $group = (string) ($in['grp'] ?? '');
        $applies = (string) ($in['applies_to'] ?? '');
        $previous = $id > 0 ? (self::all()[$id] ?? null) : null;
        // RF-017: o SLO só pode ficar ativo depois de homologado e com a evidência do aceite
        $homologated = $hom === 'approved' && $evidence !== '';
        $data = [
            'grp'                => isset(self::GROUPS[$group]) ? $group : 'triage',
            'process'            => mb_substr(trim((string) ($in['process'] ?? '')), 0, 160),
            'pause_statuses'     => implode(',', $pause),
            'applies_to'         => isset(self::APPLIES[$applies]) ? $applies : 'external',
            'hom_status'         => ($hom === 'approved' && !$homologated) ? 'review' : $hom,
            'hom_evidence'       => $evidence,
            'hom_users_id'       => $homologated ? ((int) ($previous['hom_users_id'] ?? 0) ?: (int) \Session::getLoginUserID()) : 0,
            'hom_date'           => $homologated ? ($previous['hom_date'] ?? date('Y-m-d H:i:s')) : null,
            'valid_from'         => $date($in['valid_from'] ?? null),
            'valid_to'           => $date($in['valid_to'] ?? null),
            'name'               => mb_substr(trim((string) ($in['name'] ?? '')), 0, 160),
            'from_status'        => isset(Demand::STATUSES[$from]) ? $from : Demand::STATUS_RECEIVED,
            'to_statuses'        => implode(',', $to),
            'limit_days'         => max(1, min(365, (int) ($in['limit_days'] ?? 1))),
            'calendars_id'       => max(0, (int) ($in['calendars_id'] ?? 0)),
            'users_id_escalate'  => max(0, (int) ($in['users_id_escalate'] ?? 0)),
            'is_active'          => (!empty($in['is_active']) && $homologated) ? 1 : 0,
        ];
        if ($data['name'] === '' || !$data['to_statuses']) {
            return 0;
        }
        if ($id > 0) {
            $DB->update(self::getTable(), $data, ['id' => $id]);
            return $id;
        }
        $DB->insert(self::getTable(), $data + ['date_creation' => date('Y-m-d H:i:s')]);
        return (int) $DB->insertId();
    }

    /** O SLO está homologado (situação "Homologado" com evidência do aceite)? */
    public static function isHomologated(int $id): bool
    {
        $row = self::all()[$id] ?? null;
        return $row && $row['hom_status'] === 'approved' && trim((string) $row['hom_evidence']) !== '';
    }

    /**
     * Soma dias úteis a um instante (Y-m-d H:i:s). Com calendário do GLPI usa os dias de trabalho e feriados
     * dele; sem calendário, pula sábado e domingo. O horário do dia é mantido.
     */
    public static function addBusinessDays(string $start, int $days, int $calendarId = 0): string
    {
        $cal = null;
        if ($calendarId > 0) {
            $c = new Calendar();
            if ($c->getFromDB($calendarId) && $c->hasAWorkingDay()) {
                $cal = $c;
            }
        }
        $ts = strtotime($start);
        $left = max(0, $days);
        while ($left > 0) {
            $ts = strtotime('+1 day', $ts);
            $working = $cal ? $cal->isAWorkingDay($ts) : ((int) date('N', $ts) < 6);
            if ($working) {
                $left--;
            }
        }
        return date('Y-m-d H:i:s', $ts);
    }

    /**
     * Situação de um SLO numa demanda. Estados: met (cumprido), breached (cumprido fora do prazo),
     * overdue (em andamento e vencido), running (em andamento no prazo), na (não se aplica).
     *
     * @param array<string, mixed> $slo
     * @param array<string, mixed> $demand
     * @param array<int, array<string, mixed>> $events eventos da demanda em ordem cronológica
     * @return array{state: string, start: ?string, due: ?string, end: ?string}
     */
    public static function evaluate(array $slo, array $demand, array $events, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $na = ['state' => 'na', 'start' => null, 'due' => null, 'end' => null];
        if (($demand['status'] ?? '') === Demand::STATUS_CANCELLED) {
            return $na;
        }
        $applies = (string) ($slo['applies_to'] ?? 'external');
        $origin = (string) ($demand['origin'] ?? 'external');
        if ($applies !== 'all' && $applies !== $origin) {
            return $na;
        }
        $from = (string) $slo['from_status'];
        $targets = array_filter(explode(',', (string) $slo['to_statuses']));

        $start = null;
        if ($from === Demand::STATUS_RECEIVED) {
            $start = $demand['sent_at'] ?: $demand['date_creation'] ?? null;
        }
        foreach ($events as $ev) {
            if (($ev['event'] ?? '') === 'status' && ($ev['new_value'] ?? '') === $from) {
                $start = $ev['date_creation'];
            }
        }
        if (!$start) {
            return $na;
        }
        $end = null;
        foreach ($events as $ev) {
            if (($ev['event'] ?? '') === 'status' && in_array($ev['new_value'], $targets, true) && $ev['date_creation'] >= $start) {
                $end = $ev['date_creation'];
                break;
            }
        }
        // vigência: só mede quem começou a contagem dentro do período de vigência do SLO
        if ((!empty($slo['valid_from']) && substr((string) $start, 0, 10) < $slo['valid_from']) || (!empty($slo['valid_to']) && substr((string) $start, 0, 10) > $slo['valid_to'])) {
            return $na;
        }
        // situações que pausam a contagem: o tempo parado nelas empurra o vencimento
        $pause = array_filter(explode(',', (string) ($slo['pause_statuses'] ?? '')));
        $paused = 0;
        if ($pause) {
            $current = $from;
            $since = null;
            $limit = strtotime($end ?? $now);
            foreach ($events as $ev) {
                if (($ev['event'] ?? '') !== 'status' || $ev['date_creation'] < $start) {
                    continue;
                }
                $t = strtotime($ev['date_creation']);
                if ($since !== null && !in_array($ev['new_value'], $pause, true)) {
                    $paused += max(0, min($t, $limit) - $since);
                    $since = null;
                }
                if (in_array($ev['new_value'], $pause, true) && $since === null) {
                    $since = $t;
                }
            }
            if ($since !== null) {
                $paused += max(0, $limit - $since);
            }
        }
        // o prazo vale até o fim do dia útil de vencimento ("até 1 dia útil" = até o fim do próximo dia útil)
        $base = $paused > 0 ? date('Y-m-d H:i:s', strtotime((string) $start) + $paused) : (string) $start;
        $due = substr(self::addBusinessDays($base, (int) $slo['limit_days'], (int) $slo['calendars_id']), 0, 10) . ' 23:59:59';
        if ($end) {
            return ['state' => $end <= $due ? 'met' : 'breached', 'start' => $start, 'due' => $due, 'end' => $end];
        }
        // ainda no estado "de"? se a demanda já saiu dele sem passar por um estado final, o relógio não corre
        if ($from !== Demand::STATUS_RECEIVED && ($demand['status'] ?? '') !== $from) {
            return ['state' => 'na', 'start' => $start, 'due' => $due, 'end' => null];
        }
        return ['state' => $now > $due ? 'overdue' : 'running', 'start' => $start, 'due' => $due, 'end' => null];
    }

    /**
     * Avalia todos os SLOs ativos para um conjunto de demandas, com uma só consulta de eventos.
     *
     * @param array<int, array<string, mixed>> $demands id => linha
     * @return array<int, array<int, array{state: string, start: ?string, due: ?string, end: ?string}>> demanda => slo => resultado
     */
    public static function evaluateMany(array $demands, ?string $now = null): array
    {
        global $DB;
        $slos = self::all(true);
        if (!$slos || !$demands) {
            return [];
        }
        $events = [];
        foreach ($DB->request(['FROM' => Demand::getEventsTable(), 'WHERE' => ['demands_id' => array_keys($demands), 'event' => 'status'], 'ORDER' => 'id ASC']) as $ev) {
            $events[(int) $ev['demands_id']][] = $ev;
        }
        $out = [];
        foreach ($demands as $id => $d) {
            foreach ($slos as $sid => $slo) {
                $out[(int) $id][$sid] = self::evaluate($slo, $d, $events[(int) $id] ?? [], $now);
            }
        }
        return $out;
    }

    /** Rotina agendada: avisa (e escalona) as demandas com SLO vencido. Devolve quantos avisos novos saíram. */
    public static function checkBreaches(): int
    {
        global $DB;
        $slos = self::all(true);
        if (!$slos) {
            return 0;
        }
        $open = [];
        foreach ($DB->request(['FROM' => Demand::getTable(), 'WHERE' => ['is_deleted' => 0, 'NOT' => ['status' => [Demand::STATUS_DONE, Demand::STATUS_CANCELLED]]]]) as $r) {
            $open[(int) $r['id']] = $r;
        }
        $count = 0;
        foreach (self::evaluateMany($open) as $did => $bySlo) {
            foreach ($bySlo as $sid => $res) {
                if ($res['state'] !== 'overdue') {
                    continue;
                }
                $d = $open[$did];
                $users = [(int) $d['users_id_assigned'], (int) $slos[$sid]['users_id_escalate']];
                $count += Notifier::send(
                    'slo_breach',
                    $d,
                    $users,
                    'SLO vencido: ' . $slos[$sid]['name'],
                    $d['code'] . ' · ' . $d['name'] . ' — prazo era ' . $res['due'],
                    'slo:' . $sid . ':' . $did,
                    true
                );
                if (countElementsInTable(Alert::getTable(), ['event' => 'slo_breach', 'demands_id' => $did]) === 1) {
                    Automation::fire('slo_breach', $d, ['value' => (string) $sid]);
                }
            }
        }
        return $count;
    }
}

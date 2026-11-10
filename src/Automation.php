<?php

namespace GlpiPlugin\Marketing;

/**
 * Automações no estilo monday ("quando isto acontecer, faça aquilo"). Cada receita tem um gatilho, um valor
 * opcional do gatilho (por exemplo o estado de destino) e uma ação. Nascem desligadas; o administrador liga
 * nas configurações. Uma automação não dispara outra além de duas camadas, para evitar laços.
 */
class Automation
{
    public const TRIGGERS = [
        'created'            => 'Demanda criada',
        'assigned'           => 'Demanda atribuída',
        'status_changed'     => 'Situação alterada',
        'approval_requested' => 'Aprovação solicitada',
        'slo_breach'         => 'SLO vencido',
    ];

    public const ACTIONS = [
        'notify'       => 'Avisar com pop-up',
        'assign'       => 'Atribuir a um usuário',
        'set_priority' => 'Definir prioridade',
        'add_note'     => 'Registrar atualização',
    ];

    public const TARGETS = [
        'requester' => 'Solicitante',
        'assignee'  => 'Responsável',
        'approver'  => 'Aprovador',
    ];

    private static int $depth = 0;

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_marketing_automations';
    }

    /** @return array<int, array<string, mixed>> */
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
        $trigger = (string) ($in['trigger_event'] ?? '');
        $action = (string) ($in['action'] ?? '');
        if (!isset(self::TRIGGERS[$trigger]) || !isset(self::ACTIONS[$action]) || trim((string) ($in['name'] ?? '')) === '') {
            return 0;
        }
        $data = [
            'name'          => mb_substr(trim((string) $in['name']), 0, 160),
            'trigger_event' => $trigger,
            'trigger_value' => mb_substr(trim((string) ($in['trigger_value'] ?? '')), 0, 60),
            'action'        => $action,
            'action_target' => mb_substr(trim((string) ($in['action_target'] ?? '')), 0, 60),
            'action_value'  => mb_substr(trim((string) ($in['action_value'] ?? '')), 0, 500),
            'is_active'     => !empty($in['is_active']) ? 1 : 0,
        ];
        if ($id > 0) {
            $DB->update(self::getTable(), $data, ['id' => $id]);
            return $id;
        }
        $DB->insert(self::getTable(), $data + ['run_count' => 0, 'date_creation' => date('Y-m-d H:i:s')]);
        return (int) $DB->insertId();
    }

    /**
     * Executa as automações ativas deste gatilho. $ctx['value'] é comparado com o valor do gatilho
     * (para "Situação alterada", o novo estado). Devolve quantas rodaram.
     *
     * @param array<string, mixed> $demand
     * @param array<string, mixed> $ctx
     */
    public static function fire(string $trigger, array $demand, array $ctx = []): int
    {
        global $DB;
        if (self::$depth >= 2) {
            return 0;
        }
        $ran = 0;
        self::$depth++;
        try {
            foreach (self::all(true) as $a) {
                if ($a['trigger_event'] !== $trigger) {
                    continue;
                }
                if ($a['trigger_value'] !== '' && $a['trigger_value'] !== (string) ($ctx['value'] ?? '')) {
                    continue;
                }
                self::run($a, $demand);
                $DB->update(self::getTable(), ['run_count' => (int) $a['run_count'] + 1], ['id' => (int) $a['id']]);
                $ran++;
            }
        } finally {
            self::$depth--;
        }
        return $ran;
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $demand */
    private static function run(array $a, array $demand): void
    {
        global $DB;
        $d = new Demand();
        // sempre com a linha atual: automações anteriores podem ter mudado a demanda
        if (!$d->getFromDB((int) $demand['id'])) {
            return;
        }
        $demand = $d->fields;
        $text = trim((string) $a['action_value']) !== '' ? (string) $a['action_value'] : $a['name'];
        switch ($a['action']) {
            case 'notify':
                Notifier::send('automation', $demand, Notifier::resolveTarget((string) $a['action_target'], $demand), $a['name'], $demand['code'] . ' · ' . $demand['name'] . ' — ' . $text, '', true);
                break;
            case 'assign':
                $d->assign((int) $a['action_value']);
                break;
            case 'set_priority':
                $DB->update(Demand::getTable(), ['priority' => max(1, min(5, (int) $a['action_value']))], ['id' => (int) $demand['id']]);
                break;
            case 'add_note':
                Note::add((int) $demand['id'], '⚙ ' . $a['name'] . ': ' . $text, 'system');
                break;
        }
    }
}

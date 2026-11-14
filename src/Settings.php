<?php

namespace GlpiPlugin\Marketing;

use Config;

/**
 * Configurações do plugin (Config do GLPI, contexto "plugin:marketing"): campos obrigatórios do briefing,
 * cores dos status, pop-up e som, canais por evento de notificação e responsável padrão da triagem.
 */
class Settings
{
    public const CONTEXT = 'plugin:marketing';

    /** Campos do briefing que podem ser marcados como obrigatórios para planejar (RF-004). */
    public const BRIEFING_FIELDS = [
        'objective'    => 'Objetivo',
        'audience'     => 'Público',
        'deliverable'  => 'Entrega',
        'desired_date' => 'Prazo desejado',
        'context'      => 'Contexto',
        'area'         => 'Área solicitante',
    ];

    /** Eventos que podem avisar alguém: chave => [título, quem recebe]. */
    public const EVENTS = [
        'assigned'           => ['Demanda atribuída', 'Quem recebe a atribuição'],
        'approval_requested' => ['Aprovação solicitada', 'Aprovador indicado'],
        'approval_decided'   => ['Aprovação decidida', 'Responsável pela demanda'],
        'status_changed'     => ['Situação alterada', 'Solicitante'],
        'complement'         => ['Complemento de briefing solicitado', 'Solicitante'],
        'note'               => ['Nova atualização na demanda', 'Responsável e solicitante'],
        'slo_breach'         => ['SLO vencido', 'Responsável e escalonamento'],
        'rescheduled'        => ['Prazo reprogramado', 'Gerência (usuários definidos em Configurações) e responsável'],
        'book'               => ['Book executivo gerado', 'Destinatários do book (gerente de Marketing)'],
    ];

    public const STATUS_COLORS = [
        'received'          => '#94a3b8',
        'triage'            => '#6366f1',
        'waiting_requester' => '#f59e0b',
        'planned'           => '#0ea5e9',
        'in_progress'       => '#3b82f6',
        'waiting_third'     => '#a855f7',
        'in_approval'       => '#ec4899',
        'at_risk'           => '#f97316',
        'blocked'           => '#ef4444',
        'done'              => '#22c55e',
        'cancelled'         => '#64748b',
    ];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function defaults(): array
    {
        $d = [
            'popup_enabled'      => '1',
            'sound_enabled'      => '1',
            'poll_seconds'       => '20',
            'required_fields'    => json_encode(['objective', 'audience', 'deliverable', 'desired_date']),
            'default_triage_user' => '0',
            'status_colors'      => json_encode(self::STATUS_COLORS),
        ];
        foreach (array_keys(self::EVENTS) as $ev) {
            $d['ev_' . $ev . '_popup'] = in_array($ev, ['assigned', 'approval_requested', 'approval_decided', 'complement', 'slo_breach', 'note', 'rescheduled', 'book'], true) ? '1' : '0';
            $d['ev_' . $ev . '_mail'] = '0';
        }
        return $d;
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            $stored = Config::getConfigurationValues(self::CONTEXT);
            self::$cache = array_merge(self::defaults(), array_map('strval', $stored));
        }
        return self::$cache;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::all()[$key] ?? $default;
    }

    public static function bool(string $key): bool
    {
        return self::get($key, '0') === '1';
    }

    public static function save(array $values): void
    {
        Config::setConfigurationValues(self::CONTEXT, array_map('strval', $values));
        self::$cache = null;
    }

    // ---------------------------------------------------------------- preferências por usuário

    public const USER_CONTEXT = 'plugin:marketing:users';

    /** @var array<string, string>|null */
    private static ?array $userCache = null;

    private static function userValues(): array
    {
        if (self::$userCache === null) {
            self::$userCache = array_map('strval', Config::getConfigurationValues(self::USER_CONTEXT));
        }
        return self::$userCache;
    }

    public static function userPref(int $userId, string $key, string $default = ''): string
    {
        return self::userValues()[$key . '_' . $userId] ?? $default;
    }

    public static function setUserPref(int $userId, string $key, string $value): void
    {
        Config::setConfigurationValues(self::USER_CONTEXT, [$key . '_' . $userId => $value]);
        self::$userCache = null;
    }

    /** @return int[] usuários da Gerência, avisados a cada reprogramação de prazo */
    public static function managers(): array
    {
        return array_values(array_unique(array_filter(array_map('intval', explode(',', self::get('manager_users', ''))))));
    }

    public static function pollSeconds(): int
    {
        return max(5, min(300, (int) self::get('poll_seconds', '20')));
    }

    /** @return string[] chaves de BRIEFING_FIELDS exigidas para planejar */
    public static function requiredFields(): array
    {
        $list = json_decode(self::get('required_fields', '[]'), true);
        $list = is_array($list) ? array_values(array_intersect($list, array_keys(self::BRIEFING_FIELDS))) : [];
        return $list ?: ['objective', 'audience', 'deliverable', 'desired_date'];
    }

    /** @return array<string, string> status => cor (#rrggbb) */
    public static function statusColors(): array
    {
        $custom = json_decode(self::get('status_colors', '{}'), true);
        $out = self::STATUS_COLORS;
        if (is_array($custom)) {
            foreach ($custom as $k => $v) {
                if (isset($out[$k]) && is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                    $out[$k] = $v;
                }
            }
        }
        return $out;
    }

    public static function statusColor(string $status): string
    {
        return self::statusColors()[$status] ?? '#94a3b8';
    }

    /** O evento está ligado para o canal ('popup' ou 'mail')? Pop-up exige o interruptor geral. */
    public static function eventOn(string $event, string $channel): bool
    {
        if ($channel === 'popup' && !self::bool('popup_enabled')) {
            return false;
        }
        return self::bool('ev_' . $event . '_' . $channel);
    }
}

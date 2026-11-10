<?php

namespace GlpiPlugin\Marketing;

use Session;

/**
 * Distribui um aviso pelos canais ligados nas configurações: pop-up (Alert) e e-mail (fila Outbox).
 * Quem executa a ação nunca recebe aviso dela, a menos que $includeActor seja verdadeiro.
 */
class Notifier
{
    /**
     * @param int[] $userIds
     * @return int quantidade de destinatários avisados por algum canal
     */
    public static function send(string $event, array $demand, array $userIds, string $title, string $message, string $dedup = '', bool $includeActor = false): int
    {
        $actor = (int) Session::getLoginUserID();
        $sent = 0;
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $uid) {
            if ($uid === $actor && !$includeActor) {
                continue;
            }
            $did = Alert::push($uid, $event, $demand, $title, $message, $dedup);
            $mailed = ($event !== 'automation' && Settings::eventOn($event, 'mail'))
                ? Outbox::push($uid, $event, $demand, $title, $message, $dedup)
                : false;
            if ($did || $mailed) {
                $sent++;
            }
        }
        return $sent;
    }

    /** Resolve o destino de uma automação ('requester', 'assignee', 'approver' ou 'user:ID'). */
    public static function resolveTarget(string $target, array $demand): array
    {
        if (str_starts_with($target, 'user:')) {
            return [(int) substr($target, 5)];
        }
        return match ($target) {
            'requester' => [(int) ($demand['requester_users_id'] ?? 0)],
            'assignee'  => [(int) ($demand['users_id_assigned'] ?? 0)],
            'approver'  => [(int) ($demand['users_id_approver'] ?? 0)],
            default     => [],
        };
    }
}

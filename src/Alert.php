<?php

namespace GlpiPlugin\Marketing;

/**
 * Fila de pop-ups (canto da tela, com som). O navegador consulta front/alerts.php a cada poucos segundos;
 * cada aviso é entregue uma vez (delivered_at) e some da fila. dedup_key evita avisos repetidos do mesmo fato.
 */
class Alert
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_marketing_alerts';
    }

    /** Enfileira um pop-up para o usuário. Devolve false se o evento está desligado ou já foi avisado. */
    public static function push(int $userId, string $event, array $demand, string $title, string $message, string $dedup = ''): bool
    {
        global $DB;
        if ($userId <= 0) {
            return false;
        }
        if ($event !== 'automation' && !Settings::eventOn($event, 'popup')) {
            return false;
        }
        if ($event === 'automation' && !Settings::bool('popup_enabled')) {
            return false;
        }
        $key = $dedup !== '' ? mb_substr($event . ':' . $userId . ':' . $dedup, 0, 120) : null;
        if ($key !== null && countElementsInTable(self::getTable(), ['dedup_key' => $key])) {
            return false;
        }
        $DB->insert(self::getTable(), [
            'users_id'      => $userId,
            'demands_id'    => (int) ($demand['id'] ?? 0),
            'event'         => $event,
            'title'         => mb_substr($title, 0, 255),
            'message'       => mb_substr($message, 0, 500),
            'dedup_key'     => $key,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    /**
     * Avisos ainda não entregues ao usuário; marca como entregues. Cada item traz o endereço da demanda.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function takePending(int $userId, int $limit = 5): array
    {
        global $DB;
        $rows = iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $userId, 'delivered_at' => null],
            'ORDER' => 'id ASC',
            'LIMIT' => $limit,
        ]), false);
        $out = [];
        foreach ($rows as $r) {
            $DB->update(self::getTable(), ['delivered_at' => date('Y-m-d H:i:s')], ['id' => (int) $r['id']]);
            $out[] = [
                'id'      => (int) $r['id'],
                'event'   => $r['event'],
                'title'   => $r['title'],
                'message' => $r['message'],
                'url'     => (int) $r['demands_id'] > 0 ? Ui::url('/front/demand.php?id=' . (int) $r['demands_id']) : '',
                'at'      => $r['date_creation'],
            ];
        }
        return $out;
    }

    /** Descarta avisos antigos já entregues (rotina diária). */
    public static function purge(int $days = 30): int
    {
        global $DB;
        $limit = date('Y-m-d H:i:s', time() - $days * 86400);
        $DB->delete(self::getTable(), ['delivered_at' => ['<', $limit]]);
        return $DB->affectedRows();
    }
}

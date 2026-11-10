<?php

namespace GlpiPlugin\Marketing;

use Session;

/**
 * "Atualizações" da demanda (como o feed de updates do monday): comentários dos participantes e
 * registros automáticos do sistema (kind = system).
 */
class Note
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_marketing_notes';
    }

    public static function add(int $demandId, string $body, string $kind = 'note'): int
    {
        global $DB;
        $DB->insert(self::getTable(), [
            'demands_id'    => $demandId,
            'users_id'      => $kind === 'system' ? 0 : (int) Session::getLoginUserID(),
            'kind'          => $kind === 'system' ? 'system' : 'note',
            'body'          => $body,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        return (int) $DB->insertId();
    }

    /** @return array<int, array<string, mixed>> mais recentes primeiro */
    public static function forDemand(int $demandId, int $limit = 200): array
    {
        global $DB;
        return iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['demands_id' => $demandId], 'ORDER' => 'id DESC', 'LIMIT' => $limit]), false);
    }
}

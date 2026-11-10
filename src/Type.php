<?php

namespace GlpiPlugin\Marketing;

/**
 * Tipos de demanda (RF-002): campanha, evento, GTM etc. Cada tipo tem cor e, se quiser, a sua própria
 * lista de campos obrigatórios do briefing (em branco = vale a lista geral das configurações).
 */
class Type
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_marketing_types';
    }

    /** @return array<int, array<string, mixed>> id => linha */
    public static function all(bool $activeOnly = false): array
    {
        global $DB;
        $out = [];
        $where = $activeOnly ? ['is_active' => 1] : [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => 'name ASC']) as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    public static function find(int $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** id => nome para campos de seleção. */
    public static function options(bool $activeOnly = true): array
    {
        return array_map(fn ($r) => (string) $r['name'], self::all($activeOnly));
    }

    /** Campos obrigatórios próprios do tipo, ou null se usa a lista geral. */
    public static function requiredFields(int $id): ?array
    {
        $row = $id > 0 ? self::find($id) : null;
        if (!$row || trim((string) $row['required_fields']) === '') {
            return null;
        }
        $list = json_decode((string) $row['required_fields'], true);
        $list = is_array($list) ? array_values(array_intersect($list, array_keys(Settings::BRIEFING_FIELDS))) : [];
        return $list ?: null;
    }

    public static function save(int $id, string $name, string $color, bool $active, array $required, string $description = ''): int
    {
        global $DB;
        $data = [
            'name'            => mb_substr(trim($name), 0, 120),
            'color'           => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#6366f1',
            'is_active'       => $active ? 1 : 0,
            'required_fields' => $required ? json_encode(array_values(array_intersect($required, array_keys(Settings::BRIEFING_FIELDS)))) : null,
            'description'     => $description,
        ];
        if ($data['name'] === '') {
            return 0;
        }
        if ($id > 0) {
            $DB->update(self::getTable(), $data, ['id' => $id]);
            return $id;
        }
        $DB->insert(self::getTable(), $data + ['date_creation' => date('Y-m-d H:i:s')]);
        return (int) $DB->insertId();
    }
}

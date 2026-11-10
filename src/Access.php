<?php

namespace GlpiPlugin\Marketing;

use Session;

/**
 * Direitos do plugin: perfil GLPI (direito "plugin_marketing" com bits próprios).
 * Regra geral: negar quando faltar a condição.
 *
 * Bits: READ, CREATE, UPDATE, PURGE (nativos) e
 *   APPROVE   registrar decisão de aprovação e concluir com exceção;
 *   CONFIGURE tipos, SLOs, notificações e automações (perfil Administrador).
 */
class Access extends Base
{
    public const APPROVE = 1024;
    public const CONFIGURE = 2048;

    public static function getTypeName($nb = 0)
    {
        return 'Gestão de Demandas';
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_marketing_demands';
    }

    public static function canView(): bool
    {
        return Session::haveRight(self::$rightname, READ);
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(self::$rightname, CREATE);
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    public static function canPurge(): bool
    {
        return Session::haveRight(self::$rightname, PURGE);
    }

    /** Bit de aprovação (decisão de aprovação e conclusão com exceção). */
    public static function canApprove(): bool
    {
        return Session::haveRight(self::$rightname, self::APPROVE);
    }

    /** Bit de configuração (tipos, SLOs, notificações e automações). */
    public static function canConfigure(): bool
    {
        return Session::haveRight(self::$rightname, self::CONFIGURE);
    }

    private static function page(string $path): string
    {
        return '/plugins/marketing' . $path;
    }

    /** Itens da seção "Marketing" no menu lateral. */
    public static function getMenuContent(): array
    {
        if (!self::canView()) {
            return [];
        }
        $items = [
            'dashboard' => ['title' => 'Painel de Demandas', 'page' => self::page('/front/dashboard.php'), 'icon' => 'ti ti-chart-pie'],
            'demands'   => ['title' => 'Demandas', 'page' => self::page('/front/demands.php'), 'icon' => 'ti ti-speakerphone',
                'links' => self::canCreate() ? ['add' => self::page('/front/demand.php?id=0'), 'search' => self::page('/front/demands.php')] : []],
            'kanban'    => ['title' => 'Quadro Kanban', 'page' => self::page('/front/kanban.php'), 'icon' => 'ti ti-layout-kanban'],
        ];
        if (self::canConfigure()) {
            $items['config'] = ['title' => 'Configurações', 'page' => self::page('/front/config.php'), 'icon' => 'ti ti-settings'];
        }
        return $items;
    }
}

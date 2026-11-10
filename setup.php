<?php

/**
 * Plugin "Gestão de Demandas" (chave "marketing") para GLPI 11 e 12: demandas, projetos e governança de Marketing.
 * Seção própria "Marketing" no menu lateral. Especificação: Requisitos_Plugin_GLPI11_Gestao_Marketing_Oakmont.docx v1.0.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Marketing\Access;
use GlpiPlugin\Marketing\ProfileTab;

define('PLUGIN_MARKETING_VERSION', '0.4.0');
define('PLUGIN_MARKETING_MIN_GLPI', '11.0.0');
define('PLUGIN_MARKETING_MAX_GLPI', '12.0.99');

function plugin_init_marketing(): void
{
    global $PLUGIN_HOOKS;

    // formulários deste plugin aceitam o token CSRF do GLPI
    $PLUGIN_HOOKS['csrf_compliant']['marketing'] = true;

    // aba do plugin em Administração → Perfis
    Plugin::registerClass(ProfileTab::class, ['addtabon' => [Profile::class]]);
    Plugin::registerClass(Access::class);

    // sessões abertas antes da instalação (ou antes de uma atualização que acrescentou bits) recarregam o direito uma vez por versão
    if (isset($_SESSION['glpiactiveprofile']['id']) && (!isset($_SESSION['glpiactiveprofile'][Access::$rightname]) || ($_SESSION['marketing_rights_version'] ?? '') !== PLUGIN_MARKETING_VERSION)) {
        $rights = ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Access::$rightname]);
        $_SESSION['glpiactiveprofile'][Access::$rightname] = (int) ($rights[Access::$rightname] ?? 0);
        $_SESSION['marketing_rights_version'] = PLUGIN_MARKETING_VERSION;
    }

    // seção "Marketing" no menu lateral
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['marketing']     = ['marketing' => [Access::class]];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['marketing'] = 'plugin_marketing_redefine_menus';

    // pop-up com som quando uma demanda é atribuída: só para quem tem o direito do plugin
    if (isset($_SESSION['glpiactiveprofile'][Access::$rightname]) && ((int) $_SESSION['glpiactiveprofile'][Access::$rightname] & READ)) {
        $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['marketing'] = ['js/alerts.js'];
        $PLUGIN_HOOKS[Hooks::ADD_CSS]['marketing']        = ['css/alerts.css'];
    }
}

/** Seção "Marketing" no menu lateral, logo abaixo das demais seções de gestão quando existirem. */
function plugin_marketing_redefine_menus(array $menu): array
{
    $content = Access::getMenuContent();
    unset($menu['marketing']);
    if (!$content) {
        return $menu;
    }
    $section = ['marketing' => [
        'title'   => 'Marketing',
        'icon'    => 'ti ti-speakerphone',
        'types'   => [Access::class],
        'content' => $content,
        'default' => reset($content)['page'],
    ]];
    foreach (['vmo', 'crm', 'rh', 'financeiro', 'pmo', 'dashboard'] as $after) {
        if (isset($menu[$after])) {
            $out = [];
            foreach ($menu as $key => $value) {
                $out[$key] = $value;
                if ($key === $after) {
                    $out += $section;
                }
            }
            return $out;
        }
    }
    return $section + $menu;
}

function plugin_version_marketing(): array
{
    return [
        'name'         => 'Gestão de Demandas',
        'version'      => PLUGIN_MARKETING_VERSION,
        'author'       => 'Evandro Evangelista',
        'license'      => 'GPLv3+',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_MARKETING_MIN_GLPI, 'max' => PLUGIN_MARKETING_MAX_GLPI],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_marketing_check_prerequisites(): bool
{
    return true;
}

function plugin_marketing_check_config($verbose = false): bool
{
    return true;
}

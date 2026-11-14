<?php

namespace GlpiPlugin\Marketing;

use CommonGLPI;
use Html;
use Profile;
use Session;

/** Aba "Marketing" em Administração → Perfis: direitos do plugin por perfil. */
class ProfileTab extends CommonGLPI
{
    public static function getRights($interface = 'central')
    {
        return [
            READ                   => __('Read'),
            UPDATE                 => __('Update'),
            CREATE                 => __('Create'),
            PURGE                  => __('Delete permanently'),
            Access::APPROVE        => 'Aprovar',
            Access::CONFIGURE      => 'Configurar',
            Access::INTERNAL       => 'Cadastro interno',
            Access::VIEW_ALL       => 'Ver todas as demandas',
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry('Gestão de Demandas', 0, null, 'ti ti-speakerphone');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Profile) {
            return false;
        }
        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);
        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlspecialchars(Profile::getFormURL(), ENT_QUOTES) . "'>";
        }
        $item->displayRightsChoiceMatrix(
            [['itemtype' => self::class, 'label' => 'Gestão de Demandas', 'field' => 'plugin_marketing']],
            ['canedit' => $canedit, 'default_class' => 'tab_bg_2', 'title' => 'Gestão de Demandas']
        );
        echo "<div class='small text-secondary mt-2'><b>Ler</b>: vê demandas, Kanban e painel executivo. <b>Criar/Atualizar</b>: abre e triagem demandas. <b>Aprovar</b>: decide aprovações e conclui com exceção. <b>Cadastro interno</b>: cria atividades do Marketing sem formulário, projetos e planos de ação. <b>Ver todas as demandas</b>: sem este direito o usuário só enxerga o que pediu, executa ou aprova e o que pertence aos projetos e planos dos quais é responsável. <b>Configurar</b>: tipos, pilares, SLOs, notificações e automações.</div>";
        if ($canedit) {
            echo "<div class='center mt-2'><input type='hidden' name='id' value='" . (int) $item->getID() . "'>";
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'>" . _x('button', 'Save') . "</button></div>";
            Html::closeForm();
        }
        echo "</div>";
        return true;
    }
}

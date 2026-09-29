<?php

namespace GlpiPlugin\Checklistitens;

use CommonGLPI;

/**
 * Menu "Ativos > Checklist uso de equipamentos" da interface padrão. Na interface simplificada
 * o plugin tem uma entrada única (setup.php) que abre a tela inicial.
 */
class Menu extends CommonGLPI
{
    public static $rightname = Profile::RIGHT_USAGE;

    public static function getMenuName()
    {
        return __('Checklist uso de equipamentos', 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-clipboard-check';
    }

    public static function getMenuContent()
    {
        if (!Profile::canUse() && !Profile::isManager() && !Profile::isAdmin()) {
            return false;
        }

        $menu = [
            'title' => self::getMenuName(),
            'page'  => Ui::url('front/home.php'),
            'icon'  => self::getIcon(),
        ];

        $menu['options']['home'] = [
            'title' => __('Início', 'checklistitens'),
            'page'  => Ui::url('front/home.php'),
            'icon'  => self::getIcon(),
        ];

        if (Profile::isManager() || Profile::isAdmin()) {
            $menu['options']['manager'] = [
                'title' => __('Conferência do setor', 'checklistitens'),
                'page'  => Ui::url('front/manager.php'),
                'icon'  => Confirmation::getIcon(),
            ];
        }

        if (Profile::isAdmin()) {
            $menu['options']['itemblock'] = [
                'title' => ItemBlock::getTypeName(2),
                'page'  => Ui::url('front/itemblock.php'),
                'icon'  => ItemBlock::getIcon(),
            ];

            $menu['options']['problemtype'] = [
                'title' => ProblemType::getTypeName(2),
                'page'  => ProblemType::getSearchURL(false),
                'icon'  => ProblemType::getIcon(),
                'links' => [
                    'search' => ProblemType::getSearchURL(false),
                ] + (Profile::canAdministrate() ? ['add' => ProblemType::getFormURL(false)] : []),
            ];

            $menu['options']['config'] = [
                'title' => Config::getTypeName(),
                'page'  => Ui::url('front/config.form.php'),
                'icon'  => 'ti ti-settings',
            ];
        }

        return $menu;
    }
}

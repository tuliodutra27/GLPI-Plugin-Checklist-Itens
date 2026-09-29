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
            'page'  => Ui::path('front/home.php'),
            'icon'  => self::getIcon(),
        ];

        $menu['options']['home'] = [
            'title' => __('Início', 'checklistitens'),
            'page'  => Ui::path('front/home.php'),
            'icon'  => self::getIcon(),
        ];

        if (Profile::isManager() || Profile::isAdmin()) {
            $menu['options']['manager'] = [
                'title' => __('Conferência do setor', 'checklistitens'),
                'page'  => Ui::path('front/manager.php'),
                'icon'  => Confirmation::getIcon(),
            ];
            $menu['options']['equipment'] = [
                'title' => __('Equipamentos', 'checklistitens'),
                'page'  => Ui::path('front/equipment.php'),
                'icon'  => EquipmentRecord::getIcon(),
            ];
            $menu['options']['usage'] = [
                'title' => Usage::getTypeName(2),
                'page'  => Ui::path('front/usage.php'),
                'icon'  => Usage::getIcon(),
                'links' => ['search' => Ui::path('front/usage.php')],
            ];
        }

        if (Profile::isAdmin()) {
            $menu['options']['itemblock'] = [
                'title' => ItemBlock::getTypeName(2),
                'page'  => Ui::path('front/itemblock.php'),
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

            $menu['options']['selfiepurge'] = [
                'title' => SelfiePurge::getTypeName(1),
                'page'  => Ui::path('front/selfiepurge.php'),
                'icon'  => SelfiePurge::getIcon(),
            ];

            $menu['options']['config'] = [
                'title' => Config::getTypeName(),
                'page'  => Ui::path('front/config.form.php'),
                'icon'  => 'ti ti-settings',
            ];
        }

        return $menu;
    }
}

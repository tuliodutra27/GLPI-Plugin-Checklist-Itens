<?php

namespace GlpiPlugin\Checklistitens;

use CommonGLPI;

/**
 * Menu "Ativos > Checklist uso de equipamentos" da interface padrão, montado a partir das telas
 * do plugin (Home::pages). Na interface simplificada o plugin tem uma entrada única (setup.php) que
 * abre a tela inicial.
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
        if (!Home::canSee()) {
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

        foreach (Home::pages() as $key => $page) {
            if (!$page['menu']) {
                continue;
            }
            $option = [
                'title' => $page['title'],
                'page'  => Home::url($page, false),
                'icon'  => $page['icon'],
            ];
            if (!empty($page['search'])) {
                $option['links'] = ['search' => $option['page']] + (!empty($page['add']) ? ['add' => $page['add']] : []);
            }
            $menu['options'][$key] = $option;
        }

        return $menu;
    }
}

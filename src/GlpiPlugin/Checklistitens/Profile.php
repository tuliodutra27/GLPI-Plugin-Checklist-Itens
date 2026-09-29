<?php

namespace GlpiPlugin\Checklistitens;

use CommonGLPI;
use Html;
use Profile as GlpiProfile;
use ProfileRight;
use Session;
use Toolbox;

/**
 * Direitos do plugin, exibidos numa aba do Perfil do GLPI.
 *
 * - usage:   retirada e devolução (todos os perfis);
 * - manager: conferência do setor e registro de uso dos equipamentos do setor (gestor operacional);
 * - config:  catálogo, configuração, todos os setores, liberação manual e limpeza de selfies (TI).
 */
class Profile extends GlpiProfile
{
    public const RIGHT_USAGE   = 'plugin_checklistitens_usage';
    public const RIGHT_MANAGER = 'plugin_checklistitens_manager';
    public const RIGHT_CONFIG  = 'plugin_checklistitens_config';

    /** Nome do perfil que recebe o direito de gestor na instalação (comparação sem maiúsculas). */
    public const MANAGER_PROFILE_NAME = 'gestor operacional';

    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('Checklist uso de equipamentos', 'checklistitens');
    }

    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => self::class,
                'label'    => __('Retirada e devolução de equipamentos', 'checklistitens'),
                'field'    => self::RIGHT_USAGE,
                'rights'   => [CREATE => __('Usar', 'checklistitens')],
            ],
            [
                'itemtype' => self::class,
                'label'    => __('Conferência do setor (gestor)', 'checklistitens'),
                'field'    => self::RIGHT_MANAGER,
                'rights'   => [
                    READ   => __('Ver', 'checklistitens'),
                    UPDATE => __('Conferir e abrir chamado', 'checklistitens'),
                ],
            ],
            [
                'itemtype' => self::class,
                'label'    => __('Administração (TI)', 'checklistitens'),
                'field'    => self::RIGHT_CONFIG,
                'rights'   => [
                    READ   => __('Ver todos os setores', 'checklistitens'),
                    UPDATE => __('Configurar, liberar itens e limpar selfies', 'checklistitens'),
                ],
            ],
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile && $item->getField('id')) {
            return self::createTabEntry(self::getTypeName());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof GlpiProfile) {
            self::showForProfile((int) $item->getField('id'));
        }

        return true;
    }

    public static function showForProfile(int $profiles_id): void
    {
        $profile = new GlpiProfile();
        if (!$profile->getFromDB($profiles_id)) {
            return;
        }

        $canedit = Session::haveRight('profile', UPDATE);

        echo "<div class='firstbloc'>";
        // O formulário vai para o controlador de Perfil do core, que grava os direitos postados.
        echo "<form method='post' action='" . Toolbox::getItemTypeFormURL(GlpiProfile::class) . "'>";

        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);

        if ($canedit) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
        }

        Html::closeForm();
        echo "</div>";
    }

    /**
     * Registra os direitos no core. Só na primeira instalação aplica a concessão padrão:
     * uso para todos os perfis, gestor para "gestor operacional" e tudo para o Super-Admin.
     * Numa atualização, os direitos já ajustados pelos administradores são preservados.
     */
    public static function installRights(): void
    {
        $first_install = countElementsInTable(ProfileRight::getTable(), ['name' => self::RIGHT_USAGE]) === 0;

        ProfileRight::addProfileRights([self::RIGHT_USAGE, self::RIGHT_MANAGER, self::RIGHT_CONFIG]);

        if (!$first_install) {
            return;
        }

        foreach ((new GlpiProfile())->find() as $data) {
            $rights = [self::RIGHT_USAGE => CREATE];
            $name   = mb_strtolower(trim((string) $data['name']));

            if ($name === self::MANAGER_PROFILE_NAME) {
                $rights[self::RIGHT_MANAGER] = READ | UPDATE;
            }
            if ($name === 'super-admin') {
                $rights[self::RIGHT_MANAGER] = READ | UPDATE;
                $rights[self::RIGHT_CONFIG]  = READ | UPDATE;
            }

            ProfileRight::updateProfileRights((int) $data['id'], $rights);
        }
    }

    public static function uninstallRights(): void
    {
        ProfileRight::deleteProfileRights([self::RIGHT_USAGE, self::RIGHT_MANAGER, self::RIGHT_CONFIG]);
    }

    public static function grantUsage(int $profiles_id): void
    {
        if ($profiles_id > 0) {
            ProfileRight::updateProfileRights($profiles_id, [self::RIGHT_USAGE => CREATE]);
        }
    }

    public static function canUse(): bool
    {
        return Session::haveRight(self::RIGHT_USAGE, CREATE);
    }

    public static function isManager(): bool
    {
        return Session::haveRight(self::RIGHT_MANAGER, READ);
    }

    public static function canConfirm(): bool
    {
        return Session::haveRight(self::RIGHT_MANAGER, UPDATE);
    }

    /** TI: vê todos os setores. */
    public static function isAdmin(): bool
    {
        return Session::haveRight(self::RIGHT_CONFIG, READ);
    }

    /** TI: configura, libera itens bloqueados e limpa selfies. */
    public static function canAdministrate(): bool
    {
        return Session::haveRight(self::RIGHT_CONFIG, UPDATE);
    }
}

<?php

namespace GlpiPlugin\Checklistitens;

use Plugin;

/**
 * Tipos de equipamento atendidos pelo plugin e acesso aos ativos (rádios do plugin Radios e
 * telefones nativos do GLPI).
 */
class ItemProvider
{
    public const RADIO = 'PluginRadiosRadio';
    public const PHONE = 'Phone';

    /** @return string[] */
    public static function getSupportedTypes(): array
    {
        return [self::RADIO, self::PHONE];
    }

    public static function isSupported(string $itemtype): bool
    {
        if ($itemtype === self::PHONE) {
            return true;
        }
        if ($itemtype === self::RADIO) {
            return Plugin::isPluginActive('radios') && class_exists(self::RADIO);
        }

        return false;
    }

    public static function getTypeLabel(string $itemtype): string
    {
        switch ($itemtype) {
            case self::RADIO:
                return __('Rádio', 'checklistitens');
            case self::PHONE:
                return __('Telefone (celular/tablet)', 'checklistitens');
        }

        return $itemtype;
    }

    public static function getTypeIcon(string $itemtype): string
    {
        return $itemtype === self::RADIO ? 'ti ti-radio' : 'ti ti-device-mobile';
    }

    public static function getTable(string $itemtype): string
    {
        return $itemtype === self::RADIO ? 'glpi_plugin_radios_radios' : 'glpi_phones';
    }
}

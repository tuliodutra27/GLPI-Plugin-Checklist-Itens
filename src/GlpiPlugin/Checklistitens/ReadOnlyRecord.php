<?php

namespace GlpiPlugin\Checklistitens;

/**
 * Registro que só muda pelos fluxos do plugin: o GLPI não oferece criar, editar nem apagar pela
 * interface.
 */
trait ReadOnlyRecord
{
    public static function canCreate()
    {
        return false;
    }

    public static function canUpdate()
    {
        return false;
    }

    public static function canDelete()
    {
        return false;
    }

    public static function canPurge()
    {
        return false;
    }
}

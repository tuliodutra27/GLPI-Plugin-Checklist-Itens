<?php

namespace GlpiPlugin\Checklistitens;

use Toolbox;

/**
 * Armazenamento das selfies, fora da raiz web (files/_plugins/checklistitens/selfies).
 */
class Selfie
{
    public static function getBaseDir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/checklistitens/selfies';
    }

    public static function createBaseDir(): void
    {
        $dir = self::getBaseDir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            Toolbox::logError('checklistitens: não foi possível criar o diretório de selfies ' . $dir);
        }
    }

    /**
     * Na desinstalação as tabelas são apagadas; as imagens ficariam sem vínculo nenhum
     * (dado pessoal órfão), então são removidas junto.
     */
    public static function removeBaseDir(): void
    {
        $dir = GLPI_PLUGIN_DOC_DIR . '/checklistitens';
        if (is_dir($dir)) {
            Toolbox::deleteDir($dir);
        }
    }
}

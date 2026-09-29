<?php

require_once __DIR__ . '/autoload.php';

use GlpiPlugin\Checklistitens\Install;
use GlpiPlugin\Checklistitens\Profile as ChecklistProfile;

/**
 * Instalação e atualização: é idempotente (tabelas, colunas, configuração, direitos e dados
 * padrão só são criados quando ainda não existem), então roda igual em instalação nova e em
 * atualização de versão.
 */
function plugin_checklistitens_install()
{
    $migration = new Migration(PLUGIN_CHECKLISTITENS_VERSION);
    Install::install($migration);
    $migration->executeMigration();

    return true;
}

function plugin_checklistitens_uninstall()
{
    Install::uninstall();

    return true;
}

/**
 * Hook item_add de Profile: perfil novo já nasce com o direito de retirada/devolução.
 */
function plugin_checklistitens_profile_add(Profile $profile)
{
    ChecklistProfile::grantUsage((int) $profile->getID());
}

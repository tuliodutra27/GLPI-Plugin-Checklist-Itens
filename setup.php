<?php

/**
 * Checklist uso de equipamentos — retirada e devolução de equipamentos compartilhados do setor
 * (rádios e telefones) com checklist de estado, selfie e conferência do gestor por turno.
 */

require_once __DIR__ . '/autoload.php';

use GlpiPlugin\Checklistitens\EquipmentRecord;
use GlpiPlugin\Checklistitens\Menu;
use GlpiPlugin\Checklistitens\Profile as ChecklistProfile;
use GlpiPlugin\Checklistitens\Usage;

define('PLUGIN_CHECKLISTITENS_VERSION', '0.8.0');
define('PLUGIN_CHECKLISTITENS_MIN_GLPI_VERSION', '10.0.0');
define('PLUGIN_CHECKLISTITENS_MAX_GLPI_VERSION', '10.0.99');

function plugin_init_checklistitens()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['checklistitens'] = true;

    // A aba de direitos no Perfil precisa existir mesmo com o plugin desativado, para não
    // sumir a configuração de direitos já feita.
    Plugin::registerClass(ChecklistProfile::class, ['addtabon' => ['Profile']]);

    if (!Plugin::isPluginActive('checklistitens')) {
        return;
    }

    // Perfis da interface simplificada (operador, gestor operacional) só mantêm na sessão os
    // direitos da lista Profile::$helpdesk_rights; sem isso o GLPI descarta os direitos do plugin
    // no login e as telas respondem "sem permissão".
    foreach (ChecklistProfile::getRightNames() as $right) {
        if (!in_array($right, Profile::$helpdesk_rights, true)) {
            Profile::$helpdesk_rights[] = $right;
        }
    }

    // O chamado aberto na conferência fica vinculado ao registro de uso; o registro de uso também
    // dispara a notificação de equipamento não devolvido
    Plugin::registerClass(Usage::class, ['ticket_types' => true, 'notificationtemplates_types' => true]);

    // Aba "Registro de uso" no formulário do Telefone
    Plugin::registerClass(EquipmentRecord::class, ['addtabon' => ['Phone']]);

    // Interface padrão: Ativos > Checklist uso de equipamentos
    $PLUGIN_HOOKS['menu_toadd']['checklistitens'] = ['assets' => Menu::class];

    // Interface simplificada (perfis operador, gestor operacional, Self-Service)
    $PLUGIN_HOOKS['helpdesk_menu_entry']['checklistitens']      = '/front/home.php';
    $PLUGIN_HOOKS['helpdesk_menu_entry_icon']['checklistitens'] = Menu::getIcon();

    $PLUGIN_HOOKS['config_page']['checklistitens'] = 'front/config.form.php';

    // Todo perfil novo recebe o direito de uso (retirada/devolução)
    $PLUGIN_HOOKS['item_add']['checklistitens'] = [
        'Profile' => 'plugin_checklistitens_profile_add',
    ];

    // Chamado solucionado/fechado libera o item bloqueado
    $PLUGIN_HOOKS['item_update']['checklistitens'] = [
        'Ticket' => 'plugin_checklistitens_ticket_update',
    ];
}

function plugin_version_checklistitens()
{
    return [
        'name'         => 'Checklist uso de equipamentos',
        'shortname'    => 'checklistitens',
        'version'      => PLUGIN_CHECKLISTITENS_VERSION,
        'author'       => 'Tulio Pereira',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/tuliodutra27/GLPI-Plugin-Checklist-Itens',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CHECKLISTITENS_MIN_GLPI_VERSION,
                'max' => PLUGIN_CHECKLISTITENS_MAX_GLPI_VERSION,
            ],
            'php' => [
                'min' => '8.0',
            ],
        ],
    ];
}

function plugin_checklistitens_check_prerequisites()
{
    return true;
}

function plugin_checklistitens_check_config($verbose = false)
{
    return true;
}

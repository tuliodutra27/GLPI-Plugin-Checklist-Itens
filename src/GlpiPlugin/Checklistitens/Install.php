<?php

namespace GlpiPlugin\Checklistitens;

use CronTask;
use DBConnection;
use Item_Ticket;
use Migration;
use Toolbox;

/**
 * Instalação, atualização e desinstalação do plugin.
 *
 * Tudo aqui é idempotente: cada tabela só é criada se não existir, e cada etapa de dados padrão
 * verifica o que já existe antes de gravar. Por isso o mesmo fluxo serve para instalação nova e
 * para atualização de versão.
 */
class Install
{
    public const TABLE_USAGES        = 'glpi_plugin_checklistitens_usages';
    public const TABLE_CONFIRMATIONS = 'glpi_plugin_checklistitens_confirmations';
    public const TABLE_PROBLEMTYPES  = 'glpi_plugin_checklistitens_problemtypes';
    public const TABLE_USAGEPROBLEMS = 'glpi_plugin_checklistitens_usageproblems';
    public const TABLE_ITEMBLOCKS    = 'glpi_plugin_checklistitens_itemblocks';
    public const TABLE_SELFIEPURGES  = 'glpi_plugin_checklistitens_selfiepurges';

    public static function install(Migration $migration): void
    {
        self::createTables();
        Config::installDefaults();
        Profile::installRights();
        ProblemType::installDefaults();
        Selfie::createBaseDir();
        self::registerCronTasks();
        self::installDisplayPreferences();
    }

    /**
     * Colunas padrão das listas (histórico de usos e catálogo), se ainda não houver nenhuma.
     */
    private static function installDisplayPreferences(): void
    {
        global $DB;

        $defaults = [
            Usage::class       => [2, 3, 4, 5, 6, 7, 9],
            ProblemType::class => [101, 102, 103, 104, 105],
        ];

        foreach ($defaults as $itemtype => $nums) {
            if (countElementsInTable('glpi_displaypreferences', ['itemtype' => $itemtype, 'users_id' => 0]) > 0) {
                continue;
            }
            foreach ($nums as $rank => $num) {
                $DB->insert('glpi_displaypreferences', [
                    'itemtype' => $itemtype,
                    'num'      => $num,
                    'rank'     => $rank + 1,
                    'users_id' => 0,
                ]);
            }
        }
    }

    /**
     * Tarefas automáticas (CronTask::register não duplica se já existir).
     */
    private static function registerCronTasks(): void
    {
        CronTask::register(ItemBlock::class, 'releaseblocks', DAY_TIMESTAMP, [
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
            'comment' => __('Libera itens bloqueados cujo chamado já foi solucionado (rede de segurança do hook de chamado).', 'checklistitens'),
        ]);
    }

    public static function uninstall(): void
    {
        global $DB;

        foreach (self::getTables() as $table) {
            if ($DB->tableExists($table)) {
                $DB->doQuery("DROP TABLE `$table`");
            }
        }

        Config::uninstall();
        Profile::uninstallRights();
        Selfie::removeBaseDir();
        CronTask::unregister('checklistitens');

        // Vínculos de chamados com registros de uso que deixam de existir
        $DB->delete(Item_Ticket::getTable(), ['itemtype' => Usage::class]);
        $DB->delete('glpi_displaypreferences', ['itemtype' => [Usage::class, ProblemType::class]]);
    }

    /**
     * @return string[]
     */
    public static function getTables(): array
    {
        return [
            self::TABLE_USAGES,
            self::TABLE_CONFIRMATIONS,
            self::TABLE_PROBLEMTYPES,
            self::TABLE_USAGEPROBLEMS,
            self::TABLE_ITEMBLOCKS,
            self::TABLE_SELFIEPURGES,
        ];
    }

    private static function createTables(): void
    {
        $tables = [
            // Um registro por ciclo retirada → devolução, ou por retirada recusada com problema.
            // lock_open vale 1 enquanto o uso está aberto e NULL depois: o índice único
            // (itemtype, items_id, lock_open) garante um único uso aberto por item.
            self::TABLE_USAGES => "
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `itemtype` varchar(100) NOT NULL DEFAULT '',
                `items_id` int unsigned NOT NULL DEFAULT 0,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `groups_id` int unsigned NOT NULL DEFAULT 0,
                `status` tinyint unsigned NOT NULL DEFAULT 1,
                `lock_open` tinyint unsigned NULL DEFAULT NULL,
                `date_checkout` timestamp NULL DEFAULT NULL,
                `checkout_shift_start` timestamp NULL DEFAULT NULL,
                `checkout_is_ok` tinyint NOT NULL DEFAULT 1,
                `checkout_selfie` varchar(255) NOT NULL DEFAULT '',
                `plugin_checklistitens_confirmations_id` int unsigned NOT NULL DEFAULT 0,
                `date_checkin` timestamp NULL DEFAULT NULL,
                `checkin_shift_start` timestamp NULL DEFAULT NULL,
                `checkin_is_ok` tinyint NULL DEFAULT NULL,
                `checkin_selfie` varchar(255) NOT NULL DEFAULT '',
                `plugin_checklistitens_confirmations_id_checkin` int unsigned NOT NULL DEFAULT 0,
                `date_alert_not_returned` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `open_item` (`itemtype`, `items_id`, `lock_open`),
                KEY `item` (`itemtype`, `items_id`, `date_checkout`),
                KEY `users_status` (`users_id`, `status`),
                KEY `groups_status` (`groups_id`, `status`),
                KEY `groups_shift` (`groups_id`, `checkout_shift_start`),
                KEY `entities_id` (`entities_id`),
                KEY `plugin_checklistitens_confirmations_id` (`plugin_checklistitens_confirmations_id`),
                KEY `plugin_checklistitens_confirmations_id_checkin` (`plugin_checklistitens_confirmations_id_checkin`)
            ",

            // Conferência do gestor (selfie do gestor + o que foi conferido aponta para cá).
            self::TABLE_CONFIRMATIONS => "
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `groups_id` int unsigned NOT NULL DEFAULT 0,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `date_confirmation` timestamp NULL DEFAULT NULL,
                `shift_start` timestamp NULL DEFAULT NULL,
                `selfie` varchar(255) NOT NULL DEFAULT '',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `groups_shift` (`groups_id`, `shift_start`),
                KEY `users_id` (`users_id`),
                KEY `entities_id` (`entities_id`)
            ",

            // Catálogo de problemas por tipo de equipamento.
            self::TABLE_PROBLEMTYPES => "
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(255) DEFAULT NULL,
                `itemtype` varchar(100) NOT NULL DEFAULT '',
                `category` tinyint unsigned NOT NULL DEFAULT 1,
                `itilcategories_id` int unsigned NOT NULL DEFAULT 0,
                `ranking` int NOT NULL DEFAULT 0,
                `is_active` tinyint NOT NULL DEFAULT 1,
                `comment` text,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `is_recursive` tinyint NOT NULL DEFAULT 1,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `itemtype_active` (`itemtype`, `is_active`, `ranking`),
                KEY `itilcategories_id` (`itilcategories_id`),
                KEY `entities_id` (`entities_id`),
                KEY `is_recursive` (`is_recursive`)
            ",

            // Problemas marcados em cada uso (só os marcados; "Sim" não gera linhas).
            self::TABLE_USAGEPROBLEMS => "
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `plugin_checklistitens_usages_id` int unsigned NOT NULL DEFAULT 0,
                `plugin_checklistitens_problemtypes_id` int unsigned NOT NULL DEFAULT 0,
                `problem_name` varchar(255) NOT NULL DEFAULT '',
                `phase` tinyint unsigned NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                UNIQUE KEY `usage_problem_phase` (`plugin_checklistitens_usages_id`, `plugin_checklistitens_problemtypes_id`, `phase`),
                KEY `plugin_checklistitens_problemtypes_id` (`plugin_checklistitens_problemtypes_id`)
            ",

            // Item fora de circulação por problema, até o chamado ser solucionado.
            // lock_active vale 1 enquanto ativo e NULL depois: um bloqueio ativo por item.
            self::TABLE_ITEMBLOCKS => "
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT 0,
                `itemtype` varchar(100) NOT NULL DEFAULT '',
                `items_id` int unsigned NOT NULL DEFAULT 0,
                `groups_id` int unsigned NOT NULL DEFAULT 0,
                `reason` tinyint unsigned NOT NULL DEFAULT 1,
                `plugin_checklistitens_usages_id` int unsigned NOT NULL DEFAULT 0,
                `status` tinyint unsigned NOT NULL DEFAULT 1,
                `lock_active` tinyint unsigned NULL DEFAULT NULL,
                `date_block` timestamp NULL DEFAULT NULL,
                `block_shift_start` timestamp NULL DEFAULT NULL,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `tickets_id` int unsigned NOT NULL DEFAULT 0,
                `plugin_checklistitens_confirmations_id` int unsigned NOT NULL DEFAULT 0,
                `release_type` tinyint unsigned NOT NULL DEFAULT 0,
                `users_id_release` int unsigned NOT NULL DEFAULT 0,
                `date_release` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `active_item` (`itemtype`, `items_id`, `lock_active`),
                KEY `item` (`itemtype`, `items_id`, `date_block`),
                KEY `groups_status` (`groups_id`, `status`),
                KEY `tickets_id` (`tickets_id`),
                KEY `plugin_checklistitens_usages_id` (`plugin_checklistitens_usages_id`),
                KEY `plugin_checklistitens_confirmations_id` (`plugin_checklistitens_confirmations_id`),
                KEY `entities_id` (`entities_id`)
            ",

            // Registro de cada limpeza de selfies feita por um administrador.
            self::TABLE_SELFIEPURGES => "
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `users_id` int unsigned NOT NULL DEFAULT 0,
                `date_purge` timestamp NULL DEFAULT NULL,
                `date_limit` timestamp NULL DEFAULT NULL,
                `nb_files` int unsigned NOT NULL DEFAULT 0,
                `size_bytes` bigint unsigned NOT NULL DEFAULT 0,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `date_limit` (`date_limit`)
            ",
        ];

        foreach ($tables as $table => $columns) {
            self::createTable($table, $columns);
        }
    }

    private static function createTable(string $table, string $columns): void
    {
        global $DB;

        if ($DB->tableExists($table)) {
            return;
        }

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();

        $query = "CREATE TABLE `$table` ($columns) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";
        if (!$DB->doQuery($query)) {
            Toolbox::logError(sprintf('checklistitens: erro ao criar a tabela %s: %s', $table, $DB->error()));
        }
    }
}

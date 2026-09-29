<?php

namespace GlpiPlugin\Checklistitens;

use Plugin;
use QuerySubQuery;

/**
 * Tipos de equipamento atendidos pelo plugin e acesso aos ativos (rádios do plugin Radios e
 * telefones nativos do GLPI). O plugin só lê essas tabelas, nunca altera os ativos.
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

    /**
     * Itens que o colaborador pode retirar: do setor, em estado disponível, sem uso aberto e sem
     * bloqueio ativo.
     *
     * @param int[] $groups setor (já com subgrupos)
     * @return array<int, array{id: int, label: string, detail: string, groups_id: int}>
     */
    public static function getAvailable(string $itemtype, array $groups): array
    {
        global $DB;

        $criteria = self::getAvailabilityCriteria($itemtype, $groups);
        if ($criteria === null) {
            return [];
        }

        $items = [];
        foreach ($DB->request($criteria) as $row) {
            $items[] = self::present($itemtype, $row);
        }

        return $items;
    }

    /**
     * Revalida, no momento de salvar, se o item continua disponível para o setor.
     *
     * @param int[] $groups
     */
    public static function getAvailableItem(string $itemtype, int $items_id, array $groups): ?array
    {
        global $DB;

        $criteria = self::getAvailabilityCriteria($itemtype, $groups);
        if ($criteria === null) {
            return null;
        }
        $criteria['WHERE'][self::getTable($itemtype) . '.id'] = $items_id;

        $row = $DB->request($criteria)->current();

        return $row ? $row : null;
    }

    /**
     * Dados de um item para exibição (sem checar disponibilidade).
     */
    public static function getRow(string $itemtype, int $items_id): ?array
    {
        global $DB;

        if (!in_array($itemtype, self::getSupportedTypes(), true) || !$DB->tableExists(self::getTable($itemtype))) {
            return null;
        }

        $criteria = self::getBaseCriteria($itemtype);
        $criteria['WHERE'] = [self::getTable($itemtype) . '.id' => $items_id];
        $row = $DB->request($criteria)->current();

        return $row ? $row : null;
    }

    /**
     * Rótulo principal e detalhe: rádio pelo número de série (+ fabricante/modelo), telefone
     * pelo nome (+ modelo).
     *
     * @return array{id: int, label: string, detail: string, groups_id: int}
     */
    public static function present(string $itemtype, array $row): array
    {
        $name         = Ui::text($row['name'] ?? '');
        $serial       = Ui::text($row['serial'] ?? '');
        $model        = Ui::text($row['model'] ?? '');
        $manufacturer = Ui::text($row['manufacturer'] ?? '');

        if ($itemtype === self::RADIO) {
            $label  = $serial !== '' ? $serial : ($name !== '' ? $name : '#' . $row['id']);
            $detail = trim($manufacturer . ' ' . $model);
        } else {
            $label  = $name !== '' ? $name : ($serial !== '' ? $serial : '#' . $row['id']);
            $detail = trim($model . ($serial !== '' && $serial !== $label ? ' · ' . $serial : ''));
        }

        return [
            'id'        => (int) $row['id'],
            'label'     => $label,
            'detail'    => $detail,
            'groups_id' => (int) ($row['groups_id'] ?? 0),
        ];
    }

    /** Descrição de uma linha só, ex.: "Rádio 19509A0882 - 3017 (Motorola DEM300)". */
    public static function describe(string $itemtype, int $items_id): string
    {
        $row = self::getRow($itemtype, $items_id);
        if ($row === null) {
            return sprintf('%s #%d', self::getTypeLabel($itemtype), $items_id);
        }
        $item = self::present($itemtype, $row);

        return trim(sprintf('%s %s%s', self::getShortTypeLabel($itemtype), $item['label'], $item['detail'] !== '' ? ' (' . $item['detail'] . ')' : ''));
    }

    public static function getShortTypeLabel(string $itemtype): string
    {
        return $itemtype === self::RADIO ? __('Rádio', 'checklistitens') : __('Telefone', 'checklistitens');
    }

    /**
     * @param int[] $groups
     */
    private static function getAvailabilityCriteria(string $itemtype, array $groups): ?array
    {
        global $DB;

        $groups = array_values(array_filter(array_map('intval', $groups)));
        $states = Config::getAvailableStates();
        $table  = self::getTable($itemtype);

        if (!self::isSupported($itemtype) || !in_array($itemtype, Config::getEnabledTypes(), true)
            || !count($groups) || !count($states) || !$DB->tableExists($table)) {
            return null;
        }

        $criteria = self::getBaseCriteria($itemtype);
        $criteria['WHERE'] = [
            "$table.is_deleted" => 0,
            "$table.groups_id"  => $groups,
            "$table.states_id"  => $states,
            ['NOT' => ["$table.id" => new QuerySubQuery([
                'SELECT' => 'items_id',
                'FROM'   => Install::TABLE_USAGES,
                'WHERE'  => ['itemtype' => $itemtype, 'lock_open' => 1],
            ])]],
            ['NOT' => ["$table.id" => new QuerySubQuery([
                'SELECT' => 'items_id',
                'FROM'   => Install::TABLE_ITEMBLOCKS,
                'WHERE'  => ['itemtype' => $itemtype, 'lock_active' => 1],
            ])]],
            getEntitiesRestrictCriteria($table, '', '', $DB->fieldExists($table, 'is_recursive')),
        ];
        if ($DB->fieldExists($table, 'is_template')) {
            $criteria['WHERE']["$table.is_template"] = 0;
        }

        return $criteria;
    }

    private static function getBaseCriteria(string $itemtype): array
    {
        global $DB;

        $table  = self::getTable($itemtype);
        $fields = ["$table.id", "$table.serial", "$table.groups_id", "$table.states_id", "$table.entities_id", 'glpi_manufacturers.name AS manufacturer'];
        if ($DB->fieldExists($table, 'name')) {
            $fields[] = "$table.name";
        }

        $criteria = [
            'SELECT'    => $fields,
            'FROM'      => $table,
            'LEFT JOIN' => [
                'glpi_manufacturers' => ['ON' => [$table => 'manufacturers_id', 'glpi_manufacturers' => 'id']],
            ],
        ];

        if ($itemtype === self::RADIO) {
            $criteria['SELECT'][] = "$table.model";
            $criteria['ORDER']    = "$table.serial";
        } else {
            $criteria['SELECT'][] = 'glpi_phonemodels.name AS model';
            $criteria['LEFT JOIN']['glpi_phonemodels'] = ['ON' => [$table => 'phonemodels_id', 'glpi_phonemodels' => 'id']];
            $criteria['ORDER'] = "$table.name";
        }

        return $criteria;
    }
}

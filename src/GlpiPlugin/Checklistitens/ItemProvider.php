<?php

namespace GlpiPlugin\Checklistitens;

use Plugin;
use QuerySubQuery;

/**
 * Tipos de equipamento atendidos pelo plugin e acesso aos ativos (rádios do plugin Radios e
 * telefones nativos do GLPI). O plugin só lê essas tabelas, nunca altera os ativos: a situação
 * (Status) do equipamento é mudada à mão pelo TI.
 */
class ItemProvider
{
    public const RADIO = 'PluginRadiosRadio';
    public const PHONE = 'Phone';

    /**
     * Como cada tipo é lido e mostrado:
     * - plugin: plugin que precisa estar ativo (null = tipo do próprio GLPI);
     * - ticket: o ativo pode ser vinculado ao chamado (o rádio do plugin Radios não é tipo de item
     *   de chamado);
     * - label_by: campo do rótulo principal (rádio pelo número de série, telefone pelo nome);
     * - model_join: tabela e chave do modelo, quando ele não é uma coluna da própria tabela;
     * - order: coluna da ordem das listas.
     *
     * @return array<string, array{label: string, icon: string, table: string, plugin: ?string, ticket: bool, label_by: string, model_join: ?array, order: string}>
     */
    private static function types(): array
    {
        return [
            self::RADIO => [
                'label'      => __('Rádio', 'checklistitens'),
                'icon'       => 'ti ti-radio',
                'table'      => 'glpi_plugin_radios_radios',
                'plugin'     => 'radios',
                'ticket'     => false,
                'label_by'   => 'serial',
                'model_join' => null,
                'order'      => 'serial',
            ],
            self::PHONE => [
                'label'      => __('Celular ou tablet', 'checklistitens'),
                'icon'       => 'ti ti-device-mobile',
                'table'      => 'glpi_phones',
                'plugin'     => null,
                'ticket'     => true,
                'label_by'   => 'name',
                'model_join' => ['glpi_phonemodels', 'phonemodels_id'],
                'order'      => 'name',
            ],
        ];
    }

    /** @return string[] */
    public static function getSupportedTypes(): array
    {
        return array_keys(self::types());
    }

    public static function isSupported(string $itemtype): bool
    {
        $type = self::types()[$itemtype] ?? null;
        if ($type === null) {
            return false;
        }

        return $type['plugin'] === null || (Plugin::isPluginActive($type['plugin']) && class_exists($itemtype));
    }

    public static function linksToTicket(string $itemtype): bool
    {
        return self::types()[$itemtype]['ticket'] ?? false;
    }

    public static function getTypeLabel(string $itemtype): string
    {
        return self::types()[$itemtype]['label'] ?? $itemtype;
    }

    public static function getTypeIcon(string $itemtype): string
    {
        return self::types()[$itemtype]['icon'] ?? 'ti ti-device-mobile';
    }

    public static function getTable(string $itemtype): string
    {
        return self::types()[$itemtype]['table'] ?? '';
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

        if ((self::types()[$itemtype]['label_by'] ?? 'name') === 'serial') {
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

    /**
     * Rótulo e detalhe de um item para as telas; "#id" quando o ativo não existe mais.
     *
     * @return array{label: string, detail: string}
     */
    public static function labelOf(string $itemtype, int $items_id): array
    {
        $row = self::getRow($itemtype, $items_id);
        if ($row === null) {
            return ['label' => '#' . $items_id, 'detail' => ''];
        }
        $item = self::present($itemtype, $row);

        return ['label' => $item['label'], 'detail' => $item['detail']];
    }

    /** Descrição de uma linha só, ex.: "Rádio ABC123 - 0001 (Fabricante Modelo)". */
    public static function describe(string $itemtype, int $items_id): string
    {
        $row = self::getRow($itemtype, $items_id);
        if ($row === null) {
            return sprintf('%s #%d', self::getTypeLabel($itemtype), $items_id);
        }
        $item = self::present($itemtype, $row);

        return trim(sprintf('%s %s%s', self::getTypeLabel($itemtype), $item['label'], $item['detail'] !== '' ? ' (' . $item['detail'] . ')' : ''));
    }

    /**
     * Diagnóstico para o TI quando a lista de retirada vem vazia: mostra onde os itens "somem"
     * (setor, estado disponível, uso aberto, bloqueio).
     *
     * @param int[] $groups
     * @return array<string, string|int|bool>
     */
    public static function diagnose(string $itemtype, array $groups): array
    {
        global $DB;

        $states    = Config::getAvailableStates();
        $in_sector = count($groups) ? self::getItems($itemtype, $groups) : [];

        $available_state = array_filter($in_sector, static fn ($row) => in_array((int) $row['states_id'], $states, true));
        $ids             = array_map(static fn ($row) => (int) $row['id'], $available_state);

        $open = $blocked = 0;
        if (count($ids)) {
            $open    = countElementsInTable(Usage::getTable(), ['itemtype' => $itemtype, 'items_id' => $ids, 'lock_open' => 1]);
            $blocked = countElementsInTable(ItemBlock::getTable(), ['itemtype' => $itemtype, 'items_id' => $ids, 'lock_active' => 1]);
        }

        $state_names = [];
        if (count($states)) {
            foreach ($DB->request(['SELECT' => 'completename', 'FROM' => 'glpi_states', 'WHERE' => ['id' => $states]]) as $row) {
                $state_names[] = Ui::text($row['completename']);
            }
        }

        return [
            'type_enabled'    => in_array($itemtype, Config::getEnabledTypes(), true),
            'type_supported'  => self::isSupported($itemtype),
            'enabled_types'   => implode(', ', Config::getEnabledTypes()),
            'groups_ids'      => implode(', ', $groups),
            'states_ids'      => implode(', ', $states),
            'sector'          => implode(', ', Sector::getNames($groups)),
            'states'          => implode(', ', $state_names),
            'in_sector'       => count($in_sector),
            'available_state' => count($available_state),
            'open'            => $open,
            'blocked'         => $blocked,
        ];
    }

    /**
     * Todos os itens de um tipo (não excluídos), opcionalmente só de alguns grupos. Usado no
     * registro de uso dos equipamentos.
     *
     * @param int[]|null $groups null = todos os grupos
     * @return array[]
     */
    public static function getItems(string $itemtype, ?array $groups): array
    {
        global $DB;

        $table = self::getTable($itemtype);
        if (!self::isSupported($itemtype) || !$DB->tableExists($table) || ($groups !== null && !count($groups))) {
            return [];
        }

        $criteria = self::getBaseCriteria($itemtype);
        $criteria['WHERE'] = [
            "$table.is_deleted" => 0,
        ];
        Db::addEntityRestriction($criteria['WHERE'], $table, $DB->fieldExists($table, 'is_recursive'));
        if ($groups !== null) {
            $criteria['WHERE']["$table.groups_id"] = array_values(array_map('intval', $groups));
        }
        if ($DB->fieldExists($table, 'is_template')) {
            $criteria['WHERE']["$table.is_template"] = 0;
        }

        return iterator_to_array($DB->request($criteria), false);
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
                'FROM'   => Usage::getTable(),
                'WHERE'  => ['itemtype' => $itemtype, 'lock_open' => 1],
            ])]],
            ['NOT' => ["$table.id" => new QuerySubQuery([
                'SELECT' => 'items_id',
                'FROM'   => ItemBlock::getTable(),
                'WHERE'  => ['itemtype' => $itemtype, 'lock_active' => 1],
            ])]],
        ];
        Db::addEntityRestriction($criteria['WHERE'], $table, $DB->fieldExists($table, 'is_recursive'));
        if ($DB->fieldExists($table, 'is_template')) {
            $criteria['WHERE']["$table.is_template"] = 0;
        }

        return $criteria;
    }

    private static function getBaseCriteria(string $itemtype): array
    {
        global $DB;

        $table  = self::getTable($itemtype);
        $fields = [
            "$table.id", "$table.serial", "$table.groups_id", "$table.states_id", "$table.entities_id",
            'glpi_manufacturers.name AS manufacturer', 'glpi_states.completename AS state_name',
        ];
        if ($DB->fieldExists($table, 'name')) {
            $fields[] = "$table.name";
        }

        $criteria = [
            'SELECT'    => $fields,
            'FROM'      => $table,
            'LEFT JOIN' => [
                'glpi_manufacturers' => ['ON' => [$table => 'manufacturers_id', 'glpi_manufacturers' => 'id']],
                'glpi_states'        => ['ON' => [$table => 'states_id', 'glpi_states' => 'id']],
            ],
        ];

        $type = self::types()[$itemtype];
        if ($type['model_join'] === null) {
            $criteria['SELECT'][] = "$table.model";
        } else {
            [$model_table, $model_key] = $type['model_join'];
            $criteria['SELECT'][] = "$model_table.name AS model";
            $criteria['LEFT JOIN'][$model_table] = ['ON' => [$table => $model_key, $model_table => 'id']];
        }
        $criteria['ORDER'] = "$table." . $type['order'];

        return $criteria;
    }
}

<?php

namespace GlpiPlugin\Checklistitens;

use Glpi\Toolbox\Sanitizer;
use Plugin;
use Session;

/**
 * Leitura dos laudos técnicos do plugin Laudo (pasta "laudo"), só para consulta: o checklist
 * nunca grava nada nas tabelas do Laudo.
 *
 * Um laudo está "em aberto" quando não está na lixeira e a situação dele não é uma das
 * configuradas como concluídas (padrão: a situação de nome "Concluído"). Laudo sem situação conta
 * como aberto.
 */
class LaudoProvider
{
    public const PLUGIN_KEY = 'laudo';

    public const TABLE_LAUDOS    = 'glpi_plugin_laudo_laudos';
    public const TABLE_STATUSES  = 'glpi_plugin_laudo_laudostatuses';
    public const TABLE_ITEMS     = 'glpi_plugin_laudo_laudoitems';
    public const TABLE_ITEMTYPES = 'glpi_plugin_laudo_itemtypes';

    /** Direito do plugin Laudo para abrir o formulário do laudo (link só para quem tem). */
    public const RIGHT_LAUDO = 'plugin_laudo_laudo';

    /** Nome da situação que, por padrão, conta como concluída. */
    public const DEFAULT_DONE_STATUS = 'Concluído';

    /** Plugin Laudo ativo e com as tabelas que o checklist lê. */
    public static function isAvailable(): bool
    {
        global $DB;

        static $available = null;
        if ($available === null) {
            $available = Plugin::isPluginActive(self::PLUGIN_KEY)
                && $DB->tableExists(self::TABLE_LAUDOS)
                && $DB->tableExists(self::TABLE_STATUSES);
        }

        return $available;
    }

    /** Integração ligada na configuração e plugin Laudo disponível. */
    public static function isEnabled(): bool
    {
        return Config::isLaudoEnabled() && self::isAvailable();
    }

    /**
     * Situações cadastradas no Laudo, para a tela de configuração.
     *
     * @return array<int, string> id => nome
     */
    public static function getStatusOptions(): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE_STATUSES)) {
            return [];
        }

        $options = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => self::TABLE_STATUSES, 'ORDER' => 'id']) as $row) {
            $options[(int) $row['id']] = (string) $row['name'];
        }

        return $options;
    }

    /**
     * Ids das situações de nome "Concluído" (padrão das situações concluídas).
     *
     * @return int[]
     */
    public static function findDefaultDoneStatuses(): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE_STATUSES)) {
            return [];
        }

        return Config::findIdsByName(self::TABLE_STATUSES, 'name', [self::DEFAULT_DONE_STATUS]);
    }

    /**
     * Laudos em aberto de vários equipamentos do mesmo tipo, mais recente primeiro. Não restringe
     * entidade (o laudo pode ter sido aberto por outro setor); laudo sem ativo não entra.
     *
     * Cada laudo vem no formato de exibição: id, name, status, date (datetime), occurrence,
     * items e url (null sem o direito de abrir o laudo). Textos já em texto puro.
     *
     * @param int[] $items_ids
     *
     * @return array<int, array<int, array>> items_id => laudos
     */
    public static function getOpenFor(string $itemtype, array $items_ids): array
    {
        global $DB;

        $items_ids = array_values(array_unique(array_filter(array_map('intval', $items_ids), fn ($id) => $id > 0)));
        if (!count($items_ids) || !preg_match('/^[A-Za-z0-9_\\\\]+$/', $itemtype) || !self::isEnabled()) {
            return [];
        }

        // O Laudo pode ser de outra versão: sem estas colunas não há como saber se está em aberto
        $laudos = self::TABLE_LAUDOS;
        foreach (['itemtype', 'items_id', 'plugin_laudo_laudostatuses_id'] as $field) {
            if (!$DB->fieldExists($laudos, $field)) {
                return [];
            }
        }

        $select = [
            "$laudos.id",
            "$laudos.items_id",
            "$laudos.plugin_laudo_laudostatuses_id",
            self::TABLE_STATUSES . '.name AS status_name',
        ];
        foreach (['name', 'date_start', 'date_creation', 'occurrence_description'] as $field) {
            if ($DB->fieldExists($laudos, $field)) {
                $select[] = "$laudos.$field";
            }
        }

        $where = [
            // nome de classe vem do código; o escape cobre a barra de classes com namespace
            "$laudos.itemtype" => Sanitizer::dbEscape($itemtype),
            "$laudos.items_id" => $items_ids,
        ];
        if ($DB->fieldExists($laudos, 'is_deleted')) {
            $where["$laudos.is_deleted"] = 0;
        }
        // Situação 0 (sem situação) nunca está na lista, então conta como aberto
        $done = Config::getLaudoDoneStatuses();
        if (count($done)) {
            $where['NOT'] = ["$laudos.plugin_laudo_laudostatuses_id" => $done];
        }

        $rows = [];
        $iterator = $DB->request([
            'SELECT'    => $select,
            'FROM'      => $laudos,
            'LEFT JOIN' => [
                self::TABLE_STATUSES => [
                    'ON' => [self::TABLE_STATUSES => 'id', $laudos => 'plugin_laudo_laudostatuses_id'],
                ],
            ],
            'WHERE'     => $where,
            'ORDER'     => ["$laudos.id DESC"],
        ]);
        foreach ($iterator as $row) {
            $rows[(int) $row['id']] = $row;
        }
        if (!count($rows)) {
            return [];
        }

        $affected = self::getAffectedItems(array_keys($rows));

        $result = [];
        foreach ($rows as $id => $row) {
            $result[(int) $row['items_id']][] = [
                'id'         => $id,
                'name'       => self::laudoName($id, $row['name'] ?? ''),
                'status'     => self::statusName((int) $row['plugin_laudo_laudostatuses_id'], $row['status_name'] ?? ''),
                'date'       => self::validDate($row['date_start'] ?? null) ?? self::validDate($row['date_creation'] ?? null) ?? '',
                'occurrence' => self::excerpt(self::plain($row['occurrence_description'] ?? ''), 160),
                'items'      => $affected[$id] ?? '',
                'url'        => self::getLaudoUrl($id),
            ];
        }

        // Mais recente primeiro (pela data do laudo; empate pelo id, que já vem decrescente)
        foreach ($result as &$list) {
            usort($list, fn ($a, $b) => strcmp($b['date'], $a['date']));
        }
        unset($list);

        return $result;
    }

    /**
     * @return array<int, array> laudos em aberto do equipamento (formato de getOpenFor)
     */
    public static function getOpenForItem(string $itemtype, int $items_id): array
    {
        return self::getOpenFor($itemtype, [$items_id])[$items_id] ?? [];
    }

    /** Link para o formulário do laudo, só para quem pode ver laudos. */
    public static function getLaudoUrl(int $laudos_id): ?string
    {
        if (
            $laudos_id <= 0
            || !self::isAvailable()
            || !Session::haveRight(self::RIGHT_LAUDO, READ)
            || !class_exists('GlpiPlugin\\Laudo\\Laudo')
        ) {
            return null;
        }

        return \GlpiPlugin\Laudo\Laudo::getFormURLWithID($laudos_id);
    }

    /**
     * Resumo de até 255 caracteres para a cópia no uso: os itens afetados ou, sem eles, o começo
     * da ocorrência.
     */
    public static function summarize(array $laudo): string
    {
        $text = trim((string) ($laudo['items'] ?? ''));
        if ($text === '') {
            $text = trim((string) ($laudo['occurrence'] ?? ''));
        }

        return self::excerpt($text, 255);
    }

    /**
     * Itens afetados de cada laudo ("1x Tela, 2x Carregador"), em uma consulta.
     *
     * @param int[] $laudos_ids
     *
     * @return array<int, string> laudos_id => texto
     */
    private static function getAffectedItems(array $laudos_ids): array
    {
        global $DB;

        if (
            !count($laudos_ids)
            || !$DB->tableExists(self::TABLE_ITEMS)
            || !$DB->tableExists(self::TABLE_ITEMTYPES)
            || !$DB->fieldExists(self::TABLE_ITEMS, 'plugin_laudo_laudos_id')
            || !$DB->fieldExists(self::TABLE_ITEMS, 'plugin_laudo_itemtypes_id')
        ) {
            return [];
        }

        $items  = self::TABLE_ITEMS;
        $types  = self::TABLE_ITEMTYPES;
        $select = ["$items.plugin_laudo_laudos_id", "$types.name AS type_name"];
        $has_quantity = $DB->fieldExists($items, 'quantity');
        if ($has_quantity) {
            $select[] = "$items.quantity";
        }

        $parts = [];
        $iterator = $DB->request([
            'SELECT'    => $select,
            'FROM'      => $items,
            'LEFT JOIN' => [$types => ['ON' => [$types => 'id', $items => 'plugin_laudo_itemtypes_id']]],
            'WHERE'     => ["$items.plugin_laudo_laudos_id" => array_values($laudos_ids)],
            'ORDER'     => ["$items.id"],
        ]);
        foreach ($iterator as $row) {
            $name = self::plain($row['type_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $quantity = $has_quantity ? max(1, (int) $row['quantity']) : 1;
            $parts[(int) $row['plugin_laudo_laudos_id']][] = $quantity . 'x ' . $name;
        }

        return array_map(fn ($list) => implode(', ', $list), $parts);
    }

    private static function laudoName(int $id, $name): string
    {
        $name = self::plain($name);

        return $name !== '' ? $name : sprintf(__('Laudo %d', 'checklistitens'), $id);
    }

    private static function statusName(int $statuses_id, $name): string
    {
        $name = $statuses_id > 0 ? self::plain($name) : '';

        return $name !== '' ? $name : __('Sem situação', 'checklistitens');
    }

    /** Data/hora do banco, ou null se vazia ou zerada. */
    private static function validDate($value): ?string
    {
        $value = trim((string) $value);

        return ($value === '' || substr($value, 0, 4) === '0000') ? null : $value;
    }

    /** Texto do banco (com HTML codificado) para uma linha de texto puro. */
    private static function plain($value): string
    {
        $text = Ui::text($value);
        // Tags só se o campo for rich text em outra versão do Laudo (texto como "<20%" fica)
        if (preg_match('/<\/?[a-z][a-z0-9]*[\s>\/]/i', $text)) {
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /** Corta o texto em até $max caracteres, de preferência no fim de uma palavra. */
    private static function excerpt(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut   = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,;.-") . '…';
    }
}

<?php

namespace GlpiPlugin\Checklistitens;

use Glpi\Toolbox\Sanitizer;
use Plugin;
use Session;
use Throwable;
use Toolbox;

/**
 * Laudos técnicos do plugin Laudo (pasta "laudo"): leitura dos laudos em aberto e criação do laudo
 * inicial junto com o chamado do item bloqueado. A criação passa pela classe do próprio Laudo
 * (valem a numeração por setor, as validações e o histórico dele); o checklist não grava direto
 * nas tabelas do Laudo.
 *
 * Um laudo está "em aberto" quando não está na lixeira e a situação dele não é uma das
 * configuradas como concluídas (padrão: a situação de nome "Concluído"). Laudo sem situação conta
 * como aberto.
 */
class LaudoProvider
{
    public const PLUGIN_KEY = 'laudo';

    private const LAUDO_CLASS = 'GlpiPlugin\\Laudo\\Laudo';

    public const TABLE_LAUDOS    = 'glpi_plugin_laudo_laudos';
    public const TABLE_STATUSES  = 'glpi_plugin_laudo_laudostatuses';
    public const TABLE_ITEMS     = 'glpi_plugin_laudo_laudoitems';
    public const TABLE_ITEMTYPES = 'glpi_plugin_laudo_itemtypes';

    /** Direito do plugin Laudo para abrir o formulário do laudo (link só para quem tem). */
    public const RIGHT_LAUDO = 'plugin_laudo_laudo';

    /** Nome da situação que, por padrão, conta como concluída. */
    public const DEFAULT_DONE_STATUS = 'Concluído';

    /** Nome da situação que, por padrão, o laudo criado junto com o chamado recebe. */
    public const DEFAULT_INITIAL_STATUS = 'Aberto';

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

    /**
     * "É o problema do laudo" só vale se o laudo ainda estiver em aberto agora; senão, o problema é
     * novo e bloqueia o item.
     *
     * @param array $open_laudos laudos em aberto do item agora (getOpenForItem/getOpenFor)
     */
    public static function acceptsKnown(bool $said_known, array $open_laudos): bool
    {
        return $said_known && count($open_laudos) > 0;
    }

    public static function closedMessage(): string
    {
        return __('O laudo deste equipamento foi concluído: o problema bloqueia o item.', 'checklistitens');
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
        return self::findStatuses(self::DEFAULT_DONE_STATUS);
    }

    /** Id da situação de nome "Aberto" (padrão do laudo inicial), ou 0. */
    public static function findDefaultInitialStatus(): int
    {
        return self::findStatuses(self::DEFAULT_INITIAL_STATUS)[0] ?? 0;
    }

    /** @return int[] */
    private static function findStatuses(string $name): array
    {
        global $DB;

        if (!$DB->tableExists(self::TABLE_STATUSES)) {
            return [];
        }

        return Config::findIdsByName(self::TABLE_STATUSES, 'name', [$name]);
    }

    /**
     * Laudo inicial do equipamento bloqueado, criado junto com o chamado: setor e equipamento do
     * bloqueio, a situação da configuração, o chamado, o colaborador como usuário responsável, a
     * data de hoje e o relato do problema na descrição da ocorrência. O resto fica para o TI.
     *
     * @return array{id: int, name: string, url: string}|null null quando não cria (integração
     *         desligada, sem situação inicial) ou não consegue (o motivo vai para o log)
     */
    public static function createInitial(ItemBlock $block, int $users_id, int $tickets_id, string $occurrence): ?array
    {
        $status = Config::getLaudoInitialStatus();
        if ($status <= 0 || !self::isEnabled() || !class_exists(self::LAUDO_CLASS)) {
            return null;
        }

        $input = [
            'entities_id'                   => (int) $block->fields['entities_id'],
            'groups_id'                     => (int) $block->fields['groups_id'],
            'plugin_laudo_laudostatuses_id' => $status,
            'itemtype'                      => (string) $block->fields['itemtype'],
            'items_id'                      => (int) $block->fields['items_id'],
            'users_id'                      => $users_id,
            'date_start'                    => substr(Shift::now(), 0, 10),
            'tickets_id'                    => $tickets_id,
            'ticket_reference'              => (string) $tickets_id,
            'occurrence_description'        => $occurrence,
        ];
        // Custos ainda sem valor ("?"), como no formulário de um laudo novo: o Laudo grava
        // "valor confirmado" quando a marca de pendente não vem
        $cost_fields = self::LAUDO_CLASS . '::COST_FIELDS';
        foreach (defined($cost_fields) ? constant($cost_fields) : [] as $field) {
            $input[$field . '_pending'] = 1;
        }

        $class = self::LAUDO_CLASS;
        $laudo = new $class();
        try {
            $id = (int) $laudo->add(Sanitizer::sanitize($input));
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: erro ao criar o laudo do chamado ' . $tickets_id . ': ' . $e->getMessage());
            return null;
        }
        if ($id <= 0) {
            Toolbox::logError('checklistitens: o plugin Laudo recusou o laudo do chamado ' . $tickets_id . ' (setor e situação preenchidos?)');
            return null;
        }

        return [
            'id'   => $id,
            'name' => self::laudoName($id, $laudo->fields['name'] ?? ''),
            'url'  => $class::getFormURLWithID($id),
        ];
    }

    /**
     * Laudos em aberto de vários equipamentos do mesmo tipo, mais recente primeiro. Não restringe
     * entidade (o laudo pode ter sido aberto por outro setor); laudo sem ativo não entra.
     *
     * Cada laudo vem no formato de exibição: id, name, status, date (datetime), occurrence
     * (descrição da ocorrência), resolution (observações do status final), items e url (null sem
     * o direito de abrir o laudo). Textos já em texto puro, numa linha só.
     *
     * @param int[] $items_ids
     *
     * @return array<int, array<int, array>> items_id => laudos
     */
    public static function getOpenFor(string $itemtype, array $items_ids): array
    {
        $items_ids = self::positiveIds($items_ids);
        if (!count($items_ids) || !preg_match('/^[A-Za-z0-9_\\\\]+$/', $itemtype) || !self::isEnabled()) {
            return [];
        }

        $laudos = self::TABLE_LAUDOS;
        $where  = [
            // nome de classe vem do código; o escape cobre a barra de classes com namespace
            "$laudos.itemtype" => Sanitizer::dbEscape($itemtype),
            "$laudos.items_id" => $items_ids,
        ];
        // Situação 0 (sem situação) nunca está na lista, então conta como aberto
        $done = Config::getLaudoDoneStatuses();
        if (count($done)) {
            $where['NOT'] = ["$laudos.plugin_laudo_laudostatuses_id" => $done];
        }

        $result = self::findGrouped($where, ['itemtype', 'items_id'], 'items_id');

        // Mais recente primeiro (pela data do laudo; empate pelo id, que já vem decrescente)
        foreach ($result as &$list) {
            usort($list, fn ($a, $b) => strcmp($b['date'], $a['date']));
        }
        unset($list);

        return $result;
    }

    /**
     * Laudos de cada bloqueio, para a tela de itens bloqueados: o laudo criado junto com o chamado
     * do bloqueio (em qualquer situação) e, no bloqueio ativo, também os laudos em aberto do
     * equipamento. Formato de getOpenFor.
     *
     * @param array[] $blocks linhas de ItemBlock::present
     *
     * @return array<int, array[]> id do bloqueio => laudos
     */
    public static function getForBlocks(array $blocks): array
    {
        if (!count($blocks) || !self::isEnabled()) {
            return [];
        }

        $by_ticket = self::findGrouped(
            [self::TABLE_LAUDOS . '.tickets_id' => self::positiveIds(array_column($blocks, 'ticket_id'))],
            ['tickets_id'],
            'tickets_id'
        );

        $active_ids = [];
        foreach ($blocks as $block) {
            if ($block['active']) {
                $active_ids[$block['itemtype']][] = $block['items_id'];
            }
        }
        $open = [];
        foreach ($active_ids as $itemtype => $ids) {
            $open[$itemtype] = self::getOpenFor($itemtype, $ids);
        }

        $result = [];
        foreach ($blocks as $block) {
            $list = $by_ticket[$block['ticket_id']] ?? [];
            if ($block['active']) {
                $list = array_merge($list, $open[$block['itemtype']][$block['items_id']] ?? []);
            }
            $unique = [];
            foreach ($list as $laudo) {
                $unique[$laudo['id']] = $unique[$laudo['id']] ?? $laudo;
            }
            $result[$block['id']] = array_values($unique);
        }

        return $result;
    }

    /**
     * Laudos fora da lixeira que atendem a $where, no formato de getOpenFor, agrupados pelo valor
     * da coluna $group, por id decrescente. Vazio quando a versão instalada do Laudo não tem as
     * colunas usadas.
     *
     * @param string[] $columns colunas do laudo usadas em $where
     *
     * @return array<int, array[]> valor de $group => laudos
     */
    private static function findGrouped(array $where, array $columns, string $group): array
    {
        global $DB;

        $laudos = self::TABLE_LAUDOS;
        foreach (array_merge($columns, [$group, 'plugin_laudo_laudostatuses_id']) as $field) {
            if (!$DB->fieldExists($laudos, $field)) {
                return [];
            }
        }
        // Lista de ids vazia: nenhum laudo (o IN vazio não é aceito na consulta)
        foreach ($where as $value) {
            if (is_array($value) && !count($value)) {
                return [];
            }
        }

        $select = [
            "$laudos.id",
            "$laudos.$group",
            "$laudos.plugin_laudo_laudostatuses_id",
            self::TABLE_STATUSES . '.name AS status_name',
        ];
        foreach (['name', 'date_start', 'date_creation', 'occurrence_description', 'resolution_complement'] as $field) {
            if ($DB->fieldExists($laudos, $field)) {
                $select[] = "$laudos.$field";
            }
        }
        if ($DB->fieldExists($laudos, 'is_deleted')) {
            $where["$laudos.is_deleted"] = 0;
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
            $result[(int) $row[$group]][] = [
                'id'         => $id,
                'name'       => self::laudoName($id, $row['name'] ?? ''),
                'status'     => self::statusName((int) $row['plugin_laudo_laudostatuses_id'], $row['status_name'] ?? ''),
                'date'       => self::validDate($row['date_start'] ?? null) ?? self::validDate($row['date_creation'] ?? null) ?? '',
                'occurrence' => self::excerpt(self::plain($row['occurrence_description'] ?? '', ' · '), 160),
                'resolution' => self::excerpt(self::plain($row['resolution_complement'] ?? '', ' · '), 160),
                'items'      => $affected[$id] ?? '',
                'url'        => self::getLaudoUrl($id),
            ];
        }

        return $result;
    }

    /**
     * @param array $ids
     *
     * @return int[] ids maiores que zero, sem repetição
     */
    private static function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
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
            || !class_exists(self::LAUDO_CLASS)
        ) {
            return null;
        }

        $class = self::LAUDO_CLASS;

        return $class::getFormURLWithID($laudos_id);
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

    /**
     * Texto do banco (com HTML codificado) para uma linha de texto puro. As quebras de linha viram
     * $line_break (no texto de várias linhas, " · " separa as linhas).
     */
    private static function plain($value, string $line_break = ' '): string
    {
        $text = Ui::text($value);
        // Tags só se o campo for rich text em outra versão do Laudo (texto como "<20%" fica)
        if (preg_match('/<\/?[a-z][a-z0-9]*[\s>\/]/i', $text)) {
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $lines = array_map(
            static fn ($line) => trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $line)),
            preg_split('/\R/u', $text) ?: [$text]
        );

        return implode($line_break, array_filter($lines, static fn ($line) => $line !== ''));
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

        return preg_replace('/[\s,;.·-]+$/u', '', $cut) . '…';
    }
}

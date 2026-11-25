<?php

namespace GlpiPlugin\Checklistitens;

use CommonGLPI;
use Html;
use Phone;
use QueryExpression;

/**
 * Registro de uso do equipamento: situação atual de cada rádio/telefone e a linha do tempo
 * completa de um equipamento (retiradas, conferências, devoluções, recusas, bloqueios e chamados).
 * O ativo não muda de usuário: tudo vem dos registros do plugin.
 */
class EquipmentRecord extends CommonGLPI
{
    public const SITUATION_AVAILABLE = 'available';
    public const SITUATION_IN_USE    = 'in_use';
    public const SITUATION_RELEASED  = 'released';
    public const SITUATION_BLOCKED   = 'blocked';
    public const SITUATION_REPAIR    = 'repair';
    public const SITUATION_OUT       = 'out';
    // Entrega de rádios para embarcação
    public const SITUATION_DELIVERY_DRAFT = 'delivery_draft';
    public const SITUATION_ON_VESSEL      = 'on_vessel';

    public static $rightname = Profile::RIGHT_MANAGER;

    public static function getTypeName($nb = 0)
    {
        return __('Registro de uso', 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-history';
    }

    public static function canView()
    {
        return Profile::canViewRecords();
    }

    /** @return array<string, array{label: string, color: string}> */
    public static function getSituations(): array
    {
        return [
            self::SITUATION_AVAILABLE => ['label' => __('Disponível', 'checklistitens'), 'color' => 'green'],
            self::SITUATION_IN_USE    => ['label' => __('Em uso', 'checklistitens'), 'color' => 'blue'],
            self::SITUATION_RELEASED  => ['label' => __('Liberado para devolução', 'checklistitens'), 'color' => 'cyan'],
            self::SITUATION_DELIVERY_DRAFT => ['label' => __('Em separação para embarcação', 'checklistitens'), 'color' => 'yellow'],
            self::SITUATION_ON_VESSEL      => ['label' => __('Com a embarcação', 'checklistitens'), 'color' => 'azure'],
            self::SITUATION_BLOCKED   => ['label' => __('Bloqueado, aguardando conferência', 'checklistitens'), 'color' => 'orange'],
            self::SITUATION_REPAIR    => ['label' => __('Em reparo (chamado aberto)', 'checklistitens'), 'color' => 'red'],
            self::SITUATION_OUT       => ['label' => __('Fora de uso (estado)', 'checklistitens'), 'color' => 'secondary'],
        ];
    }

    /**
     * Gestor vê equipamentos do seu setor (grupo atual do item ou algum uso no setor); TI vê todos.
     */
    public static function canViewItem(string $itemtype, int $items_id): bool
    {
        if (Profile::isAdmin()) {
            return true;
        }
        if (!Profile::isManager()) {
            return false;
        }

        $scope = Sector::forCurrentUser();
        if (!count($scope)) {
            return false;
        }

        $row = ItemProvider::getRow($itemtype, $items_id);
        if ($row !== null && in_array((int) $row['groups_id'], $scope, true)) {
            return true;
        }

        return countElementsInTable(Usage::getTable(), ['itemtype' => $itemtype, 'items_id' => $items_id, 'groups_id' => $scope]) > 0;
    }

    // ------------------------------------------------------------------ aba no Telefone

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Phone && $item->getID() > 0 && self::canViewItem(ItemProvider::PHONE, (int) $item->getID())) {
            $count = countElementsInTable(Usage::getTable(), ['itemtype' => ItemProvider::PHONE, 'items_id' => $item->getID()]);

            return self::createTabEntry(self::getTypeName(), $count);
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Phone) {
            echo Ui::css();
            Ui::render('equipment_record.html.twig', self::getRecord(ItemProvider::PHONE, (int) $item->getID(), []) + [
                'embedded'   => true,
                'page_url'   => Ui::url('front/equipment.form.php'),
            ]);
        }

        return true;
    }

    // ------------------------------------------------------------------ lista de equipamentos

    /**
     * @param array{itemtype?: string, groups_id?: int, situation?: string, q?: string} $filters
     */
    public static function getList(array $filters): array
    {
        $types = Config::getEnabledTypes();
        if (!empty($filters['itemtype']) && in_array($filters['itemtype'], $types, true)) {
            $types = [$filters['itemtype']];
        }

        $groups = Profile::isAdmin() ? null : Sector::forCurrentUser();
        if (!empty($filters['groups_id'])) {
            $chosen = Sector::expand([(int) $filters['groups_id']]);
            $groups = $groups === null ? $chosen : array_values(array_intersect($groups, $chosen));
        }

        $situations = self::getSituations();
        $states     = Config::getAvailableStates();
        $query      = mb_strtolower(trim(Ui::text($filters['q'] ?? '')));
        $list       = [];

        foreach ($types as $itemtype) {
            $open     = self::getOpenUsages($itemtype);
            $blocks   = self::getActiveBlocks($itemtype);
            $tickets  = self::countTickets($itemtype);
            $problems = self::getLastProblems($itemtype);
            $rows     = ItemProvider::getItems($itemtype, $groups);
            // Laudos em aberto (plugin Laudo) de todos os itens da lista, numa consulta só
            $laudos   = LaudoProvider::getOpenFor($itemtype, array_column($rows, 'id'));
            // Embarcação de cada entrega com rádio preso (em separação ou com a embarcação)
            $vessels  = self::getVesselNames(array_column($open, 'plugin_checklistitens_deliveries_id'));

            foreach ($rows as $row) {
                $id   = (int) $row['id'];
                $item = ItemProvider::present($itemtype, $row);

                $situation = self::situationOf($blocks[$id] ?? null, $open[$id] ?? null, $row, $states);
                $holder    = '';
                if (!isset($blocks[$id]) && isset($open[$id])) {
                    $vessel = $vessels[(int) $open[$id]['plugin_checklistitens_deliveries_id']] ?? '';
                    $holder = sprintf(
                        __('%1$s desde %2$s', 'checklistitens'),
                        $vessel !== '' ? $vessel : Ui::text(getUserName((int) $open[$id]['users_id'])),
                        Ui::datetime($open[$id]['date_checkout'])
                    );
                }

                if (!empty($filters['situation']) && $filters['situation'] !== $situation) {
                    continue;
                }
                if ($query !== '' && mb_strpos(mb_strtolower($item['label'] . ' ' . $item['detail']), $query) === false) {
                    continue;
                }

                $list[] = $item + [
                    'itemtype'        => $itemtype,
                    'type_label'      => ItemProvider::getTypeLabel($itemtype),
                    'icon'            => ItemProvider::getTypeIcon($itemtype),
                    'sector'          => Sector::getName((int) $row['groups_id']),
                    'state'           => Ui::text($row['state_name'] ?? ''),
                    'situation'       => $situation,
                    'situation_label' => $situations[$situation]['label'],
                    'situation_color' => $situations[$situation]['color'],
                    'holder'          => $holder,
                    'tickets'         => $tickets[$id] ?? 0,
                    'last_problem'    => $problems[$id] ?? '',
                    'laudos'          => $laudos[$id] ?? [],
                    'url'             => self::getUrl($itemtype, $id),
                ];
            }
        }

        return $list;
    }

    public static function getUrl(string $itemtype, int $items_id): string
    {
        return Ui::url(sprintf('front/equipment.form.php?itemtype=%s&items_id=%d', urlencode($itemtype), $items_id));
    }

    /**
     * Situação do equipamento: bloqueado (aguardando conferência ou em reparo), preso por um uso
     * aberto (retirada comum ou entrega para embarcação), fora de uso pelo estado ou disponível.
     *
     * @param array|null $block    bloqueio ativo
     * @param array|null $open     uso aberto
     * @param array|null $item_row linha do ativo (estado)
     * @param int[]      $states   estados disponíveis para retirada
     */
    private static function situationOf(?array $block, ?array $open, ?array $item_row, array $states): string
    {
        if ($block !== null) {
            return (int) $block['status'] === ItemBlock::STATUS_REPAIR ? self::SITUATION_REPAIR : self::SITUATION_BLOCKED;
        }
        if ($open === null) {
            return $item_row !== null && !in_array((int) $item_row['states_id'], $states, true)
                ? self::SITUATION_OUT
                : self::SITUATION_AVAILABLE;
        }

        switch ((int) $open['status']) {
            case Usage::STATUS_ON_VESSEL:
                return self::SITUATION_ON_VESSEL;
            case Usage::STATUS_DELIVERY_DRAFT:
                return self::SITUATION_DELIVERY_DRAFT;
            case Usage::STATUS_RELEASED:
                return self::SITUATION_RELEASED;
        }

        return self::SITUATION_IN_USE;
    }

    /**
     * @param array<int|string> $delivery_ids
     * @return array<int, string> id da entrega => nome da embarcação
     */
    private static function getVesselNames(array $delivery_ids): array
    {
        $names = [];
        foreach (self::loadById(Delivery::getTable(), array_filter($delivery_ids)) as $id => $row) {
            $names[$id] = Ui::text($row['vessel_name']);
        }

        return $names;
    }

    /** @return array<int, array> items_id => uso aberto */
    private static function getOpenUsages(string $itemtype): array
    {
        global $DB;

        $map = [];
        foreach ($DB->request(['FROM' => Usage::getTable(), 'WHERE' => ['itemtype' => $itemtype, 'lock_open' => 1]]) as $row) {
            $map[(int) $row['items_id']] = $row;
        }

        return $map;
    }

    /** @return array<int, array> items_id => bloqueio ativo */
    private static function getActiveBlocks(string $itemtype): array
    {
        global $DB;

        $map = [];
        foreach ($DB->request(['FROM' => ItemBlock::getTable(), 'WHERE' => ['itemtype' => $itemtype, 'lock_active' => 1]]) as $row) {
            $map[(int) $row['items_id']] = $row;
        }

        return $map;
    }

    /** @return array<int, int> items_id => chamados abertos pelo plugin */
    private static function countTickets(string $itemtype): array
    {
        global $DB;

        $map = [];
        $iterator = $DB->request([
            'SELECT'  => ['items_id', new QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('tickets_id') . ') AS ' . $DB->quoteName('total'))],
            'FROM'    => ItemBlock::getTable(),
            'WHERE'   => ['itemtype' => $itemtype, 'tickets_id' => ['>', 0]],
            'GROUPBY' => 'items_id',
        ]);
        foreach ($iterator as $row) {
            $map[(int) $row['items_id']] = (int) $row['total'];
        }

        return $map;
    }

    /** @return array<int, string> items_id => último problema marcado */
    private static function getLastProblems(string $itemtype): array
    {
        global $DB;

        $problems = UsageProblem::getTable();
        $usages   = Usage::getTable();

        $last = [];
        $iterator = $DB->request([
            'SELECT'     => ["$usages.items_id", new QueryExpression('MAX(' . $DB->quoteName("$problems.id") . ') AS ' . $DB->quoteName('last_id'))],
            'FROM'       => $problems,
            'INNER JOIN' => [$usages => ['ON' => [$problems => 'plugin_checklistitens_usages_id', $usages => 'id']]],
            'WHERE'      => ["$usages.itemtype" => $itemtype],
            'GROUPBY'    => "$usages.items_id",
        ]);
        foreach ($iterator as $row) {
            $last[(int) $row['last_id']] = (int) $row['items_id'];
        }
        if (!count($last)) {
            return [];
        }

        $map = [];
        foreach ($DB->request(['SELECT' => ['id', 'problem_name'], 'FROM' => $problems, 'WHERE' => ['id' => array_keys($last)]]) as $row) {
            $map[$last[(int) $row['id']]] = Ui::text($row['problem_name']);
        }

        return $map;
    }

    // ------------------------------------------------------------------ registro de um equipamento

    /**
     * Cabeçalho, indicadores e linha do tempo de um equipamento.
     *
     * @param array{date_from?: string, date_to?: string, users_id?: int} $filters
     */
    public static function getRecord(string $itemtype, int $items_id, array $filters): array
    {
        global $DB;

        $row  = ItemProvider::getRow($itemtype, $items_id);
        $item = $row !== null ? ItemProvider::present($itemtype, $row) : ['id' => $items_id, 'label' => '#' . $items_id, 'detail' => '', 'groups_id' => 0];

        foreach (['date_from', 'date_to'] as $key) {
            $filters[$key] = Ui::date($filters[$key] ?? '');
        }

        // Separação de entrega cancelada não chegou a ser um uso: fica só no detalhe da entrega
        $where = ['itemtype' => $itemtype, 'items_id' => $items_id, ['NOT' => ['status' => Usage::STATUS_DELIVERY_CANCELLED]]];
        if ($filters['date_from'] !== '') {
            $where[] = ['date_checkout' => ['>=', $filters['date_from'] . ' 00:00:00']];
        }
        if ($filters['date_to'] !== '') {
            $where[] = ['date_checkout' => ['<=', $filters['date_to'] . ' 23:59:59']];
        }
        if (!empty($filters['users_id'])) {
            $where['users_id'] = (int) $filters['users_id'];
        }

        $usages = iterator_to_array($DB->request(['FROM' => Usage::getTable(), 'WHERE' => $where, 'ORDER' => 'date_checkout DESC']), false);
        $ids    = array_map(static fn ($u) => (int) $u['id'], $usages);

        // Carrega em lote conferências, bloqueios e problemas dos usos listados
        $confirmation_ids = [];
        foreach ($usages as $u) {
            $confirmation_ids[] = (int) $u['plugin_checklistitens_confirmations_id'];
            $confirmation_ids[] = (int) $u['plugin_checklistitens_confirmations_id_checkin'];
        }
        $confirmations = self::loadById(Confirmation::getTable(), array_filter($confirmation_ids));
        $view          = new UsageView($usages);
        $notes         = UsageNote::getForUsages($ids);

        // Entregas para embarcação e recebimentos ligados aos usos (selfie e localização vêm deles)
        $deliveries = self::loadById(Delivery::getTable(), array_filter(array_column($usages, 'plugin_checklistitens_deliveries_id')));
        $receipts   = self::loadById(DeliveryReceipt::getTable(), array_filter(array_column($usages, 'plugin_checklistitens_deliveryreceipts_id')));

        $timeline = [];
        $stats    = ['uses' => 0, 'refusals' => 0, 'with_problem' => 0, 'known_issue' => 0, 'blocks' => 0, 'tickets' => [], 'repair_seconds' => 0, 'last_use' => ''];
        $users    = [];
        $now      = strtotime(Shift::now());

        foreach ($usages as $u) {
            $id     = (int) $u['id'];
            $status = (int) $u['status'];
            $users[(int) $u['users_id']] = Ui::text(getUserName((int) $u['users_id']));

            if ($status === Usage::STATUS_REFUSED) {
                $stats['refusals']++;
            } else {
                $stats['uses']++;
                if ($stats['last_use'] === '') {
                    $stats['last_use'] = Ui::datetime($u['date_checkout']);
                }
            }
            $known_out = (int) ($u['checkout_known_issue'] ?? 0) === 1;
            $known_in  = (int) ($u['checkin_known_issue'] ?? 0) === 1;
            if ($u['checkin_is_ok'] !== null && (int) $u['checkin_is_ok'] === 0 && !$known_in) {
                $stats['with_problem']++;
            }
            if ($known_out || $known_in) {
                $stats['known_issue']++;
            }

            $block = null;
            $b     = $view->block($u);
            if ($b !== null) {
                $stats['blocks']++;
                if ((int) $b['tickets_id'] > 0) {
                    $stats['tickets'][(int) $b['tickets_id']] = true;
                }
                $end = $b['date_release'] ? strtotime($b['date_release']) : $now;
                $stats['repair_seconds'] += max(0, $end - strtotime($b['date_block']));
                $block = ItemBlock::present($b);
            }

            $duration = '';
            if ($u['date_checkin']) {
                $duration = Html::timestampToString(max(0, strtotime($u['date_checkin']) - strtotime($u['date_checkout'])), false);
            }

            // Uso que faz parte de uma entrega para embarcação: a selfie e a localização são as
            // do gestor, guardadas na entrega e no recebimento
            $delivery = $deliveries[(int) $u['plugin_checklistitens_deliveries_id']] ?? null;
            $receipt  = $receipts[(int) $u['plugin_checklistitens_deliveryreceipts_id']] ?? null;

            $checkout_selfie   = Selfie::info(Selfie::KIND_CHECKOUT, $u);
            $checkout_location = Selfie::location(Selfie::KIND_CHECKOUT, $u, __('Retirada', 'checklistitens') . ' — ' . $item['label']);
            if ($delivery !== null) {
                $checkout_selfie   = Selfie::info(Selfie::KIND_DELIVERY, $delivery);
                $checkout_location = Selfie::location(Selfie::KIND_DELIVERY, $delivery, __('Entrega', 'checklistitens') . ' — ' . Ui::text($delivery['vessel_name']));
            }
            $checkin_selfie   = Selfie::info(Selfie::KIND_CHECKIN, $u);
            $checkin_location = Selfie::location(Selfie::KIND_CHECKIN, $u, __('Devolução', 'checklistitens') . ' — ' . $item['label']);
            if ($receipt !== null) {
                $checkin_selfie   = Selfie::info(Selfie::KIND_RECEIPT, $receipt);
                $checkin_location = Selfie::location(Selfie::KIND_RECEIPT, $receipt, __('Recebimento', 'checklistitens') . ' — ' . $item['label']);
            }

            $timeline[] = [
                'id'           => $id,
                'status'       => $status,
                'status_label' => Usage::getStatusLabels()[$status] ?? '',
                'status_color' => Usage::getStatusColor($status),
                'user'         => $users[(int) $u['users_id']],
                'sector'       => Sector::getName((int) $u['groups_id']),
                'delivery'     => $delivery !== null ? [
                    'id'     => (int) $delivery['id'],
                    'vessel' => Ui::text($delivery['vessel_name']),
                    'url'    => DeliveryReport::getUrl((int) $delivery['id']),
                ] : null,
                'checkout'     => [
                    'when'  => Ui::datetime($u['date_checkout']),
                    'shift' => Shift::label($u['checkout_shift_start']),
                ] + $view->result($u, Usage::PHASE_CHECKOUT) + [
                    'selfie'   => $checkout_selfie,
                    'location' => $checkout_location,
                ],
                'confirmation' => self::confirmationInfo($confirmations[(int) $u['plugin_checklistitens_confirmations_id']] ?? null),
                'checkin'      => $u['date_checkin'] ? [
                    'when'  => Ui::datetime($u['date_checkin']),
                    'shift' => Shift::label($u['checkin_shift_start']),
                ] + $view->result($u, Usage::PHASE_CHECKIN) + [
                    'selfie'   => $checkin_selfie,
                    'location' => $checkin_location,
                ] : null,
                'checkin_confirmation' => self::confirmationInfo($confirmations[(int) $u['plugin_checklistitens_confirmations_id_checkin']] ?? null),
                // Encerrado sem devolução (Devoluções pendentes), com a observação e o histórico dela
                'not_returned' => $status === Usage::STATUS_NOT_RETURNED ? [
                    'user'    => Ui::text(getUserName((int) $u['closed_users_id'])),
                    'when'    => Ui::datetime($u['date_closed']),
                    'note'    => $notes[$id][0]['text'] ?? '',
                    'history' => $notes[$id] ?? [],
                ] : null,
                'block'        => $block,
                'duration'     => $duration,
            ];
        }

        $active_block = ItemBlock::getActiveFor($itemtype, $items_id);
        $situations   = self::getSituations();
        $situation    = self::situationOf(
            $active_block,
            $active_block === null ? (self::getOpenUsages($itemtype)[$items_id] ?? null) : null,
            $row,
            Config::getAvailableStates()
        );

        return [
            'itemtype'        => $itemtype,
            'items_id'        => $items_id,
            'type_label'      => ItemProvider::getTypeLabel($itemtype),
            'icon'            => ItemProvider::getTypeIcon($itemtype),
            'label'           => $item['label'],
            'detail'          => $item['detail'],
            'sector'          => Sector::getName((int) $item['groups_id']),
            'state'           => Ui::text($row['state_name'] ?? ''),
            'situation_label' => $situations[$situation]['label'],
            'situation_color' => $situations[$situation]['color'],
            'laudos'          => LaudoProvider::getOpenForItem($itemtype, $items_id),
            'active_block_id' => $active_block !== null ? (int) $active_block['id'] : 0,
            'can_release'     => $active_block !== null && Profile::canAdministrate(),
            'stats'           => [
                'uses'         => $stats['uses'],
                'refusals'     => $stats['refusals'],
                'with_problem' => $stats['with_problem'],
                'known_issue'  => $stats['known_issue'],
                'blocks'       => $stats['blocks'],
                'tickets'      => count($stats['tickets']),
                'repair_time'  => $stats['repair_seconds'] ? Html::timestampToString($stats['repair_seconds'], false) : '—',
                'last_use'     => $stats['last_use'] ?: '—',
            ],
            'timeline'        => $timeline,
            'users'           => $users,
            'filters'         => [
                'date_from' => (string) ($filters['date_from'] ?? ''),
                'date_to'   => (string) ($filters['date_to'] ?? ''),
                'users_id'  => (int) ($filters['users_id'] ?? 0),
            ],
        ];
    }

    /**
     * CSV do registro de uso, uma linha por uso.
     */
    public static function sendCsv(string $itemtype, int $items_id, array $filters): void
    {
        $record = self::getRecord($itemtype, $items_id, $filters);

        $header = [
            __('Uso', 'checklistitens'), __('Equipamento', 'checklistitens'), __('Colaborador', 'checklistitens'),
            __('Setor', 'checklistitens'), __('Situação', 'checklistitens'), __('Retirada', 'checklistitens'),
            __('Turno da retirada', 'checklistitens'), __('Problemas na retirada', 'checklistitens'),
            __('Conferido por', 'checklistitens'), __('Conferido em', 'checklistitens'),
            __('Devolução', 'checklistitens'), __('Turno da devolução', 'checklistitens'),
            __('Problemas na devolução', 'checklistitens'), __('Devolução conferida por', 'checklistitens'),
            __('Chamado', 'checklistitens'), __('Duração', 'checklistitens'),
            __('Localização da retirada', 'checklistitens'), __('Localização da conferência', 'checklistitens'),
            __('Localização da devolução', 'checklistitens'), __('Localização da conferência da devolução', 'checklistitens'),
            __('Problema conhecido na retirada', 'checklistitens'), __('Problema conhecido na devolução', 'checklistitens'),
            __('Laudos em aberto', 'checklistitens'), __('Fotos do defeito', 'checklistitens'),
            __('Embarcação', 'checklistitens'),
            __('Encerrado sem devolução por', 'checklistitens'), __('Encerrado sem devolução em', 'checklistitens'),
            __('Observação', 'checklistitens'),
        ];

        $rows  = [];
        $label = trim($record['label'] . ' ' . $record['detail']);
        foreach ($record['timeline'] as $entry) {
            $rows[] = [
                $entry['id'],
                $label,
                $entry['user'],
                $entry['sector'],
                $entry['status_label'],
                $entry['checkout']['when'],
                $entry['checkout']['shift'],
                implode(', ', $entry['checkout']['problems']),
                $entry['confirmation']['user'] ?? '',
                $entry['confirmation']['when'] ?? '',
                $entry['checkin']['when'] ?? '',
                $entry['checkin']['shift'] ?? '',
                implode(', ', $entry['checkin']['problems'] ?? []),
                $entry['checkin_confirmation']['user'] ?? '',
                $entry['block']['ticket_id'] ?? '',
                $entry['duration'],
                Location::toText($entry['checkout']['location']),
                isset($entry['confirmation']['location']) ? Location::toText($entry['confirmation']['location']) : '',
                isset($entry['checkin']['location']) ? Location::toText($entry['checkin']['location']) : '',
                isset($entry['checkin_confirmation']['location']) ? Location::toText($entry['checkin_confirmation']['location']) : '',
                $entry['checkout']['known'] ? __('Sim', 'checklistitens') : '',
                !empty($entry['checkin']['known']) ? __('Sim', 'checklistitens') : '',
                implode(', ', array_unique(array_map(
                    static fn ($l) => $l['name'] . ' (' . $l['status'] . ')',
                    array_merge($entry['checkout']['laudos'], $entry['checkin']['laudos'] ?? [])
                ))),
                count($entry['checkout']['photos']) + count($entry['checkin']['photos'] ?? []),
                $entry['delivery']['vessel'] ?? '',
                $entry['not_returned']['user'] ?? '',
                $entry['not_returned']['when'] ?? '',
                $entry['not_returned']['note'] ?? '',
            ];
        }

        Ui::sendCsv(sprintf('registro_uso_%s_%d.csv', strtolower($itemtype), $items_id), $header, $rows);
    }

    /** @return array<int, array> */
    private static function loadById(string $table, array $ids): array
    {
        global $DB;

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!count($ids)) {
            return [];
        }

        $map = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => ['id' => $ids]]) as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    private static function confirmationInfo(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'user'   => Ui::text(getUserName((int) $row['users_id'])),
            'when'   => Ui::datetime($row['date_confirmation']),
            'selfie' => Selfie::info(Selfie::KIND_CONFIRMATION, $row),
            'location' => Selfie::location(Selfie::KIND_CONFIRMATION, $row, __('Conferência do gestor', 'checklistitens')),
        ];
    }
}

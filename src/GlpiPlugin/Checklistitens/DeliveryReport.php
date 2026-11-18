<?php

namespace GlpiPlugin\Checklistitens;

use Session;

/**
 * Consulta das entregas de rádios para embarcação: lista, detalhe, CSV e o bloco "Com embarcações"
 * do painel do gestor. Só leitura; quem grava é Delivery e DeliveryReceipt.
 *
 * O gestor vê as entregas que têm rádio de um grupo do seu setor (com subgrupos) e as que ele
 * mesmo fez; o TI vê todas.
 */
class DeliveryReport
{
    /** A entrega para embarcação só existe com o tipo Rádio disponível e habilitado. */
    public static function isFeatureAvailable(): bool
    {
        return ItemProvider::isSupported(ItemProvider::RADIO)
            && in_array(ItemProvider::RADIO, Config::getEnabledTypes(), true);
    }

    public static function canView(): bool
    {
        return self::isFeatureAvailable() && (Profile::isManager() || Profile::isAdmin());
    }

    public static function getUrl(int $id): string
    {
        return Ui::url('front/delivery.php?id=' . $id);
    }

    /**
     * Ids das entregas que o usuário pode ver, ou null quando pode ver todas (TI).
     *
     * @return int[]|null
     */
    private static function getVisibleIds(): ?array
    {
        global $DB;

        if (Profile::isAdmin()) {
            return null;
        }

        $ids    = [];
        $groups = Sector::forCurrentUser();
        if (count($groups)) {
            $iterator = $DB->request([
                'SELECT'   => 'plugin_checklistitens_deliveries_id',
                'DISTINCT' => true,
                'FROM'     => Usage::getTable(),
                'WHERE'    => ['plugin_checklistitens_deliveries_id' => ['>', 0], 'groups_id' => $groups],
            ]);
            foreach ($iterator as $row) {
                $ids[(int) $row['plugin_checklistitens_deliveries_id']] = true;
            }
        }
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => Delivery::getTable(), 'WHERE' => ['users_id' => (int) Session::getLoginUserID()]]) as $row) {
            $ids[(int) $row['id']] = true;
        }

        return array_keys($ids);
    }

    /**
     * Quem vê uma entrega (e as fotos, os recebimentos e o histórico dela): o TI, quem fez a
     * entrega, quem fez o recebimento informado e o gestor cujo setor inclui o grupo de algum rádio
     * da entrega.
     *
     * @param array|null $receipt linha do recebimento, quando é dele que se trata
     */
    public static function canViewDelivery(int $deliveries_id, ?array $receipt = null): bool
    {
        $users_id = (int) Session::getLoginUserID();
        if ($deliveries_id <= 0 || $users_id <= 0) {
            return false;
        }
        if (Profile::isAdmin()) {
            return true;
        }
        if ($receipt !== null && (int) $receipt['users_id'] === $users_id) {
            return true;
        }
        if (countElementsInTable(Delivery::getTable(), ['id' => $deliveries_id, 'users_id' => $users_id]) > 0) {
            return true;
        }

        $groups = Sector::forCurrentUser();

        return Profile::isManager() && count($groups) > 0 && countElementsInTable(Usage::getTable(), [
            'plugin_checklistitens_deliveries_id' => $deliveries_id,
            'groups_id'                           => $groups,
        ]) > 0;
    }

    /** Contagem vazia dos rádios de uma entrega. */
    private const NO_COUNTS = ['total' => 0, 'draft' => 0, 'on_vessel' => 0, 'returned' => 0, 'blocked' => 0, 'cancelled' => 0];

    /**
     * Soma $n rádios de uma situação do uso na contagem da entrega. "total" são os rádios que
     * foram (ou vão) para a embarcação: o que saiu por problema novo ("blocked") e o que saiu da
     * separação cancelada ("cancelled") não entram.
     */
    private static function count(array &$counts, int $status, int $n): void
    {
        $bucket = [
            Usage::STATUS_DELIVERY_DRAFT     => 'draft',
            Usage::STATUS_ON_VESSEL          => 'on_vessel',
            Usage::STATUS_RETURNED           => 'returned',
            Usage::STATUS_REFUSED            => 'blocked',
            Usage::STATUS_DELIVERY_CANCELLED => 'cancelled',
        ][$status] ?? null;
        if ($bucket === null) {
            return;
        }
        $counts[$bucket] += $n;
        if (!in_array($bucket, ['blocked', 'cancelled'], true)) {
            $counts['total'] += $n;
        }
    }

    /**
     * Quantos rádios cada entrega tem em cada situação.
     *
     * @param int[] $ids
     * @return array<int, array{total: int, draft: int, on_vessel: int, returned: int, blocked: int, cancelled: int}>
     */
    private static function getCounts(array $ids): array
    {
        global $DB;

        $counts = [];
        if (!count($ids)) {
            return $counts;
        }

        $iterator = $DB->request([
            'SELECT'  => ['plugin_checklistitens_deliveries_id', 'status'],
            'COUNT'   => 'total',
            'FROM'    => Usage::getTable(),
            'WHERE'   => ['plugin_checklistitens_deliveries_id' => $ids],
            'GROUPBY' => ['plugin_checklistitens_deliveries_id', 'status'],
        ]);
        foreach ($iterator as $row) {
            $id = (int) $row['plugin_checklistitens_deliveries_id'];
            $counts[$id] ??= self::NO_COUNTS;
            self::count($counts[$id], (int) $row['status'], (int) $row['total']);
        }

        return $counts;
    }

    /**
     * Entregas concluídas que ainda têm rádio com a embarcação.
     *
     * @param int[]|null $groups só as que têm rádio "Com a embarcação" nesses grupos (null = todas)
     * @return array<int, array> mais antiga primeiro
     */
    public static function getOpenDeliveries(?array $groups): array
    {
        global $DB;

        if ($groups !== null && !count($groups)) {
            return [];
        }

        $where = ['status' => Usage::STATUS_ON_VESSEL, 'plugin_checklistitens_deliveries_id' => ['>', 0]];
        if ($groups !== null) {
            $where['groups_id'] = array_values(array_map('intval', $groups));
        }
        $ids = [];
        foreach ($DB->request(['SELECT' => 'plugin_checklistitens_deliveries_id', 'DISTINCT' => true, 'FROM' => Usage::getTable(), 'WHERE' => $where]) as $row) {
            $ids[] = (int) $row['plugin_checklistitens_deliveries_id'];
        }
        if (!count($ids)) {
            return [];
        }

        $counts     = self::getCounts($ids);
        $alert_days = Config::getDeliveryAlertDays();
        $now        = strtotime(Shift::now());

        $list = [];
        $iterator = $DB->request([
            'FROM'  => Delivery::getTable(),
            'WHERE' => ['id' => $ids, 'status' => Delivery::STATUS_DELIVERED],
            'ORDER' => 'date_delivery',
        ]);
        foreach ($iterator as $row) {
            $id   = (int) $row['id'];
            $days = $row['date_delivery'] ? (int) floor(max(0, $now - strtotime($row['date_delivery'])) / DAY_TIMESTAMP) : 0;
            $list[] = [
                'id'        => $id,
                'vessel'    => Ui::text($row['vessel_name']),
                'when'      => Ui::datetime($row['date_delivery']),
                'user'      => Ui::text(getUserName((int) $row['users_id'])),
                'on_vessel' => $counts[$id]['on_vessel'] ?? 0,
                'total'     => $counts[$id]['total'] ?? 0,
                'days'      => $days,
                // destaque configurável para entrega aberta há muito tempo (0 = desligado)
                'stale'     => $alert_days > 0 && $days >= $alert_days,
                'url'       => self::getUrl($id),
            ];
        }

        return $list;
    }

    /** Entregas abertas que o usuário pode receber (selo da tela inicial). */
    public static function countOpenDeliveries(): int
    {
        return count(self::getOpenDeliveries(Delivery::getDeliveryGroups()));
    }

    /**
     * Embarcações que aparecem nas entregas visíveis, para o filtro da lista.
     *
     * @return array<int, string>
     */
    public static function getVesselOptions(): array
    {
        global $DB;

        $where   = [];
        $visible = self::getVisibleIds();
        if ($visible !== null) {
            if (!count($visible)) {
                return [];
            }
            $where['id'] = $visible;
        }

        $options = [];
        $iterator = $DB->request([
            'SELECT' => ['plugin_checklistitens_vessels_id', 'vessel_name'],
            'FROM'   => Delivery::getTable(),
            'WHERE'  => $where,
            'ORDER'  => 'vessel_name',
        ]);
        foreach ($iterator as $row) {
            $options[(int) $row['plugin_checklistitens_vessels_id']] = Ui::text($row['vessel_name']);
        }

        return $options;
    }

    /**
     * Setores (grupo do primeiro rádio de cada entrega) das entregas visíveis, para o filtro da lista.
     *
     * @return array<int, string>
     */
    public static function getSectorOptions(): array
    {
        global $DB;

        $where   = ['groups_id' => ['>', 0]];
        $visible = self::getVisibleIds();
        if ($visible !== null) {
            if (!count($visible)) {
                return [];
            }
            $where['id'] = $visible;
        }

        $options = [];
        $iterator = $DB->request([
            'SELECT'   => 'groups_id',
            'DISTINCT' => true,
            'FROM'     => Delivery::getTable(),
            'WHERE'    => $where,
        ]);
        foreach ($iterator as $row) {
            $options[(int) $row['groups_id']] = Sector::getName((int) $row['groups_id']);
        }
        asort($options);

        return $options;
    }

    /**
     * Lista de entregas, mais recente primeiro.
     *
     * @param array{status?: int, vessels_id?: int, groups_id?: int, date_from?: string, date_to?: string} $filters
     */
    public static function getList(array $filters): array
    {
        global $DB;

        $where   = [];
        $visible = self::getVisibleIds();
        if ($visible !== null) {
            if (!count($visible)) {
                return [];
            }
            $where['id'] = $visible;
        }
        if (!empty($filters['status']) && isset(Delivery::getStatusLabels()[(int) $filters['status']])) {
            $where['status'] = (int) $filters['status'];
        }
        if (!empty($filters['vessels_id'])) {
            $where['plugin_checklistitens_vessels_id'] = (int) $filters['vessels_id'];
        }
        if (!empty($filters['groups_id'])) {
            $where['groups_id'] = (int) $filters['groups_id'];
        }
        // Datas só no formato AAAA-MM-DD (o valor vai direto para a consulta)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['date_from'] ?? ''))) {
            $where[] = ['date_start' => ['>=', $filters['date_from'] . ' 00:00:00']];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['date_to'] ?? ''))) {
            $where[] = ['date_start' => ['<=', $filters['date_to'] . ' 23:59:59']];
        }

        $rows = iterator_to_array($DB->request([
            'FROM'  => Delivery::getTable(),
            'WHERE' => $where,
            'ORDER' => 'date_start DESC',
            'LIMIT' => 300,
        ]), false);

        $counts = self::getCounts(array_map(static fn ($row) => (int) $row['id'], $rows));
        $labels = Delivery::getStatusLabels();

        $list = [];
        foreach ($rows as $row) {
            $id     = (int) $row['id'];
            $status = (int) $row['status'];
            $list[] = [
                'id'           => $id,
                'vessel'       => Ui::text($row['vessel_name']),
                'user'         => Ui::text(getUserName((int) $row['users_id'])),
                'sector'       => Sector::getName((int) $row['groups_id']),
                'when'         => Ui::datetime($row['date_delivery'] ?: $row['date_start']),
                'status'       => $status,
                'status_label' => $labels[$status] ?? '',
                'status_color' => Delivery::getStatusColor($status),
                'counts'       => $counts[$id] ?? self::NO_COUNTS,
                'url'          => self::getUrl($id),
            ];
        }

        return $list;
    }

    /**
     * Tudo de uma entrega: dados, rádios, fotos, selfie e recebimentos.
     */
    public static function getDetail(int $id): ?array
    {
        global $DB;

        $row = $DB->request(['FROM' => Delivery::getTable(), 'WHERE' => ['id' => $id]])->current();
        if (!$row || !self::canView() || !self::canViewDelivery((int) $row['id'])) {
            return null;
        }

        $status   = (int) $row['status'];
        $vessel   = Ui::text($row['vessel_name']);
        $usages   = iterator_to_array($DB->request([
            'FROM'  => Usage::getTable(),
            'WHERE' => ['plugin_checklistitens_deliveries_id' => $id],
            'ORDER' => 'id',
        ]), false);
        $view   = new UsageView($usages);
        $labels = Usage::getStatusLabels();

        $items  = [];
        $counts = self::NO_COUNTS;
        foreach ($usages as $u) {
            $uid      = (int) $u['id'];
            $ustatus  = (int) $u['status'];
            $itemtype = (string) $u['itemtype'];
            $item     = ItemProvider::labelOf($itemtype, (int) $u['items_id']);
            $block    = $view->block($u);
            self::count($counts, $ustatus, 1);

            $items[] = [
                'usage_id'     => $uid,
                'label'        => $item['label'],
                'detail'       => $item['detail'],
                'status'       => $ustatus,
                'status_label' => $labels[$ustatus] ?? '',
                'status_color' => Usage::getStatusColor($ustatus),
                'record_url'   => EquipmentRecord::getUrl($itemtype, (int) $u['items_id']) . '#usage-' . $uid,
                'receipt_id'   => (int) $u['plugin_checklistitens_deliveryreceipts_id'],
                'checkout'     => $view->result($u, Usage::PHASE_CHECKOUT),
                'checkin'      => $u['date_checkin']
                    ? ['when' => Ui::datetime($u['date_checkin'])] + $view->result($u, Usage::PHASE_CHECKIN)
                    : null,
                'block'        => $block !== null ? ItemBlock::present($block) : null,
            ];
        }

        $receipts = [];
        $iterator = $DB->request([
            'FROM'  => DeliveryReceipt::getTable(),
            'WHERE' => ['plugin_checklistitens_deliveries_id' => $id, 'status' => DeliveryReceipt::STATUS_DONE],
            'ORDER' => 'date_receipt',
        ]);
        $defects  = AccessoryDefect::getForReceipts(array_map(static fn ($r) => (int) $r['id'], iterator_to_array($iterator, false)));
        $iterator->rewind();
        foreach ($iterator as $r) {
            $rid        = (int) $r['id'];
            $receipts[] = [
                'id'                  => $rid,
                'when'                => Ui::datetime($r['date_receipt']),
                'user'                => Ui::text(getUserName((int) $r['users_id'])),
                'is_ok'               => $r['is_ok'] === null ? null : (int) $r['is_ok'] === 1,
                'returned_batteries'  => (int) $r['returned_batteries'],
                'defective_batteries' => (int) $r['defective_batteries'],
                'returned_covers'     => (int) $r['returned_covers'],
                'covers_ok'           => $r['covers_ok'] === null ? null : (int) $r['covers_ok'] === 1,
                'damaged_covers'      => (int) $r['damaged_covers'],
                // baterias e capas com defeito, e o chamado aberto para elas
                'defects'             => $defects[$rid] ?? [],
                'tickets_id'          => (int) $r['tickets_id'],
                'ticket_url'          => (int) $r['tickets_id'] > 0 ? Ticket::getFormURLWithID((int) $r['tickets_id']) : '',
                'can_open_ticket'     => isset($defects[$rid]) && (int) $r['tickets_id'] === 0 && (Delivery::canDeliver() || Profile::canAdministrate()),
                'comment'             => Ui::text($r['comment'] ?? ''),
                'selfie'              => Selfie::info(Selfie::KIND_RECEIPT, $r),
                'location'            => Selfie::location(Selfie::KIND_RECEIPT, $r, __('Recebimento', 'checklistitens') . ' — ' . $vessel),
                'photos'              => DeliveryPhoto::getFor($id, $rid),
                'items'               => count(array_filter($items, static fn ($i) => $i['receipt_id'] === $rid)),
            ];
        }

        return [
            'id'              => $id,
            'status'          => $status,
            'status_label'    => Delivery::getStatusLabels()[$status] ?? '',
            'status_color'    => Delivery::getStatusColor($status),
            'vessel'          => $vessel,
            'user'            => Ui::text(getUserName((int) $row['users_id'])),
            'sector'          => Sector::getName((int) $row['groups_id']),
            'date_start'      => Ui::datetime($row['date_start']),
            'date_delivery'   => Ui::datetime($row['date_delivery']),
            'date_return'     => Ui::datetime($row['date_return']),
            'shift'           => Shift::label($row['shift_start']),
            'is_ok'           => $row['is_ok'] === null ? null : (int) $row['is_ok'] === 1,
            'spare_batteries' => (int) $row['spare_batteries'],
            'spare_covers'    => (int) $row['spare_covers'],
            'comment'         => Ui::text($row['comment'] ?? ''),
            'selfie'          => Selfie::info(Selfie::KIND_DELIVERY, $row),
            'location'        => Selfie::location(Selfie::KIND_DELIVERY, $row, __('Entrega', 'checklistitens') . ' — ' . $vessel),
            'photos'          => DeliveryPhoto::getFor($id, 0),
            'items'           => $items,
            'receipts'        => $receipts,
            'counts'          => $counts,
            'can_receive'     => $status === Delivery::STATUS_DELIVERED && $counts['on_vessel'] > 0 && Delivery::canDeliver(),
        ];
    }

    /**
     * CSV de uma entrega, uma linha por rádio (separador ";" e BOM UTF-8, para abrir no Excel).
     */
    public static function sendCsv(int $id): void
    {
        $detail = self::getDetail($id);
        if ($detail === null) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header(sprintf('Content-Disposition: attachment; filename="entrega_embarcacao_%d.csv"', $id));

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            __('Entrega', 'checklistitens'), __('Embarcação', 'checklistitens'), __('Situação da entrega', 'checklistitens'),
            __('Gestor', 'checklistitens'), __('Entregue em', 'checklistitens'), __('Baterias reserva', 'checklistitens'),
            __('Capas', 'checklistitens'),
            __('Rádios ok', 'checklistitens'), __('Observações', 'checklistitens'), __('Fotos da entrega', 'checklistitens'),
            __('Localização da entrega', 'checklistitens'),
            __('Rádio', 'checklistitens'), __('Situação do rádio', 'checklistitens'),
            __('Problemas na entrega', 'checklistitens'), __('Problema conhecido na entrega', 'checklistitens'),
            __('Recebido em', 'checklistitens'), __('Problemas no recebimento', 'checklistitens'),
            __('Problema conhecido no recebimento', 'checklistitens'), __('Chamado', 'checklistitens'),
            // do recebimento em que o rádio voltou
            __('Baterias devolvidas', 'checklistitens'), __('Baterias com defeito', 'checklistitens'),
            __('Capas devolvidas', 'checklistitens'), __('Capas em bom estado', 'checklistitens'),
            __('Baterias e capas com defeito', 'checklistitens'), __('Chamado das baterias e capas', 'checklistitens'),
        ], ';');

        $yes      = __('Sim', 'checklistitens');
        $no       = __('Não', 'checklistitens');
        $receipts = array_column($detail['receipts'], null, 'id');
        foreach ($detail['items'] as $item) {
            $receipt = $receipts[$item['receipt_id']] ?? null;
            fputcsv($out, [
                $detail['id'],
                $detail['vessel'],
                $detail['status_label'],
                $detail['user'],
                $detail['date_delivery'],
                $detail['spare_batteries'],
                $detail['spare_covers'],
                $detail['is_ok'] === null ? '' : ($detail['is_ok'] ? $yes : $no),
                $detail['comment'],
                count($detail['photos']),
                Location::toText($detail['location']),
                trim($item['label'] . ' ' . $item['detail']),
                $item['status_label'],
                implode(', ', $item['checkout']['problems']),
                $item['checkout']['known'] ? $yes : '',
                $item['checkin']['when'] ?? '',
                implode(', ', $item['checkin']['problems'] ?? []),
                !empty($item['checkin']['known']) ? $yes : '',
                $item['block']['ticket_id'] ?? '',
                $receipt['returned_batteries'] ?? '',
                $receipt['defective_batteries'] ?? '',
                $receipt['returned_covers'] ?? '',
                $receipt === null || $receipt['covers_ok'] === null ? '' : ($receipt['covers_ok'] ? $yes : $no),
                $receipt === null ? '' : AccessoryDefect::summary($receipt['defects']),
                $receipt === null || $receipt['tickets_id'] === 0 ? '' : $receipt['tickets_id'],
            ], ';');
        }
        fclose($out);
        exit;
    }
}

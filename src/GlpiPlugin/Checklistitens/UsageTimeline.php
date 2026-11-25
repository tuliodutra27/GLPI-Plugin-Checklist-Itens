<?php

namespace GlpiPlugin\Checklistitens;

use Html;

/**
 * Cartão de cada uso, do começo ao fim (retirada ou entrega para embarcação, conferência,
 * devolução ou recebimento, conferência da devolução, encerramento sem devolução e bloqueio), para
 * o registro do equipamento e para a página de um uso. Carrega numa consulta por assunto o que os
 * usos da lista precisam.
 */
class UsageTimeline
{
    private UsageView $view;

    /** @var array<int, array> */
    private array $confirmations;

    /** @var array<int, array> */
    private array $deliveries;

    /** @var array<int, array> */
    private array $receipts;

    /** @var array<int, array> */
    private array $notes;

    /**
     * @param array[] $usages linhas dos usos
     */
    public function __construct(array $usages)
    {
        $this->view          = new UsageView($usages);
        $this->confirmations = Db::rows(Confirmation::getTable(), array_merge(
            array_column($usages, 'plugin_checklistitens_confirmations_id'),
            array_column($usages, 'plugin_checklistitens_confirmations_id_checkin')
        ));
        // Uso que faz parte de uma entrega para embarcação: a selfie e a localização são as do
        // gestor, guardadas na entrega e no recebimento
        $this->deliveries = Db::rows(Delivery::getTable(), array_column($usages, 'plugin_checklistitens_deliveries_id'));
        $this->receipts   = Db::rows(DeliveryReceipt::getTable(), array_column($usages, 'plugin_checklistitens_deliveryreceipts_id'));
        $this->notes      = UsageNote::getForUsages(array_map(static fn ($u) => (int) $u['id'], $usages));
    }

    /** Bloqueio criado por este uso (linha crua), ou null. */
    public function block(array $usage): ?array
    {
        return $this->view->block($usage);
    }

    /**
     * @param string $item_label nome do equipamento, para o título dos mapas
     */
    public function entry(array $u, string $item_label): array
    {
        $id     = (int) $u['id'];
        $status = (int) $u['status'];
        $block  = $this->view->block($u);

        $duration = '';
        if ($u['date_checkin']) {
            $duration = Html::timestampToString(max(0, strtotime($u['date_checkin']) - strtotime($u['date_checkout'])), false);
        }

        $delivery = $this->deliveries[(int) $u['plugin_checklistitens_deliveries_id']] ?? null;
        $receipt  = $this->receipts[(int) $u['plugin_checklistitens_deliveryreceipts_id']] ?? null;

        $checkout_selfie   = Selfie::info(Selfie::KIND_CHECKOUT, $u);
        $checkout_location = Selfie::location(Selfie::KIND_CHECKOUT, $u, __('Retirada', 'checklistitens') . ' — ' . $item_label);
        if ($delivery !== null) {
            $checkout_selfie   = Selfie::info(Selfie::KIND_DELIVERY, $delivery);
            $checkout_location = Selfie::location(Selfie::KIND_DELIVERY, $delivery, __('Entrega', 'checklistitens') . ' — ' . Ui::text($delivery['vessel_name']));
        }
        $checkin_selfie   = Selfie::info(Selfie::KIND_CHECKIN, $u);
        $checkin_location = Selfie::location(Selfie::KIND_CHECKIN, $u, __('Devolução', 'checklistitens') . ' — ' . $item_label);
        if ($receipt !== null) {
            $checkin_selfie   = Selfie::info(Selfie::KIND_RECEIPT, $receipt);
            $checkin_location = Selfie::location(Selfie::KIND_RECEIPT, $receipt, __('Recebimento', 'checklistitens') . ' — ' . $item_label);
        }

        return [
            'id'           => $id,
            'url'          => Usage::getFormURLWithID($id),
            'status'       => $status,
            'status_label' => Usage::getStatusLabels()[$status] ?? '',
            'status_color' => Usage::getStatusColor($status),
            'user'         => Ui::text(getUserName((int) $u['users_id'])),
            'sector'       => Sector::getName((int) $u['groups_id']),
            'delivery'     => $delivery !== null ? [
                'id'     => (int) $delivery['id'],
                'vessel' => Ui::text($delivery['vessel_name']),
                'url'    => DeliveryReport::getUrl((int) $delivery['id']),
            ] : null,
            'checkout'     => [
                'when'  => Ui::datetime($u['date_checkout']),
                'shift' => Shift::label($u['checkout_shift_start']),
            ] + $this->view->result($u, Usage::PHASE_CHECKOUT) + [
                'selfie'   => $checkout_selfie,
                'location' => $checkout_location,
            ],
            'confirmation' => $this->confirmation((int) $u['plugin_checklistitens_confirmations_id']),
            'checkin'      => $u['date_checkin'] ? [
                'when'  => Ui::datetime($u['date_checkin']),
                'shift' => Shift::label($u['checkin_shift_start']),
            ] + $this->view->result($u, Usage::PHASE_CHECKIN) + [
                'selfie'   => $checkin_selfie,
                'location' => $checkin_location,
            ] : null,
            'checkin_confirmation' => $this->confirmation((int) $u['plugin_checklistitens_confirmations_id_checkin']),
            // Encerrado sem devolução (Devoluções pendentes), com a observação e o histórico dela
            'not_returned' => $status === Usage::STATUS_NOT_RETURNED ? [
                'user'    => Ui::text(getUserName((int) $u['closed_users_id'])),
                'when'    => Ui::datetime($u['date_closed']),
                'note'    => $this->notes[$id][0]['text'] ?? '',
                'history' => $this->notes[$id] ?? [],
            ] : null,
            'block'        => $block !== null ? ItemBlock::present($block) : null,
            'duration'     => $duration,
        ];
    }

    private function confirmation(int $id): ?array
    {
        $row = $this->confirmations[$id] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'user'     => Ui::text(getUserName((int) $row['users_id'])),
            'when'     => Ui::datetime($row['date_confirmation']),
            'selfie'   => Selfie::info(Selfie::KIND_CONFIRMATION, $row),
            'location' => Selfie::location(Selfie::KIND_CONFIRMATION, $row, __('Conferência do gestor', 'checklistitens')),
        ];
    }
}

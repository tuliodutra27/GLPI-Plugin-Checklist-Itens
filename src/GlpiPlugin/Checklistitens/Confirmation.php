<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBTM;
use Session;

/**
 * Conferência do gestor: confirma, de uma vez e com a selfie do gestor, tudo o que está pendente
 * no setor (com subgrupos) — libera a devolução das retiradas, confere devoluções e bloqueios e
 * abre os chamados que faltam para os itens bloqueados.
 */
class Confirmation extends CommonDBTM
{
    public static $rightname = Profile::RIGHT_MANAGER;

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Conferência do gestor', 'Conferências do gestor', $nb, 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-user-check';
    }

    public static function canView()
    {
        return Profile::isManager() || Profile::isAdmin();
    }

    public static function canCreate()
    {
        return false;
    }

    public static function canUpdate()
    {
        return false;
    }

    public static function canDelete()
    {
        return false;
    }

    public static function canPurge()
    {
        return false;
    }

    /**
     * Grupos que o usuário pode conferir: os seus (com subgrupos) ou, para o TI, todos os que já
     * têm registros no plugin.
     *
     * @return array<int, string> id => nome completo
     */
    public static function getSelectableGroups(): array
    {
        global $DB;

        $groups = Sector::forCurrentUser();
        if (Profile::isAdmin()) {
            foreach ([Install::TABLE_USAGES, Install::TABLE_ITEMBLOCKS] as $table) {
                foreach ($DB->request(['SELECT' => 'groups_id', 'DISTINCT' => true, 'FROM' => $table]) as $row) {
                    $groups[] = (int) $row['groups_id'];
                }
            }
        }

        return Sector::getNames(array_unique($groups));
    }

    /**
     * Tudo o que o painel do gestor mostra para o setor escolhido.
     *
     * @param int[] $scope grupos (o escolhido e seus subgrupos)
     */
    public static function getPanelData(int $groups_id, array $scope): array
    {
        global $DB;

        $current_shift = Shift::startFor();
        $data = [
            'shift_label'   => Shift::label($current_shift),
            'blocks'        => [],
            'checkouts'     => [],
            'checkins'      => [],
            'released'      => [],
            'alerts'        => [],
            'pending_count' => 0,
            'tickets_to_open' => 0,
        ];
        if (!count($scope)) {
            return $data;
        }

        // Itens bloqueados (ativos) e os já liberados ainda não conferidos
        $iterator = $DB->request([
            'FROM'  => ItemBlock::getTable(),
            'WHERE' => [
                'groups_id' => $scope,
                'OR'        => ['lock_active' => 1, 'plugin_checklistitens_confirmations_id' => 0],
            ],
            'ORDER' => 'date_block',
        ]);
        foreach ($iterator as $row) {
            $block = self::presentBlock($row, $current_shift);
            $data['blocks'][] = $block;
            if (!$block['conferred']) {
                $data['pending_count']++;
                if ($block['ticket_id'] === 0 && (int) $row['status'] === ItemBlock::STATUS_WAITING) {
                    $data['tickets_to_open']++;
                }
            }
        }

        // Retiradas aguardando conferência
        foreach ($DB->request(['FROM' => Usage::getTable(), 'WHERE' => ['groups_id' => $scope, 'status' => Usage::STATUS_IN_USE], 'ORDER' => 'date_checkout']) as $row) {
            $data['checkouts'][] = self::presentUsage($row, $current_shift, Usage::PHASE_CHECKOUT);
            $data['pending_count']++;
        }

        // Devoluções ainda não conferidas
        foreach ($DB->request(['FROM' => Usage::getTable(), 'WHERE' => ['groups_id' => $scope, 'status' => Usage::STATUS_RETURNED, 'plugin_checklistitens_confirmations_id_checkin' => 0], 'ORDER' => 'date_checkin']) as $row) {
            $data['checkins'][] = self::presentUsage($row, $current_shift, Usage::PHASE_CHECKIN);
            $data['pending_count']++;
        }

        // Liberados, aguardando devolução
        foreach ($DB->request(['FROM' => Usage::getTable(), 'WHERE' => ['groups_id' => $scope, 'status' => Usage::STATUS_RELEASED], 'ORDER' => 'date_checkout']) as $row) {
            $data['released'][] = self::presentUsage($row, $current_shift, Usage::PHASE_CHECKOUT);
        }

        $data['alerts'] = self::getAlerts($groups_id, $scope, $current_shift, $data);

        return $data;
    }

    /**
     * @param int[] $block_ids   bloqueios exibidos no painel
     * @param int[] $checkout_ids retiradas exibidas no painel
     * @param int[] $checkin_ids  devoluções exibidas no painel
     * @return array{ok: bool, message: string}
     */
    public static function confirm(int $groups_id, array $scope, array $block_ids, array $checkout_ids, array $checkin_ids): array
    {
        if (!count($scope)) {
            return ['ok' => false, 'message' => __('Escolha o setor.', 'checklistitens')];
        }

        $users_id = (int) Session::getLoginUserID();
        $selfie   = Selfie::storeFromRequest(Selfie::KIND_CONFIRMATION, $users_id);
        if ($selfie === null) {
            return ['ok' => false, 'message' => __('Tire a selfie para confirmar a conferência.', 'checklistitens')];
        }

        $now          = Shift::now();
        $confirmation = new self();
        $id           = $confirmation->add([
            'entities_id'       => (int) ($_SESSION['glpiactive_entity'] ?? 0),
            'groups_id'         => $groups_id,
            'users_id'          => $users_id,
            'date_confirmation' => $now,
            'shift_start'       => Shift::startFor($now),
            'selfie'            => $selfie,
        ]);
        if (!$id) {
            Selfie::deleteFile($selfie);
            return ['ok' => false, 'message' => __('Não foi possível registrar a conferência. Tente de novo.', 'checklistitens')];
        }

        $counts = ['checkouts' => 0, 'checkins' => 0, 'blocks' => 0, 'tickets' => 0, 'ticket_errors' => 0];

        // Bloqueios: abre o chamado que falta e marca como conferido (e a recusa ligada a ele)
        $block = new ItemBlock();
        $usage = new Usage();
        foreach (array_unique(array_map('intval', $block_ids)) as $block_id) {
            if (!$block->getFromDB($block_id) || !in_array((int) $block->fields['groups_id'], $scope, true)
                || (int) $block->fields['plugin_checklistitens_confirmations_id'] > 0) {
                continue;
            }
            if ((int) $block->fields['tickets_id'] === 0 && (int) $block->fields['status'] === ItemBlock::STATUS_WAITING) {
                if (TicketFactory::openForBlock($block)) {
                    $counts['tickets']++;
                } else {
                    $counts['ticket_errors']++;
                }
                $block->getFromDB($block_id);
            }
            $block->update(['id' => $block_id, 'plugin_checklistitens_confirmations_id' => $id]);
            $counts['blocks']++;

            if ((int) $block->fields['reason'] === ItemBlock::REASON_CHECKOUT
                && $usage->getFromDB((int) $block->fields['plugin_checklistitens_usages_id'])
                && (int) $usage->fields['plugin_checklistitens_confirmations_id'] === 0) {
                $usage->update(['id' => $usage->getID(), 'plugin_checklistitens_confirmations_id' => $id]);
            }
        }

        // Retiradas: liberadas para devolução
        foreach (array_unique(array_map('intval', $checkout_ids)) as $usage_id) {
            if ($usage->getFromDB($usage_id) && in_array((int) $usage->fields['groups_id'], $scope, true)
                && (int) $usage->fields['status'] === Usage::STATUS_IN_USE) {
                $usage->update([
                    'id'                                     => $usage_id,
                    'status'                                 => Usage::STATUS_RELEASED,
                    'plugin_checklistitens_confirmations_id' => $id,
                ]);
                $counts['checkouts']++;
            }
        }

        // Devoluções conferidas
        foreach (array_unique(array_map('intval', $checkin_ids)) as $usage_id) {
            if ($usage->getFromDB($usage_id) && in_array((int) $usage->fields['groups_id'], $scope, true)
                && (int) $usage->fields['status'] === Usage::STATUS_RETURNED
                && (int) $usage->fields['plugin_checklistitens_confirmations_id_checkin'] === 0) {
                $usage->update(['id' => $usage_id, 'plugin_checklistitens_confirmations_id_checkin' => $id]);
                $counts['checkins']++;
            }
        }

        $message = sprintf(
            __('Conferência registrada: %1$d retirada(s) liberada(s) para devolução, %2$d devolução(ões), %3$d bloqueio(s), %4$d chamado(s) aberto(s).', 'checklistitens'),
            $counts['checkouts'],
            $counts['checkins'],
            $counts['blocks'],
            $counts['tickets']
        );
        if ($counts['ticket_errors']) {
            $message .= ' ' . sprintf(__('%d chamado(s) não puderam ser abertos; tente pelo botão "Abrir chamado".', 'checklistitens'), $counts['ticket_errors']);
        }

        return ['ok' => true, 'message' => $message];
    }

    private static function presentUsage(array $row, string $current_shift, int $phase): array
    {
        $usage    = Usage::present($row);
        $is_checkin = $phase === Usage::PHASE_CHECKIN;
        $shift    = $is_checkin ? $row['checkin_shift_start'] : $row['checkout_shift_start'];

        return $usage + [
            'user'           => Ui::text(getUserName((int) $row['users_id'])),
            'when'           => Ui::datetime($is_checkin ? $row['date_checkin'] : $row['date_checkout']),
            'shift_label'    => Shift::label($shift),
            'previous_shift' => $shift !== null && $shift < $current_shift,
            'is_ok'          => $is_checkin ? (int) $row['checkin_is_ok'] === 1 : (int) $row['checkout_is_ok'] === 1,
            'problems'       => $is_checkin ? array_column(UsageProblem::getFor((int) $row['id'], Usage::PHASE_CHECKIN), 'name') : [],
            'selfie_url'     => ($is_checkin ? $row['checkin_selfie'] : $row['checkout_selfie']) !== ''
                ? Selfie::getUrl($is_checkin ? Selfie::KIND_CHECKIN : Selfie::KIND_CHECKOUT, (int) $row['id'])
                : '',
        ];
    }

    private static function presentBlock(array $row, string $current_shift): array
    {
        global $CFG_GLPI;

        $itemtype = (string) $row['itemtype'];
        $item_row = ItemProvider::getRow($itemtype, (int) $row['items_id']);
        $item     = $item_row !== null ? ItemProvider::present($itemtype, $item_row) : ['label' => '#' . $row['items_id'], 'detail' => ''];
        $phase    = (int) $row['reason'] === ItemBlock::REASON_CHECKIN ? Usage::PHASE_CHECKIN : Usage::PHASE_CHECKOUT;
        $status   = (int) $row['status'];
        $ticket   = (int) $row['tickets_id'];

        return [
            'id'             => (int) $row['id'],
            'itemtype'       => $itemtype,
            'type_label'     => ItemProvider::getTypeLabel($itemtype),
            'icon'           => ItemProvider::getTypeIcon($itemtype),
            'label'          => $item['label'],
            'detail'         => $item['detail'],
            'user'           => Ui::text(getUserName((int) $row['users_id'])),
            'when'           => Ui::datetime($row['date_block']),
            'shift_label'    => Shift::label($row['block_shift_start']),
            'previous_shift' => $row['block_shift_start'] !== null && $row['block_shift_start'] < $current_shift,
            'reason_label'   => ItemBlock::getReasonLabels()[(int) $row['reason']] ?? '',
            'status'         => $status,
            'status_label'   => ItemBlock::getStatusLabels()[$status] ?? '',
            'problems'       => array_column(UsageProblem::getFor((int) $row['plugin_checklistitens_usages_id'], $phase), 'name'),
            'ticket_id'      => $ticket,
            'ticket_url'     => $ticket > 0 ? $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . $ticket : '',
            'conferred'      => (int) $row['plugin_checklistitens_confirmations_id'] > 0,
            'can_open_ticket' => $ticket === 0 && $status === ItemBlock::STATUS_WAITING,
        ];
    }

    /**
     * @return array<int, array{type: string, text: string}>
     */
    private static function getAlerts(int $groups_id, array $scope, string $current_shift, array $data): array
    {
        $alerts = [];

        $has_current_pending = false;
        $previous_pending    = 0;
        foreach (['checkouts', 'checkins', 'blocks'] as $list) {
            foreach ($data[$list] as $row) {
                if ($list === 'blocks' && $row['conferred']) {
                    continue;
                }
                if ($row['previous_shift']) {
                    $previous_pending++;
                } else {
                    $has_current_pending = true;
                }
            }
        }

        $confirmed_in_shift = countElementsInTable(self::getTable(), [
            'shift_start' => $current_shift,
            'groups_id'   => array_values(array_unique(array_merge($scope, Sector::withAncestors($groups_id)))),
        ]);
        if ($has_current_pending && !$confirmed_in_shift) {
            $alerts[] = ['type' => 'warning', 'text' => __('Ainda não houve conferência neste turno.', 'checklistitens')];
        }
        if ($previous_pending) {
            $alerts[] = ['type' => 'danger', 'text' => sprintf(__('%d registro(s) de turno anterior ainda sem conferência.', 'checklistitens'), $previous_pending)];
        }

        $not_returned = 0;
        foreach (array_merge($data['checkouts'], $data['released']) as $row) {
            if ($row['previous_shift']) {
                $not_returned++;
            }
        }
        if ($not_returned) {
            $alerts[] = ['type' => 'danger', 'text' => sprintf(__('%d equipamento(s) retirado(s) em turno anterior ainda não devolvido(s).', 'checklistitens'), $not_returned)];
        }

        if ($data['tickets_to_open']) {
            $alerts[] = ['type' => 'warning', 'text' => sprintf(__('%d item(ns) bloqueado(s) sem chamado. O chamado é aberto automaticamente ao confirmar a conferência.', 'checklistitens'), $data['tickets_to_open'])];
        }

        return $alerts;
    }
}

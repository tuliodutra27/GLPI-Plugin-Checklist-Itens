<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBTM;

/**
 * Item fora de circulação por problema marcado na retirada ou na devolução. Fica bloqueado até
 * o chamado do TI ser solucionado (ou o TI liberar manualmente).
 *
 * lock_active = 1 enquanto o bloqueio vale; o índice único (itemtype, items_id, lock_active)
 * garante um bloqueio ativo por item.
 */
class ItemBlock extends CommonDBTM
{
    public const STATUS_WAITING  = 1; // aguardando conferência (sem chamado)
    public const STATUS_REPAIR   = 2; // em reparo (chamado aberto)
    public const STATUS_RELEASED = 3;

    public const REASON_CHECKOUT = 1;
    public const REASON_CHECKIN  = 2;

    public const RELEASE_TICKET = 1;
    public const RELEASE_MANUAL = 2;

    public static $rightname = Profile::RIGHT_CONFIG;

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Bloqueio de equipamento', 'Bloqueios de equipamentos', $nb, 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-lock';
    }

    public static function canView()
    {
        return Profile::isManager() || Profile::isAdmin();
    }

    // Bloqueios só mudam pelos fluxos do plugin, nunca pela edição direta.
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

    /** @return array<int, string> */
    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_WAITING  => __('Bloqueado, aguardando conferência', 'checklistitens'),
            self::STATUS_REPAIR   => __('Em reparo (chamado aberto)', 'checklistitens'),
            self::STATUS_RELEASED => __('Liberado', 'checklistitens'),
        ];
    }

    /** @return array<int, string> */
    public static function getReasonLabels(): array
    {
        return [
            self::REASON_CHECKOUT => __('Problema na retirada', 'checklistitens'),
            self::REASON_CHECKIN  => __('Problema na devolução', 'checklistitens'),
        ];
    }

    /**
     * Cria o bloqueio a partir do uso que encontrou o problema.
     *
     * @return int id do bloqueio, ou 0 se o item já estava bloqueado
     */
    public static function createFor(array $usage, int $reason): int
    {
        $now   = Shift::now();
        $block = new self();
        $id    = $block->add([
            'entities_id'                     => (int) $usage['entities_id'],
            'itemtype'                        => $usage['itemtype'],
            'items_id'                        => (int) $usage['items_id'],
            'groups_id'                       => (int) $usage['groups_id'],
            'reason'                          => $reason,
            'plugin_checklistitens_usages_id' => (int) $usage['id'],
            'status'                          => self::STATUS_WAITING,
            'lock_active'                     => 1,
            'date_block'                      => $now,
            'block_shift_start'               => Shift::startFor($now),
            'users_id'                        => (int) $usage['users_id'],
        ]);

        return $id ? (int) $id : 0;
    }

    /**
     * Encerra o bloqueio: o item volta a aparecer na retirada (se continuar em estado disponível).
     */
    public function release(int $type, int $users_id = 0): bool
    {
        if ((int) $this->fields['lock_active'] !== 1) {
            return false;
        }

        return (bool) $this->update([
            'id'               => $this->getID(),
            'status'           => self::STATUS_RELEASED,
            'lock_active'      => 'NULL',
            'release_type'     => $type,
            'users_id_release' => $users_id,
            'date_release'     => Shift::now(),
        ]);
    }

    /**
     * Libera os bloqueios ativos ligados a um chamado (chamado solucionado ou fechado).
     *
     * @return int quantos bloqueios foram liberados
     */
    public static function releaseByTicket(int $tickets_id): int
    {
        global $DB;

        if ($tickets_id <= 0) {
            return 0;
        }

        $released = 0;
        $block    = new self();
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $tickets_id, 'lock_active' => 1]]) as $row) {
            if ($block->getFromDB((int) $row['id']) && $block->release(self::RELEASE_TICKET)) {
                $released++;
            }
        }

        return $released;
    }

    public static function cronInfo($name)
    {
        if ($name === 'releaseblocks') {
            return ['description' => __('Checklist uso de equipamentos: libera itens bloqueados cujo chamado foi solucionado', 'checklistitens')];
        }

        return [];
    }

    /**
     * Rede de segurança do hook de chamado: libera os bloqueios cujo chamado já está
     * solucionado ou fechado (ex.: chamado atualizado por uma ação que não disparou o hook).
     */
    public static function cronReleaseblocks(\CronTask $task): int
    {
        global $DB;

        $iterator = $DB->request([
            'SELECT'     => [self::getTable() . '.tickets_id'],
            'DISTINCT'   => true,
            'FROM'       => self::getTable(),
            'INNER JOIN' => [
                'glpi_tickets' => ['ON' => [self::getTable() => 'tickets_id', 'glpi_tickets' => 'id']],
            ],
            'WHERE'      => [
                self::getTable() . '.lock_active' => 1,
                'glpi_tickets.status'             => [\CommonITILObject::SOLVED, \CommonITILObject::CLOSED],
            ],
        ]);

        $released = 0;
        foreach ($iterator as $row) {
            $released += self::releaseByTicket((int) $row['tickets_id']);
        }
        $task->addVolume($released);

        return $released > 0 ? 1 : 0;
    }

    /**
     * Linha do bloqueio para as telas (painel do TI, registro do equipamento).
     */
    public static function present(array $row): array
    {
        global $CFG_GLPI;

        $itemtype = (string) $row['itemtype'];
        $item_row = ItemProvider::getRow($itemtype, (int) $row['items_id']);
        $item     = $item_row !== null ? ItemProvider::present($itemtype, $item_row) : ['label' => '#' . $row['items_id'], 'detail' => ''];
        $phase    = (int) $row['reason'] === self::REASON_CHECKIN ? Usage::PHASE_CHECKIN : Usage::PHASE_CHECKOUT;
        $ticket   = (int) $row['tickets_id'];
        $status   = (int) $row['status'];

        $ticket_status = '';
        if ($ticket > 0) {
            $t = new \Ticket();
            $ticket_status = $t->getFromDB($ticket) ? \Ticket::getStatus((int) $t->fields['status']) : __('Chamado excluído', 'checklistitens');
        }

        $release = '';
        if ($status === self::STATUS_RELEASED) {
            $release = (int) $row['release_type'] === self::RELEASE_TICKET
                ? sprintf(__('Liberado automaticamente em %s (chamado solucionado)', 'checklistitens'), Ui::datetime($row['date_release']))
                : sprintf(__('Liberado por %1$s em %2$s', 'checklistitens'), Ui::text(getUserName((int) $row['users_id_release'])), Ui::datetime($row['date_release']));
        }

        return [
            'id'             => (int) $row['id'],
            'itemtype'       => $itemtype,
            'items_id'       => (int) $row['items_id'],
            'type_label'     => ItemProvider::getTypeLabel($itemtype),
            'icon'           => ItemProvider::getTypeIcon($itemtype),
            'label'          => $item['label'],
            'detail'         => $item['detail'],
            'sector'         => Sector::getName((int) $row['groups_id']),
            'user'           => Ui::text(getUserName((int) $row['users_id'])),
            'when'           => Ui::datetime($row['date_block']),
            'shift_label'    => Shift::label($row['block_shift_start']),
            'reason_label'   => self::getReasonLabels()[(int) $row['reason']] ?? '',
            'status'         => $status,
            'status_label'   => self::getStatusLabels()[$status] ?? '',
            'active'         => (int) $row['lock_active'] === 1,
            'problems'       => array_column(UsageProblem::getFor((int) $row['plugin_checklistitens_usages_id'], $phase), 'name'),
            'ticket_id'      => $ticket,
            'ticket_url'     => $ticket > 0 ? $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . $ticket : '',
            'ticket_status'  => $ticket_status,
            'conferred'      => (int) $row['plugin_checklistitens_confirmations_id'] > 0,
            'release_label'  => $release,
        ];
    }

    public static function getActiveFor(string $itemtype, int $items_id): ?array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id, 'lock_active' => 1],
        ])->current();

        return $row ? $row : null;
    }
}

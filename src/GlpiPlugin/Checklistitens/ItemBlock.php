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

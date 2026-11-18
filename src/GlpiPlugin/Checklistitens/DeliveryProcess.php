<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBTM;
use Dropdown;
use Session;

/**
 * O que a separação da entrega (Delivery) e o recebimento de volta (DeliveryReceipt) têm em comum:
 * são feitos por um gestor, enviados pelo aparelho em partes e expiram parados. lock_draft = 1
 * enquanto estão em andamento; o índice único (users_id, lock_draft) garante um de cada por gestor.
 * Só mudam pelos fluxos do plugin.
 */
abstract class DeliveryProcess extends CommonDBTM
{
    use ReadOnlyRecord;

    public const STATUS_DRAFT = 1;

    /** Cada processo define: fase das fotos do defeito e situação dos rádios que ele mexe. */
    public const DEFECT_PHASE = 0;
    public const ITEM_STATUS  = 0;

    public static $rightname = Profile::RIGHT_MANAGER;

    public $dohistory = true;

    /** @return array<int, string> */
    abstract public static function getStatusLabels(): array;

    abstract protected static function deliveryIdOf(array $row): int;

    /** Mensagem para quem tenta continuar o que não está mais em andamento (0 = não existe). */
    abstract protected static function goneMessage(int $status): string;

    /** Situações em que já foi concluído (as fotos estão no servidor). */
    abstract protected static function concludedStatuses(): array;

    /** Limites de fotos (e de rádios) que a tela e o aparelho usam. */
    abstract public static function limits(): array;

    /**
     * Desiste do que está em andamento. $expired: pedido da ação automática (sem usuário).
     *
     * @return array{ok: bool, message: string, code?: string, retry?: bool}
     */
    abstract public static function cancel(int $id, bool $expired = false): array;

    /** Estado completo, no formato que a tela e o aparelho usam. */
    abstract public static function getState(array $row): array;

    /**
     * Dono das fotos gerais: [entrega, recebimento] (recebimento 0 = fotos da entrega).
     *
     * @return array{0: int, 1: int}
     */
    abstract public static function photoOwner(array $row): array;

    /**
     * O uso (rádio) está neste processo e pode receber foto do defeito: na separação, rádio reservado
     * nela (DEFECT_PHASE = retirada); no recebimento, rádio ainda com a embarcação (devolução).
     */
    public static function holdsItem(array $row, array $usage): bool
    {
        return (int) $usage['plugin_checklistitens_deliveries_id'] === static::deliveryIdOf($row)
            && (int) $usage['status'] === static::ITEM_STATUS;
    }

    public static function canView()
    {
        return Profile::canViewRecords();
    }

    public function canViewItem()
    {
        return DeliveryReport::canViewDelivery(static::deliveryIdOf($this->fields), $this->fields);
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            return htmlspecialchars(static::getStatusLabels()[(int) $values['status']] ?? '');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        if ($field === 'status') {
            return Dropdown::showFromArray($name, static::getStatusLabels(), $options + ['value' => $values[$field]]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public static function getRow(int $id): ?array
    {
        return Db::row(static::getTable(), $id);
    }

    /**
     * O que o gestor tem em andamento (só pode haver um), ou null.
     */
    public static function getDraftFor(int $users_id): ?array
    {
        global $DB;

        if ($users_id <= 0) {
            return null;
        }
        $row = $DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => ['users_id' => $users_id, 'lock_draft' => 1],
        ])->current();

        return $row ? $row : null;
    }

    /**
     * Confere que é do usuário logado e está em andamento.
     *
     * @param array|null $row recebe a linha
     * @return array|null resposta "não existe mais", ou null se está tudo certo
     */
    public static function requireOwnDraft(int $id, ?array &$row = null): ?array
    {
        $row = static::getRow($id);
        if ($row === null || (int) $row['users_id'] !== (int) Session::getLoginUserID()) {
            $row = null;

            return static::gone();
        }
        if ((int) $row['status'] !== static::STATUS_DRAFT) {
            $gone = static::gone($row);
            $row  = null;

            return $gone;
        }

        return null;
    }

    /**
     * Resposta para o que não existe mais: o aparelho descarta a cópia que guardou.
     *
     * @param array|null $row a linha, quando ela existe e é do usuário
     */
    public static function gone(?array $row = null): array
    {
        $status = $row !== null ? (int) $row['status'] : 0;

        // "concluded": as fotos já estão no servidor, o aparelho não precisa guardar para reenviar
        return Reply::fail(static::goneMessage($status), [
            'code'      => 'gone',
            'concluded' => in_array($status, static::concludedStatuses(), true),
        ]);
    }

    /**
     * Marca atividade: é a data da última atividade que decide a expiração.
     */
    public static function touch(int $id): void
    {
        global $DB;

        if ($id > 0) {
            $DB->update(static::getTable(), ['date_mod' => Shift::now()], ['id' => $id]);
        }
    }

    /**
     * Ids do que está em andamento e parado desde antes de $limit.
     *
     * @return int[]
     */
    public static function getStaleDrafts(string $limit): array
    {
        global $DB;

        $ids = [];
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => static::getTable(), 'WHERE' => ['status' => static::STATUS_DRAFT, 'date_mod' => ['<', $limit]]]) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }
}

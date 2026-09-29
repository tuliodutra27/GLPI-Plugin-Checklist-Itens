<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBTM;
use Session;
use Throwable;
use Toolbox;

/**
 * Uso de equipamento: um registro por ciclo retirada → devolução, ou por retirada recusada
 * porque o item tinha problema.
 *
 * Os registros não são editados nem apagados pela interface; só mudam pelos fluxos do plugin
 * (retirada, conferência do gestor, devolução), sempre com histórico.
 */
class Usage extends CommonDBTM
{
    public const STATUS_IN_USE   = 1; // retirado, aguardando conferência do gestor
    public const STATUS_RELEASED = 2; // conferido, liberado para devolução
    public const STATUS_RETURNED = 3;
    public const STATUS_REFUSED  = 4; // retirada recusada: item com problema, bloqueado

    public const PHASE_CHECKOUT = 1;
    public const PHASE_CHECKIN  = 2;

    public static $rightname = Profile::RIGHT_USAGE;

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Uso de equipamento', 'Usos de equipamentos', $nb, 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-clipboard-check';
    }

    public static function canView()
    {
        return Profile::isManager() || Profile::isAdmin();
    }

    public function canViewItem()
    {
        if (Profile::isAdmin() || (int) $this->fields['users_id'] === (int) Session::getLoginUserID()) {
            return true;
        }

        return Profile::isManager() && in_array((int) $this->fields['groups_id'], Sector::forCurrentUser(), true);
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

    public function getName($options = [])
    {
        if (empty($this->fields['id'])) {
            return self::getTypeName(1);
        }

        return sprintf(
            '%s — %s',
            ItemProvider::describe((string) $this->fields['itemtype'], (int) $this->fields['items_id']),
            Ui::datetime($this->fields['date_checkout'])
        );
    }

    /** @return array<int, string> */
    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_IN_USE   => __('Em uso (aguardando conferência)', 'checklistitens'),
            self::STATUS_RELEASED => __('Liberado para devolução', 'checklistitens'),
            self::STATUS_RETURNED => __('Devolvido', 'checklistitens'),
            self::STATUS_REFUSED  => __('Recusado com problema', 'checklistitens'),
        ];
    }

    public static function getStatusColor(int $status): string
    {
        switch ($status) {
            case self::STATUS_IN_USE:
                return 'blue';
            case self::STATUS_RELEASED:
                return 'green';
            case self::STATUS_REFUSED:
                return 'orange';
        }

        return 'secondary';
    }

    /** Usos abertos (retirados e ainda não devolvidos) do usuário. */
    public static function getOpenForUser(int $users_id): array
    {
        global $DB;

        return iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id, 'lock_open' => 1],
            'ORDER' => 'date_checkout',
        ]), false);
    }

    public static function countOpenForUser(int $users_id, string $itemtype): int
    {
        return countElementsInTable(self::getTable(), ['users_id' => $users_id, 'itemtype' => $itemtype, 'lock_open' => 1]);
    }

    /**
     * Dados de um uso para as telas.
     */
    public static function present(array $row): array
    {
        $itemtype = (string) $row['itemtype'];
        $item     = ItemProvider::getRow($itemtype, (int) $row['items_id']);
        $info     = $item !== null
            ? ItemProvider::present($itemtype, $item)
            : ['label' => '#' . $row['items_id'], 'detail' => ''];
        $status   = (int) $row['status'];

        return [
            'id'           => (int) $row['id'],
            'itemtype'     => $itemtype,
            'type_label'   => ItemProvider::getTypeLabel($itemtype),
            'icon'         => ItemProvider::getTypeIcon($itemtype),
            'label'        => $info['label'],
            'detail'       => $info['detail'],
            'status'       => $status,
            'status_label' => self::getStatusLabels()[$status] ?? '',
            'status_color' => self::getStatusColor($status),
            'checkout'     => Ui::datetime($row['date_checkout']),
            'shift'        => Shift::label($row['checkout_shift_start']),
            'can_return'   => $status === self::STATUS_RELEASED,
        ];
    }

    /**
     * Retirada com "Equipamento ok? Sim": exige selfie e cria o uso aberto (item bloqueado para
     * os outros até a devolução).
     *
     * @return array{ok: bool, message: string, id?: int}
     */
    public static function checkout(string $itemtype, int $items_id): array
    {
        $users_id = (int) Session::getLoginUserID();

        $check = self::checkCheckoutAllowed($itemtype, $items_id, $users_id);
        if (!$check['ok']) {
            return $check;
        }
        $item = $check['item'];

        $limit = Config::getLimit($itemtype);
        if ($limit > 0 && self::countOpenForUser($users_id, $itemtype) >= $limit) {
            return ['ok' => false, 'message' => sprintf(
                __('Você já está com o limite de %1$d %2$s. Devolva antes de retirar outro.', 'checklistitens'),
                $limit,
                mb_strtolower(ItemProvider::getShortTypeLabel($itemtype))
            )];
        }

        $selfie = Selfie::storeFromRequest(Selfie::KIND_CHECKOUT, $users_id);
        if ($selfie === null) {
            return ['ok' => false, 'message' => __('Tire a selfie para concluir a retirada.', 'checklistitens')];
        }

        $now   = Shift::now();
        $usage = new self();
        $id    = self::safeAdd($usage, [
            'entities_id'          => (int) $item['entities_id'],
            'itemtype'             => $itemtype,
            'items_id'             => $items_id,
            'users_id'             => $users_id,
            'groups_id'            => (int) $item['groups_id'],
            'status'               => self::STATUS_IN_USE,
            'lock_open'            => 1,
            'date_checkout'        => $now,
            'checkout_shift_start' => Shift::startFor($now),
            'checkout_is_ok'       => 1,
            'checkout_selfie'      => $selfie,
        ]);

        if (!$id) {
            // o índice único de uso aberto recusou: alguém retirou o mesmo item agora há pouco
            Selfie::deleteFile($selfie);
            return ['ok' => false, 'message' => __('Este item acabou de ser retirado por outra pessoa, escolha outro.', 'checklistitens')];
        }

        return ['ok' => true, 'message' => '', 'id' => $id];
    }

    /**
     * Retirada com "Equipamento ok? Não": registra a recusa com os problemas e bloqueia o item.
     * O colaborador não leva o item e escolhe outro.
     *
     * @param int[] $problem_ids
     * @return array{ok: bool, message: string, id?: int}
     */
    public static function refuse(string $itemtype, int $items_id, array $problem_ids): array
    {
        $users_id = (int) Session::getLoginUserID();

        $check = self::checkCheckoutAllowed($itemtype, $items_id, $users_id);
        if (!$check['ok']) {
            return $check;
        }
        $item = $check['item'];

        $problems = ProblemType::getValidForType($itemtype, $problem_ids);
        if (!count($problems)) {
            return ['ok' => false, 'message' => __('Marque pelo menos um problema encontrado.', 'checklistitens')];
        }

        $now   = Shift::now();
        $usage = new self();
        $id    = self::safeAdd($usage, [
            'entities_id'          => (int) $item['entities_id'],
            'itemtype'             => $itemtype,
            'items_id'             => $items_id,
            'users_id'             => $users_id,
            'groups_id'            => (int) $item['groups_id'],
            'status'               => self::STATUS_REFUSED,
            'date_checkout'        => $now,
            'checkout_shift_start' => Shift::startFor($now),
            'checkout_is_ok'       => 0,
        ]);
        if (!$id) {
            return ['ok' => false, 'message' => __('Não foi possível registrar o problema. Tente de novo.', 'checklistitens')];
        }

        UsageProblem::addAll($id, self::PHASE_CHECKOUT, $problems);
        ItemBlock::createFor($usage->fields, ItemBlock::REASON_CHECKOUT);

        return [
            'ok'      => true,
            'id'      => $id,
            'message' => sprintf(
                __('%s foi bloqueado e será conferido pelo gestor. Escolha outro equipamento.', 'checklistitens'),
                ItemProvider::describe($itemtype, $items_id)
            ),
        ];
    }

    /**
     * Devolução: só dos próprios usos, depois da liberação do gestor. Com problema, o item volta
     * bloqueado.
     *
     * @param int[] $problem_ids
     * @return array{ok: bool, message: string, id?: int, blocked?: bool}
     */
    public static function checkin(int $usages_id, int $is_ok, array $problem_ids): array
    {
        $users_id = (int) Session::getLoginUserID();
        $usage    = new self();

        if (!$usage->getFromDB($usages_id) || (int) $usage->fields['users_id'] !== $users_id
            || (int) $usage->fields['lock_open'] !== 1) {
            return ['ok' => false, 'message' => __('Escolha um dos equipamentos que estão com você.', 'checklistitens')];
        }
        if ((int) $usage->fields['status'] !== self::STATUS_RELEASED) {
            return ['ok' => false, 'message' => __('Este equipamento ainda aguarda a conferência do gestor. A devolução é liberada depois dela.', 'checklistitens')];
        }
        if ($is_ok !== 0 && $is_ok !== 1) {
            return ['ok' => false, 'message' => __('Responda se o equipamento está ok.', 'checklistitens')];
        }

        $problems = [];
        if ($is_ok === 0) {
            $problems = ProblemType::getValidForType((string) $usage->fields['itemtype'], $problem_ids);
            if (!count($problems)) {
                return ['ok' => false, 'message' => __('Marque pelo menos um problema encontrado.', 'checklistitens')];
            }
        }

        $selfie = Selfie::storeFromRequest(Selfie::KIND_CHECKIN, $users_id);
        if ($selfie === null) {
            return ['ok' => false, 'message' => __('Tire a selfie para concluir a devolução.', 'checklistitens')];
        }

        $now     = Shift::now();
        $updated = $usage->update([
            'id'                  => $usages_id,
            'status'              => self::STATUS_RETURNED,
            'lock_open'           => 'NULL',
            'date_checkin'        => $now,
            'checkin_shift_start' => Shift::startFor($now),
            'checkin_is_ok'       => $is_ok,
            'checkin_selfie'      => $selfie,
        ]);
        if (!$updated) {
            Selfie::deleteFile($selfie);
            return ['ok' => false, 'message' => __('Não foi possível registrar a devolução. Tente de novo.', 'checklistitens')];
        }

        if ($is_ok === 0) {
            UsageProblem::addAll($usages_id, self::PHASE_CHECKIN, $problems);
            ItemBlock::createFor($usage->fields, ItemBlock::REASON_CHECKIN);
        }

        return ['ok' => true, 'message' => '', 'id' => $usages_id, 'blocked' => $is_ok === 0];
    }

    /**
     * Regras comuns da retirada: tipo habilitado e item disponível no setor do colaborador.
     *
     * @return array{ok: bool, message: string, item?: array}
     */
    private static function checkCheckoutAllowed(string $itemtype, int $items_id, int $users_id): array
    {
        if (!in_array($itemtype, Config::getEnabledTypes(), true) || $items_id <= 0) {
            return ['ok' => false, 'message' => __('Escolha o tipo e o equipamento.', 'checklistitens')];
        }

        $item = ItemProvider::getAvailableItem($itemtype, $items_id, Sector::forCurrentUser());
        if ($item === null) {
            return ['ok' => false, 'message' => __('Este equipamento não está mais disponível. Escolha outro.', 'checklistitens')];
        }

        return ['ok' => true, 'message' => '', 'item' => $item];
    }

    /**
     * add() que não deixa um erro de banco (ex.: índice único) virar erro de tela.
     *
     * @return int id criado, ou 0
     */
    private static function safeAdd(self $usage, array $input): int
    {
        try {
            $id = $usage->add($input);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: ' . $e->getMessage());
            $id = false;
        }

        return $id ? (int) $id : 0;
    }
}

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

    /**
     * Link do registro (busca e itens do chamado): abre o registro de uso do equipamento.
     */
    public static function getFormURLWithID($id = 0, $full = true)
    {
        return Ui::url('front/usage.form.php?id=' . (int) $id);
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();

        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            [
                'id'            => '1',
                'table'         => $table,
                'field'         => 'id',
                'name'          => __('ID'),
                'datatype'      => 'itemlink',
                'massiveaction' => false,
            ],
            [
                'id'            => '2',
                'table'         => $table,
                'field'         => 'itemtype',
                'name'          => __('Tipo', 'checklistitens'),
                'datatype'      => 'specific',
                'searchtype'    => ['equals', 'notequals'],
                'massiveaction' => false,
            ],
            [
                'id'               => '3',
                'table'            => $table,
                'field'            => 'items_id',
                'name'             => __('Equipamento', 'checklistitens'),
                'datatype'         => 'specific',
                'additionalfields' => ['itemtype'],
                'searchtype'       => ['equals'],
                'massiveaction'    => false,
            ],
            [
                'id'            => '4',
                'table'         => 'glpi_users',
                'field'         => 'name',
                'linkfield'     => 'users_id',
                'name'          => __('Colaborador', 'checklistitens'),
                'datatype'      => 'dropdown',
                'right'         => 'all',
                'massiveaction' => false,
            ],
            [
                'id'            => '5',
                'table'         => 'glpi_groups',
                'field'         => 'completename',
                'linkfield'     => 'groups_id',
                'name'          => __('Setor', 'checklistitens'),
                'datatype'      => 'dropdown',
                'massiveaction' => false,
            ],
            [
                'id'            => '6',
                'table'         => $table,
                'field'         => 'status',
                'name'          => __('Situação', 'checklistitens'),
                'datatype'      => 'specific',
                'searchtype'    => ['equals', 'notequals'],
                'massiveaction' => false,
            ],
            [
                'id'            => '7',
                'table'         => $table,
                'field'         => 'date_checkout',
                'name'          => __('Retirada', 'checklistitens'),
                'datatype'      => 'datetime',
                'massiveaction' => false,
            ],
            [
                'id'            => '8',
                'table'         => $table,
                'field'         => 'checkout_is_ok',
                'name'          => __('Ok na retirada', 'checklistitens'),
                'datatype'      => 'bool',
                'massiveaction' => false,
            ],
            [
                'id'            => '9',
                'table'         => $table,
                'field'         => 'date_checkin',
                'name'          => __('Devolução', 'checklistitens'),
                'datatype'      => 'datetime',
                'massiveaction' => false,
            ],
            [
                'id'            => '10',
                'table'         => $table,
                'field'         => 'checkin_is_ok',
                'name'          => __('Ok na devolução', 'checklistitens'),
                'datatype'      => 'bool',
                'massiveaction' => false,
            ],
            [
                'id'            => '11',
                'table'         => $table,
                'field'         => 'checkout_shift_start',
                'name'          => __('Turno da retirada', 'checklistitens'),
                'datatype'      => 'datetime',
                'massiveaction' => false,
            ],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'itemtype':
                return htmlspecialchars(ItemProvider::getShortTypeLabel((string) $values['itemtype']));
            case 'items_id':
                if (!empty($values['itemtype'])) {
                    return htmlspecialchars(ItemProvider::describe((string) $values['itemtype'], (int) $values['items_id']));
                }
                return (string) (int) $values['items_id'];
            case 'status':
                return htmlspecialchars(self::getStatusLabels()[(int) $values['status']] ?? '');
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        switch ($field) {
            case 'itemtype':
                $types = [];
                foreach (ItemProvider::getSupportedTypes() as $itemtype) {
                    $types[$itemtype] = ItemProvider::getShortTypeLabel($itemtype);
                }
                return \Dropdown::showFromArray($name, $types, $options + ['value' => $values[$field]]);
            case 'status':
                return \Dropdown::showFromArray($name, self::getStatusLabels(), $options + ['value' => $values[$field]]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * Restrição da busca (hook addDefaultWhere): gestor vê só o seu setor; TI vê tudo.
     */
    public static function getSearchRestriction(): string
    {
        if (Profile::isAdmin()) {
            return '';
        }

        $groups = Sector::forCurrentUser();
        if (!Profile::isManager() || !count($groups)) {
            return '0 = 1';
        }

        return sprintf('`%s`.`groups_id` IN (%s)', self::getTable(), implode(',', array_map('intval', $groups)));
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
            'items_id'     => (int) $row['items_id'],
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
     * Retirada que leva o item: "Equipamento ok? Sim", ou "Não" com o problema já conhecido num
     * laudo em aberto (sem bloqueio). Exige selfie e cria o uso aberto (item preso para os outros
     * até a devolução).
     *
     * Se o colaborador disse que o problema é o do laudo, mas o laudo foi concluído nesse
     * meio-tempo, vale a regra normal: a retirada vira recusa e o item é bloqueado.
     *
     * @param int[] $problem_ids
     * @return array{ok: bool, message: string, id?: int, refused?: bool}
     */
    public static function checkout(string $itemtype, int $items_id, bool $known_issue = false, array $problem_ids = []): array
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

        $laudos   = LaudoProvider::getOpenForItem($itemtype, $items_id);
        $problems = $photos = [];
        if ($known_issue) {
            $problems = ProblemType::getValidForType($itemtype, $problem_ids);
            if (!count($problems)) {
                return ['ok' => false, 'message' => __('Marque pelo menos um problema encontrado.', 'checklistitens')];
            }
            $photos = DefectPhoto::collectFromRequest();
            if (count($photos) < DefectPhoto::MIN_PHOTOS) {
                return ['ok' => false, 'message' => __('Tire pelo menos uma foto do equipamento com defeito.', 'checklistitens')];
            }
            if (!count($laudos)) {
                $result = self::refuse($itemtype, $items_id, $problem_ids);
                if ($result['ok']) {
                    $result['message'] = __('O laudo deste equipamento foi concluído: o problema bloqueia o item.', 'checklistitens')
                        . ' ' . $result['message'];
                }

                return $result + ['refused' => true];
            }
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
            'checkout_is_ok'       => $known_issue ? 0 : 1,
            'checkout_known_issue' => $known_issue ? 1 : 0,
            'checkout_selfie'      => $selfie,
        ] + Location::toFields('checkout_', Location::fromRequest()));

        if (!$id) {
            // o índice único de uso aberto recusou: alguém retirou o mesmo item agora há pouco
            Selfie::deleteFile($selfie);
            return ['ok' => false, 'message' => __('Este item acabou de ser retirado por outra pessoa, escolha outro.', 'checklistitens')];
        }

        if ($known_issue) {
            UsageProblem::addAll($id, self::PHASE_CHECKOUT, $problems);
            DefectPhoto::storeAll($id, self::PHASE_CHECKOUT, $photos);
        }
        // Cópia dos laudos em aberto: registra que o alerta foi exibido, mesmo no "Sim"
        if (count($laudos)) {
            UsageLaudo::recordAll($id, self::PHASE_CHECKOUT, $laudos);
        }

        return ['ok' => true, 'message' => '', 'id' => $id];
    }

    /**
     * Retirada com "Equipamento ok? Não" e problema novo: registra a recusa com os problemas e as
     * fotos do defeito e bloqueia o item. O colaborador não leva o item e escolhe outro.
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
        $photos = DefectPhoto::collectFromRequest();
        if (count($photos) < DefectPhoto::MIN_PHOTOS) {
            return ['ok' => false, 'message' => __('Tire pelo menos uma foto do equipamento com defeito.', 'checklistitens')];
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
        DefectPhoto::storeAll($id, self::PHASE_CHECKOUT, $photos);
        $laudos = LaudoProvider::getOpenForItem($itemtype, $items_id);
        if (count($laudos)) {
            UsageLaudo::recordAll($id, self::PHASE_CHECKOUT, $laudos);
        }
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
     * Devolução: só dos próprios usos, depois da liberação do gestor. Com problema novo, o item
     * volta bloqueado; com o problema já conhecido num laudo em aberto, não.
     *
     * @param int[] $problem_ids
     * @return array{ok: bool, message: string, id?: int, blocked?: bool, laudo_closed?: bool}
     */
    public static function checkin(int $usages_id, int $is_ok, array $problem_ids, bool $known_issue = false): array
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

        $itemtype = (string) $usage->fields['itemtype'];
        $laudos   = LaudoProvider::getOpenForItem($itemtype, (int) $usage->fields['items_id']);

        $problems = $photos = [];
        if ($is_ok === 0) {
            $problems = ProblemType::getValidForType($itemtype, $problem_ids);
            if (!count($problems)) {
                return ['ok' => false, 'message' => __('Marque pelo menos um problema encontrado.', 'checklistitens')];
            }
            $photos = DefectPhoto::collectFromRequest();
            if (count($photos) < DefectPhoto::MIN_PHOTOS) {
                return ['ok' => false, 'message' => __('Tire pelo menos uma foto do equipamento com defeito.', 'checklistitens')];
            }
        }

        // "É o problema do laudo" só vale se o laudo ainda estiver em aberto agora
        $laudo_closed = $is_ok === 0 && $known_issue && !count($laudos);
        $known        = $is_ok === 0 && $known_issue && count($laudos) > 0;

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
            'checkin_known_issue' => $known ? 1 : 0,
            'checkin_selfie'      => $selfie,
        ] + Location::toFields('checkin_', Location::fromRequest()));
        if (!$updated) {
            Selfie::deleteFile($selfie);
            return ['ok' => false, 'message' => __('Não foi possível registrar a devolução. Tente de novo.', 'checklistitens')];
        }

        if ($is_ok === 0) {
            UsageProblem::addAll($usages_id, self::PHASE_CHECKIN, $problems);
            DefectPhoto::storeAll($usages_id, self::PHASE_CHECKIN, $photos);
        }
        if (count($laudos)) {
            UsageLaudo::recordAll($usages_id, self::PHASE_CHECKIN, $laudos);
        }
        $blocked = $is_ok === 0 && !$known;
        if ($blocked) {
            ItemBlock::createFor($usage->fields, ItemBlock::REASON_CHECKIN);
        }

        return [
            'ok'           => true,
            'message'      => $laudo_closed ? __('O laudo deste equipamento foi concluído: o problema bloqueia o item.', 'checklistitens') : '',
            'id'           => $usages_id,
            'blocked'      => $blocked,
            'laudo_closed' => $laudo_closed,
        ];
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

<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBTM;
use CronTask;
use Dropdown;
use Glpi\Toolbox\Sanitizer;
use Session;
use Throwable;
use Toolbox;

/**
 * Entrega de rádios para embarcação, feita pelo gestor: ele separa vários rádios de uma vez,
 * fotografa, responde "Itens ok?", tira a selfie e conclui. Cada rádio da entrega é um uso
 * (usages.plugin_checklistitens_deliveries_id), para a disponibilidade e o registro do equipamento
 * continuarem valendo sem regra nova.
 *
 * A separação é enviada pelo aparelho em partes (ajax/delivery.php) e a conexão pode cair no meio:
 * por isso toda ação aceita ser repetida e dá o mesmo resultado. lock_draft = 1 enquanto a entrega
 * está em separação; o índice único (users_id, lock_draft) garante uma separação por gestor.
 *
 * Os registros não são editados nem apagados pela interface: só mudam pelos fluxos daqui.
 */
class Delivery extends CommonDBTM
{
    public const STATUS_DRAFT     = 1; // em separação: rádios reservados
    public const STATUS_DELIVERED = 2; // entregue: rádios com a embarcação
    public const STATUS_RETURNED  = 3; // todos os rádios voltaram
    public const STATUS_CANCELLED = 4; // separação cancelada ou expirada

    public const CRON_NAME = 'expiredeliveries';

    /** Tamanho máximo das observações, em caracteres. */
    public const COMMENT_MAX = 1000;

    public static $rightname = Profile::RIGHT_MANAGER;

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Entrega para embarcação', 'Entregas para embarcação', $nb, 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-ship';
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

        // Gestor: a entrega tem rádio de um grupo do setor dele
        $groups = Sector::forCurrentUser();

        return Profile::isManager() && count($groups) > 0 && countElementsInTable(Usage::getTable(), [
            'plugin_checklistitens_deliveries_id' => (int) $this->fields['id'],
            'groups_id'                           => $groups,
        ]) > 0;
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
            Ui::text($this->fields['vessel_name']),
            Ui::datetime($this->fields['date_delivery'] ?: $this->fields['date_start'])
        );
    }

    /**
     * Link do registro (histórico, busca): abre o detalhe da entrega.
     */
    public static function getFormURLWithID($id = 0, $full = true)
    {
        return Ui::url('front/delivery.php?id=' . (int) $id);
    }

    public function rawSearchOptions()
    {
        $table = self::getTable();

        // O mínimo para o histórico do registro mostrar o que mudou em cada passo
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
                'field'         => 'vessel_name',
                'name'          => __('Embarcação', 'checklistitens'),
                'datatype'      => 'string',
                'massiveaction' => false,
            ],
            [
                'id'            => '3',
                'table'         => $table,
                'field'         => 'status',
                'name'          => __('Situação', 'checklistitens'),
                'datatype'      => 'specific',
                'searchtype'    => ['equals', 'notequals'],
                'massiveaction' => false,
            ],
            [
                'id'            => '4',
                'table'         => 'glpi_users',
                'field'         => 'name',
                'linkfield'     => 'users_id',
                'name'          => __('Gestor', 'checklistitens'),
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
                'field'         => 'date_start',
                'name'          => __('Início da separação', 'checklistitens'),
                'datatype'      => 'datetime',
                'massiveaction' => false,
            ],
            [
                'id'            => '7',
                'table'         => $table,
                'field'         => 'date_delivery',
                'name'          => __('Entregue em', 'checklistitens'),
                'datatype'      => 'datetime',
                'massiveaction' => false,
            ],
            [
                'id'            => '8',
                'table'         => $table,
                'field'         => 'date_return',
                'name'          => __('Devolvida em', 'checklistitens'),
                'datatype'      => 'datetime',
                'massiveaction' => false,
            ],
            [
                'id'            => '9',
                'table'         => $table,
                'field'         => 'is_ok',
                'name'          => __('Itens ok', 'checklistitens'),
                'datatype'      => 'bool',
                'massiveaction' => false,
            ],
            [
                'id'            => '10',
                'table'         => $table,
                'field'         => 'spare_batteries',
                'name'          => __('Baterias reserva', 'checklistitens'),
                'datatype'      => 'number',
                'massiveaction' => false,
            ],
            [
                'id'            => '11',
                'table'         => $table,
                'field'         => 'spare_covers',
                'name'          => __('Capas', 'checklistitens'),
                'datatype'      => 'number',
                'massiveaction' => false,
            ],
        ];
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
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
        if ($field === 'status') {
            return Dropdown::showFromArray($name, self::getStatusLabels(), $options + ['value' => $values[$field]]);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /** @return array<int, string> */
    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_DRAFT     => __('Em separação', 'checklistitens'),
            self::STATUS_DELIVERED => __('Entregue', 'checklistitens'),
            self::STATUS_RETURNED  => __('Devolvida', 'checklistitens'),
            self::STATUS_CANCELLED => __('Cancelada', 'checklistitens'),
        ];
    }

    public static function getStatusColor(int $status): string
    {
        switch ($status) {
            case self::STATUS_DRAFT:
                return 'yellow';
            case self::STATUS_DELIVERED:
                return 'azure';
            case self::STATUS_RETURNED:
                return 'green';
        }

        return 'secondary';
    }

    // ------------------------------------------------------------------ direitos e alcance

    /**
     * Quem faz entrega e recebimento: o gestor que confere (ou o TI com esse direito), e só com o
     * tipo Rádio disponível e habilitado.
     */
    public static function canDeliver(): bool
    {
        return Profile::canConfirm()
            && ItemProvider::isSupported(ItemProvider::RADIO)
            && in_array(ItemProvider::RADIO, Config::getEnabledTypes(), true);
    }

    /**
     * Grupos cujos rádios o usuário entrega e recebe: o setor dele (com subgrupos) ou, para o TI,
     * todos os grupos que têm rádio.
     *
     * @return int[]
     */
    public static function getDeliveryGroups(): array
    {
        // É consultado a cada rádio conferido; o perfil não muda no meio do pedido
        static $cache = null;

        if ($cache === null) {
            if (Profile::isAdmin()) {
                $groups = [];
                foreach (ItemProvider::getItems(ItemProvider::RADIO, null) as $row) {
                    $group = (int) ($row['groups_id'] ?? 0);
                    if ($group > 0) {
                        $groups[$group] = $group;
                    }
                }
                $cache = array_values($groups);
            } else {
                $cache = Sector::forCurrentUser();
            }
        }

        return $cache;
    }

    /**
     * Rádios que podem entrar numa entrega agora, com os laudos em aberto de cada um.
     *
     * @return array<int, array{id: int, label: string, detail: string, groups_id: int, laudos: array}>
     */
    public static function getAvailableRadios(): array
    {
        if (!self::canDeliver()) {
            return [];
        }

        $items  = ItemProvider::getAvailable(ItemProvider::RADIO, self::getDeliveryGroups());
        $laudos = LaudoProvider::getOpenFor(ItemProvider::RADIO, array_column($items, 'id'));
        foreach ($items as &$item) {
            $item['laudos'] = $laudos[$item['id']] ?? [];
        }
        unset($item);

        return $items;
    }

    // ------------------------------------------------------------------ leitura

    public static function getRow(int $id): ?array
    {
        global $DB;

        if ($id <= 0) {
            return null;
        }
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id]])->current();

        return $row ? $row : null;
    }

    /**
     * Separação aberta do gestor (só pode haver uma), ou null.
     */
    public static function getDraftFor(int $users_id): ?array
    {
        global $DB;

        if ($users_id <= 0) {
            return null;
        }
        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id, 'lock_draft' => 1],
        ])->current();

        return $row ? $row : null;
    }

    /**
     * Usos da entrega numa situação, na ordem em que os rádios foram reservados.
     *
     * @return array[]
     */
    public static function getUsages(int $deliveries_id, int $status): array
    {
        global $DB;

        if ($deliveries_id <= 0) {
            return [];
        }

        return iterator_to_array($DB->request([
            'FROM'  => Usage::getTable(),
            'WHERE' => ['plugin_checklistitens_deliveries_id' => $deliveries_id, 'status' => $status],
            'ORDER' => 'id',
        ]), false);
    }

    /**
     * Estado completo da entrega, no formato que a tela e o aparelho usam.
     *
     * @param array $row linha da entrega
     */
    public static function getState(array $row): array
    {
        $id      = (int) $row['id'];
        $drafts  = self::getUsages($id, Usage::STATUS_DELIVERY_DRAFT);
        $refused = self::getUsages($id, Usage::STATUS_REFUSED);

        $draft_ids = array_map(static fn ($u) => (int) $u['id'], $drafts);
        $problems  = self::getProblemNames(
            array_merge($draft_ids, array_map(static fn ($u) => (int) $u['id'], $refused)),
            Usage::PHASE_CHECKOUT
        );
        $defects   = DefectPhoto::getForUsages($draft_ids);
        // Laudos em aberto agora (uma consulta só): é o que decide o "problema conhecido"
        $laudos    = LaudoProvider::getOpenFor(ItemProvider::RADIO, array_map(static fn ($u) => (int) $u['items_id'], $drafts));

        $items = [];
        foreach ($drafts as $usage) {
            $usage_id = (int) $usage['id'];
            $items_id = (int) $usage['items_id'];
            $items[]  = [
                'usage_id' => $usage_id,
                'items_id' => $items_id,
            ] + self::describeItem($items_id) + [
                'laudos'        => $laudos[$items_id] ?? [],
                'known_issue'   => (int) $usage['checkout_known_issue'] === 1,
                'problems'      => $problems[$usage_id] ?? [],
                'defect_photos' => count($defects[$usage_id][Usage::PHASE_CHECKOUT] ?? []),
            ];
        }

        $removed = [];
        foreach ($refused as $usage) {
            $usage_id  = (int) $usage['id'];
            $items_id  = (int) $usage['items_id'];
            $removed[] = [
                'usage_id' => $usage_id,
                'items_id' => $items_id,
            ] + self::describeItem($items_id) + [
                'problems' => $problems[$usage_id] ?? [],
            ];
        }

        return [
            'id'               => $id,
            'client_uuid'      => (string) $row['client_uuid'],
            'status'           => (int) $row['status'],
            'vessel'           => [
                'id'   => (int) $row['plugin_checklistitens_vessels_id'],
                'name' => Ui::text($row['vessel_name']),
            ],
            'date_start'       => (string) $row['date_start'],
            'date_start_label' => Ui::datetime($row['date_start']),
            'expires_at'       => self::expiresAt($row['date_mod'] ?: $row['date_start']),
            'items'            => $items,
            'removed'          => $removed,
            'photos'           => self::photoList($id, 0),
            'limits'           => [
                'max_items'  => Config::getDeliveryMaxItems(),
                'min_photos' => Config::getDeliveryMinPhotos(),
                'max_photos' => Config::getDeliveryMaxPhotos(),
            ],
        ];
    }

    // ------------------------------------------------------------------ ações

    /**
     * Abre a separação e reserva os rádios. Com a separação já aberta pelo mesmo identificador do
     * aparelho (pedido reenviado), só reserva o que faltar.
     *
     * @param int[] $items_ids
     * @return array{ok: bool, message: string, delivery?: array, failed?: array, code?: string, retry?: bool}
     */
    public static function reserve(string $client_uuid, int $vessels_id, array $items_ids): array
    {
        global $DB;

        if (!self::canDeliver()) {
            return self::fail(self::noRightMessage());
        }
        if (!DeliveryPhoto::isValidUuid($client_uuid)) {
            return self::fail(__('Não foi possível identificar a separação. Recarregue a página e tente de novo.', 'checklistitens'));
        }

        $users_id  = (int) Session::getLoginUserID();
        $items_ids = self::cleanIds($items_ids);

        $draft = self::getDraftFor($users_id);
        if ($draft !== null) {
            return self::reserveOnDraft($draft, $client_uuid, $items_ids);
        }

        // O aparelho reenviou o começo de uma separação que já foi concluída ou cancelada
        $old = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['users_id' => $users_id, 'client_uuid' => $client_uuid],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ])->current();
        if ($old) {
            return self::gone($old);
        }

        $vessel = Vessel::getActiveRow($vessels_id);
        if ($vessel === null) {
            return self::fail(__('Escolha uma embarcação ativa.', 'checklistitens'));
        }
        if (!count($items_ids)) {
            return self::fail(__('Escolha pelo menos um rádio.', 'checklistitens'));
        }

        // A entrega nasce com a entidade e o setor do primeiro rádio; sem nenhum rádio disponível,
        // nem chega a ser criada
        $groups = self::getDeliveryGroups();
        $first  = null;
        foreach ($items_ids as $items_id) {
            $first = ItemProvider::getAvailableItem(ItemProvider::RADIO, $items_id, $groups);
            if ($first !== null) {
                break;
            }
        }
        if ($first === null) {
            $message = self::unavailableMessage();

            return self::fail($message, [
                'failed' => array_map(static fn ($items_id) => ['items_id' => $items_id, 'message' => $message], $items_ids),
            ]);
        }

        $now      = Shift::now();
        $delivery = new self();
        $id       = self::safeAdd($delivery, [
            'entities_id'                      => (int) $first['entities_id'],
            'groups_id'                        => (int) $first['groups_id'],
            'users_id'                         => $users_id,
            'plugin_checklistitens_vessels_id' => $vessels_id,
            // cópia do nome: valor lido do banco, falta só o escape SQL
            'vessel_name'                      => Sanitizer::dbEscape((string) $vessel['name']),
            'status'                           => self::STATUS_DRAFT,
            'lock_draft'                       => 1,
            'client_uuid'                      => $client_uuid,
            'date_start'                       => $now,
            'shift_start'                      => Shift::startFor($now),
        ]);
        if (!$id) {
            // O índice único (users_id, lock_draft) recusou: outra separação dele nasceu agora
            $draft = self::getDraftFor($users_id);
            if ($draft !== null) {
                return self::reserveOnDraft($draft, $client_uuid, $items_ids);
            }

            return self::retry(__('Não foi possível iniciar a separação. Tente de novo.', 'checklistitens'));
        }

        $created = $delivery->fields;

        return self::locked($id, static function () use ($id, $created, $items_ids, $users_id): array {
            $result = self::reserveItems($created, $items_ids, $users_id);

            if (!count($result['present'])) {
                // Nenhum rádio ficou reservado: a separação vazia não fica aberta
                self::discardEmpty($id);

                return self::fail(
                    $result['failed'][0]['message'] ?? self::unavailableMessage(),
                    ['failed' => $result['failed']]
                );
            }

            // O primeiro rádio conferido pode ter sido levado por outra pessoa no último instante
            $first = $result['first'];
            if (
                $first !== null
                && ((int) $first['entities_id'] !== (int) $created['entities_id'] || (int) $first['groups_id'] !== (int) $created['groups_id'])
            ) {
                (new self())->update([
                    'id'          => $id,
                    'entities_id' => (int) $first['entities_id'],
                    'groups_id'   => (int) $first['groups_id'],
                ], false);
            }

            return ['ok' => true, 'message' => '', 'delivery' => self::stateOf($id), 'failed' => $result['failed']];
        });
    }

    /**
     * Reserva mais rádios na separação do usuário.
     *
     * @param int[] $items_ids
     * @return array{ok: bool, message: string, delivery?: array, failed?: array, code?: string, retry?: bool}
     */
    public static function addItems(int $id, array $items_ids): array
    {
        $error = self::requireDraft($id);
        if ($error !== null) {
            return $error;
        }

        return self::locked($id, static function () use ($id, $items_ids): array {
            $draft = null;
            $error = self::requireDraft($id, $draft);
            if ($error !== null) {
                return $error;
            }

            $items_ids = self::cleanIds($items_ids);
            if (!count($items_ids)) {
                return self::fail(__('Escolha pelo menos um rádio.', 'checklistitens'), ['delivery' => self::getState($draft)]);
            }

            $result = self::reserveItems($draft, $items_ids, (int) Session::getLoginUserID());
            self::touch($id);
            $state = self::stateOf($id);

            // Vale como feito se algum dos rádios pedidos está na separação, agora ou de um envio
            // anterior: o pedido reenviado recebe a mesma resposta
            if (!count($result['present']) && count($result['failed'])) {
                return self::fail($result['failed'][0]['message'], ['delivery' => $state, 'failed' => $result['failed']]);
            }

            return ['ok' => true, 'message' => '', 'delivery' => $state, 'failed' => $result['failed']];
        });
    }

    /**
     * Tira um rádio da separação e o libera. Rádio que já não está nela conta como tirado.
     *
     * @return array{ok: bool, message: string, delivery?: array, code?: string, retry?: bool}
     */
    public static function removeItem(int $id, int $items_id): array
    {
        $error = self::requireDraft($id);
        if ($error !== null) {
            return $error;
        }

        return self::locked($id, static function () use ($id, $items_id): array {
            $error = self::requireDraft($id);
            if ($error !== null) {
                return $error;
            }

            $row = self::findUsage($id, $items_id, Usage::STATUS_DELIVERY_DRAFT);
            if ($row !== null) {
                $released = (new Usage())->update([
                    'id'        => (int) $row['id'],
                    'status'    => Usage::STATUS_DELIVERY_CANCELLED,
                    'lock_open' => 'NULL',
                ]);
                if (!$released) {
                    return self::retry(__('Não foi possível tirar o rádio da separação. Tente de novo.', 'checklistitens'));
                }
                self::discardDefectDraft($row);
                self::touch($id);
            }

            return ['ok' => true, 'message' => '', 'delivery' => self::stateOf($id)];
        });
    }

    /**
     * Troca a embarcação da separação.
     *
     * @return array{ok: bool, message: string, delivery?: array, code?: string, retry?: bool}
     */
    public static function setVessel(int $id, int $vessels_id): array
    {
        $error = self::requireDraft($id);
        if ($error !== null) {
            return $error;
        }

        return self::locked($id, static function () use ($id, $vessels_id): array {
            $draft = null;
            $error = self::requireDraft($id, $draft);
            if ($error !== null) {
                return $error;
            }

            $vessel = Vessel::getActiveRow($vessels_id);
            if ($vessel === null) {
                return self::fail(__('Escolha uma embarcação ativa.', 'checklistitens'), ['delivery' => self::getState($draft)]);
            }

            $updated = (new self())->update([
                'id'                               => $id,
                'plugin_checklistitens_vessels_id' => $vessels_id,
                'vessel_name'                      => Sanitizer::dbEscape((string) $vessel['name']),
            ]);
            if (!$updated) {
                return self::retry(__('Não foi possível trocar a embarcação. Tente de novo.', 'checklistitens'));
            }
            self::touch($id);

            return ['ok' => true, 'message' => '', 'delivery' => self::stateOf($id)];
        });
    }

    /**
     * Problema num rádio da separação. Com laudo em aberto e "é o problema do laudo", o rádio segue
     * na entrega; senão é problema novo: o rádio sai da entrega e fica bloqueado. As fotos do
     * defeito já foram enviadas antes, uma a uma.
     *
     * @param int[] $problem_ids
     * @return array{ok: bool, message: string, delivery?: array, blocked?: bool, laudo_closed?: bool, code?: string, retry?: bool}
     */
    public static function itemProblem(int $id, int $items_id, array $problem_ids, bool $known): array
    {
        $error = self::requireDraft($id);
        if ($error !== null) {
            return $error;
        }

        return self::locked($id, static function () use ($id, $items_id, $problem_ids, $known): array {
            $error = self::requireDraft($id);
            if ($error !== null) {
                return $error;
            }

            $row = self::findUsage($id, $items_id, Usage::STATUS_DELIVERY_DRAFT);
            if ($row === null) {
                // Pedido reenviado: o rádio já saiu da entrega por este problema
                if (self::findUsage($id, $items_id, Usage::STATUS_REFUSED) !== null) {
                    return [
                        'ok'           => true,
                        'message'      => $known ? self::laudoClosedMessage() : '',
                        'delivery'     => self::stateOf($id),
                        'blocked'      => true,
                        'laudo_closed' => $known,
                    ];
                }

                return self::fail(__('Este rádio não está mais na separação.', 'checklistitens'), ['delivery' => self::stateOf($id)]);
            }
            if ((int) $row['checkout_known_issue'] === 1) {
                // Pedido reenviado: o problema conhecido já está registrado
                return ['ok' => true, 'message' => '', 'delivery' => self::stateOf($id), 'blocked' => false, 'laudo_closed' => false];
            }

            $usage_id = (int) $row['id'];
            $problems = ProblemType::getValidForType(ItemProvider::RADIO, $problem_ids);
            if (!count($problems)) {
                return self::fail(__('Marque pelo menos um problema encontrado.', 'checklistitens'), ['delivery' => self::stateOf($id)]);
            }
            if (DefectPhoto::countFor($usage_id, Usage::PHASE_CHECKOUT) < DefectPhoto::MIN_PHOTOS) {
                return self::fail(__('Tire pelo menos uma foto do equipamento com defeito.', 'checklistitens'), ['delivery' => self::stateOf($id)]);
            }

            // "É o problema do laudo" só vale se o laudo ainda estiver em aberto agora
            $laudos   = LaudoProvider::getOpenForItem(ItemProvider::RADIO, $items_id);
            $accepted = $known && count($laudos) > 0;

            $done = self::atomically(static function () use ($row, $usage_id, $problems, $laudos, $accepted): bool {
                // Cópia dos laudos em aberto: registra que o alerta foi exibido
                if (count($laudos)) {
                    UsageLaudo::recordAll($usage_id, Usage::PHASE_CHECKOUT, $laudos);
                }
                if (!self::addProblems($usage_id, Usage::PHASE_CHECKOUT, $problems)) {
                    return false;
                }
                if ($accepted) {
                    return (bool) (new Usage())->update([
                        'id'                   => $usage_id,
                        'checkout_is_ok'       => 0,
                        'checkout_known_issue' => 1,
                    ]);
                }

                return self::refuseUsage($row);
            });
            if (!$done) {
                return self::retry(__('Não foi possível registrar o problema. Tente de novo.', 'checklistitens'));
            }
            self::touch($id);

            $laudo_closed = $known && !$accepted;

            return [
                'ok'           => true,
                'message'      => $laudo_closed ? self::laudoClosedMessage() : '',
                'delivery'     => self::stateOf($id),
                'blocked'      => !$accepted,
                'laudo_closed' => $laudo_closed,
            ];
        });
    }

    /**
     * Conclui a entrega: confere tudo de novo e os rádios passam a "Com a embarcação". Lê de
     * $_POST/$_FILES: is_ok, spare_batteries, comment, a selfie e a localização.
     *
     * @return array{ok: bool, message: string, redirect?: string, delivery?: array, blocked?: int, laudo_closed?: bool, code?: string, retry?: bool}
     */
    public static function finish(int $id): array
    {
        if (!self::canDeliver()) {
            return self::fail(self::noRightMessage());
        }

        return self::locked($id, static fn (): array => self::finishLocked($id));
    }

    /**
     * Cancela a separação e libera os rádios. Vale para quem a criou, para o TI e para a ação
     * automática ($expired). Rádio que saiu por problema continua bloqueado.
     *
     * @return array{ok: bool, message: string, code?: string, retry?: bool}
     */
    public static function cancel(int $id, bool $expired = false): array
    {
        return self::locked($id, static function () use ($id, $expired): array {
            $row = self::getRow($id);
            // Sem a entrega não há o que cancelar: o resultado pedido já vale
            if ($row === null || (int) $row['status'] === self::STATUS_CANCELLED) {
                return ['ok' => true, 'message' => ''];
            }
            if (
                !$expired
                && (int) $row['users_id'] !== (int) Session::getLoginUserID()
                && !Profile::canAdministrate()
            ) {
                return self::fail(__('Só quem iniciou a separação ou o TI pode cancelá-la.', 'checklistitens'));
            }
            if ((int) $row['status'] !== self::STATUS_DRAFT) {
                return self::gone($row);
            }

            $usages = self::getUsages($id, Usage::STATUS_DELIVERY_DRAFT);
            $done   = self::atomically(static function () use ($id, $usages): bool {
                $usage = new Usage();
                foreach ($usages as $row) {
                    $released = $usage->update([
                        'id'        => (int) $row['id'],
                        'status'    => Usage::STATUS_DELIVERY_CANCELLED,
                        'lock_open' => 'NULL',
                    ]);
                    if (!$released) {
                        return false;
                    }
                }

                return (bool) (new self())->update([
                    'id'         => $id,
                    'status'     => self::STATUS_CANCELLED,
                    'lock_draft' => 'NULL',
                ]);
            });
            if (!$done) {
                return self::retry(__('Não foi possível cancelar a separação. Tente de novo.', 'checklistitens'));
            }

            // Arquivos por último: apagar não tem como ser desfeito
            DeliveryPhoto::deleteAllFor($id, 0);
            foreach ($usages as $row) {
                self::discardDefectDraft($row);
            }

            return ['ok' => true, 'message' => __('Separação cancelada. Os rádios foram liberados.', 'checklistitens')];
        });
    }

    // ------------------------------------------------------------------ ação automática

    public static function cronInfo($name)
    {
        if ($name === self::CRON_NAME) {
            return ['description' => __('Checklist uso de equipamentos: cancela a separação de entrega para embarcação (e o recebimento) parada além do prazo', 'checklistitens')];
        }

        return [];
    }

    /**
     * Separação ou recebimento parado além do prazo da configuração: a separação é cancelada (os
     * rádios voltam a ficar disponíveis) e o recebimento é descartado (os rádios continuam com a
     * embarcação). Cobre o aparelho perdido e o processo abandonado.
     */
    public static function cronExpiredeliveries(CronTask $task): int
    {
        global $DB;

        $limit = date('Y-m-d H:i:s', strtotime(Shift::now()) - Config::getDeliveryDraftHours() * HOUR_TIMESTAMP);
        $count = 0;

        // Os ids são lidos antes: cada cancelamento faz as suas próprias consultas
        $deliveries = iterator_to_array($DB->request([
            'SELECT' => 'id',
            'FROM'   => self::getTable(),
            'WHERE'  => ['status' => self::STATUS_DRAFT, 'date_mod' => ['<', $limit]],
        ]), false);
        foreach ($deliveries as $row) {
            if (self::cancel((int) $row['id'], true)['ok']) {
                $count++;
            }
        }

        $receipts = iterator_to_array($DB->request([
            'SELECT' => 'id',
            'FROM'   => DeliveryReceipt::getTable(),
            'WHERE'  => ['status' => DeliveryReceipt::STATUS_DRAFT, 'date_mod' => ['<', $limit]],
        ]), false);
        foreach ($receipts as $row) {
            if (DeliveryReceipt::cancel((int) $row['id'], true)['ok']) {
                $count++;
            }
        }

        $task->addVolume($count);

        return $count > 0 ? 1 : 0;
    }

    // ------------------------------------------------------------------ apoio (também do recebimento)

    /**
     * Resposta de erro de regra: o aparelho não deve reenviar o mesmo pedido.
     */
    public static function fail(string $message, array $extra = []): array
    {
        return ['ok' => false, 'message' => $message, 'retry' => false] + $extra;
    }

    /**
     * Resposta de falha passageira (banco, concorrência): o aparelho tenta de novo sozinho.
     */
    public static function retry(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'retry' => true];
    }

    /**
     * Resposta para a separação que não existe mais: o aparelho descarta a cópia que guardou.
     *
     * @param array|null $row linha da entrega, quando ela existe e é do usuário
     */
    public static function gone(?array $row = null): array
    {
        $status    = $row !== null ? (int) $row['status'] : 0;
        $concluded = in_array($status, [self::STATUS_DELIVERED, self::STATUS_RETURNED], true);
        if ($concluded) {
            $message = __('Esta entrega já foi concluída.', 'checklistitens');
        } elseif ($status === self::STATUS_CANCELLED) {
            $message = __('Esta separação foi cancelada ou passou do prazo, e os rádios foram liberados. Comece outra.', 'checklistitens');
        } else {
            $message = __('Esta separação não existe mais. Comece outra.', 'checklistitens');
        }

        // "concluded": as fotos já estão no servidor, o aparelho não precisa guardar para reenviar
        return ['ok' => false, 'code' => 'gone', 'message' => $message, 'retry' => false, 'concluded' => $concluded];
    }

    public static function noRightMessage(): string
    {
        return __('Você não tem permissão para fazer entregas para embarcação.', 'checklistitens');
    }

    public static function laudoClosedMessage(): string
    {
        return __('O laudo deste equipamento foi concluído: o problema bloqueia o item.', 'checklistitens');
    }

    /**
     * Marca atividade na separação: é a data da última atividade que decide a expiração.
     */
    public static function touch(int $id): void
    {
        global $DB;

        if ($id > 0) {
            $DB->update(self::getTable(), ['date_mod' => Shift::now()], ['id' => $id]);
        }
    }

    /**
     * Ids inteiros, positivos e sem repetição, na ordem em que vieram.
     *
     * @return int[]
     */
    public static function cleanIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }

        return array_values($clean);
    }

    /**
     * Rótulo e detalhe de um rádio para as telas (o mesmo da retirada comum).
     *
     * @return array{label: string, detail: string}
     */
    public static function describeItem(int $items_id): array
    {
        $row = ItemProvider::getRow(ItemProvider::RADIO, $items_id);
        if ($row === null) {
            return ['label' => '#' . $items_id, 'detail' => ''];
        }
        $item = ItemProvider::present(ItemProvider::RADIO, $row);

        return ['label' => $item['label'], 'detail' => $item['detail']];
    }

    /**
     * Fotos da entrega (recebimento 0) ou de um recebimento, no formato do estado.
     *
     * @return array<int, array{uuid: string, url: string}>
     */
    public static function photoList(int $deliveries_id, int $receipts_id): array
    {
        return array_map(
            static fn ($photo) => ['uuid' => $photo['uuid'], 'url' => $photo['url']],
            DeliveryPhoto::getFor($deliveries_id, $receipts_id)
        );
    }

    /**
     * Quando a separação (ou o recebimento) parada é cancelada sozinha.
     */
    public static function expiresAt(?string $last_activity): string
    {
        $base = $last_activity ? strtotime($last_activity) : false;
        if ($base === false) {
            $base = strtotime(Shift::now());
        }

        return date('Y-m-d H:i:s', $base + Config::getDeliveryDraftHours() * HOUR_TIMESTAMP);
    }

    /**
     * Resposta "Sim/Não" de um campo do formulário: 1, 0, ou null quando não veio.
     */
    public static function readYesNo(string $field): ?int
    {
        $value = $_POST[$field] ?? null;
        if ($value === '1' || $value === 1) {
            return 1;
        }
        if ($value === '0' || $value === 0) {
            return 0;
        }

        return null;
    }

    /**
     * Quantidade de baterias, de 0 a 99 (campo vazio conta como 0); null quando o valor não serve.
     */
    public static function readQuantity(string $field): ?int
    {
        $value = $_POST[$field] ?? '';
        $value = is_scalar($value) ? trim((string) $value) : 'x';
        if ($value === '') {
            return 0;
        }

        return preg_match('/^\d{1,2}$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * Observações do gestor prontas para gravar: texto puro, cortado no tamanho máximo. O corte é
     * feito no texto original, para não partir no meio a codificação que o GLPI aplica.
     */
    public static function readComment(string $field = 'comment'): string
    {
        $raw = $_POST[$field] ?? '';
        if (!is_string($raw)) {
            return '';
        }

        $text = trim((string) Sanitizer::unsanitize($raw));
        if (mb_strlen($text) > self::COMMENT_MAX) {
            $text = mb_substr($text, 0, self::COMMENT_MAX);
        }

        return (string) Sanitizer::sanitize($text);
    }

    /**
     * add() que não deixa um erro de banco (ex.: índice único) virar erro de tela.
     *
     * @return int id criado, ou 0
     */
    public static function safeAdd(CommonDBTM $item, array $input): int
    {
        try {
            $id = $item->add($input);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: ' . $e->getMessage());
            $id = false;
        }

        return $id ? (int) $id : 0;
    }

    /**
     * Executa uma ação com a entrega travada no banco: a conclusão, o cancelamento e a ação
     * automática de expirar não se atropelam quando chegam juntos por sessões diferentes.
     *
     * @param callable(): array $work
     */
    public static function locked(int $deliveries_id, callable $work): array
    {
        global $DB;

        $name = 'ckl_delivery_' . $deliveries_id;
        try {
            $free = $DB->getLock($name);
        } catch (Throwable $e) {
            // Sem a trava, segue: os índices únicos e as conferências de situação continuam valendo
            Toolbox::logError('checklistitens: ' . $e->getMessage());

            return $work();
        }
        if (!$free) {
            return self::retry(__('Esta entrega está sendo atualizada agora. Tente de novo em instantes.', 'checklistitens'));
        }

        try {
            return $work();
        } finally {
            try {
                $DB->releaseLock($name);
            } catch (Throwable $e) {
                // a trava termina com a conexão de qualquer forma
            }
        }
    }

    /**
     * Grava várias coisas como uma só: ou todas ficam, ou nenhuma (o aparelho reenvia depois).
     *
     * @param callable(): bool $work devolve false para desfazer
     */
    public static function atomically(callable $work): bool
    {
        global $DB;

        if ($DB->inTransaction()) {
            return (bool) $work();
        }

        $DB->beginTransaction();
        try {
            $done = (bool) $work();
        } catch (Throwable $e) {
            $DB->rollBack();
            Toolbox::logError('checklistitens: ' . $e->getMessage());

            return false;
        }
        if (!$done) {
            $DB->rollBack();

            return false;
        }

        return (bool) $DB->commit();
    }

    /**
     * Grava os problemas marcados que ainda não estão no uso e confere se todos ficaram gravados.
     *
     * @param array<int, array> $problems linhas do catálogo (ProblemType::getValidForType)
     */
    public static function addProblems(int $usages_id, int $phase, array $problems): bool
    {
        $stored  = array_flip(array_column(UsageProblem::getFor($usages_id, $phase), 'problemtypes_id'));
        $missing = array_diff_key($problems, $stored);
        if (!count($missing)) {
            return true;
        }

        try {
            UsageProblem::addAll($usages_id, $phase, $missing);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: ' . $e->getMessage());
        }
        $stored = array_flip(array_column(UsageProblem::getFor($usages_id, $phase), 'problemtypes_id'));

        return !count(array_diff_key($problems, $stored));
    }

    /**
     * Garante o bloqueio ativo do item de um uso.
     *
     * @param array $usage linha do uso (users_id = quem encontrou o problema)
     * @return bool false quando o item não ficou bloqueado
     */
    public static function ensureBlocked(array $usage, int $reason): bool
    {
        $itemtype = (string) $usage['itemtype'];
        $items_id = (int) $usage['items_id'];

        if (ItemBlock::getActiveFor($itemtype, $items_id) !== null) {
            return true;
        }

        try {
            ItemBlock::createFor($usage, $reason);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: ' . $e->getMessage());
        }

        return ItemBlock::getActiveFor($itemtype, $items_id) !== null;
    }

    // ------------------------------------------------------------------ interno

    /**
     * Confere o direito e se a entrega é uma separação aberta do usuário.
     *
     * @param array|null $draft recebe a linha da separação
     * @return array|null resposta de erro, ou null se está tudo certo
     */
    private static function requireDraft(int $id, ?array &$draft = null): ?array
    {
        $draft = null;

        if (!self::canDeliver()) {
            return self::fail(self::noRightMessage());
        }

        $row = self::getRow($id);
        if ($row === null || (int) $row['users_id'] !== (int) Session::getLoginUserID()) {
            return self::gone();
        }
        if ((int) $row['status'] !== self::STATUS_DRAFT) {
            return self::gone($row);
        }
        $draft = $row;

        return null;
    }

    /**
     * reserve() com a separação já aberta: o mesmo identificador do aparelho é o pedido reenviado
     * (só acrescenta o que faltar); outro identificador é uma segunda separação, que não pode.
     *
     * @param int[] $items_ids
     */
    private static function reserveOnDraft(array $draft, string $client_uuid, array $items_ids): array
    {
        if (strcasecmp((string) $draft['client_uuid'], $client_uuid) !== 0) {
            return [
                'ok'       => false,
                'code'     => 'draft',
                'message'  => __('Você já tem uma separação em andamento.', 'checklistitens'),
                'retry'    => false,
                'delivery' => self::getState($draft),
            ];
        }

        return self::addItems((int) $draft['id'], $items_ids);
    }

    /**
     * Reserva os rádios na separação. Cada rádio é conferido de novo e o índice único de uso aberto
     * decide quando duas pessoas pegam o mesmo ao mesmo tempo.
     *
     * @param array $delivery  linha da separação
     * @param int[] $items_ids
     * @return array{present: int[], failed: array<int, array{items_id: int, message: string}>, first: ?array}
     *         present = rádios pedidos que estão na separação (reservados agora ou antes);
     *         first = primeiro rádio reservado agora (linha crua)
     */
    private static function reserveItems(array $delivery, array $items_ids, int $users_id): array
    {
        $deliveries_id = (int) $delivery['id'];
        $groups        = self::getDeliveryGroups();
        $max           = Config::getDeliveryMaxItems();

        $current = [];
        foreach (self::getUsages($deliveries_id, Usage::STATUS_DELIVERY_DRAFT) as $row) {
            $current[(int) $row['items_id']] = true;
        }

        $now     = Shift::now();
        $shift   = Shift::startFor($now);
        $present = [];
        $failed  = [];
        $first   = null;

        foreach ($items_ids as $items_id) {
            if (isset($current[$items_id])) {
                // já está nesta separação (pedido reenviado)
                $present[] = $items_id;
                continue;
            }
            if (count($current) >= $max) {
                $failed[] = [
                    'items_id' => $items_id,
                    'message'  => sprintf(__('O limite é de %d rádios por entrega.', 'checklistitens'), $max),
                ];
                continue;
            }

            $item = ItemProvider::getAvailableItem(ItemProvider::RADIO, $items_id, $groups);
            if ($item === null) {
                $failed[] = ['items_id' => $items_id, 'message' => self::unavailableMessage()];
                continue;
            }

            $usage_id = self::safeAdd(new Usage(), [
                'entities_id'                         => (int) $item['entities_id'],
                'itemtype'                            => ItemProvider::RADIO,
                'items_id'                            => $items_id,
                'users_id'                            => $users_id,
                'groups_id'                           => (int) $item['groups_id'],
                'status'                              => Usage::STATUS_DELIVERY_DRAFT,
                'lock_open'                           => 1,
                'date_checkout'                       => $now,
                'checkout_shift_start'                => $shift,
                'checkout_is_ok'                      => 1,
                'plugin_checklistitens_deliveries_id' => $deliveries_id,
            ]);
            if (!$usage_id) {
                // o índice único de uso aberto recusou: alguém pegou o mesmo rádio agora há pouco
                $failed[] = [
                    'items_id' => $items_id,
                    'message'  => __('Este rádio acabou de ser reservado por outra pessoa. Escolha outro.', 'checklistitens'),
                ];
                continue;
            }

            $current[$items_id] = true;
            $present[]          = $items_id;
            $first              = $first ?? $item;
        }

        return ['present' => $present, 'failed' => $failed, 'first' => $first];
    }

    /**
     * Conclusão da entrega, já com ela travada.
     */
    private static function finishLocked(int $id): array
    {
        $users_id = (int) Session::getLoginUserID();
        $row      = self::getRow($id);
        if ($row === null || (int) $row['users_id'] !== $users_id) {
            return self::gone();
        }

        $redirect = Ui::url('front/delivery.php?done=delivery&id=' . $id);
        $status   = (int) $row['status'];
        if (in_array($status, [self::STATUS_DELIVERED, self::STATUS_RETURNED], true)) {
            // Pedido reenviado: a entrega já está concluída
            return ['ok' => true, 'message' => '', 'redirect' => $redirect];
        }
        if ($status !== self::STATUS_DRAFT) {
            return self::gone($row);
        }

        if (Vessel::getActiveRow((int) $row['plugin_checklistitens_vessels_id']) === null) {
            return self::fail(__('A embarcação escolhida não está mais ativa. Escolha outra.', 'checklistitens'), ['delivery' => self::getState($row)]);
        }
        if (!count(self::getUsages($id, Usage::STATUS_DELIVERY_DRAFT))) {
            return self::fail(__('Reserve pelo menos um rádio para concluir a entrega.', 'checklistitens'), ['delivery' => self::getState($row)]);
        }

        // "É o problema do laudo" é conferido de novo: laudo concluído nesse meio-tempo bloqueia o rádio
        $closed = self::refuseClosedLaudos($id);
        if ($closed === null) {
            return self::retry(__('Não foi possível concluir a entrega. Tente de novo.', 'checklistitens'));
        }
        $closed_message = $closed > 0
            ? __('O laudo de um rádio foi concluído: o problema bloqueia o item, e ele saiu da entrega.', 'checklistitens')
            : '';

        $usages = self::getUsages($id, Usage::STATUS_DELIVERY_DRAFT);
        if (!count($usages)) {
            return self::fail(
                $closed_message . ' ' . __('Não sobrou rádio na entrega: acrescente outro ou cancele.', 'checklistitens'),
                ['delivery' => self::stateOf($id)]
            );
        }

        $is_ok = self::readYesNo('is_ok');
        if ($is_ok === null) {
            return self::fail(__('Responda se os itens estão ok.', 'checklistitens'), ['delivery' => self::stateOf($id)]);
        }
        $has_problem = count(self::getUsages($id, Usage::STATUS_REFUSED)) > 0;
        foreach ($usages as $usage) {
            $has_problem = $has_problem || (int) $usage['checkout_known_issue'] === 1;
        }
        if ($is_ok === 0 && !$has_problem) {
            return self::fail(__('Indique o rádio com problema ou responda Sim.', 'checklistitens'), ['delivery' => self::stateOf($id)]);
        }
        if ($has_problem) {
            $is_ok = 0;
        }

        $min    = Config::getDeliveryMinPhotos();
        $photos = DeliveryPhoto::countFor($id, 0);
        if ($photos < $min) {
            return self::fail(
                sprintf(__('Faltam fotos: %1$d de %2$d.', 'checklistitens'), $photos, $min),
                ['delivery' => self::stateOf($id)]
            );
        }

        $batteries = self::readQuantity('spare_batteries');
        if ($batteries === null) {
            return self::fail(__('Informe as baterias reserva com um número de 0 a 99.', 'checklistitens'), ['delivery' => self::stateOf($id)]);
        }
        $covers = self::readQuantity('spare_covers');
        if ($covers === null) {
            return self::fail(__('Informe as capas com um número de 0 a 99.', 'checklistitens'), ['delivery' => self::stateOf($id)]);
        }
        $comment = self::readComment();

        $selfie = Selfie::storeFromRequest(Selfie::KIND_DELIVERY, $users_id);
        if ($selfie === null) {
            return self::fail(__('Tire a selfie para concluir a entrega.', 'checklistitens'), ['delivery' => self::stateOf($id)]);
        }

        $now    = Shift::now();
        $shift  = Shift::startFor($now);
        $fields = [
            'id'              => $id,
            'status'          => self::STATUS_DELIVERED,
            'lock_draft'      => 'NULL',
            'date_delivery'   => $now,
            // como texto: o update() do GLPI não enxerga a troca de vazio (NULL) para o número 0
            'is_ok'           => (string) $is_ok,
            'spare_batteries' => $batteries,
            'spare_covers'    => $covers,
            'comment'         => $comment,
            'selfie'          => $selfie,
        ] + Location::toFields('', Location::fromRequest());

        $done = self::atomically(static function () use ($fields, $usages, $now, $shift): bool {
            if (!(new self())->update($fields)) {
                return false;
            }
            $usage = new Usage();
            foreach ($usages as $row) {
                $delivered = $usage->update([
                    'id'                   => (int) $row['id'],
                    'status'               => Usage::STATUS_ON_VESSEL,
                    // a entrega acontece agora, não quando o rádio foi reservado
                    'date_checkout'        => $now,
                    'checkout_shift_start' => $shift,
                ]);
                if (!$delivered) {
                    return false;
                }
            }

            return true;
        });
        if (!$done) {
            Selfie::deleteFile($selfie);

            return self::retry(__('Não foi possível concluir a entrega. Tente de novo.', 'checklistitens'));
        }

        // Fotos de defeito de rádio que seguiu sem problema registrado (marcação desfeita): não ficam
        foreach ($usages as $usage) {
            self::discardDefectDraft($usage);
        }

        $result = ['ok' => true, 'message' => $closed_message, 'redirect' => $redirect];
        if ($closed > 0) {
            $result += ['blocked' => $closed, 'laudo_closed' => true];
        }

        return $result;
    }

    /**
     * Rádios que seguiam na separação como "problema do laudo" e cujo laudo já não está em aberto:
     * viram problema novo (bloqueio) e saem da entrega.
     *
     * @return int|null quantos saíram, ou null se a gravação falhou
     */
    private static function refuseClosedLaudos(int $id): ?int
    {
        $known = array_filter(
            self::getUsages($id, Usage::STATUS_DELIVERY_DRAFT),
            static fn ($usage) => (int) $usage['checkout_known_issue'] === 1
        );
        if (!count($known)) {
            return 0;
        }

        $open   = LaudoProvider::getOpenFor(ItemProvider::RADIO, array_map(static fn ($usage) => (int) $usage['items_id'], $known));
        $closed = 0;
        foreach ($known as $usage) {
            if (count($open[(int) $usage['items_id']] ?? [])) {
                continue;
            }
            if (!self::atomically(static fn (): bool => self::refuseUsage($usage))) {
                return null;
            }
            $closed++;
        }

        return $closed;
    }

    /**
     * Problema novo num rádio da separação: bloqueia o item e o uso vira "Recusado com problema".
     * Primeiro o bloqueio, depois o uso solta o rádio: ele não fica disponível nem por um instante.
     *
     * @param array $usage linha do uso
     */
    private static function refuseUsage(array $usage): bool
    {
        if (!self::ensureBlocked($usage, ItemBlock::REASON_CHECKOUT)) {
            return false;
        }

        return (bool) (new Usage())->update([
            'id'                   => (int) $usage['id'],
            'status'               => Usage::STATUS_REFUSED,
            'lock_open'            => 'NULL',
            'checkout_is_ok'       => 0,
            'checkout_known_issue' => 0,
        ]);
    }

    /**
     * Fotos de defeito enviadas para um rádio cujo problema não chegou a ser registrado: são
     * rascunho, saem junto com ele. Com o problema conhecido registrado, ficam.
     *
     * @param array $usage linha do uso, como estava antes de sair da separação
     */
    private static function discardDefectDraft(array $usage): void
    {
        if ((int) $usage['checkout_known_issue'] !== 1) {
            DefectPhoto::deleteFor((int) $usage['id'], Usage::PHASE_CHECKOUT);
        }
    }

    /**
     * Apaga a separação recém-criada em que nenhum rádio pôde ser reservado (com o histórico dela).
     */
    private static function discardEmpty(int $id): void
    {
        try {
            (new self())->delete(['id' => $id], true);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: ' . $e->getMessage());
        }
    }

    private static function stateOf(int $id): ?array
    {
        $row = self::getRow($id);

        return $row !== null ? self::getState($row) : null;
    }

    /**
     * Uso mais recente de um rádio na entrega, numa situação.
     */
    private static function findUsage(int $deliveries_id, int $items_id, int $status): ?array
    {
        global $DB;

        if ($deliveries_id <= 0 || $items_id <= 0) {
            return null;
        }

        $row = $DB->request([
            'FROM'  => Usage::getTable(),
            'WHERE' => [
                'plugin_checklistitens_deliveries_id' => $deliveries_id,
                'itemtype'                            => ItemProvider::RADIO,
                'items_id'                            => $items_id,
                'status'                              => $status,
            ],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ])->current();

        return $row ? $row : null;
    }

    /**
     * Problemas marcados em vários usos, numa consulta.
     *
     * @param int[] $usage_ids
     * @return array<int, string[]> usages_id => textos dos problemas
     */
    private static function getProblemNames(array $usage_ids, int $phase): array
    {
        global $DB;

        if (!count($usage_ids)) {
            return [];
        }

        $names = [];
        $iterator = $DB->request([
            'SELECT' => ['plugin_checklistitens_usages_id', 'problem_name'],
            'FROM'   => Install::TABLE_USAGEPROBLEMS,
            'WHERE'  => ['plugin_checklistitens_usages_id' => array_values($usage_ids), 'phase' => $phase],
            'ORDER'  => 'id',
        ]);
        foreach ($iterator as $row) {
            $names[(int) $row['plugin_checklistitens_usages_id']][] = Ui::text($row['problem_name']);
        }

        return $names;
    }

    private static function unavailableMessage(): string
    {
        return __('Este rádio não está mais disponível. Escolha outro.', 'checklistitens');
    }
}

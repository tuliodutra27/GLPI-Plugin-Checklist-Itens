<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBTM;
use Dropdown;
use Session;

/**
 * Recebimento dos rádios de volta da embarcação, no mesmo modelo da entrega: o gestor marca quais
 * rádios voltaram, responde "Itens ok?", fotografa, tira a selfie e conclui. A volta pode ser
 * parcial, por isso uma entrega pode ter vários recebimentos.
 *
 * Os rádios já estão presos pela entrega, então o recebimento em andamento não reserva nada:
 * descartá-lo deixa tudo como estava. lock_draft = 1 enquanto está em andamento; o índice único
 * (users_id, lock_draft) garante um recebimento por gestor. Como a entrega, é enviado pelo aparelho
 * em partes, e toda ação aceita ser repetida.
 */
class DeliveryReceipt extends CommonDBTM
{
    public const STATUS_DRAFT     = 1; // em andamento
    public const STATUS_DONE      = 2; // concluído
    public const STATUS_DISCARDED = 3; // descartado ou expirado

    public static $rightname = Profile::RIGHT_MANAGER;

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Recebimento da embarcação', 'Recebimentos da embarcação', $nb, 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-arrow-back-up';
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
            'plugin_checklistitens_deliveries_id' => (int) $this->fields['plugin_checklistitens_deliveries_id'],
            'groups_id'                           => $groups,
        ]) > 0;
    }

    // O recebimento só muda pelos fluxos daqui, nunca pela edição direta.
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
     * Link do registro (histórico, busca): o recebimento aparece no detalhe da entrega dele.
     */
    public static function getFormURLWithID($id = 0, $full = true)
    {
        $row = self::getRow((int) $id);

        return Ui::url('front/delivery.php?id=' . (int) ($row['plugin_checklistitens_deliveries_id'] ?? 0));
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
                'field'         => 'plugin_checklistitens_deliveries_id',
                'name'          => __('Entrega', 'checklistitens'),
                'datatype'      => 'number',
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
                'table'         => $table,
                'field'         => 'date_start',
                'name'          => __('Início do recebimento', 'checklistitens'),
                'datatype'      => 'datetime',
                'massiveaction' => false,
            ],
            [
                'id'            => '6',
                'table'         => $table,
                'field'         => 'date_receipt',
                'name'          => __('Recebido em', 'checklistitens'),
                'datatype'      => 'datetime',
                'massiveaction' => false,
            ],
            [
                'id'            => '7',
                'table'         => $table,
                'field'         => 'is_ok',
                'name'          => __('Itens ok', 'checklistitens'),
                'datatype'      => 'bool',
                'massiveaction' => false,
            ],
            [
                'id'            => '8',
                'table'         => $table,
                'field'         => 'returned_batteries',
                'name'          => __('Baterias devolvidas', 'checklistitens'),
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
            self::STATUS_DRAFT     => __('Em andamento', 'checklistitens'),
            self::STATUS_DONE      => __('Concluído', 'checklistitens'),
            self::STATUS_DISCARDED => __('Descartado', 'checklistitens'),
        ];
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
     * Recebimento em andamento do gestor (só pode haver um), ou null.
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
     * Estado completo do recebimento, no formato que a tela e o aparelho usam. Os itens são os
     * rádios da entrega que ainda estão com a embarcação.
     *
     * @param array $row linha do recebimento
     */
    public static function getState(array $row): array
    {
        $id            = (int) $row['id'];
        $deliveries_id = (int) $row['plugin_checklistitens_deliveries_id'];
        $delivery      = Delivery::getRow($deliveries_id) ?? [];
        $usages        = Delivery::getUsages($deliveries_id, Usage::STATUS_ON_VESSEL);

        $defects = DefectPhoto::getForUsages(array_map(static fn ($u) => (int) $u['id'], $usages));
        // Laudos em aberto agora (uma consulta só): é o que decide o "problema conhecido"
        $laudos  = LaudoProvider::getOpenFor(ItemProvider::RADIO, array_map(static fn ($u) => (int) $u['items_id'], $usages));

        $items = [];
        foreach ($usages as $usage) {
            $usage_id = (int) $usage['id'];
            $items_id = (int) $usage['items_id'];
            $items[]  = [
                'usage_id' => $usage_id,
                'items_id' => $items_id,
            ] + Delivery::describeItem($items_id) + [
                'laudos'        => $laudos[$items_id] ?? [],
                'defect_photos' => count($defects[$usage_id][Usage::PHASE_CHECKIN] ?? []),
            ];
        }

        return [
            'id'          => $id,
            'client_uuid' => (string) $row['client_uuid'],
            'status'      => (int) $row['status'],
            'delivery'    => [
                'id'                  => $deliveries_id,
                'vessel_name'         => Ui::text($delivery['vessel_name'] ?? ''),
                'date_delivery'       => (string) ($delivery['date_delivery'] ?? ''),
                'date_delivery_label' => Ui::datetime($delivery['date_delivery'] ?? null),
                'spare_batteries'     => (int) ($delivery['spare_batteries'] ?? 0),
                'batteries_left'      => self::batteriesLeft($deliveries_id),
            ],
            'date_start'  => (string) $row['date_start'],
            'expires_at'  => Delivery::expiresAt($row['date_mod'] ?: $row['date_start']),
            'items'       => $items,
            'photos'      => Delivery::photoList($deliveries_id, $id),
            'limits'      => [
                'min_photos' => Config::getReceiptMinPhotos(),
                'max_photos' => Config::getDeliveryMaxPhotos(),
            ],
        ];
    }

    // ------------------------------------------------------------------ ações

    /**
     * Abre o recebimento de uma entrega que tem rádio do setor do gestor com a embarcação. Com um
     * recebimento da mesma entrega já em andamento, devolve ele.
     *
     * @return array{ok: bool, message: string, receipt?: array, code?: string, retry?: bool}
     */
    public static function start(int $deliveries_id, string $client_uuid): array
    {
        if (!Delivery::canDeliver()) {
            return Delivery::fail(Delivery::noRightMessage());
        }
        if (!DeliveryPhoto::isValidUuid($client_uuid)) {
            return Delivery::fail(__('Não foi possível identificar o recebimento. Recarregue a página e tente de novo.', 'checklistitens'));
        }

        $users_id = (int) Session::getLoginUserID();

        $draft = self::getDraftFor($users_id);
        if ($draft !== null) {
            return self::startOnDraft($draft, $deliveries_id);
        }

        $delivery = Delivery::getRow($deliveries_id);
        if ($delivery === null || (int) $delivery['status'] !== Delivery::STATUS_DELIVERED) {
            return Delivery::fail(__('Esta entrega não tem mais rádio com a embarcação.', 'checklistitens'));
        }

        $groups = Delivery::getDeliveryGroups();
        $mine   = count($groups) ? countElementsInTable(Usage::getTable(), [
            'plugin_checklistitens_deliveries_id' => $deliveries_id,
            'status'                              => Usage::STATUS_ON_VESSEL,
            'groups_id'                           => $groups,
        ]) : 0;
        if (!$mine) {
            return Delivery::fail(__('Esta entrega não tem rádio do seu setor com a embarcação.', 'checklistitens'));
        }

        $now     = Shift::now();
        $receipt = new self();
        $id      = Delivery::safeAdd($receipt, [
            'plugin_checklistitens_deliveries_id' => $deliveries_id,
            'entities_id'                         => (int) $delivery['entities_id'],
            'groups_id'                           => (int) $delivery['groups_id'],
            'users_id'                            => $users_id,
            'status'                              => self::STATUS_DRAFT,
            'lock_draft'                          => 1,
            'client_uuid'                         => $client_uuid,
            'date_start'                          => $now,
            'shift_start'                         => Shift::startFor($now),
        ]);
        if (!$id) {
            // O índice único (users_id, lock_draft) recusou: outro recebimento dele nasceu agora
            $draft = self::getDraftFor($users_id);
            if ($draft !== null) {
                return self::startOnDraft($draft, $deliveries_id);
            }

            return Delivery::retry(__('Não foi possível iniciar o recebimento. Tente de novo.', 'checklistitens'));
        }

        return ['ok' => true, 'message' => '', 'receipt' => self::getState($receipt->fields)];
    }

    /**
     * Conclui o recebimento. Lê de $_POST/$_FILES: is_ok, returned_batteries, comment, a selfie e a
     * localização. As fotos do recebimento e as do defeito já foram enviadas antes, uma a uma.
     *
     * @param int[]             $usage_ids usos (rádios) que voltaram
     * @param array<int, int[]> $problems  usages_id => problemas do catálogo marcados
     * @param array<int, mixed> $known     usages_id => 1 quando "é o problema do laudo"
     * @return array{ok: bool, message: string, redirect?: string, blocked?: int, laudo_closed?: bool, receipt?: array, code?: string, retry?: bool}
     */
    public static function finish(int $id, array $usage_ids, array $problems, array $known): array
    {
        if (!Delivery::canDeliver()) {
            return Delivery::fail(Delivery::noRightMessage());
        }

        $row = self::getRow($id);
        if ($row === null || (int) $row['users_id'] !== (int) Session::getLoginUserID()) {
            return self::gone();
        }

        // A trava é da entrega: dois recebimentos da mesma entrega não mexem nos mesmos rádios juntos
        return Delivery::locked(
            (int) $row['plugin_checklistitens_deliveries_id'],
            static fn (): array => self::finishLocked($id, $usage_ids, $problems, $known)
        );
    }

    /**
     * Descarta o recebimento em andamento. Os rádios continuam "Com a embarcação". Vale para quem o
     * criou e para a ação automática ($expired).
     *
     * @return array{ok: bool, message: string, code?: string, retry?: bool}
     */
    public static function cancel(int $id, bool $expired = false): array
    {
        $row = self::getRow($id);
        // Sem o recebimento não há o que descartar: o resultado pedido já vale
        if ($row === null) {
            return ['ok' => true, 'message' => ''];
        }

        return Delivery::locked(
            (int) $row['plugin_checklistitens_deliveries_id'],
            static fn (): array => self::cancelLocked($id, $expired)
        );
    }

    // ------------------------------------------------------------------ apoio

    /**
     * Resposta para o recebimento que não existe mais: o aparelho descarta a cópia que guardou.
     *
     * @param array|null $row linha do recebimento, quando ele existe e é do usuário
     */
    public static function gone(?array $row = null): array
    {
        $status = $row !== null ? (int) $row['status'] : 0;
        if ($status === self::STATUS_DONE) {
            $message = __('Este recebimento já foi concluído.', 'checklistitens');
        } elseif ($status === self::STATUS_DISCARDED) {
            $message = __('Este recebimento foi descartado ou passou do prazo. Os rádios continuam com a embarcação: comece outro.', 'checklistitens');
        } else {
            $message = __('Este recebimento não existe mais. Comece outro.', 'checklistitens');
        }

        // "concluded": as fotos já estão no servidor, o aparelho não precisa guardar para reenviar
        return ['ok' => false, 'code' => 'gone', 'message' => $message, 'retry' => false, 'concluded' => $status === self::STATUS_DONE];
    }

    /**
     * Baterias reserva da entrega que ainda não voltaram: as entregues menos as devolvidas nos
     * recebimentos já concluídos (a volta pode ser em partes).
     */
    public static function batteriesLeft(int $deliveries_id): int
    {
        global $DB;

        $delivery = Delivery::getRow($deliveries_id);
        if ($delivery === null) {
            return 0;
        }

        $returned = 0;
        $iterator = $DB->request([
            'SELECT' => 'returned_batteries',
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_checklistitens_deliveries_id' => $deliveries_id, 'status' => self::STATUS_DONE],
        ]);
        foreach ($iterator as $row) {
            $returned += (int) $row['returned_batteries'];
        }

        return max(0, (int) $delivery['spare_batteries'] - $returned);
    }

    /**
     * Marca atividade no recebimento: é a data da última atividade que decide a expiração.
     */
    public static function touch(int $id): void
    {
        global $DB;

        if ($id > 0) {
            $DB->update(self::getTable(), ['date_mod' => Shift::now()], ['id' => $id]);
        }
    }

    // ------------------------------------------------------------------ interno

    /**
     * start() com um recebimento já em andamento: o da mesma entrega é devolvido; o de outra impede.
     */
    private static function startOnDraft(array $draft, int $deliveries_id): array
    {
        if ((int) $draft['plugin_checklistitens_deliveries_id'] === $deliveries_id) {
            return ['ok' => true, 'message' => '', 'receipt' => self::getState($draft)];
        }

        return [
            'ok'      => false,
            'code'    => 'draft',
            'message' => __('Você já tem um recebimento em andamento.', 'checklistitens'),
            'retry'   => false,
            'receipt' => self::getState($draft),
        ];
    }

    /**
     * Conclusão do recebimento, já com a entrega travada.
     */
    private static function finishLocked(int $id, array $usage_ids, array $problems, array $known): array
    {
        $users_id = (int) Session::getLoginUserID();
        $row      = self::getRow($id);
        if ($row === null || (int) $row['users_id'] !== $users_id) {
            return self::gone();
        }

        $deliveries_id = (int) $row['plugin_checklistitens_deliveries_id'];
        $status        = (int) $row['status'];
        if ($status === self::STATUS_DONE) {
            // Pedido reenviado: o recebimento já está concluído
            return self::doneResult($row, $known);
        }
        if ($status !== self::STATUS_DRAFT) {
            return self::gone($row);
        }

        // Rádios com a embarcação antes deste recebimento
        $on_vessel = [];
        foreach (Delivery::getUsages($deliveries_id, Usage::STATUS_ON_VESSEL) as $usage) {
            $on_vessel[(int) $usage['id']] = $usage;
        }

        $state     = static fn (): array => ['receipt' => self::getState($row)];
        $usage_ids = Delivery::cleanIds($usage_ids);
        if (!count($usage_ids)) {
            return Delivery::fail(__('Marque pelo menos um rádio que voltou.', 'checklistitens'), $state());
        }
        foreach ($usage_ids as $usage_id) {
            if (!isset($on_vessel[$usage_id])) {
                return Delivery::fail(__('Um dos rádios marcados não está mais com a embarcação. Confira a lista e tente de novo.', 'checklistitens'), $state());
            }
        }

        $is_ok = Delivery::readYesNo('is_ok');
        if ($is_ok === null) {
            return Delivery::fail(__('Responda se os itens estão ok.', 'checklistitens'), $state());
        }

        // Com "Sim" os problemas enviados são ignorados; com "Não", cada rádio com problema precisa
        // de problemas do catálogo e das fotos do defeito
        $with_problem = [];
        if ($is_ok === 0) {
            foreach ($usage_ids as $usage_id) {
                $marked = $problems[$usage_id] ?? [];
                if (!is_array($marked) || !count($marked)) {
                    continue;
                }
                $label = Delivery::describeItem((int) $on_vessel[$usage_id]['items_id'])['label'];
                $valid = ProblemType::getValidForType(ItemProvider::RADIO, $marked);
                if (!count($valid)) {
                    return Delivery::fail(sprintf(__('Marque pelo menos um problema encontrado no rádio %s.', 'checklistitens'), $label), $state());
                }
                if (DefectPhoto::countFor($usage_id, Usage::PHASE_CHECKIN) < DefectPhoto::MIN_PHOTOS) {
                    return Delivery::fail(sprintf(__('Tire pelo menos uma foto do defeito do rádio %s.', 'checklistitens'), $label), $state());
                }
                $with_problem[$usage_id] = $valid;
            }
            if (!count($with_problem)) {
                return Delivery::fail(__('Indique o rádio com problema ou responda Sim.', 'checklistitens'), $state());
            }
        }

        $min    = Config::getReceiptMinPhotos();
        $photos = DeliveryPhoto::countFor($deliveries_id, $id);
        if ($photos < $min) {
            return Delivery::fail(sprintf(__('Faltam fotos: %1$d de %2$d.', 'checklistitens'), $photos, $min), $state());
        }

        $batteries = Delivery::readQuantity('returned_batteries');
        if ($batteries === null) {
            return Delivery::fail(__('Informe as baterias devolvidas com um número de 0 a 99.', 'checklistitens'), $state());
        }
        // Não volta mais bateria do que foi entregue (descontando os recebimentos anteriores)
        $left = self::batteriesLeft($deliveries_id);
        if ($batteries > $left) {
            return Delivery::fail(sprintf(
                __('Esta entrega tem %d bateria(s) reserva para voltar. Informe no máximo esse número.', 'checklistitens'),
                $left
            ), $state());
        }
        $comment = Delivery::readComment();

        $selfie = Selfie::storeFromRequest(Selfie::KIND_RECEIPT, $users_id);
        if ($selfie === null) {
            return Delivery::fail(__('Tire a selfie para concluir o recebimento.', 'checklistitens'), $state());
        }

        // Laudos em aberto dos rádios recebidos, numa consulta
        $laudos = LaudoProvider::getOpenFor(
            ItemProvider::RADIO,
            array_map(static fn ($usage_id) => (int) $on_vessel[$usage_id]['items_id'], $usage_ids)
        );

        $now    = Shift::now();
        $shift  = Shift::startFor($now);
        $fields = [
            'id'                 => $id,
            'status'             => self::STATUS_DONE,
            'lock_draft'         => 'NULL',
            'date_receipt'       => $now,
            // como texto: o update() do GLPI não enxerga a troca de vazio (NULL) para o número 0
            'is_ok'              => (string) $is_ok,
            'returned_batteries' => $batteries,
            'comment'            => $comment,
            'selfie'             => $selfie,
        ] + Location::toFields('', Location::fromRequest());

        $blocked      = 0;
        $laudo_closed = false;

        $done = Delivery::atomically(static function () use (
            $id,
            $deliveries_id,
            $users_id,
            $fields,
            $usage_ids,
            $on_vessel,
            $with_problem,
            $known,
            $laudos,
            $now,
            $shift,
            &$blocked,
            &$laudo_closed
        ): bool {
            if (!(new self())->update($fields)) {
                return false;
            }

            $usage = new Usage();
            foreach ($usage_ids as $usage_id) {
                $row         = $on_vessel[$usage_id];
                $open        = $laudos[(int) $row['items_id']] ?? [];
                $has_problem = isset($with_problem[$usage_id]);
                $said_known  = $has_problem && !empty($known[$usage_id]);
                // "É o problema do laudo" só vale se o laudo ainda estiver em aberto agora
                $accepted    = $said_known && count($open) > 0;

                // Cópia dos laudos em aberto: registra que o alerta foi exibido
                if (count($open)) {
                    UsageLaudo::recordAll($usage_id, Usage::PHASE_CHECKIN, $open);
                }
                if ($has_problem) {
                    if (!Delivery::addProblems($usage_id, Usage::PHASE_CHECKIN, $with_problem[$usage_id])) {
                        return false;
                    }
                    if (!$accepted) {
                        // Bloqueio antes de o uso soltar o rádio; quem encontrou o problema é quem recebe
                        if (!Delivery::ensureBlocked(['users_id' => $users_id] + $row, ItemBlock::REASON_CHECKIN)) {
                            return false;
                        }
                        $blocked++;
                        $laudo_closed = $laudo_closed || $said_known;
                    }
                }

                $returned = $usage->update([
                    'id'                                        => $usage_id,
                    'status'                                    => Usage::STATUS_RETURNED,
                    'lock_open'                                 => 'NULL',
                    'date_checkin'                              => $now,
                    'checkin_shift_start'                       => $shift,
                    // como texto, pelo mesmo motivo do is_ok do recebimento
                    'checkin_is_ok'                             => $has_problem ? '0' : '1',
                    'checkin_known_issue'                       => $accepted ? 1 : 0,
                    'plugin_checklistitens_deliveryreceipts_id' => $id,
                ]);
                if (!$returned) {
                    return false;
                }
            }

            // O último rádio voltou: a entrega passa a devolvida
            if (!count(Delivery::getUsages($deliveries_id, Usage::STATUS_ON_VESSEL))) {
                $closed = (new Delivery())->update([
                    'id'          => $deliveries_id,
                    'status'      => Delivery::STATUS_RETURNED,
                    'date_return' => $now,
                ]);
                if (!$closed) {
                    return false;
                }
            }

            return true;
        });
        if (!$done) {
            Selfie::deleteFile($selfie);

            return Delivery::retry(__('Não foi possível concluir o recebimento. Tente de novo.', 'checklistitens'));
        }

        // Só ficam as fotos de defeito dos rádios recebidos agora com problema. As dos outros que
        // estavam com a embarcação (recebidos sem problema, ou que continuam com ela) eram de uma
        // marcação desfeita: apareceriam no histórico de um rádio recebido como ok
        foreach (array_keys($on_vessel) as $usage_id) {
            if (!isset($with_problem[$usage_id])) {
                DefectPhoto::deleteFor($usage_id, Usage::PHASE_CHECKIN);
            }
        }

        return [
            'ok'           => true,
            'message'      => $laudo_closed ? Delivery::laudoClosedMessage() : '',
            'redirect'     => self::doneUrl($deliveries_id),
            'blocked'      => $blocked,
            'laudo_closed' => $laudo_closed,
        ];
    }

    /**
     * Resposta do recebimento já concluído (pedido reenviado), refeita a partir do que ficou gravado.
     *
     * @param array $row   linha do recebimento
     * @param array $known usages_id => 1, como veio no pedido
     */
    private static function doneResult(array $row, array $known): array
    {
        global $DB;

        $blocked      = 0;
        $laudo_closed = false;

        // Problema novo neste recebimento: rádio recebido com problema e sem o aceite do laudo
        $new_problem = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'checkin_is_ok', 'checkin_known_issue'],
            'FROM'   => Usage::getTable(),
            'WHERE'  => ['plugin_checklistitens_deliveryreceipts_id' => (int) $row['id']],
        ]);
        foreach ($iterator as $usage) {
            if ($usage['checkin_is_ok'] !== null && (int) $usage['checkin_is_ok'] === 0 && (int) $usage['checkin_known_issue'] === 0) {
                $new_problem[] = (int) $usage['id'];
            }
        }
        if (count($new_problem)) {
            $blocked = countElementsInTable(ItemBlock::getTable(), [
                'plugin_checklistitens_usages_id' => $new_problem,
                'reason'                          => ItemBlock::REASON_CHECKIN,
            ]);
            foreach ($new_problem as $usage_id) {
                $laudo_closed = $laudo_closed || !empty($known[$usage_id]);
            }
        }

        return [
            'ok'           => true,
            'message'      => $laudo_closed ? Delivery::laudoClosedMessage() : '',
            'redirect'     => self::doneUrl((int) $row['plugin_checklistitens_deliveries_id']),
            'blocked'      => $blocked,
            'laudo_closed' => $laudo_closed,
        ];
    }

    /**
     * Descarte do recebimento, já com a entrega travada.
     */
    private static function cancelLocked(int $id, bool $expired): array
    {
        $row = self::getRow($id);
        if ($row === null || (int) $row['status'] === self::STATUS_DISCARDED) {
            return ['ok' => true, 'message' => ''];
        }
        if (!$expired && (int) $row['users_id'] !== (int) Session::getLoginUserID()) {
            return Delivery::fail(__('Só quem iniciou o recebimento pode descartá-lo.', 'checklistitens'));
        }
        if ((int) $row['status'] !== self::STATUS_DRAFT) {
            return self::gone($row);
        }

        $discarded = (new self())->update([
            'id'         => $id,
            'status'     => self::STATUS_DISCARDED,
            'lock_draft' => 'NULL',
        ]);
        if (!$discarded) {
            return Delivery::retry(__('Não foi possível descartar o recebimento. Tente de novo.', 'checklistitens'));
        }

        // Arquivos por último: apagar não tem como ser desfeito. As fotos de defeito enviadas para
        // este recebimento são rascunho; as dos rádios já recebidos (situação 3) ficam
        $deliveries_id = (int) $row['plugin_checklistitens_deliveries_id'];
        DeliveryPhoto::deleteAllFor($deliveries_id, $id);
        foreach (Delivery::getUsages($deliveries_id, Usage::STATUS_ON_VESSEL) as $usage) {
            DefectPhoto::deleteFor((int) $usage['id'], Usage::PHASE_CHECKIN);
        }

        return ['ok' => true, 'message' => __('Recebimento descartado. Os rádios continuam com a embarcação.', 'checklistitens')];
    }

    private static function doneUrl(int $deliveries_id): string
    {
        return Ui::url('front/delivery.php?done=receipt&id=' . $deliveries_id);
    }
}

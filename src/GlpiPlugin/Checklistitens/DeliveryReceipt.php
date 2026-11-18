<?php

namespace GlpiPlugin\Checklistitens;

use Session;

/**
 * Recebimento dos rádios de volta da embarcação, no mesmo modelo da entrega: o gestor marca quais
 * rádios voltaram, responde "Rádios ok?", fotografa, tira a selfie e conclui. A volta pode ser
 * parcial, por isso uma entrega pode ter vários recebimentos.
 *
 * Os rádios já estão presos pela entrega, então o recebimento em andamento (STATUS_DRAFT) não
 * reserva nada: descartá-lo deixa tudo como estava.
 */
class DeliveryReceipt extends DeliveryProcess
{
    public const STATUS_DONE      = 2; // concluído
    public const STATUS_DISCARDED = 3; // descartado ou expirado

    /** Fase das fotos do defeito e situação dos rádios desta etapa (DeliveryProcess::holdsItem). */
    public const DEFECT_PHASE = Usage::PHASE_CHECKIN;
    public const ITEM_STATUS  = Usage::STATUS_ON_VESSEL;

    public static function getTypeName($nb = 0)
    {
        return _n('Recebimento da embarcação', 'Recebimentos da embarcação', $nb, 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-arrow-back-up';
    }

    /**
     * Link do registro (histórico, busca): o recebimento aparece no detalhe da entrega dele.
     */
    public static function getFormURLWithID($id = 0, $full = true)
    {
        return DeliveryReport::getUrl(self::deliveryIdOf(self::getRow((int) $id) ?? []));
    }

    protected static function deliveryIdOf(array $row): int
    {
        return (int) ($row['plugin_checklistitens_deliveries_id'] ?? 0);
    }

    public static function photoOwner(array $row): array
    {
        return [self::deliveryIdOf($row), (int) $row['id']];
    }

    protected static function goneMessage(int $status): string
    {
        if ($status === self::STATUS_DONE) {
            return __('Este recebimento já foi concluído.', 'checklistitens');
        }
        if ($status === self::STATUS_DISCARDED) {
            return __('Este recebimento foi descartado ou passou do prazo. Os rádios continuam com a embarcação: comece outro.', 'checklistitens');
        }

        return __('Este recebimento não existe mais. Comece outro.', 'checklistitens');
    }

    protected static function concludedStatuses(): array
    {
        return [self::STATUS_DONE];
    }

    public static function limits(): array
    {
        return [
            'min_photos' => Config::getReceiptMinPhotos(),
            'max_photos' => Config::getDeliveryMaxPhotos(),
        ];
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
                'name'          => __('Rádios ok', 'checklistitens'),
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
            [
                'id'            => '9',
                'table'         => $table,
                'field'         => 'defective_batteries',
                'name'          => __('Baterias com defeito', 'checklistitens'),
                'datatype'      => 'number',
                'massiveaction' => false,
            ],
            [
                'id'            => '10',
                'table'         => $table,
                'field'         => 'returned_covers',
                'name'          => __('Capas devolvidas', 'checklistitens'),
                'datatype'      => 'number',
                'massiveaction' => false,
            ],
            [
                'id'            => '11',
                'table'         => $table,
                'field'         => 'covers_ok',
                'name'          => __('Capas em bom estado', 'checklistitens'),
                'datatype'      => 'bool',
                'massiveaction' => false,
            ],
        ];
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
                'spare_covers'        => (int) ($delivery['spare_covers'] ?? 0),
                'covers_left'         => self::coversLeft($deliveries_id),
            ],
            'date_start'  => (string) $row['date_start'],
            'expires_at'  => self::expiresAt($row),
            'items'       => $items,
            'photos'      => DeliveryPhoto::forState($deliveries_id, $id),
            'limits'      => self::limits(),
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
            return Reply::fail(Delivery::noRightMessage());
        }
        if (!DeliveryPhoto::isValidUuid($client_uuid)) {
            return Reply::fail(__('Não foi possível identificar o recebimento. Recarregue a página e tente de novo.', 'checklistitens'));
        }

        $users_id = (int) Session::getLoginUserID();

        $draft = self::getDraftFor($users_id);
        if ($draft !== null) {
            return self::startOnDraft($draft, $deliveries_id);
        }

        $delivery = Delivery::getRow($deliveries_id);
        if ($delivery === null || (int) $delivery['status'] !== Delivery::STATUS_DELIVERED) {
            return Reply::fail(__('Esta entrega não tem mais rádio com a embarcação.', 'checklistitens'));
        }

        $groups = Delivery::getDeliveryGroups();
        $mine   = count($groups) ? countElementsInTable(Usage::getTable(), [
            'plugin_checklistitens_deliveries_id' => $deliveries_id,
            'status'                              => Usage::STATUS_ON_VESSEL,
            'groups_id'                           => $groups,
        ]) : 0;
        if (!$mine) {
            return Reply::fail(__('Esta entrega não tem rádio do seu setor com a embarcação.', 'checklistitens'));
        }

        $now     = Shift::now();
        $receipt = new self();
        $id      = Db::add($receipt, [
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

            return Reply::retry(__('Não foi possível iniciar o recebimento. Tente de novo.', 'checklistitens'));
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
     * @param array             $accessory "battery"|"cover" => número da peça => problemas do catálogo
     * @return array{ok: bool, message: string, redirect?: string, blocked?: int, laudo_closed?: bool, receipt?: array, code?: string, retry?: bool}
     */
    public static function finish(int $id, array $usage_ids, array $problems, array $known, array $accessory = []): array
    {
        if (!Delivery::canDeliver()) {
            return Reply::fail(Delivery::noRightMessage());
        }

        $row = self::getRow($id);
        if ($row === null || (int) $row['users_id'] !== (int) Session::getLoginUserID()) {
            return self::gone();
        }

        // A trava é da entrega: dois recebimentos da mesma entrega não mexem nos mesmos rádios juntos
        return Delivery::locked(
            (int) $row['plugin_checklistitens_deliveries_id'],
            static fn (): array => self::finishLocked($id, $usage_ids, $problems, $known, $accessory)
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
     * Baterias reserva da entrega que ainda não voltaram: as entregues menos as devolvidas nos
     * recebimentos já concluídos (a volta pode ser em partes).
     */
    public static function batteriesLeft(int $deliveries_id): int
    {
        return self::remaining($deliveries_id, 'spare_batteries', 'returned_batteries');
    }

    /** Capas da entrega que ainda não voltaram, como em batteriesLeft(). */
    public static function coversLeft(int $deliveries_id): int
    {
        return self::remaining($deliveries_id, 'spare_covers', 'returned_covers');
    }

    /**
     * Quantidade entregue (coluna da entrega) menos a devolvida nos recebimentos concluídos
     * (coluna do recebimento). Os nomes das colunas vêm só do código.
     */
    private static function remaining(int $deliveries_id, string $delivered_field, string $returned_field): int
    {
        global $DB;

        $delivery = Delivery::getRow($deliveries_id);
        if ($delivery === null) {
            return 0;
        }

        $returned = 0;
        $iterator = $DB->request([
            'SELECT' => $returned_field,
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_checklistitens_deliveries_id' => $deliveries_id, 'status' => self::STATUS_DONE],
        ]);
        foreach ($iterator as $row) {
            $returned += (int) $row[$returned_field];
        }

        return max(0, (int) ($delivery[$delivered_field] ?? 0) - $returned);
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
    private static function finishLocked(int $id, array $usage_ids, array $problems, array $known, array $accessory = []): array
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
        $usage_ids = Input::ids($usage_ids);
        if (!count($usage_ids)) {
            return Reply::fail(__('Marque pelo menos um rádio que voltou.', 'checklistitens'), $state());
        }
        foreach ($usage_ids as $usage_id) {
            if (!isset($on_vessel[$usage_id])) {
                return Reply::fail(__('Um dos rádios marcados não está mais com a embarcação. Confira a lista e tente de novo.', 'checklistitens'), $state());
            }
        }

        $is_ok = Input::yesNo('is_ok');
        if ($is_ok === null) {
            return Reply::fail(__('Responda se os rádios estão ok.', 'checklistitens'), $state());
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
                    return Reply::fail(sprintf(__('Marque pelo menos um problema encontrado no rádio %s.', 'checklistitens'), $label), $state());
                }
                if (DefectPhoto::countFor($usage_id, Usage::PHASE_CHECKIN) < DefectPhoto::MIN_PHOTOS) {
                    return Reply::fail(sprintf(__('Tire pelo menos uma foto do defeito do rádio %s.', 'checklistitens'), $label), $state());
                }
                $with_problem[$usage_id] = $valid;
            }
            if (!count($with_problem)) {
                return Reply::fail(__('Indique o rádio com problema ou responda Sim.', 'checklistitens'), $state());
            }
        }

        $min    = Config::getReceiptMinPhotos();
        $photos = DeliveryPhoto::countFor($deliveries_id, $id);
        if ($photos < $min) {
            return Reply::fail(sprintf(__('Faltam fotos: %1$d de %2$d.', 'checklistitens'), $photos, $min), $state());
        }

        $batteries = Input::quantity('returned_batteries');
        if ($batteries === null) {
            return Reply::fail(__('Informe as baterias devolvidas com um número de 0 a 99.', 'checklistitens'), $state());
        }
        // Não volta mais bateria do que foi entregue (descontando os recebimentos anteriores)
        $left = self::batteriesLeft($deliveries_id);
        if ($batteries > $left) {
            return Reply::fail(sprintf(
                __('Esta entrega tem %d bateria(s) reserva para voltar. Informe no máximo esse número.', 'checklistitens'),
                $left
            ), $state());
        }
        // Bateria com defeito é uma das devolvidas (as baterias não são controladas uma a uma)
        $defective = Input::quantity('defective_batteries');
        if ($defective === null || $defective > $batteries) {
            return Reply::fail(__('As baterias com defeito não podem passar das baterias devolvidas.', 'checklistitens'), $state());
        }

        $covers = Input::quantity('returned_covers');
        if ($covers === null) {
            return Reply::fail(__('Informe as capas devolvidas com um número de 0 a 99.', 'checklistitens'), $state());
        }
        $covers_left = self::coversLeft($deliveries_id);
        if ($covers > $covers_left) {
            return Reply::fail(sprintf(
                __('Esta entrega tem %d capa(s) para voltar. Informe no máximo esse número.', 'checklistitens'),
                $covers_left
            ), $state());
        }
        // "As capas estão em bom estado?" só vale quando alguma capa voltou
        $covers_ok = $covers > 0 ? Input::yesNo('covers_ok') : null;
        if ($covers > 0 && $covers_ok === null) {
            return Reply::fail(__('Responda se as capas devolvidas estão em bom estado.', 'checklistitens'), $state());
        }
        // Capas com dano: quantas (de 1 até as devolvidas), só quando a resposta foi "Não"
        $damaged = 0;
        if ($covers_ok === 0) {
            $damaged = Input::quantity('damaged_covers');
            if ($damaged === null || $damaged < 1 || $damaged > $covers) {
                return Reply::fail(sprintf(__('Informe quantas capas estão com dano (de 1 a %d).', 'checklistitens'), $covers), $state());
            }
        }

        // Cada bateria com defeito e cada capa com dano: problemas do catálogo e pelo menos uma foto
        $pieces = [];
        foreach ([AccessoryDefect::KIND_BATTERY => [$defective, 'battery'], AccessoryDefect::KIND_COVER => [$damaged, 'cover']] as $kind => [$count, $key]) {
            $read = AccessoryDefect::readPieces($deliveries_id, $id, $kind, $count, is_array($accessory[$key] ?? null) ? $accessory[$key] : []);
            if ($read['error'] !== '') {
                return Reply::fail($read['error'], $state());
            }
            $pieces = array_merge($pieces, $read['pieces']);
        }
        $comment = Input::comment('comment', Delivery::COMMENT_MAX);

        $selfie = Selfie::storeFromRequest(Selfie::KIND_RECEIPT, $users_id);
        if ($selfie === null) {
            return Reply::fail(__('Tire a selfie para concluir o recebimento.', 'checklistitens'), $state());
        }

        // Laudos em aberto dos rádios recebidos, numa consulta
        $laudos = LaudoProvider::getOpenFor(
            ItemProvider::RADIO,
            array_map(static fn ($usage_id) => (int) $on_vessel[$usage_id]['items_id'], $usage_ids)
        );

        $now    = Shift::now();
        $shift  = Shift::startFor($now);
        $fields = [
            'id'                  => $id,
            'status'              => self::STATUS_DONE,
            'lock_draft'          => 'NULL',
            'date_receipt'        => $now,
            'is_ok'               => Db::yesNo($is_ok),
            'returned_batteries'  => $batteries,
            'defective_batteries' => $defective,
            'returned_covers'     => $covers,
            'damaged_covers'      => $damaged,
            'covers_ok'           => Db::yesNo($covers_ok),
            'comment'             => $comment,
            'selfie'              => $selfie,
        ] + Location::toFields('', Location::fromRequest());

        $blocked      = 0;
        $laudo_closed = false;

        $done = Db::atomically(static function () use (
            $id,
            $deliveries_id,
            $pieces,
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
            if (!AccessoryDefect::saveAll($deliveries_id, $id, $pieces)) {
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
                    'checkin_is_ok'                             => Db::yesNo($has_problem ? 0 : 1),
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

            return Reply::retry(__('Não foi possível concluir o recebimento. Tente de novo.', 'checklistitens'));
        }

        // Só ficam as fotos de defeito dos rádios recebidos agora com problema. As dos outros que
        // estavam com a embarcação (recebidos sem problema, ou que continuam com ela) eram de uma
        // marcação desfeita: apareceriam no histórico de um rádio recebido como ok
        foreach (array_keys($on_vessel) as $usage_id) {
            if (!isset($with_problem[$usage_id])) {
                DefectPhoto::deleteFor($usage_id, Usage::PHASE_CHECKIN);
            }
        }
        // Fotos de peça que não ficou com defeito (a quantidade diminuiu depois do envio)
        DeliveryPhoto::deleteDefectsExcept($deliveries_id, $id, array_column($pieces, 'ref'));

        // Bateria ou capa com defeito: chamado para o TI na hora, com os problemas e as fotos. Uma
        // falha aqui não desfaz o recebimento (o TI abre pelo detalhe da entrega)
        $tickets_id = count($pieces) ? TicketFactory::openForAccessories($id) : 0;

        return [
            'ok'           => true,
            'message'      => $laudo_closed ? Delivery::laudoClosedMessage() : '',
            'redirect'     => self::doneUrl($deliveries_id),
            'blocked'      => $blocked,
            'laudo_closed' => $laudo_closed,
            'ticket'       => $tickets_id,
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
            return Reply::fail(__('Só quem iniciou o recebimento pode descartá-lo.', 'checklistitens'));
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
            return Reply::retry(__('Não foi possível descartar o recebimento. Tente de novo.', 'checklistitens'));
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

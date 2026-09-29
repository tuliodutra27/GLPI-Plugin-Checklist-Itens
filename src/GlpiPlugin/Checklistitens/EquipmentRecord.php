<?php

namespace GlpiPlugin\Checklistitens;

use CommonGLPI;
use Html;
use Phone;
use QueryExpression;

/**
 * Registro de uso do equipamento: situação atual de cada rádio/telefone e a linha do tempo
 * completa de um equipamento (retiradas, conferências, devoluções, recusas, bloqueios e chamados).
 * O ativo não muda de usuário: tudo vem dos registros do plugin.
 */
class EquipmentRecord extends CommonGLPI
{
    public const SITUATION_AVAILABLE = 'available';
    public const SITUATION_IN_USE    = 'in_use';
    public const SITUATION_RELEASED  = 'released';
    public const SITUATION_BLOCKED   = 'blocked';
    public const SITUATION_REPAIR    = 'repair';
    public const SITUATION_OUT       = 'out';

    public static $rightname = Profile::RIGHT_MANAGER;

    public static function getTypeName($nb = 0)
    {
        return __('Registro de uso', 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-history';
    }

    public static function canView()
    {
        return Profile::isManager() || Profile::isAdmin();
    }

    /** @return array<string, array{label: string, color: string}> */
    public static function getSituations(): array
    {
        return [
            self::SITUATION_AVAILABLE => ['label' => __('Disponível', 'checklistitens'), 'color' => 'green'],
            self::SITUATION_IN_USE    => ['label' => __('Em uso', 'checklistitens'), 'color' => 'blue'],
            self::SITUATION_RELEASED  => ['label' => __('Liberado para devolução', 'checklistitens'), 'color' => 'cyan'],
            self::SITUATION_BLOCKED   => ['label' => __('Bloqueado, aguardando conferência', 'checklistitens'), 'color' => 'orange'],
            self::SITUATION_REPAIR    => ['label' => __('Em reparo (chamado aberto)', 'checklistitens'), 'color' => 'red'],
            self::SITUATION_OUT       => ['label' => __('Fora de uso (estado)', 'checklistitens'), 'color' => 'secondary'],
        ];
    }

    /**
     * Gestor vê equipamentos do seu setor (grupo atual do item ou algum uso no setor); TI vê todos.
     */
    public static function canViewItem(string $itemtype, int $items_id): bool
    {
        if (Profile::isAdmin()) {
            return true;
        }
        if (!Profile::isManager()) {
            return false;
        }

        $scope = Sector::forCurrentUser();
        if (!count($scope)) {
            return false;
        }

        $row = ItemProvider::getRow($itemtype, $items_id);
        if ($row !== null && in_array((int) $row['groups_id'], $scope, true)) {
            return true;
        }

        return countElementsInTable(Usage::getTable(), ['itemtype' => $itemtype, 'items_id' => $items_id, 'groups_id' => $scope]) > 0;
    }

    // ------------------------------------------------------------------ aba no Telefone

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Phone && $item->getID() > 0 && self::canViewItem(ItemProvider::PHONE, (int) $item->getID())) {
            $count = countElementsInTable(Usage::getTable(), ['itemtype' => ItemProvider::PHONE, 'items_id' => $item->getID()]);

            return self::createTabEntry(self::getTypeName(), $count);
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Phone) {
            echo Html::css(\Plugin::getWebDir('checklistitens', false) . '/css/checklistitens.css');
            Ui::render('equipment_record.html.twig', self::getRecord(ItemProvider::PHONE, (int) $item->getID(), []) + [
                'embedded'   => true,
                'page_url'   => Ui::url('front/equipment.form.php'),
            ]);
        }

        return true;
    }

    // ------------------------------------------------------------------ lista de equipamentos

    /**
     * @param array{itemtype?: string, groups_id?: int, situation?: string, q?: string} $filters
     */
    public static function getList(array $filters): array
    {
        $types = Config::getEnabledTypes();
        if (!empty($filters['itemtype']) && in_array($filters['itemtype'], $types, true)) {
            $types = [$filters['itemtype']];
        }

        $groups = Profile::isAdmin() ? null : Sector::forCurrentUser();
        if (!empty($filters['groups_id'])) {
            $chosen = Sector::expand([(int) $filters['groups_id']]);
            $groups = $groups === null ? $chosen : array_values(array_intersect($groups, $chosen));
        }

        $situations = self::getSituations();
        $states     = Config::getAvailableStates();
        $query      = mb_strtolower(trim(Ui::text($filters['q'] ?? '')));
        $list       = [];

        foreach ($types as $itemtype) {
            $open     = self::getOpenUsages($itemtype);
            $blocks   = self::getActiveBlocks($itemtype);
            $tickets  = self::countTickets($itemtype);
            $problems = self::getLastProblems($itemtype);

            foreach (ItemProvider::getItems($itemtype, $groups) as $row) {
                $id   = (int) $row['id'];
                $item = ItemProvider::present($itemtype, $row);

                $holder = '';
                if (isset($blocks[$id])) {
                    $situation = (int) $blocks[$id]['status'] === ItemBlock::STATUS_REPAIR ? self::SITUATION_REPAIR : self::SITUATION_BLOCKED;
                } elseif (isset($open[$id])) {
                    $situation = (int) $open[$id]['status'] === Usage::STATUS_RELEASED ? self::SITUATION_RELEASED : self::SITUATION_IN_USE;
                    $holder    = sprintf(__('%1$s desde %2$s', 'checklistitens'), Ui::text(getUserName((int) $open[$id]['users_id'])), Ui::datetime($open[$id]['date_checkout']));
                } elseif (!in_array((int) $row['states_id'], $states, true)) {
                    $situation = self::SITUATION_OUT;
                } else {
                    $situation = self::SITUATION_AVAILABLE;
                }

                if (!empty($filters['situation']) && $filters['situation'] !== $situation) {
                    continue;
                }
                if ($query !== '' && mb_strpos(mb_strtolower($item['label'] . ' ' . $item['detail']), $query) === false) {
                    continue;
                }

                $list[] = $item + [
                    'itemtype'        => $itemtype,
                    'type_label'      => ItemProvider::getShortTypeLabel($itemtype),
                    'icon'            => ItemProvider::getTypeIcon($itemtype),
                    'sector'          => Sector::getName((int) $row['groups_id']),
                    'state'           => Ui::text($row['state_name'] ?? ''),
                    'situation'       => $situation,
                    'situation_label' => $situations[$situation]['label'],
                    'situation_color' => $situations[$situation]['color'],
                    'holder'          => $holder,
                    'tickets'         => $tickets[$id] ?? 0,
                    'last_problem'    => $problems[$id] ?? '',
                    'url'             => self::getUrl($itemtype, $id),
                ];
            }
        }

        return $list;
    }

    public static function getUrl(string $itemtype, int $items_id): string
    {
        return Ui::url(sprintf('front/equipment.form.php?itemtype=%s&items_id=%d', urlencode($itemtype), $items_id));
    }

    /** @return array<int, array> items_id => uso aberto */
    private static function getOpenUsages(string $itemtype): array
    {
        global $DB;

        $map = [];
        foreach ($DB->request(['FROM' => Usage::getTable(), 'WHERE' => ['itemtype' => $itemtype, 'lock_open' => 1]]) as $row) {
            $map[(int) $row['items_id']] = $row;
        }

        return $map;
    }

    /** @return array<int, array> items_id => bloqueio ativo */
    private static function getActiveBlocks(string $itemtype): array
    {
        global $DB;

        $map = [];
        foreach ($DB->request(['FROM' => ItemBlock::getTable(), 'WHERE' => ['itemtype' => $itemtype, 'lock_active' => 1]]) as $row) {
            $map[(int) $row['items_id']] = $row;
        }

        return $map;
    }

    /** @return array<int, int> items_id => chamados abertos pelo plugin */
    private static function countTickets(string $itemtype): array
    {
        global $DB;

        $map = [];
        $iterator = $DB->request([
            'SELECT'  => ['items_id', new QueryExpression('COUNT(DISTINCT ' . $DB->quoteName('tickets_id') . ') AS ' . $DB->quoteName('total'))],
            'FROM'    => ItemBlock::getTable(),
            'WHERE'   => ['itemtype' => $itemtype, 'tickets_id' => ['>', 0]],
            'GROUPBY' => 'items_id',
        ]);
        foreach ($iterator as $row) {
            $map[(int) $row['items_id']] = (int) $row['total'];
        }

        return $map;
    }

    /** @return array<int, string> items_id => último problema marcado */
    private static function getLastProblems(string $itemtype): array
    {
        global $DB;

        $problems = UsageProblem::getTable();
        $usages   = Usage::getTable();

        $last = [];
        $iterator = $DB->request([
            'SELECT'     => ["$usages.items_id", new QueryExpression('MAX(' . $DB->quoteName("$problems.id") . ') AS ' . $DB->quoteName('last_id'))],
            'FROM'       => $problems,
            'INNER JOIN' => [$usages => ['ON' => [$problems => 'plugin_checklistitens_usages_id', $usages => 'id']]],
            'WHERE'      => ["$usages.itemtype" => $itemtype],
            'GROUPBY'    => "$usages.items_id",
        ]);
        foreach ($iterator as $row) {
            $last[(int) $row['last_id']] = (int) $row['items_id'];
        }
        if (!count($last)) {
            return [];
        }

        $map = [];
        foreach ($DB->request(['SELECT' => ['id', 'problem_name'], 'FROM' => $problems, 'WHERE' => ['id' => array_keys($last)]]) as $row) {
            $map[$last[(int) $row['id']]] = Ui::text($row['problem_name']);
        }

        return $map;
    }

    // ------------------------------------------------------------------ registro de um equipamento

    /**
     * Cabeçalho, indicadores e linha do tempo de um equipamento.
     *
     * @param array{date_from?: string, date_to?: string, users_id?: int} $filters
     */
    public static function getRecord(string $itemtype, int $items_id, array $filters): array
    {
        global $DB;

        $row  = ItemProvider::getRow($itemtype, $items_id);
        $item = $row !== null ? ItemProvider::present($itemtype, $row) : ['id' => $items_id, 'label' => '#' . $items_id, 'detail' => '', 'groups_id' => 0];

        // Datas só no formato AAAA-MM-DD (o valor vai direto para a consulta)
        foreach (['date_from', 'date_to'] as $key) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters[$key] ?? ''))) {
                $filters[$key] = '';
            }
        }

        $where = ['itemtype' => $itemtype, 'items_id' => $items_id];
        if ($filters['date_from'] !== '') {
            $where[] = ['date_checkout' => ['>=', $filters['date_from'] . ' 00:00:00']];
        }
        if ($filters['date_to'] !== '') {
            $where[] = ['date_checkout' => ['<=', $filters['date_to'] . ' 23:59:59']];
        }
        if (!empty($filters['users_id'])) {
            $where['users_id'] = (int) $filters['users_id'];
        }

        $usages = iterator_to_array($DB->request(['FROM' => Usage::getTable(), 'WHERE' => $where, 'ORDER' => 'date_checkout DESC']), false);
        $ids    = array_map(static fn ($u) => (int) $u['id'], $usages);

        // Carrega em lote conferências, bloqueios e problemas dos usos listados
        $confirmation_ids = [];
        foreach ($usages as $u) {
            $confirmation_ids[] = (int) $u['plugin_checklistitens_confirmations_id'];
            $confirmation_ids[] = (int) $u['plugin_checklistitens_confirmations_id_checkin'];
        }
        $confirmations = self::loadById(Confirmation::getTable(), array_filter($confirmation_ids));
        $blocks        = [];
        $problems      = [];
        if (count($ids)) {
            foreach ($DB->request(['FROM' => ItemBlock::getTable(), 'WHERE' => ['plugin_checklistitens_usages_id' => $ids]]) as $b) {
                $blocks[(int) $b['plugin_checklistitens_usages_id']] = $b;
            }
            foreach ($DB->request(['FROM' => UsageProblem::getTable(), 'WHERE' => ['plugin_checklistitens_usages_id' => $ids], 'ORDER' => 'id']) as $p) {
                $problems[(int) $p['plugin_checklistitens_usages_id']][(int) $p['phase']][] = Ui::text($p['problem_name']);
            }
        }

        $timeline = [];
        $stats    = ['uses' => 0, 'refusals' => 0, 'with_problem' => 0, 'blocks' => 0, 'tickets' => [], 'repair_seconds' => 0, 'last_use' => ''];
        $users    = [];
        $now      = strtotime(Shift::now());

        foreach ($usages as $u) {
            $id     = (int) $u['id'];
            $status = (int) $u['status'];
            $users[(int) $u['users_id']] = Ui::text(getUserName((int) $u['users_id']));

            if ($status === Usage::STATUS_REFUSED) {
                $stats['refusals']++;
            } else {
                $stats['uses']++;
                if ($stats['last_use'] === '') {
                    $stats['last_use'] = Ui::datetime($u['date_checkout']);
                }
            }
            if ($u['checkin_is_ok'] !== null && (int) $u['checkin_is_ok'] === 0) {
                $stats['with_problem']++;
            }

            $block = null;
            if (isset($blocks[$id])) {
                $b = $blocks[$id];
                $stats['blocks']++;
                if ((int) $b['tickets_id'] > 0) {
                    $stats['tickets'][(int) $b['tickets_id']] = true;
                }
                $end = $b['date_release'] ? strtotime($b['date_release']) : $now;
                $stats['repair_seconds'] += max(0, $end - strtotime($b['date_block']));
                $block = ItemBlock::present($b);
            }

            $duration = '';
            if ($u['date_checkin']) {
                $duration = Html::timestampToString(max(0, strtotime($u['date_checkin']) - strtotime($u['date_checkout'])), false);
            }

            $timeline[] = [
                'id'           => $id,
                'status'       => $status,
                'status_label' => Usage::getStatusLabels()[$status] ?? '',
                'status_color' => Usage::getStatusColor($status),
                'user'         => $users[(int) $u['users_id']],
                'sector'       => Sector::getName((int) $u['groups_id']),
                'checkout'     => [
                    'when'     => Ui::datetime($u['date_checkout']),
                    'shift'    => Shift::label($u['checkout_shift_start']),
                    'is_ok'    => (int) $u['checkout_is_ok'] === 1,
                    'problems' => $problems[$id][Usage::PHASE_CHECKOUT] ?? [],
                    'selfie'   => self::selfieInfo((string) $u['checkout_selfie'], Selfie::KIND_CHECKOUT, $id, $u['date_checkout']),
                ],
                'confirmation' => self::confirmationInfo($confirmations[(int) $u['plugin_checklistitens_confirmations_id']] ?? null),
                'checkin'      => $u['date_checkin'] ? [
                    'when'     => Ui::datetime($u['date_checkin']),
                    'shift'    => Shift::label($u['checkin_shift_start']),
                    'is_ok'    => (int) $u['checkin_is_ok'] === 1,
                    'problems' => $problems[$id][Usage::PHASE_CHECKIN] ?? [],
                    'selfie'   => self::selfieInfo((string) $u['checkin_selfie'], Selfie::KIND_CHECKIN, $id, $u['date_checkin']),
                ] : null,
                'checkin_confirmation' => self::confirmationInfo($confirmations[(int) $u['plugin_checklistitens_confirmations_id_checkin']] ?? null),
                'block'        => $block,
                'duration'     => $duration,
            ];
        }

        $active_block = ItemBlock::getActiveFor($itemtype, $items_id);
        $situations   = self::getSituations();
        if ($active_block !== null) {
            $situation = (int) $active_block['status'] === ItemBlock::STATUS_REPAIR ? self::SITUATION_REPAIR : self::SITUATION_BLOCKED;
        } else {
            $open = self::getOpenUsages($itemtype)[$items_id] ?? null;
            if ($open !== null) {
                $situation = (int) $open['status'] === Usage::STATUS_RELEASED ? self::SITUATION_RELEASED : self::SITUATION_IN_USE;
            } elseif ($row !== null && !in_array((int) $row['states_id'], Config::getAvailableStates(), true)) {
                $situation = self::SITUATION_OUT;
            } else {
                $situation = self::SITUATION_AVAILABLE;
            }
        }

        return [
            'itemtype'        => $itemtype,
            'items_id'        => $items_id,
            'type_label'      => ItemProvider::getTypeLabel($itemtype),
            'icon'            => ItemProvider::getTypeIcon($itemtype),
            'label'           => $item['label'],
            'detail'          => $item['detail'],
            'sector'          => Sector::getName((int) $item['groups_id']),
            'state'           => Ui::text($row['state_name'] ?? ''),
            'situation_label' => $situations[$situation]['label'],
            'situation_color' => $situations[$situation]['color'],
            'active_block_id' => $active_block !== null ? (int) $active_block['id'] : 0,
            'can_release'     => $active_block !== null && Profile::canAdministrate(),
            'stats'           => [
                'uses'         => $stats['uses'],
                'refusals'     => $stats['refusals'],
                'with_problem' => $stats['with_problem'],
                'blocks'       => $stats['blocks'],
                'tickets'      => count($stats['tickets']),
                'repair_time'  => $stats['repair_seconds'] ? Html::timestampToString($stats['repair_seconds'], false) : '—',
                'last_use'     => $stats['last_use'] ?: '—',
            ],
            'timeline'        => $timeline,
            'users'           => $users,
            'filters'         => [
                'date_from' => (string) ($filters['date_from'] ?? ''),
                'date_to'   => (string) ($filters['date_to'] ?? ''),
                'users_id'  => (int) ($filters['users_id'] ?? 0),
            ],
        ];
    }

    /**
     * CSV (separador ";" e BOM UTF-8, para abrir direto no Excel).
     */
    public static function sendCsv(string $itemtype, int $items_id, array $filters): void
    {
        $record = self::getRecord($itemtype, $items_id, $filters);

        header('Content-Type: text/csv; charset=UTF-8');
        header(sprintf('Content-Disposition: attachment; filename="registro_uso_%s_%d.csv"', strtolower($itemtype), $items_id));

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            __('Uso', 'checklistitens'), __('Equipamento', 'checklistitens'), __('Colaborador', 'checklistitens'),
            __('Setor', 'checklistitens'), __('Situação', 'checklistitens'), __('Retirada', 'checklistitens'),
            __('Turno da retirada', 'checklistitens'), __('Problemas na retirada', 'checklistitens'),
            __('Conferido por', 'checklistitens'), __('Conferido em', 'checklistitens'),
            __('Devolução', 'checklistitens'), __('Turno da devolução', 'checklistitens'),
            __('Problemas na devolução', 'checklistitens'), __('Devolução conferida por', 'checklistitens'),
            __('Chamado', 'checklistitens'), __('Duração', 'checklistitens'),
        ], ';');

        $label = trim($record['label'] . ' ' . $record['detail']);
        foreach ($record['timeline'] as $entry) {
            fputcsv($out, [
                $entry['id'],
                $label,
                $entry['user'],
                $entry['sector'],
                $entry['status_label'],
                $entry['checkout']['when'],
                $entry['checkout']['shift'],
                implode(', ', $entry['checkout']['problems']),
                $entry['confirmation']['user'] ?? '',
                $entry['confirmation']['when'] ?? '',
                $entry['checkin']['when'] ?? '',
                $entry['checkin']['shift'] ?? '',
                implode(', ', $entry['checkin']['problems'] ?? []),
                $entry['checkin_confirmation']['user'] ?? '',
                $entry['block']['ticket_id'] ?? '',
                $entry['duration'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    /** @return array<int, array> */
    private static function loadById(string $table, array $ids): array
    {
        global $DB;

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!count($ids)) {
            return [];
        }

        $map = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => ['id' => $ids]]) as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    private static function confirmationInfo(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'user'   => Ui::text(getUserName((int) $row['users_id'])),
            'when'   => Ui::datetime($row['date_confirmation']),
            'selfie' => self::selfieInfo((string) $row['selfie'], Selfie::KIND_CONFIRMATION, (int) $row['id'], $row['date_confirmation']),
        ];
    }

    /**
     * @return array{url: string, removed: string}
     */
    private static function selfieInfo(string $path, string $kind, int $id, ?string $date): array
    {
        if ($path !== '') {
            return ['url' => Selfie::getUrl($kind, $id), 'removed' => ''];
        }

        return ['url' => '', 'removed' => ''];
    }
}

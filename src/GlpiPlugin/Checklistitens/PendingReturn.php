<?php

namespace GlpiPlugin\Checklistitens;

use CommonGLPI;

/**
 * Devoluções pendentes: usos de turnos que já terminaram e que o colaborador não devolveu pelo
 * sistema, embora o equipamento já esteja de volta. Quem tem a permissão (perfis escolhidos na
 * configuração) encerra esses usos como "Devolução não registrada": o equipamento volta a ficar
 * disponível e o histórico guarda quem retirou, quem encerrou, quando e a observação.
 *
 * Quem vê todos os setores (TI) vê tudo; os outros perfis da lista, só o próprio setor.
 */
class PendingReturn extends CommonGLPI
{
    /** Quantos dias de encerramentos a tela mostra. */
    public const RECENT_DAYS = 30;

    public static function getTypeName($nb = 0)
    {
        return __('Devoluções pendentes', 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-clock';
    }

    /** O perfil ativo está entre os que podem encerrar uso sem devolução. */
    public static function canUse(): bool
    {
        $profile = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);

        return $profile > 0 && in_array($profile, Config::getNotReturnedProfiles(), true);
    }

    /** @return int[]|null setores que o usuário enxerga; null = todos */
    private static function scope(): ?array
    {
        return Profile::isAdmin() ? null : Sector::forCurrentUser();
    }

    /** Critério de setor para as consultas (lista vazia não enxerga nada). */
    private static function sectorWhere(): array
    {
        $scope = self::scope();
        if ($scope === null) {
            return [];
        }

        return ['groups_id' => count($scope) ? $scope : [0]];
    }

    /** Quantos usos aguardam encerramento (selo da tela inicial). */
    public static function count(): int
    {
        return countElementsInTable(Usage::getTable(), Usage::notReturnedCriteria() + self::sectorWhere());
    }

    /**
     * Dados da tela: pendentes agrupados por setor e turno da retirada, e os encerrados nos
     * últimos dias com a observação e o histórico dela.
     */
    public static function getPageData(): array
    {
        global $DB;

        $pending = iterator_to_array($DB->request([
            'FROM'  => Usage::getTable(),
            'WHERE' => Usage::notReturnedCriteria() + self::sectorWhere(),
            'ORDER' => ['groups_id', 'checkout_shift_start', 'date_checkout'],
        ]), false);

        $groups = [];
        foreach ($pending as $row) {
            $key = $row['groups_id'] . '|' . $row['checkout_shift_start'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'key'    => 'g' . count($groups),
                    'sector' => Sector::getName((int) $row['groups_id']),
                    'shift'  => Shift::label($row['checkout_shift_start']),
                    'rows'   => [],
                ];
            }
            $groups[$key]['rows'][] = self::present($row);
        }

        $closed = iterator_to_array($DB->request([
            'FROM'  => Usage::getTable(),
            'WHERE' => [
                'status'      => Usage::STATUS_NOT_RETURNED,
                'date_closed' => ['>=', date('Y-m-d H:i:s', strtotime(Shift::now()) - self::RECENT_DAYS * DAY_TIMESTAMP)],
            ] + self::sectorWhere(),
            'ORDER' => ['date_closed DESC', 'id DESC'],
        ]), false);

        $notes  = UsageNote::getForUsages(array_column($closed, 'id'));
        $recent = [];
        foreach ($closed as $row) {
            $history  = $notes[(int) $row['id']] ?? [];
            $recent[] = self::present($row) + [
                'closed_by' => Ui::text(getUserName((int) $row['closed_users_id'])),
                'closed_at' => Ui::datetime($row['date_closed']),
                'note'      => $history[0]['text'] ?? '',
                'history'   => $history,
            ];
        }

        return [
            'groups'      => array_values($groups),
            'total'       => count($pending),
            'recent'      => $recent,
            'recent_days' => self::RECENT_DAYS,
            'max_note'    => UsageNote::MAX_LENGTH,
        ];
    }

    private static function present(array $row): array
    {
        return Usage::present($row) + [
            'user'        => Ui::text(getUserName((int) $row['users_id'])),
            'sector'      => Sector::getName((int) $row['groups_id']),
            'conferred'   => (int) $row['status'] === Usage::STATUS_RELEASED,
            'record_url'  => EquipmentRecord::getUrl((string) $row['itemtype'], (int) $row['items_id']) . '#usage-' . (int) $row['id'],
        ];
    }

    /**
     * Encerra os usos marcados. Cada um é conferido de novo (pode ter sido devolvido ou encerrado
     * por outra pessoa enquanto a tela estava aberta).
     *
     * @param int[]  $usage_ids
     * @param string $note texto já tratado para gravar (Input::comment)
     * @return array{ok: bool, message: string}
     */
    public static function close(array $usage_ids, string $note, bool $confirmed): array
    {
        global $DB;

        $usage_ids = Input::ids($usage_ids);
        if (!count($usage_ids)) {
            return ['ok' => false, 'message' => __('Marque os equipamentos que já estão de volta.', 'checklistitens')];
        }
        if (!$confirmed) {
            return ['ok' => false, 'message' => __('Confirme que os equipamentos marcados estão de volta.', 'checklistitens')];
        }

        // Só os do setor de quem encerra; Usage::closeNotReturned confere a situação de novo
        $allowed = $DB->request([
            'SELECT' => 'id',
            'FROM'   => Usage::getTable(),
            'WHERE'  => ['id' => $usage_ids] + self::sectorWhere(),
        ]);

        $closed = 0;
        foreach ($allowed as $row) {
            $usage_id = (int) $row['id'];
            if (Usage::closeNotReturned($usage_id)) {
                $closed++;
                if (Ui::text($note) !== '') {
                    UsageNote::record($usage_id, $note);
                }
            }
        }

        $skipped = count($usage_ids) - $closed;
        if ($closed === 0) {
            return ['ok' => false, 'message' => __('Nenhum uso foi encerrado: eles já foram devolvidos ou encerrados.', 'checklistitens')];
        }

        $message = sprintf(_n('%d uso encerrado. O equipamento já pode ser retirado.', '%d usos encerrados. Os equipamentos já podem ser retirados.', $closed, 'checklistitens'), $closed);
        if ($skipped > 0) {
            $message .= ' ' . sprintf(_n('%d não foi encerrado (já devolvido ou encerrado).', '%d não foram encerrados (já devolvidos ou encerrados).', $skipped, 'checklistitens'), $skipped);
        }

        return ['ok' => true, 'message' => $message];
    }

    /**
     * Altera a observação de um uso encerrado sem devolução (cada alteração vira uma versão).
     *
     * @param string $note texto já tratado para gravar (Input::comment)
     * @return array{ok: bool, message: string}
     */
    public static function saveNote(int $usages_id, string $note): array
    {
        $exists = countElementsInTable(Usage::getTable(), [
            'id'     => $usages_id,
            'status' => Usage::STATUS_NOT_RETURNED,
        ] + self::sectorWhere()) > 0;
        if (!$exists) {
            return ['ok' => false, 'message' => __('Uso não encontrado.', 'checklistitens')];
        }

        return UsageNote::record($usages_id, $note)
            ? ['ok' => true, 'message' => __('Observação salva.', 'checklistitens')]
            : ['ok' => true, 'message' => __('A observação não mudou.', 'checklistitens')];
    }
}

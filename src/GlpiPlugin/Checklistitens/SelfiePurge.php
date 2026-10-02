<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBTM;

/**
 * Limpeza de selfies e de localizações: nada é apagado automaticamente. Depois do prazo (selfies:
 * 90 dias ou mais; localizações: configurável, padrão 90), os administradores do plugin recebem a
 * opção de limpar, escolhendo até que data. Cada limpeza fica registrada; os registros de uso
 * permanecem.
 */
class SelfiePurge extends CommonDBTM
{
    public const TYPE_SELFIES   = 1;
    public const TYPE_LOCATIONS = 2;

    public static $rightname = Profile::RIGHT_CONFIG;

    public $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return _n('Limpeza de selfies e localizações', 'Limpezas de selfies e localizações', $nb, 'checklistitens');
    }

    public static function getIcon()
    {
        return 'ti ti-trash';
    }

    public static function canView()
    {
        return Profile::isAdmin();
    }

    /** Data mais recente que pode ser limpa (hoje menos a retenção configurada, mínimo 90 dias). */
    public static function getMaxLimitDate(): string
    {
        return date('Y-m-d', strtotime(Shift::now()) - Config::getRetentionDays() * DAY_TIMESTAMP);
    }

    /**
     * Selfies gravadas antes da data limite (fim do dia informado).
     *
     * @return array<int, array{table: string, field: string, id: int, path: string}>
     */
    public static function getEligible(string $limit_date): array
    {
        global $DB;

        $limit = $limit_date . ' 23:59:59';
        $sources = [
            [Usage::getTable(), 'checkout_selfie', 'date_checkout'],
            [Usage::getTable(), 'checkin_selfie', 'date_checkin'],
            [Confirmation::getTable(), 'selfie', 'date_confirmation'],
        ];

        $files = [];
        foreach ($sources as [$table, $field, $date_field]) {
            $iterator = $DB->request([
                'SELECT' => ['id', $field],
                'FROM'   => $table,
                'WHERE'  => [
                    [$field => ['<>', '']],
                    [$date_field => ['<=', $limit]],
                ],
            ]);
            foreach ($iterator as $row) {
                $files[] = ['table' => $table, 'field' => $field, 'id' => (int) $row['id'], 'path' => (string) $row[$field]];
            }
        }

        return $files;
    }

    /**
     * @return array{count: int, bytes: int}
     */
    public static function getSummary(string $limit_date): array
    {
        $bytes = 0;
        $files = self::getEligible($limit_date);
        foreach ($files as $file) {
            $path = Selfie::getFullPath($file['path']);
            if ($path !== null) {
                $bytes += (int) filesize($path);
            }
        }

        return ['count' => count($files), 'bytes' => $bytes];
    }

    /**
     * Apaga as selfies até a data limite e registra a limpeza.
     *
     * @return array{ok: bool, message: string}
     */
    public static function purge(string $limit_date, int $users_id): array
    {
        global $DB;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $limit_date) || $limit_date > self::getMaxLimitDate()) {
            return ['ok' => false, 'message' => sprintf(
                __('Escolha uma data até %s (as selfies ficam guardadas por pelo menos %d dias).', 'checklistitens'),
                \Html::convDate(self::getMaxLimitDate()),
                Config::getRetentionDays()
            )];
        }

        $count = 0;
        $bytes = 0;
        foreach (self::getEligible($limit_date) as $file) {
            $bytes += Selfie::deleteFile($file['path']);
            $DB->update($file['table'], [$file['field'] => ''], ['id' => $file['id']]);
            $count++;
        }

        $purge = new self();
        $purge->add([
            'users_id'   => $users_id,
            'purge_type' => self::TYPE_SELFIES,
            'date_purge' => Shift::now(),
            'date_limit' => $limit_date . ' 23:59:59',
            'nb_files'   => $count,
            'size_bytes' => $bytes,
        ]);

        return ['ok' => true, 'message' => sprintf(
            __('%1$d selfie(s) removida(s), %2$s liberados. Os registros de uso foram mantidos.', 'checklistitens'),
            $count,
            \Toolbox::getSize($bytes)
        )];
    }

    // ------------------------------------------------------------------ localizações

    /** Data mais recente cujas localizações podem ser limpas (hoje menos a retenção configurada). */
    public static function getLocationMaxLimitDate(): string
    {
        return date('Y-m-d', strtotime(Shift::now()) - Config::getLocationRetentionDays() * DAY_TIMESTAMP);
    }

    /**
     * Localizações registradas até a data limite (fim do dia informado).
     *
     * @return array<int, array{table: string, prefix: string, id: int}>
     */
    public static function getEligibleLocations(string $limit_date): array
    {
        global $DB;

        $limit = $limit_date . ' 23:59:59';
        $sources = [
            [Usage::getTable(), 'checkout_', 'date_checkout'],
            [Usage::getTable(), 'checkin_', 'date_checkin'],
            [Confirmation::getTable(), '', 'date_confirmation'],
        ];

        $rows = [];
        foreach ($sources as [$table, $prefix, $date_field]) {
            $iterator = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => $table,
                'WHERE'  => [
                    $prefix . 'location_status' => Location::STATUS_OK,
                    [$date_field => ['<=', $limit]],
                ],
            ]);
            foreach ($iterator as $row) {
                $rows[] = ['table' => $table, 'prefix' => $prefix, 'id' => (int) $row['id']];
            }
        }

        return $rows;
    }

    /**
     * Apaga as coordenadas até a data limite (a situação passa a "removida na limpeza") e
     * registra a limpeza. O registro de uso continua.
     *
     * @return array{ok: bool, message: string}
     */
    public static function purgeLocations(string $limit_date, int $users_id): array
    {
        global $DB;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $limit_date) || $limit_date > self::getLocationMaxLimitDate()) {
            return ['ok' => false, 'message' => sprintf(
                __('Escolha uma data até %s (as localizações ficam guardadas por %d dias).', 'checklistitens'),
                \Html::convDate(self::getLocationMaxLimitDate()),
                Config::getLocationRetentionDays()
            )];
        }

        $count = 0;
        foreach (self::getEligibleLocations($limit_date) as $row) {
            $DB->update($row['table'], [
                $row['prefix'] . 'latitude'        => null,
                $row['prefix'] . 'longitude'       => null,
                $row['prefix'] . 'accuracy'        => null,
                $row['prefix'] . 'location_status' => Location::STATUS_PURGED,
            ], ['id' => $row['id']]);
            $count++;
        }

        $purge = new self();
        $purge->add([
            'users_id'   => $users_id,
            'purge_type' => self::TYPE_LOCATIONS,
            'date_purge' => Shift::now(),
            'date_limit' => $limit_date . ' 23:59:59',
            'nb_files'   => $count,
            'size_bytes' => 0,
        ]);

        return ['ok' => true, 'message' => sprintf(
            __('%d localização(ões) removida(s). Os registros de uso foram mantidos.', 'checklistitens'),
            $count
        )];
    }

    /**
     * Texto para uma selfie que não existe mais: "Selfie removida na limpeza de dd/mm/aaaa".
     */
    public static function getRemovedLabel(?string $record_date): string
    {
        static $purges = null;
        global $DB;

        if ($record_date === null || $record_date === '') {
            return '';
        }
        if ($purges === null) {
            $purges = iterator_to_array($DB->request(['FROM' => self::getTable(), 'WHERE' => ['purge_type' => self::TYPE_SELFIES], 'ORDER' => 'date_purge']), false);
        }

        foreach ($purges as $purge) {
            if ($record_date <= $purge['date_limit']) {
                return sprintf(__('Selfie removida na limpeza de %s', 'checklistitens'), \Html::convDate($purge['date_purge']));
            }
        }

        return '';
    }

    /** @return array[] limpezas anteriores, mais recentes primeiro */
    public static function getHistory(): array
    {
        global $DB;

        $list = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'ORDER' => 'date_purge DESC', 'LIMIT' => 50]) as $row) {
            $list[] = [
                'type'  => (int) ($row['purge_type'] ?? self::TYPE_SELFIES) === self::TYPE_LOCATIONS
                    ? __('Localizações', 'checklistitens')
                    : __('Selfies', 'checklistitens'),
                'user'  => Ui::text(getUserName((int) $row['users_id'])),
                'when'  => Ui::datetime($row['date_purge']),
                'limit' => \Html::convDate($row['date_limit']),
                'files' => (int) $row['nb_files'],
                'size'  => \Toolbox::getSize((int) $row['size_bytes']),
            ];
        }

        return $list;
    }
}

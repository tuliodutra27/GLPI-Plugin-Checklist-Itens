<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBTM;
use Toolbox;

/**
 * Fotos da entrega para embarcação (recebimento 0) e de cada recebimento de volta. Guardadas fora
 * da raiz web (files/_plugins/checklistitens/deliveries/AAAA/MM/...) e servidas só por
 * front/deliveryphoto.php, que confere quem pode ver.
 *
 * O aparelho envia uma foto por vez, cada uma com um identificador gerado nele (client_uuid):
 * reenviar a mesma foto depois de uma queda de conexão não cria outra. As fotos só são apagadas
 * enquanto a separação ou o recebimento está em andamento, ou quando ele é cancelado; depois de
 * concluído, ficam.
 */
class DeliveryPhoto extends CommonDBTM
{
    use ReadOnlyRecord;

    public static $rightname = Profile::RIGHT_MANAGER;

    /** Identificador gerado no aparelho (UUID ou parte dele): só hexadecimal e hífen. */
    public const UUID_PATTERN = '/^[0-9a-fA-F-]{8,36}$/';

    public static function getTypeName($nb = 0)
    {
        return _n('Foto da entrega', 'Fotos da entrega', $nb, 'checklistitens');
    }

    public static function folder(): ImageFolder
    {
        return new ImageFolder('deliveries');
    }

    public static function canView()
    {
        return Profile::isManager() || Profile::isAdmin();
    }

    public static function isValidUuid(string $uuid): bool
    {
        return preg_match(self::UUID_PATTERN, $uuid) === 1;
    }

    /** Peça da foto: vazio (foto geral) ou "battery:N" / "cover:N" (N de 1 a 99). */
    public static function isValidRef(string $ref): bool
    {
        return $ref === '' || preg_match('/^(' . implode('|', AccessoryDefect::keys()) . '):[1-9][0-9]?$/', $ref) === 1;
    }

    /**
     * Fotos das peças com defeito de um recebimento, por peça.
     *
     * @return array<string, array<int, array{id: int, uuid: string, url: string}>> "battery:1" => fotos
     */
    public static function getDefectPhotos(int $deliveries_id, int $receipts_id): array
    {
        global $DB;

        $list = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'client_uuid', 'defect_ref'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_checklistitens_deliveries_id'       => $deliveries_id,
                'plugin_checklistitens_deliveryreceipts_id' => $receipts_id,
                'NOT'                                       => ['defect_ref' => ''],
            ],
            'ORDER'  => 'id',
        ]);
        foreach ($iterator as $row) {
            $list[(string) $row['defect_ref']][] = [
                'id'   => (int) $row['id'],
                'uuid' => (string) $row['client_uuid'],
                'url'  => self::getUrl((int) $row['id']),
            ];
        }

        return $list;
    }

    /**
     * Apaga as fotos de peças que não ficaram com defeito no recebimento concluído (o gestor pode
     * ter diminuído a quantidade depois de enviar as fotos).
     *
     * @param string[] $keep peças que ficaram com defeito ("battery:1", ...)
     */
    public static function deleteDefectsExcept(int $deliveries_id, int $receipts_id, array $keep): int
    {
        $deleted = 0;
        foreach (self::getDefectPhotos($deliveries_id, $receipts_id) as $ref => $photos) {
            if (in_array($ref, $keep, true)) {
                continue;
            }
            foreach ($photos as $photo) {
                $deleted += self::removeRow((int) $photo['id']) ? 1 : 0;
            }
        }

        return $deleted;
    }

    /**
     * Grava a foto enviada em $_FILES['image'] para a entrega (recebimento 0) ou para um
     * recebimento. Quem chama já conferiu que a separação ou o recebimento é do usuário e está em
     * andamento.
     *
     * @return array{ok: bool, message: string, count: int, retry?: bool, photo?: array{uuid: string, url: string}}
     */
    public static function storeUploaded(int $deliveries_id, int $receipts_id, string $uuid, string $ref = ''): array
    {
        $fail = static fn (string $message, bool $retry = false): array => ($retry ? Reply::retry($message) : Reply::fail($message))
            + ['count' => self::countFor($deliveries_id, $receipts_id, $ref)];

        if ($deliveries_id <= 0 || $receipts_id < 0 || !self::isValidUuid($uuid) || !self::isValidRef($ref)) {
            return $fail(__('Foto sem identificação. Tire a foto de novo.', 'checklistitens'));
        }

        // Reenvio da mesma foto (a resposta anterior se perdeu no caminho): já está gravada
        $existing = self::findByUuid($deliveries_id, $uuid);
        if ($existing !== null) {
            return self::existingResult($existing, $receipts_id, $uuid, $fail);
        }

        // Foto de peça com defeito: até 5 por peça, como as fotos do defeito do rádio
        $max = $ref === '' ? Config::getDeliveryMaxPhotos() : DefectPhoto::MAX_PHOTOS;
        if (self::countFor($deliveries_id, $receipts_id, $ref) >= $max) {
            return $fail(sprintf(__('O limite é de %d fotos.', 'checklistitens'), $max));
        }

        $image = UploadedImage::fromUpload($_FILES['image'] ?? null);
        if (!$image['ok']) {
            return $fail($image['message'], $image['retry']);
        }

        $relative = self::folder()->store($deliveries_id . '_' . $receipts_id, $image['data'], $image['type']);
        if ($relative === null) {
            return $fail(__('Não foi possível guardar a foto. Tente de novo.', 'checklistitens'), true);
        }

        $id = Db::insert(self::getTable(), [
            'plugin_checklistitens_deliveries_id'       => $deliveries_id,
            'plugin_checklistitens_deliveryreceipts_id' => $receipts_id,
            'client_uuid'                               => $uuid,
            'defect_ref'                                => $ref,
            'filepath'                                  => $relative,
            'size_bytes'                                => strlen($image['data']),
            'date_creation'                             => Shift::now(),
        ]);
        if ($id <= 0) {
            // arquivo sem registro: ninguém chegaria até ele
            self::folder()->delete($relative);

            // O índice único (entrega, identificador) recusou: a mesma foto chegou duas vezes ao mesmo tempo
            $existing = self::findByUuid($deliveries_id, $uuid);
            if ($existing !== null) {
                return self::existingResult($existing, $receipts_id, $uuid, $fail);
            }

            Toolbox::logError(sprintf('checklistitens: não foi possível registrar a foto da entrega %d', $deliveries_id));
            return $fail(__('Não foi possível guardar a foto. Tente de novo.', 'checklistitens'), true);
        }

        return [
            'ok'      => true,
            'message' => '',
            'photo'   => ['uuid' => $uuid, 'url' => self::getUrl($id)],
            'count'   => self::countFor($deliveries_id, $receipts_id, $ref),
        ];
    }

    /**
     * Apaga uma foto (linha e arquivo) da separação ou do recebimento em andamento.
     *
     * @return bool false só quando a foto existe e não pôde ser apagada (foto que já não existe
     *              conta como apagada, para o reenvio do pedido dar o mesmo resultado)
     */
    public static function remove(int $deliveries_id, int $receipts_id, string $uuid): bool
    {
        global $DB;

        if (!self::isValidUuid($uuid)) {
            return true;
        }

        $row = self::findByUuid($deliveries_id, $uuid);
        if ($row === null || (int) $row['plugin_checklistitens_deliveryreceipts_id'] !== $receipts_id) {
            return true;
        }

        // Primeiro a linha: se o banco recusar, o arquivo continua no lugar
        if (!$DB->delete(self::getTable(), ['id' => (int) $row['id']])) {
            return false;
        }
        self::folder()->delete((string) $row['filepath']);

        return true;
    }

    /** Fotos gerais ($ref vazio) ou de uma peça com defeito ("battery:N", "cover:N"). */
    public static function countFor(int $deliveries_id, int $receipts_id, string $ref = ''): int
    {
        return countElementsInTable(self::getTable(), [
            'plugin_checklistitens_deliveries_id'       => $deliveries_id,
            'plugin_checklistitens_deliveryreceipts_id' => $receipts_id,
            'defect_ref'                                => $ref,
        ]);
    }

    /**
     * Fotos da entrega (recebimento 0) ou de um recebimento, na ordem em que chegaram.
     *
     * @return array<int, array{id: int, uuid: string, url: string}>
     */
    public static function getFor(int $deliveries_id, int $receipts_id, string $ref = ''): array
    {
        global $DB;

        $list = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'client_uuid'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_checklistitens_deliveries_id'       => $deliveries_id,
                'plugin_checklistitens_deliveryreceipts_id' => $receipts_id,
                'defect_ref'                                => $ref,
            ],
            'ORDER'  => 'id',
        ]);
        foreach ($iterator as $row) {
            $list[] = [
                'id'   => (int) $row['id'],
                'uuid' => (string) $row['client_uuid'],
                'url'  => self::getUrl((int) $row['id']),
            ];
        }

        return $list;
    }

    /**
     * Fotos gerais no formato do estado da separação ou do recebimento.
     *
     * @return array<int, array{uuid: string, url: string}>
     */
    public static function forState(int $deliveries_id, int $receipts_id): array
    {
        return array_map(
            static fn ($photo) => ['uuid' => $photo['uuid'], 'url' => $photo['url']],
            self::getFor($deliveries_id, $receipts_id)
        );
    }

    /**
     * Apaga todas as fotos da entrega (recebimento 0) ou de um recebimento. Só para separação
     * cancelada ou recebimento descartado: fotos de entrega ou recebimento concluído ficam.
     *
     * @return int quantas foram apagadas
     */
    public static function deleteAllFor(int $deliveries_id, int $receipts_id): int
    {
        global $DB;

        if ($deliveries_id <= 0) {
            return 0;
        }

        $rows = iterator_to_array($DB->request([
            'SELECT' => ['id', 'filepath'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_checklistitens_deliveries_id'       => $deliveries_id,
                'plugin_checklistitens_deliveryreceipts_id' => $receipts_id,
            ],
        ]), false);

        $deleted = 0;
        foreach ($rows as $row) {
            if ($DB->delete(self::getTable(), ['id' => (int) $row['id']])) {
                self::folder()->delete((string) $row['filepath']);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Arquivos das fotos das peças com defeito, para anexar ao chamado.
     *
     * @return array<string, string[]> "battery:1" => caminhos completos dos arquivos
     */
    public static function getDefectFiles(int $deliveries_id, int $receipts_id): array
    {
        global $DB;

        $files = [];
        $iterator = $DB->request([
            'SELECT' => ['filepath', 'defect_ref'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_checklistitens_deliveries_id'       => $deliveries_id,
                'plugin_checklistitens_deliveryreceipts_id' => $receipts_id,
                'NOT'                                       => ['defect_ref' => ''],
            ],
            'ORDER'  => 'id',
        ]);
        foreach ($iterator as $row) {
            $path = self::folder()->fullPath((string) $row['filepath']);
            if ($path !== null) {
                $files[(string) $row['defect_ref']][] = $path;
            }
        }

        return $files;
    }

    /** Apaga uma foto (linha e arquivo) pelo id. */
    private static function removeRow(int $id): bool
    {
        global $DB;

        $row = $DB->request(['SELECT' => ['filepath'], 'FROM' => self::getTable(), 'WHERE' => ['id' => $id]])->current();
        if (!$row || !$DB->delete(self::getTable(), ['id' => $id])) {
            return false;
        }
        self::folder()->delete((string) $row['filepath']);

        return true;
    }

    public static function getUrl(int $id): string
    {
        return Ui::url(sprintf('front/deliveryphoto.php?id=%d', $id));
    }

    /**
     * Quem pode ver a foto: quem pode ver a entrega, e quem fez o recebimento a que ela pertence.
     *
     * @param array $photo linha da tabela de fotos
     */
    public static function canViewPhoto(array $photo): bool
    {
        $receipts_id = (int) ($photo['plugin_checklistitens_deliveryreceipts_id'] ?? 0);

        return DeliveryReport::canViewDelivery(
            (int) ($photo['plugin_checklistitens_deliveries_id'] ?? 0),
            $receipts_id > 0 ? DeliveryReceipt::getRow($receipts_id) : null
        );
    }

    /** Linha da foto com aquele identificador na entrega (o índice único é por entrega), ou null. */
    private static function findByUuid(int $deliveries_id, string $uuid): ?array
    {
        global $DB;

        if ($deliveries_id <= 0 || !self::isValidUuid($uuid)) {
            return null;
        }

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_checklistitens_deliveries_id' => $deliveries_id, 'client_uuid' => $uuid],
        ])->current();

        return $row ? $row : null;
    }

    /**
     * Resposta para a foto que já estava gravada com o mesmo identificador.
     *
     * @param callable(string, bool=): array $fail
     */
    private static function existingResult(array $existing, int $receipts_id, string $uuid, callable $fail): array
    {
        if ((int) $existing['plugin_checklistitens_deliveryreceipts_id'] !== $receipts_id) {
            // mesmo identificador na entrega e num recebimento dela: não é a mesma foto
            return $fail(__('Não foi possível guardar a foto. Tire a foto de novo.', 'checklistitens'));
        }

        return [
            'ok'      => true,
            'message' => '',
            'photo'   => ['uuid' => $uuid, 'url' => self::getUrl((int) $existing['id'])],
            'count'   => self::countFor((int) $existing['plugin_checklistitens_deliveries_id'], $receipts_id),
        ];
    }
}

<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBTM;
use Session;
use Throwable;
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
    public static $rightname = Profile::RIGHT_MANAGER;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Identificador gerado no aparelho (UUID ou parte dele): só hexadecimal e hífen. */
    public const UUID_PATTERN = '/^[0-9a-fA-F-]{8,36}$/';

    public static function getTypeName($nb = 0)
    {
        return _n('Foto da entrega', 'Fotos da entrega', $nb, 'checklistitens');
    }

    public static function canView()
    {
        return Profile::isManager() || Profile::isAdmin();
    }

    // As fotos só entram e saem pelos fluxos da entrega e do recebimento.
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

    public static function getBaseDir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/checklistitens/deliveries';
    }

    public static function createBaseDir(): void
    {
        $dir = self::getBaseDir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            Toolbox::logError('checklistitens: não foi possível criar o diretório de fotos de entrega ' . $dir);
        }
    }

    public static function isValidUuid(string $uuid): bool
    {
        return preg_match(self::UUID_PATTERN, $uuid) === 1;
    }

    /**
     * Imagem enviada num campo de arquivo, já validada (JPEG ou PNG, até MAX_BYTES). Também serve
     * às fotos do defeito enviadas uma a uma.
     *
     * "retry" diz ao aparelho se vale reenviar a mesma foto: sim quando ela não chegou inteira ou o
     * servidor não conseguiu ler; não quando a própria foto é o problema.
     *
     * @return array{ok: bool, message: string, retry: bool, data?: string, type?: int}
     */
    public static function readUploadedImage(string $field = 'image'): array
    {
        $fail = static fn (string $message, bool $retry = false): array => ['ok' => false, 'message' => $message, 'retry' => $retry];

        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || !isset($file['tmp_name']) || !is_string($file['tmp_name'])) {
            return $fail(__('Nenhuma foto foi recebida.', 'checklistitens'));
        }

        $error    = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $too_big  = sprintf(__('A foto é grande demais (máximo %d MB).', 'checklistitens'), (int) (self::MAX_BYTES / 1048576));
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return $fail($too_big);
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            return $fail(__('Nenhuma foto foi recebida.', 'checklistitens'));
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            // envio cortado no meio ou falha do servidor ao receber: a foto em si pode estar boa
            if ($error !== UPLOAD_ERR_PARTIAL) {
                Toolbox::logError('checklistitens: falha ao receber foto (código de upload ' . $error . ')');
            }
            return $fail(__('A foto não chegou inteira. Tente de novo.', 'checklistitens'), true);
        }

        $data = file_get_contents($file['tmp_name']);
        if (!is_string($data)) {
            Toolbox::logError('checklistitens: não foi possível ler a foto recebida');
            return $fail(__('A foto não chegou inteira. Tente de novo.', 'checklistitens'), true);
        }
        if (strlen($data) > self::MAX_BYTES) {
            return $fail($too_big);
        }

        $info = $data !== '' ? @getimagesizefromstring($data) : false;
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return $fail(__('O arquivo enviado não é uma foto válida (JPEG ou PNG).', 'checklistitens'));
        }

        return ['ok' => true, 'message' => '', 'retry' => false, 'data' => $data, 'type' => $info[2]];
    }

    /**
     * Grava a foto enviada em $_FILES['image'] para a entrega (recebimento 0) ou para um
     * recebimento. Quem chama já conferiu que a separação ou o recebimento é do usuário e está em
     * andamento.
     *
     * @return array{ok: bool, message: string, count: int, retry?: bool, photo?: array{uuid: string, url: string}}
     */
    public static function storeUploaded(int $deliveries_id, int $receipts_id, string $uuid): array
    {
        global $DB;

        $fail = static fn (string $message, bool $retry = false): array => [
            'ok'      => false,
            'message' => $message,
            'retry'   => $retry,
            'count'   => self::countFor($deliveries_id, $receipts_id),
        ];

        if ($deliveries_id <= 0 || $receipts_id < 0 || !self::isValidUuid($uuid)) {
            return $fail(__('Foto sem identificação. Tire a foto de novo.', 'checklistitens'));
        }

        // Reenvio da mesma foto (a resposta anterior se perdeu no caminho): já está gravada
        $existing = self::findByUuid($deliveries_id, $uuid);
        if ($existing !== null) {
            return self::existingResult($existing, $receipts_id, $uuid, $fail);
        }

        $max = Config::getDeliveryMaxPhotos();
        if (self::countFor($deliveries_id, $receipts_id) >= $max) {
            return $fail(sprintf(__('O limite é de %d fotos.', 'checklistitens'), $max));
        }

        $image = self::readUploadedImage();
        if (!$image['ok']) {
            return $fail($image['message'], $image['retry']);
        }

        $relative = sprintf(
            '%s/%s/%d_%d_%s.%s',
            date('Y'),
            date('m'),
            $deliveries_id,
            $receipts_id,
            bin2hex(random_bytes(8)),
            $image['type'] === IMAGETYPE_PNG ? 'png' : 'jpg'
        );
        $path = self::getBaseDir() . '/' . $relative;

        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0770, true) && !is_dir(dirname($path))) {
            Toolbox::logError('checklistitens: não foi possível criar ' . dirname($path));
            return $fail(__('Não foi possível guardar a foto. Tente de novo.', 'checklistitens'), true);
        }
        if (file_put_contents($path, $image['data']) === false) {
            Toolbox::logError('checklistitens: não foi possível gravar a foto de entrega ' . $path);
            return $fail(__('Não foi possível guardar a foto. Tente de novo.', 'checklistitens'), true);
        }

        try {
            $ok = $DB->insert(self::getTable(), [
                'plugin_checklistitens_deliveries_id'       => $deliveries_id,
                'plugin_checklistitens_deliveryreceipts_id' => $receipts_id,
                'client_uuid'                               => $uuid,
                'filepath'                                  => $relative,
                'size_bytes'                                => strlen($image['data']),
                'date_creation'                             => Shift::now(),
            ]);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: ' . $e->getMessage());
            $ok = false;
        }
        $id = $ok ? (int) $DB->insertId() : 0;

        if ($id <= 0) {
            // arquivo sem registro: ninguém chegaria até ele
            @unlink($path);

            // O índice único (entrega, identificador) recusou: a mesma foto chegou duas vezes ao mesmo tempo
            $existing = self::findByUuid($deliveries_id, $uuid);
            if ($existing !== null) {
                return self::existingResult($existing, $receipts_id, $uuid, $fail);
            }

            Toolbox::logError(sprintf('checklistitens: não foi possível registrar a foto da entrega %d', $deliveries_id));
            return $fail(__('Não foi possível guardar a foto. Tente de novo.', 'checklistitens'), true);
        }

        // A foto conta como atividade: a separação (ou o recebimento) parada é que expira
        if ($receipts_id > 0) {
            DeliveryReceipt::touch($receipts_id);
        } else {
            Delivery::touch($deliveries_id);
        }

        return [
            'ok'      => true,
            'message' => '',
            'photo'   => ['uuid' => $uuid, 'url' => self::getUrl($id)],
            'count'   => self::countFor($deliveries_id, $receipts_id),
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
        self::deleteFile((string) $row['filepath']);

        if ($receipts_id > 0) {
            DeliveryReceipt::touch($receipts_id);
        } else {
            Delivery::touch($deliveries_id);
        }

        return true;
    }

    public static function countFor(int $deliveries_id, int $receipts_id): int
    {
        return countElementsInTable(self::getTable(), [
            'plugin_checklistitens_deliveries_id'       => $deliveries_id,
            'plugin_checklistitens_deliveryreceipts_id' => $receipts_id,
        ]);
    }

    /**
     * Fotos da entrega (recebimento 0) ou de um recebimento, na ordem em que chegaram.
     *
     * @return array<int, array{id: int, uuid: string, url: string}>
     */
    public static function getFor(int $deliveries_id, int $receipts_id): array
    {
        global $DB;

        $list = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'client_uuid'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_checklistitens_deliveries_id'       => $deliveries_id,
                'plugin_checklistitens_deliveryreceipts_id' => $receipts_id,
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
                self::deleteFile((string) $row['filepath']);
                $deleted++;
            }
        }

        return $deleted;
    }

    public static function getUrl(int $id): string
    {
        return Ui::url(sprintf('front/deliveryphoto.php?id=%d', $id));
    }

    public static function isValidPath(string $relative): bool
    {
        return preg_match('#^\d{4}/\d{2}/\d+_\d+_[0-9a-f]{16}\.(jpg|png)$#', $relative) === 1;
    }

    public static function getFullPath(string $relative): ?string
    {
        if (!self::isValidPath($relative)) {
            return null;
        }
        $path = self::getBaseDir() . '/' . $relative;

        return is_file($path) ? $path : null;
    }

    public static function send(string $relative): void
    {
        $path = self::getFullPath($relative);
        if ($path === null) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . (str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg'));
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /**
     * Quem pode ver a foto: o TI, quem fez a entrega (ou o recebimento a que a foto pertence) e o
     * gestor cujo setor inclui o grupo de algum rádio daquela entrega.
     *
     * @param array $photo linha da tabela de fotos
     */
    public static function canViewPhoto(array $photo): bool
    {
        if (Profile::isAdmin()) {
            return true;
        }

        $users_id      = (int) Session::getLoginUserID();
        $deliveries_id = (int) ($photo['plugin_checklistitens_deliveries_id'] ?? 0);
        $receipts_id   = (int) ($photo['plugin_checklistitens_deliveryreceipts_id'] ?? 0);
        if ($users_id <= 0 || $deliveries_id <= 0) {
            return false;
        }

        if (countElementsInTable(Install::TABLE_DELIVERIES, ['id' => $deliveries_id, 'users_id' => $users_id]) > 0) {
            return true;
        }
        if ($receipts_id > 0 && countElementsInTable(Install::TABLE_DELIVERYRECEIPTS, ['id' => $receipts_id, 'users_id' => $users_id]) > 0) {
            return true;
        }

        $groups = Sector::forCurrentUser();

        return Profile::isManager() && count($groups) > 0 && countElementsInTable(Install::TABLE_USAGES, [
            'plugin_checklistitens_deliveries_id' => $deliveries_id,
            'groups_id'                           => $groups,
        ]) > 0;
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

    private static function deleteFile(string $relative): void
    {
        $path = self::getFullPath($relative);
        if ($path !== null && !@unlink($path)) {
            Toolbox::logError('checklistitens: não foi possível apagar a foto de entrega ' . $path);
        }
    }
}

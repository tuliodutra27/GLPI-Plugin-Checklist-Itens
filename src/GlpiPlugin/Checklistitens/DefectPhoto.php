<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBChild;
use Toolbox;

/**
 * Fotos do equipamento com defeito: de 1 a 5, obrigatórias quando um problema é marcado na
 * retirada ou na devolução. Guardadas fora da raiz web
 * (files/_plugins/checklistitens/defects/AAAA/MM/...) e servidas só por front/photo.php.
 * Nunca são apagadas pelo plugin.
 *
 * Como a selfie, chegam já reduzidas pelo navegador (campo defect_photos[], JPEG em base64) ou,
 * se o navegador não conseguir processar, como arquivo original (campo defect_photo_files[]).
 *
 * Ao abrir o chamado do item bloqueado (TicketFactory), uma cópia de cada foto vira Documento do
 * GLPI anexado ao chamado; o arquivo do plugin continua no lugar.
 */
class DefectPhoto extends CommonDBChild
{
    public static $itemtype = Usage::class;
    public static $items_id = 'plugin_checklistitens_usages_id';

    public static $rightname = Profile::RIGHT_USAGE;

    public const MIN_PHOTOS = 1;
    public const MAX_PHOTOS = 5;

    /** Limites para as telas (bloco de fotos do defeito e peças com defeito). */
    public static function limits(): array
    {
        return ['min' => self::MIN_PHOTOS, 'max' => self::MAX_PHOTOS];
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Foto do defeito', 'Fotos do defeito', $nb, 'checklistitens');
    }

    public static function folder(): ImageFolder
    {
        return new ImageFolder('defects');
    }

    /**
     * Fotos enviadas no formulário, já validadas. Primeiro as reduzidas pelo navegador, depois os
     * arquivos originais; o que passar de MAX_PHOTOS é ignorado.
     *
     * @return array<int, array{data: string, type: int}>
     */
    public static function collectFromRequest(): array
    {
        $images = [];
        foreach ((array) ($_POST['defect_photos'] ?? []) as $value) {
            $image = UploadedImage::fromDataUrl($value);
            if ($image !== null) {
                $images[] = $image;
            }
        }
        foreach (UploadedImage::filesOf($_FILES['defect_photo_files'] ?? null) as $file) {
            $image = UploadedImage::fromUpload($file);
            if ($image['ok']) {
                $images[] = $image;
            }
        }

        return array_slice($images, 0, self::MAX_PHOTOS);
    }

    /**
     * Grava as fotos de um uso/fase (no máximo MAX_PHOTOS).
     *
     * @param array<int, array{data: string, type: int}> $images de collectFromRequest()
     * @return int quantas foram gravadas
     */
    public static function storeAll(int $usages_id, int $phase, array $images): int
    {
        $stored = 0;
        foreach (array_slice($images, 0, self::MAX_PHOTOS) as $image) {
            if (self::storeImage($usages_id, $phase, $image['data'], $image['type'])) {
                $stored++;
            }
        }

        return $stored;
    }

    /**
     * Grava o arquivo e o registro de uma foto já validada.
     *
     * @param string $uuid identificador gerado no aparelho (vazio nas fotos enviadas no formulário
     *                     da retirada comum, que não reenvia)
     */
    private static function storeImage(int $usages_id, int $phase, string $data, int $type, string $uuid = ''): bool
    {
        $relative = self::folder()->store($usages_id . '_' . $phase, $data, $type);
        if ($relative === null) {
            return false;
        }

        $id = Db::insert(self::getTable(), [
            'plugin_checklistitens_usages_id' => $usages_id,
            'phase'                           => $phase,
            'filepath'                        => $relative,
            'size_bytes'                      => strlen($data),
            'documents_id'                    => 0,
            'client_uuid'                     => $uuid,
            'date_creation'                   => Shift::now(),
        ]);
        if (!$id) {
            // arquivo sem registro: ninguém chegaria até ele
            self::folder()->delete($relative);
            Toolbox::logError(sprintf('checklistitens: não foi possível registrar a foto de defeito do uso %d', $usages_id));
            return false;
        }

        return true;
    }

    /**
     * Grava uma foto do defeito enviada sozinha em $_FILES['image'] (entrega para embarcação e
     * recebimento de volta, em que o aparelho envia uma foto por vez). Reenviar a mesma foto, com o
     * mesmo identificador, não grava outra. Quem chama já conferiu que o uso é do usuário.
     *
     * @return array{ok: bool, message: string, retry?: bool}
     */
    public static function storeUploaded(int $usages_id, int $phase, string $uuid): array
    {
        if (
            $usages_id <= 0
            || !in_array($phase, [Usage::PHASE_CHECKOUT, Usage::PHASE_CHECKIN], true)
            || !DeliveryPhoto::isValidUuid($uuid)
        ) {
            return Reply::fail(__('Foto sem identificação. Tire a foto de novo.', 'checklistitens'));
        }

        // Reenvio da mesma foto (a resposta anterior se perdeu no caminho): já está gravada
        $already = countElementsInTable(self::getTable(), [
            'plugin_checklistitens_usages_id' => $usages_id,
            'phase'                           => $phase,
            'client_uuid'                     => $uuid,
        ]);
        if ($already > 0) {
            return Reply::ok();
        }

        if (self::countFor($usages_id, $phase) >= self::MAX_PHOTOS) {
            return Reply::fail(sprintf(__('O limite é de %d fotos do defeito por equipamento.', 'checklistitens'), self::MAX_PHOTOS));
        }

        $image = UploadedImage::fromUpload($_FILES['image'] ?? null);
        if (!$image['ok']) {
            return $image['retry'] ? Reply::retry($image['message']) : Reply::fail($image['message']);
        }

        if (!self::storeImage($usages_id, $phase, $image['data'], $image['type'], $uuid)) {
            return Reply::retry(__('Não foi possível guardar a foto. Tente de novo.', 'checklistitens'));
        }

        return Reply::ok();
    }

    public static function countFor(int $usages_id, int $phase): int
    {
        if ($usages_id <= 0) {
            return 0;
        }

        return countElementsInTable(self::getTable(), [
            'plugin_checklistitens_usages_id' => $usages_id,
            'phase'                           => $phase,
        ]);
    }

    /**
     * Apaga as fotos (linhas e arquivos) de um uso e fase que ainda não viraram documento de
     * chamado. Só para rascunho descartado: fotos enviadas antes de o problema ser registrado, numa
     * separação cancelada, num recebimento descartado ou num rádio cuja marcação foi desfeita.
     * Foto de problema registrado continua sem ser apagada.
     *
     * @return int quantas foram apagadas
     */
    public static function deleteFor(int $usages_id, int $phase): int
    {
        global $DB;

        if ($usages_id <= 0) {
            return 0;
        }

        $rows = iterator_to_array($DB->request([
            'SELECT' => ['id', 'filepath'],
            'FROM'   => self::getTable(),
            'WHERE'  => [
                'plugin_checklistitens_usages_id' => $usages_id,
                'phase'                           => $phase,
                'documents_id'                    => 0,
            ],
        ]), false);

        $deleted = 0;
        foreach ($rows as $row) {
            // Primeiro a linha: se o banco recusar, o arquivo continua no lugar
            if (!$DB->delete(self::getTable(), ['id' => (int) $row['id']])) {
                continue;
            }
            self::folder()->delete((string) $row['filepath']);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Fotos de vários usos de uma vez (painel do gestor, registro do equipamento).
     *
     * @param int[] $usage_ids
     *
     * @return array<int, array<int, array<int, array{id: int, url: string}>>> [usages_id => [phase => [foto, ...]]]
     */
    public static function getForUsages(array $usage_ids): array
    {
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map('intval', $usage_ids))));
        if (!count($ids)) {
            return [];
        }

        $list = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'plugin_checklistitens_usages_id', 'phase'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_checklistitens_usages_id' => $ids],
            'ORDER'  => ['plugin_checklistitens_usages_id', 'phase', 'id'],
        ]);
        foreach ($iterator as $row) {
            $list[(int) $row['plugin_checklistitens_usages_id']][(int) $row['phase']][] = [
                'id'  => (int) $row['id'],
                'url' => self::getUrl((int) $row['id']),
            ];
        }

        return $list;
    }

    public static function getUrl(int $id): string
    {
        return Ui::url(sprintf('front/photo.php?id=%d', $id));
    }

    /**
     * Fotos de um uso/fase para o chamado, na ordem em que chegaram: número, arquivo (null se
     * sumiu) e o documento do GLPI, quando já foi anexada.
     *
     * @return array<int, array{id: int, number: int, path: ?string, documents_id: int}>
     */
    public static function forTicket(int $usages_id, int $phase): array
    {
        global $DB;

        $list = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_checklistitens_usages_id' => $usages_id, 'phase' => $phase],
            'ORDER' => 'id',
        ]);
        foreach ($iterator as $row) {
            $list[] = [
                'id'           => (int) $row['id'],
                'number'       => count($list) + 1,
                'path'         => self::folder()->fullPath((string) $row['filepath']),
                'documents_id' => (int) $row['documents_id'],
            ];
        }

        return $list;
    }

    /** A foto virou o documento do GLPI anexado ao chamado. */
    public static function setDocument(int $id, int $documents_id): void
    {
        global $DB;

        $DB->update(self::getTable(), ['documents_id' => $documents_id], ['id' => $id]);
    }
}

<?php

namespace GlpiPlugin\Checklistitens;

use Session;

/**
 * Selfies: guardadas fora da raiz web (pasta "selfies") e servidas só por front/selfie.php, que
 * confere quem pode ver.
 *
 * A foto chega já redimensionada pelo navegador (campo selfie_data) ou, se o navegador não
 * conseguir processar, o arquivo original (campo selfie_file).
 */
class Selfie
{
    public const KIND_CHECKOUT     = 'checkout';
    public const KIND_CHECKIN      = 'checkin';
    public const KIND_CONFIRMATION = 'confirmation';
    public const KIND_DELIVERY     = 'delivery';     // selfie do gestor na entrega para embarcação
    public const KIND_RECEIPT      = 'receipt';      // selfie do gestor no recebimento de volta

    public static function folder(): ImageFolder
    {
        return new ImageFolder('selfies');
    }

    /**
     * Onde fica cada selfie e a localização lida junto com ela: classe (tabela), coluna da selfie,
     * coluna da data e prefixo das colunas de localização.
     *
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: string}>
     */
    public static function sources(): array
    {
        return [
            self::KIND_CHECKOUT     => [Usage::class, 'checkout_selfie', 'date_checkout', 'checkout_'],
            self::KIND_CHECKIN      => [Usage::class, 'checkin_selfie', 'date_checkin', 'checkin_'],
            self::KIND_CONFIRMATION => [Confirmation::class, 'selfie', 'date_confirmation', ''],
            self::KIND_DELIVERY     => [Delivery::class, 'selfie', 'date_delivery', ''],
            self::KIND_RECEIPT      => [DeliveryReceipt::class, 'selfie', 'date_receipt', ''],
        ];
    }

    /**
     * Registro dono de uma selfie, com o caminho do arquivo.
     *
     * @return array{row: array, path: string}|null
     */
    public static function find(string $kind, int $id): ?array
    {
        global $DB;

        $source = self::sources()[$kind] ?? null;
        if ($source === null || $id <= 0) {
            return null;
        }
        [$class, $field] = $source;
        $row = $DB->request(['FROM' => $class::getTable(), 'WHERE' => ['id' => $id]])->current();

        return $row ? ['row' => $row, 'path' => (string) $row[$field]] : null;
    }

    /**
     * Selfie de um registro para as telas: o link, ou o aviso de que foi removida na limpeza.
     *
     * @return array{url: string, removed: string}
     */
    public static function info(string $kind, array $row): array
    {
        [, $field, $date_field] = self::sources()[$kind];
        if ((string) ($row[$field] ?? '') !== '') {
            return ['url' => self::getUrl($kind, (int) $row['id']), 'removed' => ''];
        }

        return ['url' => '', 'removed' => SelfiePurge::getRemovedLabel($row[$date_field] ?? null)];
    }

    /** Localização lida junto com a selfie, para as telas (Location::present). */
    public static function location(string $kind, array $row, string $label): array
    {
        return Location::present($row, self::sources()[$kind][3], $label);
    }

    /**
     * Valida e grava a selfie enviada no formulário.
     *
     * @return string|null caminho relativo gravado, ou null se não veio foto válida
     */
    public static function storeFromRequest(string $kind, int $users_id): ?string
    {
        if (!empty($_POST['selfie_data'])) {
            $image = UploadedImage::fromDataUrl($_POST['selfie_data']);
        } elseif (isset($_FILES['selfie_file'])) {
            $upload = UploadedImage::fromUpload($_FILES['selfie_file']);
            $image  = $upload['ok'] ? $upload : null;
        } else {
            $image = null;
        }

        return $image !== null ? self::folder()->store($kind . '_' . $users_id, $image['data'], $image['type']) : null;
    }

    public static function getUrl(string $kind, int $id): string
    {
        return Ui::url(sprintf('front/selfie.php?kind=%s&id=%d', $kind, $id));
    }

    /**
     * Quem pode ver a selfie de um registro: o próprio usuário, o gestor cujo setor inclui o
     * grupo do registro, ou o TI.
     */
    public static function canView(int $owner_users_id, int $groups_id): bool
    {
        if ($owner_users_id === (int) Session::getLoginUserID() || Profile::isAdmin()) {
            return true;
        }

        return Profile::isManager() && in_array($groups_id, Sector::forCurrentUser(), true);
    }
}

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

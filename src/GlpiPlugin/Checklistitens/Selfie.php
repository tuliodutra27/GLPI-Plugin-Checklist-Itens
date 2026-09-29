<?php

namespace GlpiPlugin\Checklistitens;

use Glpi\Toolbox\Sanitizer;
use Session;
use Toolbox;

/**
 * Selfies: guardadas fora da raiz web (files/_plugins/checklistitens/selfies/AAAA/MM/...) e
 * servidas só por front/selfie.php, que confere quem pode ver.
 *
 * A foto chega de duas formas: já redimensionada pelo navegador (campo selfie_data, JPEG em
 * base64) ou, se o navegador não conseguir processar, o arquivo original (campo selfie_file).
 */
class Selfie
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const KIND_CHECKOUT     = 'checkout';
    public const KIND_CHECKIN      = 'checkin';
    public const KIND_CONFIRMATION = 'confirmation';

    public static function getBaseDir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/checklistitens/selfies';
    }

    public static function createBaseDir(): void
    {
        $dir = self::getBaseDir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            Toolbox::logError('checklistitens: não foi possível criar o diretório de selfies ' . $dir);
        }
    }

    /**
     * Na desinstalação as tabelas são apagadas; as imagens ficariam sem vínculo nenhum
     * (dado pessoal órfão), então são removidas junto.
     */
    public static function removeBaseDir(): void
    {
        $dir = GLPI_PLUGIN_DOC_DIR . '/checklistitens';
        if (is_dir($dir)) {
            Toolbox::deleteDir($dir);
        }
    }

    /**
     * Valida e grava a selfie enviada no formulário.
     *
     * @return string|null caminho relativo gravado, ou null se não veio foto válida
     */
    public static function storeFromRequest(string $kind, int $users_id): ?string
    {
        $data = null;

        if (!empty($_POST['selfie_data'])) {
            $raw = Sanitizer::unsanitize((string) $_POST['selfie_data']);
            if (preg_match('#^data:image/(?:jpeg|png);base64,([A-Za-z0-9+/=\s]+)$#', $raw, $matches)) {
                $data = base64_decode(preg_replace('/\s+/', '', $matches[1]), true);
            }
        } elseif (isset($_FILES['selfie_file']['tmp_name']) && is_uploaded_file($_FILES['selfie_file']['tmp_name'])) {
            if ((int) $_FILES['selfie_file']['size'] <= self::MAX_BYTES) {
                $data = file_get_contents($_FILES['selfie_file']['tmp_name']);
            }
        }

        if (!is_string($data) || $data === '' || strlen($data) > self::MAX_BYTES) {
            return null;
        }

        $info = @getimagesizefromstring($data);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null;
        }

        $relative = sprintf(
            '%s/%s/%s_%d_%s.%s',
            date('Y'),
            date('m'),
            $kind,
            $users_id,
            bin2hex(random_bytes(8)),
            $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg'
        );
        $path = self::getBaseDir() . '/' . $relative;

        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0770, true)) {
            Toolbox::logError('checklistitens: não foi possível criar ' . dirname($path));
            return null;
        }
        if (file_put_contents($path, $data) === false) {
            Toolbox::logError('checklistitens: não foi possível gravar a selfie ' . $path);
            return null;
        }

        return $relative;
    }

    public static function isValidPath(string $relative): bool
    {
        return preg_match('#^\d{4}/\d{2}/(checkout|checkin|confirmation)_\d+_[0-9a-f]{16}\.(jpg|png)$#', $relative) === 1;
    }

    public static function getFullPath(string $relative): ?string
    {
        if (!self::isValidPath($relative)) {
            return null;
        }
        $path = self::getBaseDir() . '/' . $relative;

        return is_file($path) ? $path : null;
    }

    /** Apaga o arquivo e devolve quantos bytes foram liberados. */
    public static function deleteFile(string $relative): int
    {
        $path = self::getFullPath($relative);
        if ($path === null) {
            return 0;
        }
        $size = (int) filesize($path);

        return @unlink($path) ? $size : 0;
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
}

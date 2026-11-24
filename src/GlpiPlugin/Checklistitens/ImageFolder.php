<?php

namespace GlpiPlugin\Checklistitens;

use Toolbox;

/**
 * Pasta de imagens do plugin, fora da raiz web (files/_plugins/checklistitens/<pasta>/AAAA/MM/...).
 * Os arquivos só saem por um controlador que confere quem pode ver (front/selfie.php, photo.php,
 * deliveryphoto.php). O nome do arquivo é "<dono>_<número>_<aleatório>.<jpg|png>"; o caminho
 * relativo é o que fica gravado no banco.
 */
class ImageFolder
{
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function dir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/checklistitens/' . $this->name;
    }

    public function create(): void
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            Toolbox::logError('checklistitens: não foi possível criar o diretório ' . $dir);
        }
    }

    public function remove(): void
    {
        if (is_dir($this->dir())) {
            Toolbox::deleteDir($this->dir());
        }
    }

    /**
     * Grava a imagem (já validada por UploadedImage).
     *
     * @param string $owner dono e número: "checkout_12", "345_1"...
     * @return string|null caminho relativo, ou null se não foi possível gravar
     */
    public function store(string $owner, string $data, int $type): ?string
    {
        $relative = sprintf(
            '%s/%s/%s_%s.%s',
            date('Y'),
            date('m'),
            $owner,
            bin2hex(random_bytes(8)),
            $type === IMAGETYPE_PNG ? 'png' : 'jpg'
        );
        $path = $this->dir() . '/' . $relative;

        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0770, true) && !is_dir(dirname($path))) {
            Toolbox::logError('checklistitens: não foi possível criar ' . dirname($path));
            return null;
        }
        if (file_put_contents($path, $data) === false) {
            Toolbox::logError('checklistitens: não foi possível gravar a imagem ' . $path);
            return null;
        }

        return $relative;
    }

    /**
     * Foto com linha própria numa tabela (filepath e size_bytes entram aqui). Sem a linha, o arquivo
     * sai: ninguém chegaria até ele.
     *
     * @return int id da linha, ou 0
     */
    public function storeRecord(string $table, string $owner, string $data, int $type, array $fields): int
    {
        $relative = $this->store($owner, $data, $type);
        if ($relative === null) {
            return 0;
        }

        $id = Db::insert($table, $fields + ['filepath' => $relative, 'size_bytes' => strlen($data)]);
        if ($id <= 0) {
            $this->delete($relative);
        }

        return $id;
    }

    /** Apaga a linha da foto e depois o arquivo: se o banco recusar, o arquivo continua no lugar. */
    public function deleteRecord(string $table, array $row): bool
    {
        global $DB;

        if (!$DB->delete($table, ['id' => (int) $row['id']])) {
            return false;
        }
        $this->delete((string) $row['filepath']);

        return true;
    }

    /** Caminho completo de um arquivo existente; null para caminho fora do padrão ou arquivo sumido. */
    public function fullPath(string $relative): ?string
    {
        if (preg_match('#^\d{4}/\d{2}/[a-z0-9]+_\d+_[0-9a-f]{16}\.(jpg|png)$#', $relative) !== 1) {
            return null;
        }
        $path = $this->dir() . '/' . $relative;

        return is_file($path) ? $path : null;
    }

    /** Apaga o arquivo e devolve quantos bytes foram liberados. */
    public function delete(string $relative): int
    {
        $path = $this->fullPath($relative);
        if ($path === null) {
            return 0;
        }
        $size = (int) filesize($path);
        if (!@unlink($path)) {
            Toolbox::logError('checklistitens: não foi possível apagar ' . $path);
            return 0;
        }

        return $size;
    }

    /** Envia o arquivo como resposta (404 se não existir) e encerra. */
    public function send(string $relative): void
    {
        $path = $this->fullPath($relative);
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

<?php

namespace GlpiPlugin\Checklistitens;

use Glpi\Toolbox\Sanitizer;
use Toolbox;

/**
 * Foto recebida do navegador, validada: JPEG ou PNG de até MAX_BYTES. Chega reduzida pela página
 * (data URL em base64 num campo de texto) ou como arquivo enviado.
 */
class UploadedImage
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Tipo (IMAGETYPE_JPEG ou IMAGETYPE_PNG) do conteúdo, ou null se não for uma foto aceita. */
    public static function typeOf(string $data): ?int
    {
        if ($data === '' || strlen($data) > self::MAX_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($data);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return null;
        }

        return $info[2];
    }

    /**
     * Foto reduzida pelo navegador ("data:image/jpeg;base64,...").
     *
     * @return array{data: string, type: int}|null
     */
    public static function fromDataUrl($value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $raw = Sanitizer::unsanitize($value);
        if (!preg_match('#^data:image/(?:jpeg|png);base64,#', $raw, $matches)) {
            return null;
        }
        $data = base64_decode(preg_replace('/\s+/', '', substr($raw, strlen($matches[0]))), true);
        $type = is_string($data) ? self::typeOf($data) : null;

        return $type !== null ? ['data' => $data, 'type' => $type] : null;
    }

    /**
     * Arquivo enviado (uma entrada de $_FILES). "retry" diz ao aparelho se vale reenviar a mesma
     * foto: sim quando ela não chegou inteira ou o servidor não conseguiu ler; não quando a própria
     * foto é o problema.
     *
     * @return array{ok: bool, message: string, retry: bool, data?: string, type?: int}
     */
    public static function fromUpload($file): array
    {
        if (!is_array($file) || !isset($file['tmp_name']) || !is_string($file['tmp_name'])) {
            return Reply::fail(__('Nenhuma foto foi recebida.', 'checklistitens'));
        }

        $error   = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $too_big = sprintf(__('A foto é grande demais (máximo %d MB).', 'checklistitens'), (int) (self::MAX_BYTES / 1048576));
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return Reply::fail($too_big);
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            return Reply::fail(__('Nenhuma foto foi recebida.', 'checklistitens'));
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            // envio cortado no meio ou falha do servidor ao receber: a foto em si pode estar boa
            if ($error !== UPLOAD_ERR_PARTIAL) {
                Toolbox::logError('checklistitens: falha ao receber foto (código de upload ' . $error . ')');
            }
            return Reply::retry(__('A foto não chegou inteira. Tente de novo.', 'checklistitens'));
        }

        $data = file_get_contents($file['tmp_name']);
        if (!is_string($data)) {
            Toolbox::logError('checklistitens: não foi possível ler a foto recebida');
            return Reply::retry(__('A foto não chegou inteira. Tente de novo.', 'checklistitens'));
        }
        if (strlen($data) > self::MAX_BYTES) {
            return Reply::fail($too_big);
        }

        $type = self::typeOf($data);
        if ($type === null) {
            return Reply::fail(__('O arquivo enviado não é uma foto válida (JPEG ou PNG).', 'checklistitens'));
        }

        return Reply::ok(['retry' => false, 'data' => $data, 'type' => $type]);
    }

    /**
     * Arquivos enviados num campo múltiplo (name[]), um por entrada.
     *
     * @return array<int, array> entradas no formato de $_FILES para fromUpload()
     */
    public static function filesOf($field): array
    {
        if (!is_array($field) || !isset($field['tmp_name']) || !is_array($field['tmp_name'])) {
            return [];
        }

        $files = [];
        foreach ($field['tmp_name'] as $key => $tmp) {
            $files[] = [
                'tmp_name' => $tmp,
                'error'    => $field['error'][$key] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $field['size'][$key] ?? 0,
            ];
        }

        return $files;
    }
}

<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBChild;
use Document;
use Document_Item;
use Glpi\Toolbox\Sanitizer;
use Throwable;
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
 * Ao abrir o chamado do item bloqueado, uma cópia de cada foto vira Documento do GLPI anexado
 * ao chamado; o arquivo do plugin continua no lugar.
 */
class DefectPhoto extends CommonDBChild
{
    public static $itemtype = Usage::class;
    public static $items_id = 'plugin_checklistitens_usages_id';

    public static $rightname = Profile::RIGHT_USAGE;

    public const MIN_PHOTOS = 1;
    public const MAX_PHOTOS = 5;
    public const MAX_BYTES  = 5 * 1024 * 1024;
    public const MAX_SIDE   = 1600; // maior lado da foto reduzida no navegador, em pixels

    public static function getTypeName($nb = 0)
    {
        return _n('Foto do defeito', 'Fotos do defeito', $nb, 'checklistitens');
    }

    public static function getBaseDir(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/checklistitens/defects';
    }

    public static function createBaseDir(): void
    {
        $dir = self::getBaseDir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
            Toolbox::logError('checklistitens: não foi possível criar o diretório de fotos de defeito ' . $dir);
        }
    }

    /**
     * Fotos enviadas no formulário, já validadas (JPEG ou PNG, até MAX_BYTES cada). Primeiro as
     * reduzidas pelo navegador, depois os arquivos originais; o que passar de MAX_PHOTOS é ignorado.
     *
     * @return string[] conteúdo binário de cada imagem
     */
    public static function collectFromRequest(): array
    {
        $images = [];

        foreach ((array) ($_POST['defect_photos'] ?? []) as $value) {
            if (count($images) >= self::MAX_PHOTOS) {
                break;
            }
            if (!is_string($value) || $value === '') {
                continue;
            }
            $raw = Sanitizer::unsanitize($value);
            if (!preg_match('#^data:image/(?:jpeg|png);base64,#', $raw, $matches)) {
                continue;
            }
            $data = base64_decode(preg_replace('/\s+/', '', substr($raw, strlen($matches[0]))), true);
            if (is_string($data) && self::imageType($data) !== null) {
                $images[] = $data;
            }
        }

        $files = $_FILES['defect_photo_files'] ?? null;
        if (is_array($files) && isset($files['tmp_name']) && is_array($files['tmp_name'])) {
            foreach ($files['tmp_name'] as $key => $tmp) {
                if (count($images) >= self::MAX_PHOTOS) {
                    break;
                }
                if (
                    !is_string($tmp)
                    || (int) ($files['error'][$key] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                    || (int) ($files['size'][$key] ?? 0) > self::MAX_BYTES
                    || !is_uploaded_file($tmp)
                ) {
                    continue;
                }
                $data = file_get_contents($tmp);
                if (is_string($data) && self::imageType($data) !== null) {
                    $images[] = $data;
                }
            }
        }

        return $images;
    }

    /** Tipo da imagem (IMAGETYPE_JPEG ou IMAGETYPE_PNG), ou null se não for uma foto aceita. */
    private static function imageType(string $data): ?int
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
     * Grava as fotos de um uso/fase (no máximo MAX_PHOTOS).
     *
     * @param string[] $images conteúdo binário (collectFromRequest)
     *
     * @return int quantas foram gravadas
     */
    public static function storeAll(int $usages_id, int $phase, array $images): int
    {
        $stored = 0;
        foreach (array_slice(array_values($images), 0, self::MAX_PHOTOS) as $data) {
            $type = is_string($data) ? self::imageType($data) : null;
            if ($type === null) {
                continue;
            }
            if (self::storeImage($usages_id, $phase, $data, $type)) {
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
        global $DB;

        $relative = sprintf(
            '%s/%s/%d_%d_%s.%s',
            date('Y'),
            date('m'),
            $usages_id,
            $phase,
            bin2hex(random_bytes(8)),
            $type === IMAGETYPE_PNG ? 'png' : 'jpg'
        );
        $path = self::getBaseDir() . '/' . $relative;

        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0770, true)) {
            Toolbox::logError('checklistitens: não foi possível criar ' . dirname($path));
            return false;
        }
        if (file_put_contents($path, $data) === false) {
            Toolbox::logError('checklistitens: não foi possível gravar a foto de defeito ' . $path);
            return false;
        }

        $fields = [
            'plugin_checklistitens_usages_id' => $usages_id,
            'phase'                           => $phase,
            'filepath'                        => $relative,
            'size_bytes'                      => strlen($data),
            'documents_id'                    => 0,
            'date_creation'                   => Shift::now(),
        ];
        // A coluna só entra quando há identificador: a retirada comum grava como sempre gravou
        if ($uuid !== '') {
            $fields['client_uuid'] = $uuid;
        }

        $ok = $DB->insert(self::getTable(), $fields);
        if (!$ok) {
            // arquivo sem registro: ninguém chegaria até ele
            @unlink($path);
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
     * @return array{ok: bool, message: string, count: int, retry?: bool}
     */
    public static function storeUploaded(int $usages_id, int $phase, string $uuid): array
    {
        $fail = static fn (string $message, bool $retry = false): array => [
            'ok'      => false,
            'message' => $message,
            'retry'   => $retry,
            'count'   => self::countFor($usages_id, $phase),
        ];

        if (
            $usages_id <= 0
            || !in_array($phase, [Usage::PHASE_CHECKOUT, Usage::PHASE_CHECKIN], true)
            || !DeliveryPhoto::isValidUuid($uuid)
        ) {
            return $fail(__('Foto sem identificação. Tire a foto de novo.', 'checklistitens'));
        }

        // Reenvio da mesma foto (a resposta anterior se perdeu no caminho): já está gravada
        $already = countElementsInTable(self::getTable(), [
            'plugin_checklistitens_usages_id' => $usages_id,
            'phase'                           => $phase,
            'client_uuid'                     => $uuid,
        ]);
        if ($already > 0) {
            return ['ok' => true, 'message' => '', 'count' => self::countFor($usages_id, $phase)];
        }

        if (self::countFor($usages_id, $phase) >= self::MAX_PHOTOS) {
            return $fail(sprintf(__('O limite é de %d fotos do defeito por equipamento.', 'checklistitens'), self::MAX_PHOTOS));
        }

        $image = DeliveryPhoto::readUploadedImage();
        if (!$image['ok']) {
            return $fail($image['message'], $image['retry']);
        }

        if (!self::storeImage($usages_id, $phase, $image['data'], $image['type'], $uuid)) {
            return $fail(__('Não foi possível guardar a foto. Tente de novo.', 'checklistitens'), true);
        }

        return ['ok' => true, 'message' => '', 'count' => self::countFor($usages_id, $phase)];
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
            $path = self::getFullPath((string) $row['filepath']);
            if ($path !== null && !@unlink($path)) {
                Toolbox::logError('checklistitens: não foi possível apagar a foto de defeito ' . $path);
            }
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
     * Anexa ao chamado as fotos do uso/fase que ainda não foram anexadas. Segue o mesmo caminho
     * do GLPI para anexos (CommonDBTM::addFiles): cópia em GLPI_TMP_DIR, Document com _filename
     * e _prefix_filename, e depois o vínculo Document_Item com o chamado.
     *
     * @return int quantas fotos ficaram anexadas
     */
    public static function attachToTicket(int $usages_id, int $phase, int $tickets_id, int $entities_id): int
    {
        global $CFG_GLPI, $DB;

        if ($usages_id <= 0 || $tickets_id <= 0) {
            return 0;
        }
        if (!is_dir(GLPI_TMP_DIR)) {
            Toolbox::logError('checklistitens: diretório temporário do GLPI não existe; fotos não anexadas ao chamado ' . $tickets_id);
            return 0;
        }

        $rows = iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_checklistitens_usages_id' => $usages_id, 'phase' => $phase],
            'ORDER' => 'id',
        ]), false);

        // O GLPI avisa "cópia do documento bem sucedida" a cada arquivo; quem está registrando o
        // uso não precisa ver isso (erros vão para o log)
        $had_messages = isset($_SESSION['MESSAGE_AFTER_REDIRECT']);
        $messages     = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];

        $attached = 0;
        $linked   = [];
        foreach ($rows as $index => $row) {
            if ((int) $row['documents_id'] > 0) {
                continue;
            }
            $number   = $index + 1;
            $tmp_path = null;

            try {
                $source = self::getFullPath((string) $row['filepath']);
                if ($source === null) {
                    Toolbox::logError(sprintf('checklistitens: arquivo da foto de defeito %d não encontrado', $row['id']));
                    continue;
                }

                // Nome exibido no chamado = nome do arquivo sem o prefixo (Document::moveDocument)
                $display  = sprintf('foto-defeito-%d-%d.%s', $usages_id, $number, pathinfo($source, PATHINFO_EXTENSION));
                $prefix   = bin2hex(random_bytes(8)) . '_';
                $tmp_path = GLPI_TMP_DIR . '/' . $prefix . $display;
                if (!copy($source, $tmp_path)) {
                    Toolbox::logError('checklistitens: não foi possível copiar a foto de defeito para ' . $tmp_path);
                    continue;
                }

                $doc = new Document();
                if ($doc->getDuplicateOf($entities_id, $tmp_path) && is_file(GLPI_DOC_DIR . '/' . $doc->fields['filepath'])) {
                    // mesmo arquivo já existe como documento na entidade: reaproveita, como o GLPI faz
                    $documents_id = (int) $doc->fields['id'];
                } else {
                    $documents_id = (int) $doc->add([
                        'name'                    => Sanitizer::sanitize(sprintf(__('Foto do defeito %d - Checklist', 'checklistitens'), $number)),
                        'entities_id'             => $entities_id,
                        'is_recursive'            => 0,
                        'tickets_id'              => $tickets_id,
                        'documentcategories_id'   => (int) ($CFG_GLPI['documentcategories_id_forticket'] ?? 0),
                        '_only_if_upload_succeed' => 1,
                        '_filename'               => [$prefix . $display],
                        '_prefix_filename'        => [$prefix],
                    ]);
                }
                if ($documents_id <= 0) {
                    Toolbox::logError(sprintf('checklistitens: não foi possível criar o documento da foto de defeito %d (tipo de arquivo permitido?)', $row['id']));
                    continue;
                }

                $exists = isset($linked[$documents_id]) || countElementsInTable(Document_Item::getTable(), [
                    'documents_id' => $documents_id,
                    'itemtype'     => 'Ticket',
                    'items_id'     => $tickets_id,
                ]) > 0;
                if (!$exists) {
                    $link_id = (new Document_Item())->add([
                        'documents_id'  => $documents_id,
                        'itemtype'      => 'Ticket',
                        'items_id'      => $tickets_id,
                        'date'          => Shift::now(),
                        // sem e-mail de "chamado atualizado" a cada foto
                        '_do_notif'     => false,
                        '_disablenotif' => true,
                    ]);
                    if (!$link_id) {
                        Toolbox::logError(sprintf('checklistitens: não foi possível vincular o documento %d ao chamado %d', $documents_id, $tickets_id));
                        continue;
                    }
                }
                $linked[$documents_id] = true;

                $DB->update(self::getTable(), ['documents_id' => $documents_id], ['id' => (int) $row['id']]);
                $attached++;
            } catch (Throwable $e) {
                Toolbox::logError(sprintf('checklistitens: erro ao anexar a foto de defeito %d ao chamado %d: %s', $row['id'], $tickets_id, $e->getMessage()));
            } finally {
                if ($tmp_path !== null && is_file($tmp_path)) {
                    @unlink($tmp_path);
                }
            }
        }

        if ($had_messages) {
            $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messages;
        } else {
            unset($_SESSION['MESSAGE_AFTER_REDIRECT']);
        }

        return $attached;
    }
}

<?php

namespace GlpiPlugin\Checklistitens;

/**
 * Localização lida pelo navegador no momento de cada selfie (retirada, devolução e conferência).
 *
 * Uma leitura só, junto com a foto (não é rastreamento). É obrigatória, mas não bloqueia o
 * salvamento: sem ela o registro guarda o motivo e o gestor vê em destaque. A coordenada não vem
 * do EXIF da foto (a câmera na página não gera EXIF, e o Android/Chrome costuma remover o GPS).
 */
class Location
{
    public const STATUS_NONE        = 0; // registros anteriores à localização
    public const STATUS_OK          = 1;
    public const STATUS_DENIED      = 2; // usuário recusou a permissão
    public const STATUS_UNAVAILABLE = 3; // localização desligada, sem sinal ou erro
    public const STATUS_TIMEOUT     = 4;
    public const STATUS_INSECURE    = 5; // endereço não seguro: o navegador não fornece a localização
    public const STATUS_PURGED      = 6; // removida na limpeza

    /** @return array<int, string> */
    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_NONE        => __('Não registrada', 'checklistitens'),
            self::STATUS_OK          => __('Registrada', 'checklistitens'),
            self::STATUS_DENIED      => __('Sem localização: permissão negada', 'checklistitens'),
            self::STATUS_UNAVAILABLE => __('Sem localização: indisponível no aparelho', 'checklistitens'),
            self::STATUS_TIMEOUT     => __('Sem localização: tempo esgotado', 'checklistitens'),
            self::STATUS_INSECURE    => __('Sem localização: navegador bloqueou (endereço não seguro)', 'checklistitens'),
            self::STATUS_PURGED      => __('Localização removida na limpeza', 'checklistitens'),
        ];
    }

    /**
     * Leitura enviada pelo formulário (campos location_* do bloco da selfie), validada.
     *
     * @return array{status: int, latitude: ?float, longitude: ?float, accuracy: ?int}
     */
    public static function fromRequest(): array
    {
        $status = (int) ($_POST['location_status'] ?? 0);
        if (!in_array($status, [self::STATUS_OK, self::STATUS_DENIED, self::STATUS_UNAVAILABLE, self::STATUS_TIMEOUT, self::STATUS_INSECURE], true)) {
            // Formulário sem a leitura (ex.: JS não rodou): conta como indisponível
            $status = self::STATUS_UNAVAILABLE;
        }

        $empty = ['status' => $status, 'latitude' => null, 'longitude' => null, 'accuracy' => null];
        if ($status !== self::STATUS_OK) {
            return $empty;
        }

        $latitude  = filter_var($_POST['location_latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($_POST['location_longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $accuracy  = filter_var($_POST['location_accuracy'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            return ['status' => self::STATUS_UNAVAILABLE] + $empty;
        }

        return [
            'status'    => self::STATUS_OK,
            'latitude'  => round($latitude, 7),
            'longitude' => round($longitude, 7),
            'accuracy'  => ($accuracy === false || $accuracy < 0) ? null : (int) min(round($accuracy), 4294967295),
        ];
    }

    /**
     * Colunas para gravar a leitura. Prefixo "checkout_"/"checkin_" nos usos, "" na conferência.
     * Os valores são números já validados, seguros para o banco.
     */
    public static function toFields(string $prefix, array $location): array
    {
        $ok = $location['status'] === self::STATUS_OK;

        $fields = [$prefix . 'location_status' => $location['status']];
        if ($ok) {
            $fields[$prefix . 'latitude']  = sprintf('%.7F', $location['latitude']);
            $fields[$prefix . 'longitude'] = sprintf('%.7F', $location['longitude']);
            if ($location['accuracy'] !== null) {
                $fields[$prefix . 'accuracy'] = (int) $location['accuracy'];
            }
        }

        return $fields;
    }

    /**
     * Dados para as telas: link de mapa (Apple e Google) ou o motivo de não ter localização.
     *
     * @param string $label texto do marcador no mapa (ex.: "Retirada")
     * @return array{status: int, ok: bool, missing: bool, label: string, accuracy: string, apple_url: string, google_url: string, coordinates: string}
     */
    public static function present(array $row, string $prefix, string $label): array
    {
        $status    = (int) ($row[$prefix . 'location_status'] ?? self::STATUS_NONE);
        $latitude  = $row[$prefix . 'latitude'] ?? null;
        $longitude = $row[$prefix . 'longitude'] ?? null;
        $ok        = $status === self::STATUS_OK && $latitude !== null && $longitude !== null;

        $coordinates = $ok ? sprintf('%.6F,%.6F', (float) $latitude, (float) $longitude) : '';

        return [
            'status'      => $status,
            'ok'          => $ok,
            // Sem localização numa selfie em que ela deveria existir (não conta registros antigos
            // nem a limpeza feita pelo administrador)
            'missing'     => in_array($status, [self::STATUS_DENIED, self::STATUS_UNAVAILABLE, self::STATUS_TIMEOUT, self::STATUS_INSECURE], true),
            'label'       => self::getStatusLabels()[$status] ?? '',
            'accuracy'    => ($ok && isset($row[$prefix . 'accuracy'])) ? (string) (int) $row[$prefix . 'accuracy'] : '',
            'coordinates' => $coordinates,
            'apple_url'   => $ok ? 'https://maps.apple.com/?ll=' . $coordinates . '&q=' . rawurlencode($label) : '',
            'google_url'  => $ok ? 'https://www.google.com/maps/search/?api=1&query=' . $coordinates : '',
        ];
    }

    /** Texto para o CSV: "lat,lon (±12 m)" ou o motivo. */
    public static function toText(array $presented): string
    {
        if ($presented['ok']) {
            return $presented['coordinates'] . ($presented['accuracy'] !== '' ? ' (±' . $presented['accuracy'] . ' m)' : '');
        }

        return $presented['status'] === self::STATUS_NONE ? '' : $presented['label'];
    }
}

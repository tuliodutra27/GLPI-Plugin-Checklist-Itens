<?php

namespace GlpiPlugin\Checklistitens;

use Throwable;
use Toolbox;

/**
 * Bateria ou capa que voltou com defeito da embarcação. As peças não são ativos do GLPI nem são
 * controladas uma a uma: cada peça com defeito é numerada dentro do recebimento ("bateria 1",
 * "capa 2"), com os problemas marcados no catálogo e as fotos (nas fotos da entrega, com a peça
 * em defect_ref).
 */
class AccessoryDefect
{
    public const KIND_BATTERY = 1;
    public const KIND_COVER   = 2;

    /** Nome da peça na foto e no envio do aparelho: "battery:1", "cover:2". */
    public static function ref(int $kind, int $number): string
    {
        return ($kind === self::KIND_COVER ? 'cover' : 'battery') . ':' . $number;
    }

    public static function label(int $kind, int $number): string
    {
        return $kind === self::KIND_COVER
            ? sprintf(__('Capa %d', 'checklistitens'), $number)
            : sprintf(__('Bateria %d', 'checklistitens'), $number);
    }

    public static function problemType(int $kind): string
    {
        return $kind === self::KIND_COVER ? ProblemType::ACCESSORY_COVER : ProblemType::ACCESSORY_BATTERY;
    }

    /**
     * Confere as peças com defeito enviadas na conclusão do recebimento: cada uma precisa de pelo
     * menos um problema do catálogo e de uma foto.
     *
     * @param array $posted número da peça => ids dos problemas marcados
     * @return array{pieces: array<int, array>, error: string}
     */
    public static function readPieces(int $deliveries_id, int $receipts_id, int $kind, int $count, array $posted): array
    {
        $pieces = [];
        for ($number = 1; $number <= $count; $number++) {
            $label  = self::label($kind, $number);
            $marked = $posted[$number] ?? [];
            $valid  = is_array($marked) ? ProblemType::getValidForType(self::problemType($kind), $marked) : [];
            if (!count($valid)) {
                return ['pieces' => [], 'error' => sprintf(__('Marque pelo menos um problema da %s.', 'checklistitens'), mb_strtolower($label))];
            }
            $ref = self::ref($kind, $number);
            if (DeliveryPhoto::countFor($deliveries_id, $receipts_id, $ref) < DefectPhoto::MIN_PHOTOS) {
                return ['pieces' => [], 'error' => sprintf(__('Tire pelo menos uma foto da %s com defeito.', 'checklistitens'), mb_strtolower($label))];
            }
            $pieces[] = ['kind' => $kind, 'number' => $number, 'ref' => $ref, 'problems' => $valid];
        }

        return ['pieces' => $pieces, 'error' => ''];
    }

    /**
     * Grava as peças com defeito do recebimento (dentro da transação da conclusão).
     *
     * @param array<int, array> $pieces de readPieces()
     */
    public static function saveAll(int $deliveries_id, int $receipts_id, array $pieces): bool
    {
        global $DB;

        foreach ($pieces as $piece) {
            $names = array_map(static fn ($row) => Ui::text($row['name']), array_values($piece['problems']));
            try {
                $ok = $DB->insert(Install::TABLE_ACCESSORYDEFECTS, [
                    'plugin_checklistitens_deliveries_id'       => $deliveries_id,
                    'plugin_checklistitens_deliveryreceipts_id' => $receipts_id,
                    'kind'                                      => (int) $piece['kind'],
                    'number'                                    => (int) $piece['number'],
                    'problemtypes_ids'                          => implode(',', array_keys($piece['problems'])),
                    // cópia dos nomes: o catálogo pode mudar depois
                    'problems'                                  => json_encode($names, JSON_UNESCAPED_UNICODE),
                    'date_creation'                             => Shift::now(),
                ]);
            } catch (Throwable $e) {
                Toolbox::logError('checklistitens: ' . $e->getMessage());
                $ok = false;
            }
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Peças com defeito dos recebimentos, para as telas e o chamado.
     *
     * @param int[] $receipt_ids
     * @return array<int, array<int, array{kind: int, number: int, label: string, problems: string[], problemtypes_ids: int[], photos: array}>>
     */
    public static function getForReceipts(array $receipt_ids): array
    {
        global $DB;

        $receipt_ids = array_values(array_filter(array_map('intval', $receipt_ids)));
        if (!count($receipt_ids)) {
            return [];
        }

        $list   = [];
        $photos = [];
        $iterator = $DB->request([
            'FROM'  => Install::TABLE_ACCESSORYDEFECTS,
            'WHERE' => ['plugin_checklistitens_deliveryreceipts_id' => $receipt_ids],
            'ORDER' => ['kind', 'number'],
        ]);
        foreach ($iterator as $row) {
            $rid  = (int) $row['plugin_checklistitens_deliveryreceipts_id'];
            $kind = (int) $row['kind'];
            if (!isset($photos[$rid])) {
                $photos[$rid] = DeliveryPhoto::getDefectPhotos((int) $row['plugin_checklistitens_deliveries_id'], $rid);
            }
            $names = json_decode((string) $row['problems'], true);
            $list[$rid][] = [
                'kind'             => $kind,
                'number'           => (int) $row['number'],
                'label'            => self::label($kind, (int) $row['number']),
                'problems'         => is_array($names) ? array_map('strval', $names) : [],
                'problemtypes_ids' => array_values(array_filter(array_map('intval', explode(',', (string) $row['problemtypes_ids'])))),
                'photos'           => $photos[$rid][self::ref($kind, (int) $row['number'])] ?? [],
            ];
        }

        return $list;
    }

    /**
     * Texto curto para o CSV: "Bateria 1: Trava quebrada, Não carrega; Capa 1: Rasgada".
     *
     * @param array<int, array> $pieces de getForReceipts()
     */
    public static function summary(array $pieces): string
    {
        return implode('; ', array_map(
            static fn ($piece) => $piece['label'] . ': ' . implode(', ', $piece['problems']),
            $pieces
        ));
    }
}

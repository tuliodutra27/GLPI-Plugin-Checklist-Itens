<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBChild;
use Glpi\Toolbox\Sanitizer;

/**
 * Laudos em aberto (plugin Laudo) exibidos num uso, na retirada ou na devolução. Guarda uma cópia
 * do nome, da situação, da data e de um resumo, para o histórico não mudar quando o laudo for
 * editado, concluído ou apagado.
 */
class UsageLaudo extends CommonDBChild
{
    public static $itemtype = Usage::class;
    public static $items_id = 'plugin_checklistitens_usages_id';

    // A cópia faz parte do registro de uso: não gera linha no histórico do uso
    public static $logs_for_parent = false;

    public static $rightname = Profile::RIGHT_USAGE;

    public static function getTypeName($nb = 0)
    {
        return _n('Laudo em aberto', 'Laudos em aberto', $nb, 'checklistitens');
    }

    /**
     * Grava a cópia dos laudos exibidos. Laudo já gravado no mesmo uso e fase é ignorado.
     *
     * @param array<int, array> $laudos formato de LaudoProvider::getOpenFor
     */
    public static function recordAll(int $usages_id, int $phase, array $laudos): void
    {
        global $DB;

        if ($usages_id <= 0 || !count($laudos)) {
            return;
        }

        // Confere antes para não esbarrar no índice único (erro de SQL no log)
        $existing = [];
        $iterator = $DB->request([
            'SELECT' => ['plugin_laudo_laudos_id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['plugin_checklistitens_usages_id' => $usages_id, 'phase' => $phase],
        ]);
        foreach ($iterator as $row) {
            $existing[(int) $row['plugin_laudo_laudos_id']] = true;
        }

        $item = new self();
        foreach ($laudos as $laudo) {
            $laudos_id = (int) ($laudo['id'] ?? 0);
            if ($laudos_id <= 0 || isset($existing[$laudos_id])) {
                continue;
            }
            $existing[$laudos_id] = true;

            $item->add([
                'plugin_checklistitens_usages_id' => $usages_id,
                'phase'                           => $phase,
                'plugin_laudo_laudos_id'          => $laudos_id,
                'laudo_name'                      => self::dbText((string) ($laudo['name'] ?? '')),
                'laudo_status'                    => self::dbText((string) ($laudo['status'] ?? '')),
                'laudo_date'                      => self::dbDate($laudo['date'] ?? null),
                'laudo_summary'                   => self::dbText(LaudoProvider::summarize($laudo)),
                'date_creation'                   => Shift::now(),
            ]);
        }
    }

    /**
     * Laudos gravados em vários usos, em uma consulta. Mesmo formato de LaudoProvider::getOpenFor;
     * occurrence vem vazio e items traz o resumo gravado.
     *
     * @param int[] $usage_ids
     *
     * @return array<int, array<int, array<int, array>>> usages_id => fase => laudos
     */
    public static function getForUsages(array $usage_ids): array
    {
        global $DB;

        $usage_ids = array_values(array_unique(array_filter(array_map('intval', $usage_ids), fn ($id) => $id > 0)));
        if (!count($usage_ids) || !$DB->tableExists(self::getTable())) {
            return [];
        }

        $with_link = LaudoProvider::isAvailable();
        $result    = [];
        $iterator  = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_checklistitens_usages_id' => $usage_ids],
            'ORDER' => ['laudo_date DESC', 'plugin_laudo_laudos_id DESC'],
        ]);
        foreach ($iterator as $row) {
            $laudos_id = (int) $row['plugin_laudo_laudos_id'];
            $result[(int) $row['plugin_checklistitens_usages_id']][(int) $row['phase']][] = [
                'id'         => $laudos_id,
                'name'       => Ui::text($row['laudo_name']),
                'status'     => Ui::text($row['laudo_status']),
                'date'       => (string) ($row['laudo_date'] ?? ''),
                'occurrence' => '',
                'items'      => Ui::text($row['laudo_summary']),
                'url'        => $with_link ? LaudoProvider::getLaudoUrl($laudos_id) : null,
            ];
        }

        return $result;
    }

    /**
     * Texto puro para gravar (HTML codificado e escape SQL, como o GLPI faz), cabendo na coluna
     * mesmo depois da codificação.
     */
    private static function dbText(string $text, int $max = 255): string
    {
        $text = trim($text);
        while (($over = mb_strlen(Sanitizer::encodeHtmlSpecialChars($text)) - $max) > 0) {
            $text = mb_substr($text, 0, mb_strlen($text) - $over);
        }

        return Sanitizer::sanitize($text);
    }

    /** Data/hora no formato do banco, dentro do intervalo de uma coluna timestamp; senão null. */
    private static function dbDate($value): ?string
    {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{4})-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $value, $m)) {
            return null;
        }
        $year = (int) $m[1];

        return ($year > 1970 && $year < 2038) ? $value : null;
    }
}

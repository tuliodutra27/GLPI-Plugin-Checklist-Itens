<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBChild;
use Session;

/**
 * Observação de um uso encerrado sem devolução. Cada alteração é uma linha nova, com quem escreveu
 * e quando: nada é editado nem apagado, então o histórico de todas as alterações fica completo. A
 * observação atual é a mais recente.
 */
class UsageNote extends CommonDBChild
{
    use ReadOnlyRecord;

    public static $itemtype = Usage::class;
    public static $items_id = 'plugin_checklistitens_usages_id';

    // O histórico é a própria tabela: não gera linha no histórico do uso
    public static $logs_for_parent = false;

    public static $rightname = Profile::RIGHT_USAGE;

    /** Tamanho máximo da observação, em caracteres. */
    public const MAX_LENGTH = 2000;

    public static function getTypeName($nb = 0)
    {
        return _n('Observação', 'Observações', $nb, 'checklistitens');
    }

    /**
     * Grava uma nova versão da observação. Texto igual ao atual não gera versão.
     *
     * @param string $content texto já tratado para gravar (Input::comment)
     */
    public static function record(int $usages_id, string $content): bool
    {
        $current = self::getForUsages([$usages_id])[$usages_id][0]['text'] ?? '';
        if (Ui::text($content) === $current) {
            return false;
        }

        return (bool) (new self())->add([
            'plugin_checklistitens_usages_id' => $usages_id,
            'users_id'                        => (int) Session::getLoginUserID(),
            'content'                         => $content,
            'date_creation'                   => Shift::now(),
        ]);
    }

    /**
     * Versões da observação de vários usos, da mais nova para a mais antiga.
     *
     * @param int[] $usage_ids
     * @return array<int, array<int, array{text: string, user: string, when: string}>>
     */
    public static function getForUsages(array $usage_ids): array
    {
        global $DB;

        $usage_ids = Input::ids($usage_ids);
        if (!count($usage_ids) || !$DB->tableExists(self::getTable())) {
            return [];
        }

        $result = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_checklistitens_usages_id' => $usage_ids],
            'ORDER' => ['date_creation DESC', 'id DESC'],
        ]);
        foreach ($iterator as $row) {
            $result[(int) $row['plugin_checklistitens_usages_id']][] = [
                'text' => Ui::text($row['content']),
                'user' => Ui::text(getUserName((int) $row['users_id'])),
                'when' => Ui::datetime($row['date_creation']),
            ];
        }

        return $result;
    }
}

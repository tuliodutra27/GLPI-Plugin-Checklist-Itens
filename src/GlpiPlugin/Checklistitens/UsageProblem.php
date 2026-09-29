<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBTM;
use Glpi\Toolbox\Sanitizer;

/**
 * Problemas marcados num uso (retirada ou devolução). Guarda uma cópia do texto do problema
 * para o histórico não mudar se o catálogo for editado depois.
 */
class UsageProblem extends CommonDBTM
{
    public static $rightname = Profile::RIGHT_USAGE;

    public static function getTypeName($nb = 0)
    {
        return _n('Problema marcado', 'Problemas marcados', $nb, 'checklistitens');
    }

    /**
     * @param array<int, array> $problems linhas do catálogo (ProblemType::getValidForType)
     */
    public static function addAll(int $usages_id, int $phase, array $problems): void
    {
        $item = new self();
        foreach ($problems as $id => $row) {
            $item->add([
                'plugin_checklistitens_usages_id'       => $usages_id,
                'plugin_checklistitens_problemtypes_id' => (int) $id,
                // valor lido do banco: já vem com HTML codificado, falta só o escape SQL
                'problem_name'                          => Sanitizer::dbEscape((string) $row['name']),
                'phase'                                 => $phase,
            ]);
        }
    }

    /**
     * @return array<int, array{id: int, name: string, problemtypes_id: int}>
     */
    public static function getFor(int $usages_id, int $phase): array
    {
        global $DB;

        $list = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['plugin_checklistitens_usages_id' => $usages_id, 'phase' => $phase],
            'ORDER' => 'id',
        ]);
        foreach ($iterator as $row) {
            $list[] = [
                'id'              => (int) $row['id'],
                'name'            => Ui::text($row['problem_name']),
                'problemtypes_id' => (int) $row['plugin_checklistitens_problemtypes_id'],
            ];
        }

        return $list;
    }
}

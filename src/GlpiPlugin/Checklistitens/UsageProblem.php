<?php

namespace GlpiPlugin\Checklistitens;

use CommonDBTM;
use Glpi\Toolbox\Sanitizer;
use Throwable;
use Toolbox;

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
    /**
     * Confere o problema informado num uso: pelo menos um problema ativo do catálogo do tipo e
     * pelo menos DefectPhoto::MIN_PHOTOS fotos do defeito.
     *
     * @param string $radio rótulo do rádio, quando a tela lista vários (a mensagem diz qual)
     * @return array{problems: array<int, array>, error: string} problemas válidos (linhas do catálogo)
     */
    public static function validate(string $itemtype, array $problem_ids, int $photos, string $radio = ''): array
    {
        $problems = ProblemType::getValidForType($itemtype, $problem_ids);
        if (!count($problems)) {
            return ['problems' => [], 'error' => $radio === ''
                ? __('Marque pelo menos um problema encontrado.', 'checklistitens')
                : sprintf(__('Marque pelo menos um problema encontrado no rádio %s.', 'checklistitens'), $radio)];
        }
        if ($photos < DefectPhoto::MIN_PHOTOS) {
            return ['problems' => [], 'error' => $radio === ''
                ? __('Tire pelo menos uma foto do equipamento com defeito.', 'checklistitens')
                : sprintf(__('Tire pelo menos uma foto do defeito do rádio %s.', 'checklistitens'), $radio)];
        }

        return ['problems' => $problems, 'error' => ''];
    }

    /**
     * Grava os problemas marcados que ainda não estão no uso e confere se todos ficaram gravados
     * (o pedido pode chegar de novo).
     *
     * @param array<int, array> $problems linhas do catálogo (validate)
     */
    public static function record(int $usages_id, int $phase, array $problems): bool
    {
        $stored  = array_flip(array_column(self::getFor($usages_id, $phase), 'problemtypes_id'));
        $missing = array_diff_key($problems, $stored);
        if (!count($missing)) {
            return true;
        }

        try {
            self::addAll($usages_id, $phase, $missing);
        } catch (Throwable $e) {
            Toolbox::logError('checklistitens: ' . $e->getMessage());
        }
        $stored = array_flip(array_column(self::getFor($usages_id, $phase), 'problemtypes_id'));

        return !count(array_diff_key($problems, $stored));
    }
}

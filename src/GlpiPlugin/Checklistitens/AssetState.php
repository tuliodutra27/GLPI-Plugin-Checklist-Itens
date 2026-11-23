<?php

namespace GlpiPlugin\Checklistitens;

use Throwable;
use Toolbox;

/**
 * A situação do equipamento acompanha o bloqueio: uma situação do GLPI para cada fase (aguardando
 * conferência, chamado aberto, liberado), escolhida na configuração; 0 = não muda.
 *
 * A troca passa pela classe do próprio ativo, então o histórico dele registra quem mudou (o plugin
 * Rádios grava também o histórico do rádio). Só troca a situação que o checklist deixou na fase
 * anterior (ao bloquear, um dos estados disponíveis para retirada): o que o TI mudou à mão, como
 * baixa ou descarte, fica como está.
 */
class AssetState
{
    public static function follow(string $itemtype, int $items_id, int $block_status): void
    {
        $target = Config::getBlockState($block_status);
        if ($target <= 0 || !ItemProvider::isSupported($itemtype)) {
            return;
        }

        $item = new $itemtype();
        if (!$item->getFromDB($items_id) || !array_key_exists('states_id', $item->fields)) {
            return;
        }
        $current = (int) $item->fields['states_id'];
        if ($current === $target || !in_array($current, self::leftBefore($block_status), true)) {
            return;
        }

        // Numa ação automática do GLPI (sem usuário logado), o histórico do plugin Rádios não
        // consegue gravar quem mudou e o GLPI registra o erro de SQL no log; a situação já foi
        // gravada antes disso
        try {
            $item->update(['id' => $items_id, 'states_id' => $target]);
        } catch (Throwable $e) {
            Toolbox::logError(sprintf('checklistitens: situação do equipamento %s %d: %s', $itemtype, $items_id, $e->getMessage()));
        }
    }

    /**
     * Situações em que o equipamento pode estar ao entrar na fase: as que o checklist deixa nas
     * fases anteriores, e os estados disponíveis para retirada enquanto ele ainda não mexeu.
     *
     * @return int[]
     */
    private static function leftBefore(int $block_status): array
    {
        $waiting = Config::getBlockState(ItemBlock::STATUS_WAITING);
        $repair  = Config::getBlockState(ItemBlock::STATUS_REPAIR);

        switch ($block_status) {
            case ItemBlock::STATUS_WAITING:
                $states = Config::getAvailableStates();
                break;
            case ItemBlock::STATUS_REPAIR:
                $states = array_merge([$waiting], Config::getAvailableStates());
                break;
            default:
                $states = [$waiting, $repair];
        }

        return array_values(array_filter($states, static fn (int $state): bool => $state > 0));
    }
}

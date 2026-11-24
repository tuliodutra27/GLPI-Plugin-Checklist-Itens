<?php

namespace GlpiPlugin\Checklistitens;

/**
 * Leitura de uma lista de usos para as telas (conferência, registro do equipamento, detalhe da
 * entrega): carrega numa consulta por assunto os problemas, as fotos do defeito, os laudos
 * exibidos e os bloqueios de todos os usos, e monta o resultado do checklist de cada fase.
 */
class UsageView
{
    /** @var array<int, array<int, string[]>> */
    private array $problems;

    /** @var array<int, array<int, array>> */
    private array $photos;

    /** @var array<int, array<int, array>> */
    private array $laudos;

    /** @var array<int, array> */
    private array $blocks;

    /**
     * @param array[] $usages linhas dos usos
     */
    public function __construct(array $usages)
    {
        $ids = array_map(static fn ($usage) => (int) $usage['id'], $usages);

        $this->problems = UsageProblem::namesFor($ids);
        $this->photos   = DefectPhoto::getForUsages($ids);
        $this->laudos   = UsageLaudo::getForUsages($ids);
        $this->blocks   = ItemBlock::getForUsages($ids);
    }

    /**
     * Resultado do checklist numa fase: resposta "ok?", problema conhecido (laudo em aberto),
     * problemas marcados, fotos do defeito e laudos exibidos.
     *
     * @return array{is_ok: bool, known: bool, problems: string[], photos: array, laudos: array}
     */
    public function result(array $usage, int $phase): array
    {
        $id     = (int) $usage['id'];
        $prefix = $phase === Usage::PHASE_CHECKIN ? 'checkin_' : 'checkout_';

        return [
            'is_ok'    => (int) $usage[$prefix . 'is_ok'] === 1,
            'known'    => (int) ($usage[$prefix . 'known_issue'] ?? 0) === 1,
            'problems' => $this->problems[$id][$phase] ?? [],
            'photos'   => $this->photos[$id][$phase] ?? [],
            'laudos'   => $this->laudos[$id][$phase] ?? [],
        ];
    }

    /** Bloqueio criado por este uso (linha crua), ou null. */
    public function block(array $usage): ?array
    {
        return $this->blocks[(int) $usage['id']] ?? null;
    }
}

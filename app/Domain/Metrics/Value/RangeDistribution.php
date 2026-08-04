<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

/**
 * Distribuição das leituras nas cinco faixas (FR-102).
 *
 * ⚠️ A soma dos cinco percentuais DEVE dar 100%. Os limites de
 * `config/clinical.php` são fechados nos dois extremos, e um `<` no lugar de
 * `<=` perde uma leitura — a soma deixa de fechar e ninguém olha para a soma.
 * Por isso `sumsToOneHundred()` existe e é testado com valores exatos de borda.
 */
final readonly class RangeDistribution
{
    /**
     * @param  array<string, int>  $counts  faixa → número de leituras
     * @param  array<string, float>  $percentages  faixa → percentual
     */
    public function __construct(
        public int $total,
        public array $counts,
        public array $percentages,
    ) {}

    public static function empty(): self
    {
        $zeroed = array_fill_keys(['very_low', 'low', 'target', 'high', 'very_high'], 0);

        return new self(0, $zeroed, array_map(fn (): float => 0.0, $zeroed));
    }

    public function timeInRange(): float
    {
        return $this->percentages['target'] ?? 0.0;
    }

    /** Tempo acima da faixa: soma dos dois níveis. */
    public function timeAboveRange(): float
    {
        return ($this->percentages['high'] ?? 0.0) + ($this->percentages['very_high'] ?? 0.0);
    }

    public function timeBelowRange(): float
    {
        return ($this->percentages['very_low'] ?? 0.0) + ($this->percentages['low'] ?? 0.0);
    }

    /** Invariante obrigatória. Tolerância cobre só erro de ponto flutuante. */
    public function sumsToOneHundred(): bool
    {
        if ($this->total === 0) {
            return true;
        }

        return abs(array_sum($this->percentages) - 100.0) < 0.0001;
    }
}

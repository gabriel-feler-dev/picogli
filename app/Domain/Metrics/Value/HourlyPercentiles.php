<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

/**
 * Percentis de uma hora do dia — a matéria-prima do gráfico AGP (FR-202).
 *
 * ⚠️ Hora sem leitura devolve `null` em todos os percentis, **nunca zero**.
 * Zero pareceria glicose de 0 mg/dL num gráfico — pior que ausência, porque
 * ausência é visível e zero convence.
 */
final readonly class HourlyPercentiles
{
    public function __construct(
        public int $hour,
        public int $count,
        public ?float $p5 = null,
        public ?float $p25 = null,
        public ?float $p50 = null,
        public ?float $p75 = null,
        public ?float $p95 = null,
    ) {}

    public static function empty(int $hour): self
    {
        return new self($hour, 0);
    }

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    /** A mediana, com o nome que o domínio usa. */
    public function median(): ?float
    {
        return $this->p50;
    }

    /**
     * Invariante: os percentis são monotônicos.
     *
     * Testada em toda hora com dado. Se quebrar, o cálculo está errado — e num
     * gráfico de bandas isso apareceria como banda invertida, que ninguém
     * interpreta como bug.
     */
    public function isMonotonic(): bool
    {
        if ($this->isEmpty()) {
            return true;
        }

        return $this->p5 <= $this->p25
            && $this->p25 <= $this->p50
            && $this->p50 <= $this->p75
            && $this->p75 <= $this->p95;
    }
}

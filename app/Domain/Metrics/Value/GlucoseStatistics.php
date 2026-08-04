<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

/** Estatísticas descritivas da série do sensor (FR-101). */
final readonly class GlucoseStatistics
{
    public function __construct(
        public int $count,
        public float $mean,
        public float $standardDeviation,
        public float $coefficientOfVariation,
        public float $gmi,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0.0, 0.0, 0.0, 0.0);
    }

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }
}

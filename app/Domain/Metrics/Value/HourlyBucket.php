<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

/** Uma hora do perfil horário (FR-104). */
final readonly class HourlyBucket
{
    public function __construct(
        public int $hour,
        public int $count,
        public float $mean,
        public float $percentAbove,
        public float $percentBelow,
    ) {}

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }
}

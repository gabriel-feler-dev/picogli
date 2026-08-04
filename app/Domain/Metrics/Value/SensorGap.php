<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

use DateTimeImmutable;

/**
 * Intervalo sem leitura de sensor (FR-107).
 *
 * ⚠️ Lacuna não é ausência de informação — é informação. Ela interrompe
 * episódio (nada foi medido ali), reduz a cobertura, e no gráfico precisa
 * aparecer como DESCONTINUIDADE. Interpolar dentro dela é inventar medição.
 */
final readonly class SensorGap
{
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public float $minutes,
    ) {}

    public function hours(): float
    {
        return $this->minutes / 60;
    }

    public function contains(DateTimeImmutable $at): bool
    {
        return $at > $this->start && $at < $this->end;
    }
}

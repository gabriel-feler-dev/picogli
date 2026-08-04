<?php

declare(strict_types=1);

namespace App\Domain\Import\Value\Events;

use DateTimeImmutable;

/**
 * Uma leitura de CGM. ~288/dia — é o volume do sistema e a base de todas as
 * métricas de glicose (TIR, GMI, CV).
 */
final readonly class SensorReadingEvent implements ImportEvent
{
    public function __construct(
        public DateTimeImmutable $recordedAtLocal,
        public int $glucoseMgdl,
        public ?float $isig,
        public ?float $deviceIndex,
        public int $sourceLine,
    ) {}
}

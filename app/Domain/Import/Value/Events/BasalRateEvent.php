<?php

declare(strict_types=1);

namespace App\Domain\Import\Value\Events;

use DateTimeImmutable;

/**
 * Mudança de taxa basal programada (perfil manual).
 *
 * Em loop fechado a basal é decidida pela bomba e reportada no bloco
 * AutoInsulin como total diário. Estes registros aparecem quando há perfil
 * manual vigente — no export de referência, taxas de 1,65 a 2,2 U/h.
 */
final readonly class BasalRateEvent implements ImportEvent
{
    public function __construct(
        public DateTimeImmutable $recordedAtLocal,
        public float $rateUh,
        public ?float $deviceIndex,
        public int $sourceLine,
    ) {}
}

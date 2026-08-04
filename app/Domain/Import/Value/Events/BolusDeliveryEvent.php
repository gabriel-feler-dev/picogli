<?php

declare(strict_types=1);

namespace App\Domain\Import\Value\Events;

use DateTimeImmutable;

/**
 * Confirmação de entrega de bolus — chega ~5 min depois do pedido (§A3).
 *
 * `$unitsDelivered` é a ÚNICA coluna somável de insulina (Artigo VIII.3).
 * No export de referência a soma correta é **295,15 U**; se um agregado der
 * ~590 U, `Selected` entrou na conta.
 */
final readonly class BolusDeliveryEvent implements ImportEvent
{
    public function __construct(
        public DateTimeImmutable $recordedAtLocal,
        public ?string $bolusType,
        public ?string $rawSource,
        public ?float $unitsSelected,
        public float $unitsDelivered,
        public ?int $bolusNumber,
        public ?float $deviceIndex,
        public int $sourceLine,
    ) {}

    /**
     * Entrega parcial: pediu X, entregou menos.
     *
     * Diferente de bolus cancelado, que não tem volume nenhum — é justamente
     * essa diferença que permite distinguir os dois casos.
     */
    public function isPartial(): bool
    {
        return $this->unitsSelected !== null
            && abs($this->unitsSelected - $this->unitsDelivered) > 0.0005;
    }
}

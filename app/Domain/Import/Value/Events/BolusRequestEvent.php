<?php

declare(strict_types=1);

namespace App\Domain\Import\Value\Events;

use DateTimeImmutable;

/**
 * Pedido de bolus — a primeira das até três linhas de um bolus (§A3).
 *
 * ⚠️ `$unitsSelected` NUNCA entra em soma de insulina. Somá-lo junto com
 * `unitsDelivered` da linha de entrega dobra o total: 295,15 U viram 590,30 U.
 * Constituição, Artigo VIII.3.
 *
 * Bolus CANCELADO também é representado aqui, com `unitsSelected = null` e
 * `cancellationReason` preenchido — decisão registrada em plan.md §Bolus
 * cancelado. Como não tem volume entregue, não afeta a soma. São 4 no export
 * de referência, todos com motivo `User Request`.
 */
final readonly class BolusRequestEvent implements ImportEvent
{
    public function __construct(
        public DateTimeImmutable $recordedAtLocal,
        public ?string $bolusType,
        public ?string $rawSource,
        public ?float $unitsSelected,
        public ?int $bolusNumber,
        public ?string $cancellationReason,
        public ?float $deviceIndex,
        public int $sourceLine,
    ) {}

    public function isCancelled(): bool
    {
        return $this->cancellationReason !== null;
    }
}

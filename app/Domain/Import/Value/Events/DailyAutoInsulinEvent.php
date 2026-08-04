<?php

declare(strict_types=1);

namespace App\Domain\Import\Value\Events;

use DateTimeImmutable;

/**
 * Total DIÁRIO de insulina entregue automaticamente pelo SmartGuard.
 *
 * Vem do bloco `Aggregated Auto Insulin Data`, uma linha por dia com
 * `Time=00:00:00` e `Bolus Source=CLOSED_LOOP_AUTO_INSULIN`.
 *
 * ⚠️ NÃO é um bolus pontual — é um agregado do dia. Tratá-lo como bolus comum
 * (o que acontece se a detecção de bloco confundir AutoInsulin com Pump)
 * infla a insulina daquele dia sem quebrar nada visivelmente.
 *
 * Ignorar este bloco subestima a insulina total em ~60% num usuário de 780G
 * com loop fechado: no export de referência são 31,4 U/dia automáticas contra
 * 21,1 U/dia de bolus.
 */
final readonly class DailyAutoInsulinEvent implements ImportEvent
{
    public function __construct(
        public DateTimeImmutable $recordedAtLocal,
        public float $unitsDelivered,
        public ?float $deviceIndex,
        public int $sourceLine,
    ) {}

    public function localDate(): string
    {
        return $this->recordedAtLocal->format('Y-m-d');
    }
}

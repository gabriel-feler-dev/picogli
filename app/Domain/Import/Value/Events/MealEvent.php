<?php

declare(strict_types=1);

namespace App\Domain\Import\Value\Events;

use DateTimeImmutable;

/**
 * Uma refeição, vinda de uma linha BWZ (Bolus WiZard).
 *
 * ⚠️ A linha BWZ NÃO tem `Bolus Number` (§A3). O vínculo com o bolus é feito
 * por timestamp IDÊNTICO ao do pedido — trabalho do `BolusLinker`, não daqui.
 *
 * `$carbRatio` e `$insulinSensitivity` registram a configuração VIGENTE no
 * momento do bolus. É o que permite reconstruir o perfil do dispositivo sem o
 * relatório de configurações — ver `SettingsInferrer` (T010).
 *
 * `peak_2h`, `delta_2h` e `glucose_4h` NÃO estão aqui de propósito: não vêm do
 * CSV. São calculados no pós-import pelo `MealEnricher` (T011), que consulta
 * as leituras de sensor já gravadas.
 */
final readonly class MealEvent implements ImportEvent
{
    public function __construct(
        public DateTimeImmutable $recordedAtLocal,
        public float $carbsG,
        public ?float $carbRatio,
        public ?float $insulinSensitivity,
        public ?int $targetLow,
        public ?int $targetHigh,
        public ?int $bgInput,
        public ?float $estimateU,
        public ?float $correctionU,
        public ?float $foodU,
        public ?float $activeInsulinU,
        public ?string $bwzStatus,
        public ?float $deviceIndex,
        public int $sourceLine,
    ) {}
}

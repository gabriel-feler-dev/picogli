<?php

declare(strict_types=1);

namespace App\Domain\Import\Value\Events;

use DateTimeImmutable;

/**
 * Glicemia capilar (picada no dedo).
 *
 * Vai para tabela SEPARADA das leituras de sensor. Métricas de CGM (TIR, GMI,
 * CV) só valem sobre a série do sensor; capilar entrando no cálculo distorce,
 * porque a frequência é irregular e o propósito é outro. Separar em duas
 * tabelas torna esse erro impossível por estrutura, não por disciplina.
 *
 * ⚠️ `$usedForCalibration` marca a glicemia ENVIADA para calibrar
 * (`BG Source=BG_SENT_FOR_CALIB`). O valor ACEITO pelo sensor vem em outra
 * linha, como `DeviceEvent(calibration)`. São 39 de cada no export de
 * referência — mesma contagem, linhas diferentes. Para calcular erro do sensor
 * (MARD), o valor relevante é o aceito.
 */
final readonly class BgReadingEvent implements ImportEvent
{
    public function __construct(
        public DateTimeImmutable $recordedAtLocal,
        public int $glucoseMgdl,
        public ?string $source,
        public bool $usedForCalibration,
        public ?float $deviceIndex,
        public int $sourceLine,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Value;

use DateTimeImmutable;

/**
 * Uma calibração capilar pareada com a leitura de sensor mais próxima (R10).
 *
 * ⚠️ `$offsetMinutes` faz parte do dado, não é metadado descartável. Duas
 * janelas de pareamento diferentes produzem dois erros médios, e **os dois estão
 * certos** — o que os distingue é a janela. Por isso ela viaja até a evidência do
 * achado: sem ela, "erro médio de 10,7%" não é reproduzível.
 *
 * O erro é **relativo ao capilar**, que é a referência: o capilar é o que a
 * calibração usou para ajustar o sensor.
 */
final readonly class CalibrationPair
{
    public function __construct(
        public DateTimeImmutable $at,
        public int $bgMgdl,
        public int $sensorMgdl,
        public float $offsetMinutes,
    ) {}

    /** Erro relativo absoluto, em pontos percentuais do capilar. */
    public function relativeErrorPercent(): float
    {
        if ($this->bgMgdl === 0) {
            return 0.0;
        }

        return abs($this->sensorMgdl - $this->bgMgdl) / $this->bgMgdl * 100;
    }

    public function signedDifference(): int
    {
        return $this->sensorMgdl - $this->bgMgdl;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

use DateTimeImmutable;

/**
 * Uma leitura de CGM, reduzida ao mínimo que a métrica precisa.
 *
 * ⚠️ `$at` é HORA LOCAL DE PAREDE (Artigo VIII.5). Todo bucketing por horário
 * depende disso; usar UTC deslocaria o perfil inteiro mantendo os números
 * plausíveis.
 */
final readonly class GlucoseReading
{
    public function __construct(
        public DateTimeImmutable $at,
        public int $mgdl,
    ) {}
}

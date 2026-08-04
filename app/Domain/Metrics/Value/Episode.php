<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

use DateTimeImmutable;

/**
 * Um episódio confirmado de hipo ou hiperglicemia (FR-106).
 *
 * ⚠️ `$durationMinutes` é MEDIDO — `fim − início` em minutos reais — não
 * `n_leituras × 5`. As duas divergem exatamente onde houve falha de leitura, e
 * é ali que a diferença importa: contar leituras afirmaria continuidade que
 * ninguém mediu.
 *
 * `$interruptedByGap` marca episódio que terminou porque o sensor saiu do ar,
 * não porque a glicose voltou. A distinção é honesta: no primeiro caso não se
 * sabe o que aconteceu depois.
 */
final readonly class Episode
{
    public function __construct(
        public EpisodeType $type,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public float $durationMinutes,
        public int $extreme,
        public int $readingCount,
        public bool $interruptedByGap = false,
    ) {}

    /** Nadir na hipoglicemia — mesmo valor de `$extreme`, com nome do domínio. */
    public function nadir(): int
    {
        return $this->extreme;
    }

    public function peak(): int
    {
        return $this->extreme;
    }
}

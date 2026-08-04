<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

/**
 * Uma hora do perfil horário (FR-104).
 *
 * ⚠️ `$dominantRange` existe porque a barra de 24 h precisa de uma COR por hora,
 * e escolher cor a partir de valores clínicos é classificação — não layout.
 * Se o React recebesse só a média e decidisse a faixa, a decisão clínica teria
 * escapado para o cliente (NFR-201), e o dashboard poderia discordar da fase 4
 * sobre o que é "hora alta".
 *
 * `null` quando a hora não tem leitura: ausência não tem faixa.
 */
final readonly class HourlyBucket
{
    public function __construct(
        public int $hour,
        public int $count,
        public float $mean,
        public float $percentAbove,
        public float $percentBelow,
        public ?string $dominantRange = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

/**
 * Cobertura do sensor no período (FR-103).
 *
 * ⚠️ Artigo V — este objeto acompanha TODA métrica exibida. O denominador
 * nunca é escondido: "GMI 6,7%" sobre 40% de captura é pior que nenhum número,
 * porque parece confiável.
 */
final readonly class Coverage
{
    public function __construct(
        public int $readingCount,
        public int $expectedCount,
        public float $spanInDays,
        public float $percentage,
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0.0, 0.0);
    }
}

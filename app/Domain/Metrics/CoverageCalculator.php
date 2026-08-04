<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Metrics\Value\Coverage;
use App\Domain\Metrics\Value\GlucoseSeries;

/**
 * Cobertura do sensor no período (FR-103).
 *
 * `cobertura = leituras ÷ (span_em_dias × leituras_por_dia)`
 *
 * ⚠️ O denominador usa o SPAN entre a primeira e a última leitura, não dias de
 * calendário (spec.md §D2). Calendário puniria um export que começa às 18h do
 * primeiro dia, mostrando cobertura baixa onde o sensor funcionou perfeito.
 */
final class CoverageCalculator
{
    public function __construct(private readonly MetricsConfig $config) {}

    public function calculate(GlucoseSeries $series): Coverage
    {
        if ($series->count() < 2) {
            return Coverage::empty();
        }

        $spanInDays = $series->spanInDays();

        // TRUNCA, não arredonda. Arredondar para cima criaria leituras
        // esperadas que o span não comporta, e a cobertura ficaria abaixo da
        // real. No export de referência: 13,7799 × 288 = 3968,6 → 3968.
        $expected = (int) ($spanInDays * $this->config->sensor['readings_per_day']);

        return new Coverage(
            readingCount: $series->count(),
            expectedCount: $expected,
            spanInDays: $spanInDays,
            percentage: $expected > 0 ? ($series->count() / $expected) * 100 : 0.0,
        );
    }
}

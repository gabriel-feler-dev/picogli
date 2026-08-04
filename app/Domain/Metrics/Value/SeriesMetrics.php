<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

/**
 * Estatísticas e distribuição saindo da MESMA varredura da série (NFR-102).
 *
 * Estão juntas porque são calculadas juntas: separá-las em duas chamadas
 * significaria percorrer ~105 mil leituras por ano duas vezes para obter o que
 * uma passada já entrega.
 */
final readonly class SeriesMetrics
{
    public function __construct(
        public GlucoseStatistics $statistics,
        public RangeDistribution $distribution,
    ) {}
}

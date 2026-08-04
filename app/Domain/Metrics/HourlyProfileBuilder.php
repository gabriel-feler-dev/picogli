<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Metrics\Value\HourlyBucket;

/**
 * Perfil por hora do dia (FR-104).
 *
 * ⚠️ Usa a HORA LOCAL de cada leitura (Artigo VIII.5). Todo o valor do produto
 * mora aqui: "sua tarde é 4x mais problemática que sua manhã" só existe se o
 * bucketing usar hora de parede. Com UTC, o perfil inteiro desliza e os números
 * continuam plausíveis.
 *
 * As 24 horas são sempre devolvidas, mesmo vazias. Hora sem leitura é
 * informação — o sensor não estava cobrindo — e omitir o balde faria a UI
 * desenhar um gráfico com furo silencioso em vez de uma lacuna explícita.
 */
final class HourlyProfileBuilder
{
    public function __construct(private readonly MetricsConfig $config) {}

    /** @return array<int, HourlyBucket> hora (0–23) → balde */
    public function build(GlucoseSeries $series): array
    {
        $targetLow = $this->config->ranges['target']['min'];
        $targetHigh = $this->config->ranges['target']['max'];

        $sums = array_fill(0, 24, 0);
        $counts = array_fill(0, 24, 0);
        $above = array_fill(0, 24, 0);
        $below = array_fill(0, 24, 0);

        foreach ($series->readings as $reading) {
            $hour = (int) $reading->at->format('G');

            $sums[$hour] += $reading->mgdl;
            $counts[$hour]++;

            if ($reading->mgdl > $targetHigh) {
                $above[$hour]++;
            } elseif ($reading->mgdl < $targetLow) {
                $below[$hour]++;
            }
        }

        $profile = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $n = $counts[$hour];

            $profile[$hour] = new HourlyBucket(
                hour: $hour,
                count: $n,
                mean: $n > 0 ? $sums[$hour] / $n : 0.0,
                percentAbove: $n > 0 ? ($above[$hour] / $n) * 100 : 0.0,
                percentBelow: $n > 0 ? ($below[$hour] / $n) * 100 : 0.0,
            );
        }

        return $profile;
    }
}

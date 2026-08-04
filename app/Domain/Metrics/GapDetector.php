<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Metrics\Value\SensorGap;

/**
 * Lacunas de sensor (FR-107).
 *
 * Um intervalo maior que `gap_threshold_minutes` entre leituras consecutivas.
 *
 * ⚠️ Esta classe vem ANTES do EpisodeDetector por dependência, não por
 * conveniência: "lacuna interrompe episódio" é regra, e sem ela a lacuna de
 * 22,4 h do export de referência poderia virar um episódio de quase um dia —
 * afirmação sobre um período em que ninguém mediu nada.
 */
final class GapDetector
{
    public function __construct(private readonly MetricsConfig $config) {}

    /** @return list<SensorGap> */
    public function detect(GlucoseSeries $series): array
    {
        $threshold = $this->config->sensor['gap_threshold_minutes'];
        $gaps = [];
        $previous = null;

        foreach ($series->readings as $reading) {
            if ($previous !== null) {
                $minutes = ($reading->at->getTimestamp() - $previous->at->getTimestamp()) / 60;

                if ($minutes > $threshold) {
                    $gaps[] = new SensorGap($previous->at, $reading->at, $minutes);
                }
            }

            $previous = $reading;
        }

        return $gaps;
    }

    /** @param list<SensorGap> $gaps */
    public function totalHours(array $gaps): float
    {
        return array_sum(array_map(fn (SensorGap $g): float => $g->hours(), $gaps));
    }
}

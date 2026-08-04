<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Metrics\Value\HourlyPercentiles;

/**
 * Percentis por hora local, para o gráfico AGP (FR-202, spec.md §D3).
 *
 * ⚠️ Calculado no SERVIDOR, não em JavaScript. O dashboard e a fase 4 têm de
 * mostrar o mesmo número, e duas implementações da mesma estatística divergem —
 * sempre.
 *
 * ⚠️ Usa hora LOCAL (Artigo VIII.5). Com UTC o perfil inteiro desliza e os
 * números continuam plausíveis.
 */
final class HourlyPercentileBuilder
{
    /** Os percentis que o AGP desenha como bandas. */
    private const PERCENTILES = [5, 25, 50, 75, 95];

    /** @return array<int, HourlyPercentiles> hora (0–23) → percentis */
    public function build(GlucoseSeries $series): array
    {
        $byHour = array_fill(0, 24, []);

        foreach ($series->readings as $reading) {
            $byHour[(int) $reading->at->format('G')][] = $reading->mgdl;
        }

        $profile = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $values = $byHour[$hour];

            if ($values === []) {
                $profile[$hour] = HourlyPercentiles::empty($hour);

                continue;
            }

            sort($values);

            $profile[$hour] = new HourlyPercentiles(
                hour: $hour,
                count: count($values),
                p5: $this->percentile($values, 5),
                p25: $this->percentile($values, 25),
                p50: $this->percentile($values, 50),
                p75: $this->percentile($values, 75),
                p95: $this->percentile($values, 95),
            );
        }

        return $profile;
    }

    /**
     * Percentil por INTERPOLAÇÃO LINEAR entre posições.
     *
     * ⚠️ O método está declarado aqui de propósito. Existem ao menos seis
     * definições de percentil em uso, e elas dão resultados diferentes na mesma
     * série. Sem fixar a escolha, uma reimplementação futura produziria número
     * distinto e alguém caçaria bug onde só há convenção.
     *
     * Definição adotada — a mesma de `numpy.percentile(..., method='linear')`
     * e do `PERCENTILE.INC` de planilha:
     *
     *     posição = (n − 1) × p
     *     valor   = v[⌊posição⌋] + fração × (v[⌈posição⌉] − v[⌊posição⌋])
     *
     * Exemplo verificável: em `[10, 20, …, 100]` (n=10), o p50 fica na posição
     * `9 × 0,5 = 4,5`, entre 50 e 60 → **55**.
     *
     * @param  list<int>  $sorted  já ordenado crescente
     */
    private function percentile(array $sorted, int $percentile): float
    {
        $n = count($sorted);

        if ($n === 1) {
            return (float) $sorted[0];
        }

        $position = ($n - 1) * ($percentile / 100);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        if ($lower === $upper) {
            return (float) $sorted[$lower];
        }

        $fraction = $position - $lower;

        return $sorted[$lower] + $fraction * ($sorted[$upper] - $sorted[$lower]);
    }
}

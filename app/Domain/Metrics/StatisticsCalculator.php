<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Metrics\Value\GlucoseStatistics;
use App\Domain\Metrics\Value\RangeDistribution;
use App\Domain\Metrics\Value\SeriesMetrics;

/**
 * Estatísticas e distribuição por faixa, em UMA passada (FR-101, FR-102).
 *
 * NFR-102: média, desvio e os cinco baldes saem da mesma varredura. Com ~105
 * mil leituras por ano, cinco passadas seriam desperdício gratuito.
 */
final class StatisticsCalculator
{
    public function __construct(private readonly MetricsConfig $config) {}

    public function calculate(GlucoseSeries $series): SeriesMetrics
    {
        if ($series->isEmpty()) {
            return new SeriesMetrics(GlucoseStatistics::empty(), RangeDistribution::empty());
        }

        $sum = 0.0;
        $sumOfSquares = 0.0;
        $counts = array_fill_keys(array_keys($this->config->ranges), 0);

        foreach ($series->readings as $reading) {
            $value = $reading->mgdl;

            $sum += $value;
            $sumOfSquares += $value * $value;

            $counts[$this->rangeFor($value)]++;
        }

        $n = $series->count();
        $mean = $sum / $n;

        // Desvio POPULACIONAL: a série é o período inteiro, não uma amostra
        // dele. Usar o amostral (n-1) inflaria o CV de forma pequena e
        // sistemática, e o CV tem meta clínica (<36%).
        //
        // ⚠️ sqrt(E[x²] - E[x]²) perde precisão quando a variância é minúscula
        // frente à média. Glicose varia muito, então não é o caso — mas se a
        // suíte um dia acusar variância negativa por arredondamento, troque por
        // Welford em vez de caçar fantasma.
        $variance = max(0.0, ($sumOfSquares / $n) - ($mean * $mean));
        $sd = sqrt($variance);

        $statistics = new GlucoseStatistics(
            count: $n,
            mean: $mean,
            standardDeviation: $sd,
            coefficientOfVariation: $mean > 0 ? ($sd / $mean) * 100 : 0.0,
            gmi: $this->config->gmi['intercept'] + $this->config->gmi['slope'] * $mean,
        );

        $percentages = [];
        foreach ($counts as $range => $count) {
            $percentages[$range] = ($count / $n) * 100;
        }

        return new SeriesMetrics(
            $statistics,
            new RangeDistribution($n, $counts, $percentages),
        );
    }

    /**
     * Em qual faixa a leitura cai.
     *
     * ⚠️ AQUI MORA O RISCO DE BORDA. Os limites de `config/clinical.php` são
     * FECHADOS nos dois extremos: 54–69, 70–180, 181–250. Um `<` no lugar de
     * `<=` faz uma leitura não cair em faixa nenhuma, a soma para de dar 100%,
     * e ninguém olha para a soma.
     *
     * A primeira faixa cujo intervalo contém o valor vence. Como as faixas de
     * config são disjuntas e contíguas, isso é determinístico — e a invariante
     * testada com os valores exatos de borda é o que garante que continuem.
     */
    private function rangeFor(int $value): string
    {
        foreach ($this->config->ranges as $name => $bounds) {
            $min = $bounds['min'] ?? PHP_INT_MIN;
            $max = $bounds['max'] ?? PHP_INT_MAX;

            if ($value >= $min && $value <= $max) {
                return $name;
            }
        }

        // Faixas de config que não cobrem todo o domínio: erro de configuração,
        // não de dado. Falha alto em vez de descartar a leitura em silêncio.
        throw new \LogicException("Leitura de {$value} mg/dL não cai em nenhuma faixa configurada.");
    }
}

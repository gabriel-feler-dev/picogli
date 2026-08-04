<?php

declare(strict_types=1);

use App\Domain\Metrics\CoverageCalculator;
use App\Domain\Metrics\GapDetector;
use App\Domain\Metrics\MetricsConfig;
use App\Domain\Metrics\StatisticsCalculator;
use App\Domain\Metrics\ValidityGate;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Metrics\Value\Validity;

/**
 * T100–T103 — FR-101, FR-102, FR-103, FR-107
 *
 * Testes de DOMÍNIO PURO: nenhum toca banco nem sobe o framework.
 * A config é injetada, não lida via `config()` — é o que mantém isso possível.
 */
function metricsConfig(): MetricsConfig
{
    // Mesma forma de config/clinical.php, explícita para o teste não depender
    // de o arquivo estar como esperado.
    return MetricsConfig::fromArray([
        'ranges' => [
            'very_low' => ['max' => 53],
            'low' => ['min' => 54, 'max' => 69],
            'target' => ['min' => 70, 'max' => 180],
            'high' => ['min' => 181, 'max' => 250],
            'very_high' => ['min' => 251],
        ],
        'gmi' => ['intercept' => 3.31, 'slope' => 0.02392],
        'validity' => ['min_days' => 14, 'min_coverage' => 0.70, 'min_days_rounding_floor' => 13.5],
        'sensor' => ['readings_per_day' => 288, 'interval_minutes' => 5, 'gap_threshold_minutes' => 30],
    ]);
}

/** Série sintética: leituras a cada 5 min a partir de um instante. */
function seriesEvery5Min(string $start, array $values): GlucoseSeries
{
    $at = new DateTimeImmutable($start);
    $pairs = [];

    foreach ($values as $i => $value) {
        $pairs[] = [$at->modify("+{$i} minutes * 5")->format('Y-m-d H:i:s'), $value];
    }

    return GlucoseSeries::fromPairs(array_map(
        fn (int $i, int $v): array => [
            (new DateTimeImmutable($start))->modify('+'.($i * 5).' minutes')->format('Y-m-d H:i:s'),
            $v,
        ],
        array_keys($values),
        $values,
    ));
}

beforeEach(function () {
    $this->config = metricsConfig();
});

describe('T100 — GlucoseSeries', function () {

    it('ordena sempre, mesmo com entrada fora de ordem', function () {
        $series = GlucoseSeries::fromPairs([
            ['2026-07-29 12:00:00', 150],
            ['2026-07-29 08:00:00', 100],
            ['2026-07-29 10:00:00', 120],
        ]);

        expect($series->values())->toBe([100, 120, 150]);
        expect($series->first()->at->format('H:i'))->toBe('08:00');
        expect($series->last()->at->format('H:i'))->toBe('12:00');
    });

    it('série vazia não lança — devolve estado nulo explícito', function () {
        $series = GlucoseSeries::of([]);

        expect($series->isEmpty())->toBeTrue();
        expect($series->count())->toBe(0);
        expect($series->first())->toBeNull();
        expect($series->spanInDays())->toBe(0.0);

        $metrics = (new StatisticsCalculator($this->config))->calculate($series);
        expect($metrics->statistics->isEmpty())->toBeTrue();
        expect($metrics->distribution->sumsToOneHundred())->toBeTrue();
    });

    it('span é intervalo real, não dias de calendário (§D2)', function () {
        // Começa às 18h de um dia, termina às 6h do seguinte: 12 h = 0,5 dia.
        $series = GlucoseSeries::fromPairs([
            ['2026-07-16 18:00:00', 100],
            ['2026-07-17 06:00:00', 120],
        ]);

        expect($series->spanInDays())->toBe(0.5);
    });
});

describe('T101 — StatisticsCalculator', function () {

    // ⚠️ O TESTE MAIS IMPORTANTE DA FASE.
    // Um `<` no lugar de `<=` faz uma leitura não cair em faixa nenhuma; a soma
    // deixa de dar 100% e ninguém olha para a soma.
    it('distribui os valores exatos de BORDA, um em cada faixa', function () {
        $series = GlucoseSeries::fromPairs(array_map(
            fn (int $i, int $v): array => ['2026-07-29 '.sprintf('%02d:00:00', $i), $v],
            range(0, 7),
            [53, 54, 69, 70, 180, 181, 250, 251],
        ));

        $d = (new StatisticsCalculator($this->config))->calculate($series)->distribution;

        expect($d->counts)->toBe([
            'very_low' => 1,   // 53
            'low' => 2,        // 54, 69
            'target' => 2,     // 70, 180
            'high' => 2,       // 181, 250
            'very_high' => 1,  // 251
        ]);
        expect($d->total)->toBe(8);
        expect($d->sumsToOneHundred())->toBeTrue();
    });

    it('a soma dá 100% em qualquer série', function (array $values) {
        $series = GlucoseSeries::fromPairs(array_map(
            fn (int $i, int $v): array => ['2026-07-29 '.sprintf('%02d:%02d:00', intdiv($i, 60), $i % 60), $v],
            array_keys($values),
            $values,
        ));

        expect((new StatisticsCalculator($this->config))->calculate($series)
            ->distribution->sumsToOneHundred())->toBeTrue();
    })->with([
        'só na faixa' => [[100, 110, 120]],
        'só hipo' => [[40, 50, 60]],
        'só hiper' => [[300, 400]],
        'extremos' => [[20, 600]],
        'uma leitura' => [[100]],
    ]);

    it('calcula média, desvio populacional, CV e GMI', function () {
        // Série simétrica: média 100, desvio populacional exato.
        $series = GlucoseSeries::fromPairs([
            ['2026-07-29 00:00:00', 90],
            ['2026-07-29 00:05:00', 100],
            ['2026-07-29 00:10:00', 110],
        ]);

        $stats = (new StatisticsCalculator($this->config))->calculate($series)->statistics;

        expect($stats->mean)->toBe(100.0);
        // DP populacional de [90,100,110] = sqrt(200/3) ≈ 8,165
        expect(round($stats->standardDeviation, 3))->toBe(8.165);
        expect(round($stats->coefficientOfVariation, 3))->toBe(8.165);
        // GMI = 3,31 + 0,02392 × 100 = 5,702
        expect(round($stats->gmi, 3))->toBe(5.702);
    });

    it('faixas de config que não cobrem o domínio falham alto', function () {
        $broken = MetricsConfig::fromArray([
            'ranges' => ['target' => ['min' => 70, 'max' => 180]],
            'gmi' => ['intercept' => 3.31, 'slope' => 0.02392],
            'validity' => ['min_days' => 14, 'min_coverage' => 0.7, 'min_days_rounding_floor' => 13.5],
            'sensor' => ['readings_per_day' => 288, 'interval_minutes' => 5, 'gap_threshold_minutes' => 30],
        ]);

        $series = GlucoseSeries::fromPairs([['2026-07-29 00:00:00', 300]]);

        // Erro de CONFIGURAÇÃO, não de dado — descartar a leitura em silêncio
        // faria a soma não fechar sem pista de por quê.
        expect(fn () => (new StatisticsCalculator($broken))->calculate($series))
            ->toThrow(LogicException::class, 'não cai em nenhuma faixa');
    });
});

describe('T102 — CoverageCalculator e ValidityGate', function () {

    it('usa o span como denominador e trunca as esperadas', function () {
        // 1 dia exato → 288 esperadas. 145 leituras = 50,3%.
        $series = GlucoseSeries::fromPairs([
            ['2026-07-16 00:00:00', 100],
            ['2026-07-17 00:00:00', 100],
        ]);

        $coverage = (new CoverageCalculator($this->config))->calculate($series);

        expect($coverage->spanInDays)->toBe(1.0);
        expect($coverage->expectedCount)->toBe(288);
        expect($coverage->readingCount)->toBe(2);
    });

    it('reprova por DIAS quando o sensor funcionou perfeito', function () {
        // 3 dias, 100% de captura: o problema é o período, não o sensor —
        // e dizer "captura insuficiente" aqui seria mentira.
        $coverage = new App\Domain\Metrics\Value\Coverage(864, 864, 3.0, 100.0);

        expect((new ValidityGate($this->config))->evaluate($coverage))
            ->toBe(Validity::InsufficientDays);
    });

    it('reprova por CAPTURA quando há dias suficientes', function () {
        $coverage = new App\Domain\Metrics\Value\Coverage(1600, 4032, 14.0, 39.7);

        expect((new ValidityGate($this->config))->evaluate($coverage))
            ->toBe(Validity::InsufficientCoverage);
    });

    it('aprova 13,8 dias pela regra de arredondamento documentada', function () {
        $coverage = new App\Domain\Metrics\Value\Coverage(3616, 3968, 13.78, 91.13);

        expect((new ValidityGate($this->config))->evaluate($coverage))->toBe(Validity::Valid);
    });

    it('NÃO aprova 13,4 dias — o arredondamento tem piso', function () {
        $coverage = new App\Domain\Metrics\Value\Coverage(3500, 3859, 13.4, 90.7);

        expect((new ValidityGate($this->config))->evaluate($coverage))
            ->toBe(Validity::InsufficientDays);
    });
});

describe('T103 — GapDetector', function () {

    it('detecta intervalo acima do limiar', function () {
        $series = GlucoseSeries::fromPairs([
            ['2026-07-20 15:24:00', 120],
            ['2026-07-20 15:29:00', 118],   // 5 min — normal
            ['2026-07-20 17:34:00', 130],   // 125 min — lacuna
            ['2026-07-20 17:39:00', 128],
        ]);

        $gaps = (new GapDetector($this->config))->detect($series);

        expect($gaps)->toHaveCount(1);
        expect($gaps[0]->minutes)->toBe(125.0);
        expect($gaps[0]->start->format('H:i'))->toBe('15:29');
        expect($gaps[0]->end->format('H:i'))->toBe('17:34');
    });

    it('não confunde intervalo normal de 5 min com lacuna', function () {
        $series = GlucoseSeries::fromPairs([
            ['2026-07-20 00:00:00', 100],
            ['2026-07-20 00:05:00', 105],
            ['2026-07-20 00:10:00', 110],
        ]);

        expect((new GapDetector($this->config))->detect($series))->toBe([]);
    });

    it('30 min exatos não é lacuna; 31 é', function () {
        $exact = GlucoseSeries::fromPairs([
            ['2026-07-20 00:00:00', 100], ['2026-07-20 00:30:00', 100],
        ]);
        $over = GlucoseSeries::fromPairs([
            ['2026-07-20 00:00:00', 100], ['2026-07-20 00:31:00', 100],
        ]);

        expect((new GapDetector($this->config))->detect($exact))->toBe([]);
        expect((new GapDetector($this->config))->detect($over))->toHaveCount(1);
    });

    it('soma as horas perdidas', function () {
        $series = GlucoseSeries::fromPairs([
            ['2026-07-20 00:00:00', 100],
            ['2026-07-20 02:00:00', 100],   // 2 h
            ['2026-07-20 05:00:00', 100],   // 3 h
        ]);

        $detector = new GapDetector($this->config);
        $gaps = $detector->detect($series);

        expect($gaps)->toHaveCount(2);
        expect($detector->totalHours($gaps))->toBe(5.0);
    });
});

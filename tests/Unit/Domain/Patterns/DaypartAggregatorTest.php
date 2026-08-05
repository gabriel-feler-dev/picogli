<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsConfig;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Patterns\DaypartAggregator;
use App\Domain\Patterns\Value\Daypart;

/**
 * T302.2 — §D6. Quatro blocos de 6 h, percentual SOBRE LEITURAS.
 *
 * Roda sem container: é a prova de que a agregação é domínio puro.
 */
function daypartConfig(): MetricsConfig
{
    return new MetricsConfig(
        ranges: [
            'very_low' => ['max' => 53],
            'low' => ['min' => 54, 'max' => 69],
            'target' => ['min' => 70, 'max' => 180],
            'high' => ['min' => 181, 'max' => 250],
            'very_high' => ['min' => 251],
        ],
        gmi: ['intercept' => 3.31, 'slope' => 0.02392],
        validity: ['min_days' => 14, 'min_coverage' => 0.70, 'min_days_rounding_floor' => 13.5],
        sensor: ['readings_per_day' => 288, 'interval_minutes' => 5, 'gap_threshold_minutes' => 30],
        episodes: [
            'hypoglycemia' => ['threshold' => 70, 'min_duration_minutes' => 15, 'recovery_minutes' => 15],
            'hyperglycemia_level2' => ['threshold' => 250, 'min_duration_minutes' => 30, 'recovery_minutes' => 15],
        ],
    );
}

function daypartBounds(): array
{
    return [
        'dawn' => ['label' => 'madrugada', 'from' => 0, 'to' => 5],
        'morning' => ['label' => 'manhã', 'from' => 6, 'to' => 11],
        'afternoon' => ['label' => 'tarde', 'from' => 12, 'to' => 17],
        'evening' => ['label' => 'noite', 'from' => 18, 'to' => 23],
    ];
}

function aggregator(?array $bounds = null): DaypartAggregator
{
    return new DaypartAggregator(daypartConfig(), $bounds ?? daypartBounds());
}

/** N leituras numa hora, das quais $above acima de 180. */
function readingsAtHour(int $hour, int $count, int $above = 0): array
{
    $readings = [];

    for ($i = 0; $i < $count; $i++) {
        $readings[] = new GlucoseReading(
            new DateTimeImmutable(sprintf('2026-07-16 %02d:%02d:00', $hour, $i % 60)),
            $i < $above ? 200 : 120,
        );
    }

    return $readings;
}

describe('percentual sobre leituras, não média de percentuais', function () {

    // ⚠️ O TESTE CENTRAL DA §D6. As duas formas de calcular divergem sempre que
    // as horas têm `n` diferente — e no export de referência elas têm: de 132 a
    // 156 leituras por hora.
    it('pondera pelo n de cada hora', function () {
        //   hora 0:  10 leituras,  1 acima  = 10%
        //   hora 1:  90 leituras, 27 acima  = 30%
        //   média dos percentuais ......... = 20%   ← ERRADO
        //   sobre leituras: 28 / 100 ...... = 28%   ← CERTO
        $series = GlucoseSeries::of([
            ...readingsAtHour(0, 10, 1),
            ...readingsAtHour(1, 90, 27),
        ]);

        $dawn = aggregator()->aggregate($series)['dawn'];

        expect($dawn->count)->toBe(100);
        // A contagem é o assert que importa: ela é exata, o percentual é vista.
        expect($dawn->aboveCount)->toBe(28);
        expect($dawn->percentAbove())->toBeCloseToValue(28.0);
        expect(round($dawn->percentAbove(), 2))->not->toBe(20.0);
    });

    it('a soma dos n dos quatro períodos fecha com a série', function () {
        $series = GlucoseSeries::of([
            ...readingsAtHour(3, 12),
            ...readingsAtHour(8, 30),
            ...readingsAtHour(15, 7),
            ...readingsAtHour(22, 51),
        ]);

        $stats = aggregator()->aggregate($series);
        $soma = array_sum(array_map(fn ($s): int => $s->count, $stats));

        expect($soma)->toBe($series->count());
        expect($soma)->toBe(100);
    });
});

describe('as fronteiras de hora', function () {

    it('coloca cada hora no período certo', function (int $hour, string $esperado) {
        $stats = aggregator()->aggregate(GlucoseSeries::of(readingsAtHour($hour, 1)));

        expect($stats[$esperado]->count)->toBe(1);
    })->with([
        [0, 'dawn'], [5, 'dawn'],
        [6, 'morning'], [11, 'morning'],
        [12, 'afternoon'], [17, 'afternoon'],
        [18, 'evening'], [23, 'evening'],
    ]);
});

describe('o denominador (Artigo V dentro da regra)', function () {

    it('devolve os quatro períodos mesmo vazios, com n = 0', function () {
        $stats = aggregator()->aggregate(GlucoseSeries::of([]));

        expect($stats)->toHaveCount(4);

        foreach (Daypart::cases() as $daypart) {
            expect($stats[$daypart->value]->count)->toBe(0);
            expect($stats[$daypart->value]->isEmpty())->toBeTrue();
            // Zero, não divisão por zero.
            expect($stats[$daypart->value]->percentAbove())->toBe(0.0);
            expect($stats[$daypart->value]->mean())->toBe(0.0);
        }
    });

    it('hasEnoughReadings protege comparação sobre amostra minúscula', function () {
        $stats = aggregator()->aggregate(GlucoseSeries::of(readingsAtHour(14, 40, 12)));

        // 30% acima — o pior percentual imaginável — mas sobre 40 leituras.
        expect($stats['afternoon']->percentAbove())->toBe(30.0);
        expect($stats['afternoon']->hasEnoughReadings(100))->toBeFalse();
        expect($stats['afternoon']->hasEnoughReadings(40))->toBeTrue();
    });

    it('conta abaixo da faixa separadamente', function () {
        $series = GlucoseSeries::of([
            new GlucoseReading(new DateTimeImmutable('2026-07-16 02:00:00'), 60),
            new GlucoseReading(new DateTimeImmutable('2026-07-16 02:05:00'), 120),
            new GlucoseReading(new DateTimeImmutable('2026-07-16 02:10:00'), 200),
            new GlucoseReading(new DateTimeImmutable('2026-07-16 02:15:00'), 40),
        ]);

        $dawn = aggregator()->aggregate($series)['dawn'];

        expect($dawn->belowCount)->toBe(2);
        expect($dawn->aboveCount)->toBe(1);
        expect($dawn->percentBelow())->toBe(50.0);
        expect($dawn->mean())->toBe(105.0);
    });

    it('a faixa-alvo é fechada: 180 não é acima, 70 não é abaixo', function () {
        $series = GlucoseSeries::of([
            new GlucoseReading(new DateTimeImmutable('2026-07-16 02:00:00'), 180),
            new GlucoseReading(new DateTimeImmutable('2026-07-16 02:05:00'), 70),
            new GlucoseReading(new DateTimeImmutable('2026-07-16 02:10:00'), 181),
            new GlucoseReading(new DateTimeImmutable('2026-07-16 02:15:00'), 69),
        ]);

        $dawn = aggregator()->aggregate($series)['dawn'];

        expect($dawn->aboveCount)->toBe(1);
        expect($dawn->belowCount)->toBe(1);
    });
});

describe('validação dos limites (as três falhas silenciosas)', function () {

    it('recusa config que não casa com o enum', function () {
        $bounds = daypartBounds();
        $bounds['tarde_da_noite'] = ['label' => 'x', 'from' => 0, 'to' => 0];

        expect(fn () => aggregator($bounds))
            ->toThrow(InvalidArgumentException::class, 'tarde_da_noite');
    });

    // Sem esta guarda as leituras da hora órfã sairiam da agregação sem erro
    // nenhum, e a soma dos n deixaria de fechar com o total da série.
    it('recusa hora que não pertence a nenhum período', function () {
        $bounds = daypartBounds();
        $bounds['evening']['to'] = 22;   // a hora 23 fica órfã

        expect(fn () => aggregator($bounds))
            ->toThrow(InvalidArgumentException::class, 'A hora 23 não pertence');
    });

    // E sem esta, a leitura entraria duas vezes e o percentual continuaria
    // entre 0 e 100 — plausível e errado.
    it('recusa hora em dois períodos', function () {
        $bounds = daypartBounds();
        $bounds['morning']['from'] = 5;   // a hora 5 fica em dawn e em morning

        expect(fn () => aggregator($bounds))
            ->toThrow(InvalidArgumentException::class, 'contada duas vezes');
    });
});

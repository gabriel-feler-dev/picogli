<?php

declare(strict_types=1);

use App\Domain\Metrics\HourlyPercentileBuilder;
use App\Domain\Metrics\Value\GlucoseSeries;

/**
 * T202 — FR-202 (Percentis por hora), spec.md §D3
 *
 * Domínio puro: não toca banco nem sobe o framework.
 */
beforeEach(function () {
    $this->builder = new HourlyPercentileBuilder;
});

describe('método de interpolação declarado', function () {

    // Exemplo que a spec cita: [10..100] com n=10.
    // posição do p50 = (10-1) × 0,5 = 4,5 → entre 50 e 60 → 55.
    it('reproduz o exemplo verificável da spec', function () {
        $values = range(10, 100, 10);

        $series = GlucoseSeries::fromPairs(array_map(
            fn (int $i, int $v): array => ['2026-07-29 04:'.sprintf('%02d', $i * 5).':00', $v],
            array_keys($values),
            $values,
        ));

        $p = $this->builder->build($series)[4];

        expect($p->count)->toBe(10);
        expect($p->p50)->toBe(55.0);
        expect($p->median())->toBe(55.0);
    });

    it('percentis nos extremos batem com o mínimo e o máximo', function () {
        $series = GlucoseSeries::fromPairs([
            ['2026-07-29 04:00:00', 60],
            ['2026-07-29 04:05:00', 100],
            ['2026-07-29 04:10:00', 200],
        ]);

        $p = $this->builder->build($series)[4];

        // p0 e p100 não são calculados, mas p5 fica próximo do mínimo e p95 do
        // máximo — e nunca fora deles.
        expect($p->p5)->toBeGreaterThanOrEqual(60.0);
        expect($p->p95)->toBeLessThanOrEqual(200.0);
        expect($p->p50)->toBe(100.0);
    });

    it('série de uma leitura devolve o próprio valor em todos os percentis', function () {
        $series = GlucoseSeries::fromPairs([['2026-07-29 04:00:00', 117]]);

        $p = $this->builder->build($series)[4];

        expect($p->p5)->toBe(117.0);
        expect($p->p50)->toBe(117.0);
        expect($p->p95)->toBe(117.0);
        expect($p->isMonotonic())->toBeTrue();
    });
});

describe('hora vazia', function () {

    // ⚠️ Zero pareceria glicose de 0 mg/dL num gráfico — pior que ausência,
    // porque ausência é visível e zero convence.
    it('devolve null, não zero', function () {
        $series = GlucoseSeries::fromPairs([['2026-07-29 04:00:00', 117]]);

        $profile = $this->builder->build($series);

        expect($profile[3]->isEmpty())->toBeTrue();
        expect($profile[3]->p50)->toBeNull();
        expect($profile[3]->median())->toBeNull();
        expect($profile[3]->count)->toBe(0);
    });

    it('devolve as 24 horas sempre', function () {
        $profile = $this->builder->build(GlucoseSeries::of([]));

        expect($profile)->toHaveCount(24);

        foreach ($profile as $hour => $p) {
            expect($p->hour)->toBe($hour);
            expect($p->isEmpty())->toBeTrue();
        }
    });
});

describe('invariante de monotonicidade', function () {

    // Banda invertida num gráfico não é lida como bug por ninguém — por isso a
    // invariante é testada, não presumida.
    it('p5 ≤ p25 ≤ p50 ≤ p75 ≤ p95 em qualquer série', function (array $values) {
        $series = GlucoseSeries::fromPairs(array_map(
            fn (int $i, int $v): array => ['2026-07-29 04:'.sprintf('%02d', $i).':00', $v],
            array_keys($values),
            $values,
        ));

        $p = $this->builder->build($series)[4];

        expect($p->isMonotonic())->toBeTrue();
    })->with([
        'crescente' => [[40, 60, 80, 100, 200, 300]],
        'decrescente' => [[300, 200, 100, 80, 60, 40]],
        'repetidos' => [[100, 100, 100, 100]],
        'dois valores' => [[55, 300]],
        'extremos' => [[20, 600, 100, 45, 250]],
    ]);
});

describe('usa hora local, nunca UTC', function () {

    it('agrupa pelo relógio de parede do aparelho', function () {
        // 23:30 local. Se o balde usasse UTC (São Paulo = UTC-3), cairia em 02h.
        $series = GlucoseSeries::fromPairs([
            ['2026-07-29 23:30:00', 200],
            ['2026-07-29 23:35:00', 210],
        ]);

        $profile = $this->builder->build($series);

        expect($profile[23]->count)->toBe(2);
        expect($profile[2]->isEmpty())->toBeTrue();
    });
});

<?php

declare(strict_types=1);

use App\Domain\Metrics\CoverageCalculator;
use App\Domain\Metrics\GapDetector;
use App\Domain\Metrics\MetricsConfig;
use App\Domain\Metrics\StatisticsCalculator;
use App\Domain\Metrics\ValidityGate;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Metrics\Value\Validity;
use App\Jobs\ImportCsvJob;
use App\Models\SensorReading;
use App\Models\User;

/**
 * T101–T103 verificados contra o export real.
 *
 * Os valores vêm de `gabarito.md` §Fase 2, apurados por análise INDEPENDENTE do
 * código que está sendo testado — e antes dele existir. É o que dá ao Artigo XI
 * força real: se divergir, presume-se que o código está errado.
 */
beforeEach(function () {
    $this->user = User::factory()->create();

    (new ImportCsvJob($this->user->id, requireReferenceExport(), 'America/Sao_Paulo'))->handle(
        app(App\Domain\Import\CarelinkCsvReader::class),
        app(App\Domain\Import\EventExploder::class),
        app(App\Domain\Import\BolusLinker::class),
        app(App\Domain\Import\Persistence\MealEnricher::class),
        app(App\Domain\Import\SettingsInferrer::class),
    );

    // A borda: aqui o banco vira domínio puro. Daqui para dentro, nenhuma
    // calculadora sabe que existe Eloquent.
    $this->series = GlucoseSeries::of(
        SensorReading::where('user_id', $this->user->id)
            ->orderBy('recorded_at_local')
            ->get(['recorded_at_local', 'glucose_mgdl'])
            ->map(fn (SensorReading $r) => new GlucoseReading(
                new DateTimeImmutable($r->recorded_at_local->format('Y-m-d H:i:s')),
                $r->glucose_mgdl,
            ))
            ->all()
    );

    $this->config = MetricsConfig::fromArray(config('clinical'));
});

it('a série tem as 3.616 leituras do gabarito', function () {
    expect($this->series->count())->toBe(3616);
    expect($this->series->first()->at->format('Y-m-d H:i:s'))->toBe('2026-07-16 00:04:07');
    expect($this->series->last()->at->format('Y-m-d H:i:s'))->toBe('2026-07-29 18:47:11');
});

it('reproduz as métricas globais (FR-101)', function () {
    $stats = (new StatisticsCalculator($this->config))->calculate($this->series)->statistics;

    // gabarito.md §Métricas globais
    expect(round($stats->mean))->toBe(142.0);
    expect(round($stats->standardDeviation))->toBe(41.0);
    expect(round($stats->coefficientOfVariation, 1))->toBe(28.8);
    expect(round($stats->gmi, 2))->toBe(6.70);
});

it('reproduz o tempo nas faixas e a soma fecha em 100% (FR-102)', function () {
    $d = (new StatisticsCalculator($this->config))->calculate($this->series)->distribution;

    expect(round($d->percentages['target'], 1))->toBe(83.9);
    expect(round($d->percentages['high'], 1))->toBe(12.9);
    expect(round($d->percentages['very_high'], 1))->toBe(1.9);
    expect(round($d->percentages['low'], 1))->toBe(1.3);
    expect(round($d->percentages['very_low'], 1))->toBe(0.0);

    // ⚠️ Invariante obrigatória.
    expect($d->sumsToOneHundred())->toBeTrue();
    expect($d->total)->toBe(3616);

    // Metas de config/clinical.php — todas atingidas neste período.
    expect($d->timeInRange())->toBeGreaterThan(70.0);
    expect($d->timeAboveRange())->toBeLessThan(25.0);
    expect($d->timeBelowRange())->toBeLessThan(4.0);
});

it('reproduz a cobertura e aprova no portão de validade (FR-103)', function () {
    $coverage = (new CoverageCalculator($this->config))->calculate($this->series);

    // gabarito.md §Cobertura
    expect($coverage->readingCount)->toBe(3616);
    expect($coverage->expectedCount)->toBe(3968);
    expect(round($coverage->spanInDays, 1))->toBe(13.8);
    expect(round($coverage->percentage, 1))->toBe(91.1);

    // 13,8 dias arredonda para 14 pela regra documentada, e a captura passa.
    expect((new ValidityGate($this->config))->evaluate($coverage))->toBe(Validity::Valid);
});

it('reproduz as três lacunas de sensor (FR-107)', function () {
    $detector = new GapDetector($this->config);
    $gaps = $detector->detect($this->series);

    // gabarito.md §Lacunas de sensor
    expect($gaps)->toHaveCount(3);
    expect(round($detector->totalHours($gaps), 1))->toBe(29.0);

    $longest = collect($gaps)->sortByDesc(fn ($g) => $g->minutes)->first();

    // Assert em MINUTOS, nao em horas arredondadas: 1347 min = 22,45 h fica
    // exatamente na borda de arredondamento, e Python e PHP escolhem lados
    // opostos (22,4 vs 22,5). O dado e identico; o formato e que enganava.
    expect($longest->minutes)->toBe(1347.0);
    expect($longest->start->format('Y-m-d H:i'))->toBe('2026-07-21 17:29');
    expect($longest->end->format('Y-m-d H:i'))->toBe('2026-07-22 15:56');
});

it('a maior lacuna é a que vai interromper episódios no T104', function () {
    $gaps = (new GapDetector($this->config))->detect($this->series);
    $longest = collect($gaps)->sortByDesc(fn ($g) => $g->minutes)->first();

    // Sem esta lacuna reconhecida, um episódio poderia atravessar 22 h sem
    // nenhuma medição — afirmação sobre período em que ninguém mediu.
    expect($longest->contains(new DateTimeImmutable('2026-07-22 03:00:00')))->toBeTrue();
    expect($longest->contains(new DateTimeImmutable('2026-07-23 03:00:00')))->toBeFalse();
});

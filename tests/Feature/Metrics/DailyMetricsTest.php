<?php

declare(strict_types=1);

use App\Domain\Metrics\HourlyProfileBuilder;
use App\Domain\Metrics\MetricsConfig;
use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Metrics\StatisticsCalculator;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Jobs\ComputeMetricsJob;
use App\Jobs\ImportCsvJob;
use App\Models\DailyMetrics;
use App\Models\SensorReading;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * T105 (perfil horário), T106 (schema) e T107 (materialização).
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

    $this->config = MetricsConfig::fromArray(config('clinical'));

    $this->series = GlucoseSeries::of(
        SensorReading::where('user_id', $this->user->id)
            ->orderBy('recorded_at_local')
            ->get(['recorded_at_local', 'glucose_mgdl'])
            ->map(fn (SensorReading $r) => new GlucoseReading(
                new DateTimeImmutable($r->recorded_at_local->format('Y-m-d H:i:s')),
                $r->glucose_mgdl,
            ))->all()
    );
});

describe('T105 — perfil horário (FR-104)', function () {

    it('reproduz o perfil do gabarito', function () {
        $profile = (new HourlyProfileBuilder($this->config))->build($this->series);

        expect($profile)->toHaveCount(24);

        // gabarito.md §Perfil horário
        expect(round($profile[4]->mean))->toBe(123.0);
        expect(round($profile[4]->percentAbove, 1))->toBe(0.0);
        expect($profile[4]->count)->toBe(156);

        expect(round($profile[16]->mean))->toBe(159.0);
        expect(round($profile[16]->percentAbove, 1))->toBe(34.2);

        expect(round($profile[18]->percentBelow, 1))->toBe(8.0);
        expect(round($profile[20]->mean))->toBe(171.0);
    });

    it('identifica as horas extremas — a matéria-prima da regra R1', function () {
        $profile = (new HourlyProfileBuilder($this->config))->build($this->series);

        $worstMean = collect($profile)->sortByDesc(fn ($b) => $b->mean)->first();
        $worstBelow = collect($profile)->sortByDesc(fn ($b) => $b->percentBelow)->first();
        $worstAbove = collect($profile)->sortByDesc(fn ($b) => $b->percentAbove)->first();

        expect($worstMean->hour)->toBe(20);
        expect($worstBelow->hour)->toBe(18);
        expect($worstAbove->hour)->toBe(16);
    });

    it('devolve as 24 horas mesmo quando alguma não tem leitura', function () {
        $series = GlucoseSeries::fromPairs([['2026-07-29 08:00:00', 120]]);

        $profile = (new HourlyProfileBuilder($this->config))->build($series);

        expect($profile)->toHaveCount(24);
        expect($profile[8]->count)->toBe(1);
        // Hora vazia é informação, não motivo para omitir o balde.
        expect($profile[3]->isEmpty())->toBeTrue();
        expect($profile[3]->mean)->toBe(0.0);
    });

    it('usa hora LOCAL, não UTC', function () {
        // 23:00 local em São Paulo = 02:00 UTC do dia seguinte. Se o balde
        // usasse UTC, esta leitura cairia na hora 2.
        $series = GlucoseSeries::fromPairs([['2026-07-29 23:30:00', 200]]);

        $profile = (new HourlyProfileBuilder($this->config))->build($series);

        expect($profile[23]->count)->toBe(1);
        expect($profile[2]->count)->toBe(0);
    });
});

describe('T107 — daily_metrics (FR-105, FR-108)', function () {

    it('reproduz as 14 linhas do gabarito', function () {
        app(DailyMetricsWriter::class)->write($this->user->id);

        $rows = DailyMetrics::where('user_id', $this->user->id)
            ->orderBy('local_date')->get();

        expect($rows)->toHaveCount(14);

        // gabarito.md §Por dia — data, n, cap%, média, TIR, %abaixo, CV
        $expected = [
            ['2026-07-16', 288, 100, 156, 67.4, 0.0, 31.5],
            ['2026-07-17', 288, 100, 144, 94.4, 0.0, 16.6],
            ['2026-07-18', 288, 100, 139, 79.5, 0.0, 29.0],
            ['2026-07-19', 288, 100, 136, 94.1, 0.0, 20.4],
            ['2026-07-20', 262, 91, 127, 95.8, 0.8, 22.7],
            ['2026-07-21', 210, 73, 137, 89.5, 4.3, 24.5],
            ['2026-07-22', 97, 34, 136, 84.5, 3.1, 29.6],
            ['2026-07-23', 288, 100, 132, 80.9, 3.5, 31.0],
            ['2026-07-24', 288, 100, 148, 80.6, 0.0, 24.8],
            ['2026-07-25', 281, 98, 159, 68.7, 2.5, 42.2],
            ['2026-07-26', 288, 100, 154, 67.7, 2.8, 35.5],
            ['2026-07-27', 288, 100, 135, 92.4, 1.4, 20.7],
            ['2026-07-28', 236, 82, 137, 97.5, 1.3, 16.1],
            ['2026-07-29', 226, 78, 139, 87.6, 0.0, 23.0],
        ];

        foreach ($expected as $i => [$date, $n, $cap, $mean, $tir, $below, $cv]) {
            $row = $rows[$i];

            expect($row->local_date)->toBe($date);
            expect($row->reading_count)->toBe($n, "n de {$date}");
            expect(round($row->coverage_pct))->toBe((float) $cap, "cobertura de {$date}");
            expect(round($row->mean_glucose))->toBe((float) $mean, "média de {$date}");
            expect(round($row->tir_pct, 1))->toBe($tir, "TIR de {$date}");
            expect(round($row->tbr_level1_pct + $row->tbr_level2_pct, 1))->toBe($below, "abaixo de {$date}");
            expect(round($row->cv_pct, 1))->toBe($cv, "CV de {$date}");
        }
    });

    it('25/07 é o único dia com CV acima da meta', function () {
        app(DailyMetricsWriter::class)->write($this->user->id);

        $aboveTarget = DailyMetrics::where('user_id', $this->user->id)
            ->where('cv_pct', '>', config('clinical.targets.coefficient_of_variation.value'))
            ->pluck('local_date')->all();

        expect($aboveTarget)->toBe(['2026-07-25']);
    });

    it('a soma das cinco faixas fecha 100% em todo dia', function () {
        app(DailyMetricsWriter::class)->write($this->user->id);

        foreach (DailyMetrics::where('user_id', $this->user->id)->get() as $row) {
            expect(abs($row->rangeSum() - 100.0))->toBeLessThan(0.02, "soma de {$row->local_date}");
        }
    });

    it('grava insulina e carboidrato do dia', function () {
        app(DailyMetricsWriter::class)->write($this->user->id);

        // gabarito §Totais diários de insulina automática: 22/07 = 9,0 U —
        // o dia em que o sensor ficou fora do ar e o SmartGuard desligou.
        $day = DailyMetrics::where('local_date', '2026-07-22')->firstOrFail();

        expect(round($day->auto_insulin_u, 1))->toBe(9.0);
        expect($day->total_insulin_u)->toBeGreaterThan($day->auto_insulin_u);
        expect($day->total_carbs_g)->toBeGreaterThan(0.0);
    });

    it('recalcular não duplica nem altera linha (FR-108)', function () {
        $writer = app(DailyMetricsWriter::class);

        $writer->write($this->user->id);
        $first = DailyMetrics::orderBy('local_date')->get()
            ->map(fn ($r) => [$r->local_date, $r->reading_count, round($r->mean_glucose, 4)])->all();

        $writer->write($this->user->id);
        $second = DailyMetrics::orderBy('local_date')->get()
            ->map(fn ($r) => [$r->local_date, $r->reading_count, round($r->mean_glucose, 4)])->all();

        expect(DailyMetrics::count())->toBe(14);
        expect($second)->toBe($first);
    });

    it('grava a versão das fórmulas, para invalidar cache depois', function () {
        app(DailyMetricsWriter::class)->write($this->user->id);

        expect(DailyMetrics::where('metrics_version', DailyMetricsWriter::VERSION)->count())->toBe(14);
    });

    it('a chave única rejeita duplicata de (user_id, local_date)', function () {
        app(DailyMetricsWriter::class)->write($this->user->id);

        expect(fn () => DailyMetrics::create([
            'user_id' => $this->user->id,
            'local_date' => '2026-07-16',
            'reading_count' => 1, 'coverage_pct' => 1, 'mean_glucose' => 100,
            'sd_glucose' => 1, 'cv_pct' => 1, 'tir_pct' => 100,
            'tar_level1_pct' => 0, 'tar_level2_pct' => 0,
            'tbr_level1_pct' => 0, 'tbr_level2_pct' => 0,
            'metrics_version' => 'x',
        ]))->toThrow(Illuminate\Database\QueryException::class);
    });
});

describe('T107.3 — o import despacha o cálculo NA FILA', function () {

    it('enfileira ComputeMetricsJob em vez de calcular na mesma execução', function () {
        Queue::fake();

        $other = User::factory()->create();

        (new ImportCsvJob($other->id, requireReferenceExport(), 'America/Sao_Paulo'))->handle(
            app(App\Domain\Import\CarelinkCsvReader::class),
            app(App\Domain\Import\EventExploder::class),
            app(App\Domain\Import\BolusLinker::class),
            app(App\Domain\Import\Persistence\MealEnricher::class),
            app(App\Domain\Import\SettingsInferrer::class),
        );

        // ADR-5: o import já consome o orçamento do worker (--max-time=55).
        // Encadear o cálculo arriscaria estourar e deixar métricas pela metade.
        Queue::assertPushed(ComputeMetricsJob::class, fn ($job) => $job->userId === $other->id);
    });
});

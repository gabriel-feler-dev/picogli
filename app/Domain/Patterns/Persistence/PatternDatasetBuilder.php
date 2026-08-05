<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Persistence;

use App\Domain\Import\Value\InferredSettings;
use App\Domain\Metrics\CoverageCalculator;
use App\Domain\Metrics\EpisodeDetector;
use App\Domain\Metrics\GapDetector;
use App\Domain\Metrics\HourlyProfileBuilder;
use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Metrics\StatisticsCalculator;
use App\Domain\Metrics\ValidityGate;
use App\Domain\Metrics\Value\Coverage;
use App\Domain\Metrics\Value\EpisodeType;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Metrics\Value\Validity;
use App\Domain\Patterns\CalibrationPairer;
use App\Domain\Patterns\DaypartAggregator;
use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\Value\DailySnapshot;
use App\Domain\Patterns\Value\MealPoint;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Models\BgReading;
use App\Models\DailyAutoInsulin;
use App\Models\DailyMetrics;
use App\Models\DeviceEvent;
use App\Models\DeviceSettingsSnapshot;
use App\Models\Meal;
use App\Models\SensorReading;
use DateTimeImmutable;

/**
 * Monta o `PatternDataset` a partir do banco.
 *
 * ⚠️ **Esta é a ÚNICA classe da fase 4 que toca banco.** É de propósito, e o
 * benefício não é estético: quando a fase 5 perguntar "o que sai do banco em
 * direção à IA?", existe um arquivo que responde por inteiro.
 *
 * ## Nove consultas, sempre (NFR-402)
 *
 * ```
 * 1  última data com leitura        (só em forLatestPeriod)
 * 2  sensor_readings do período
 * 3  daily_metrics
 * 4  meals
 * 5  daily_auto_insulin
 * 6  device_events                 ← todos, agregados em PHP
 * 7  bg_readings de calibração
 * 8  device_settings_snapshots     ← o mais recente
 * 9  conferência de metrics_version
 * ```
 *
 * O número **não cresce** com dias, refeições ou episódios. Duas decisões
 * sustentam isso:
 *
 *   - **`device_events` vem inteiro numa consulta** e é agregado em PHP (266
 *     linhas no export de referência). Três `GROUP BY` separados custariam três
 *     idas ao banco e ainda encostariam em sintaxe de dialeto — o Artigo IX.
 *   - **O pareamento de calibração não consulta nada.** A série já está em
 *     memória; o `CalibrationPairer` casa as duas listas por busca binária.
 *
 * ## O que NÃO é feito aqui
 *
 * Recalcular `daily_metrics` quando a versão está velha. O builder **sinaliza**
 * (§D9). Recalcular escondido misturaria número de duas versões de fórmula, e o
 * texto do achado continuaria plausível — o pior tipo de erro deste projeto.
 */
final class PatternDatasetBuilder
{
    public function __construct(
        private readonly PatternsConfig $patterns,
        private readonly StatisticsCalculator $statistics,
        private readonly CoverageCalculator $coverage,
        private readonly ValidityGate $validityGate,
        private readonly HourlyProfileBuilder $hourlyProfile,
        private readonly GapDetector $gaps,
        private readonly EpisodeDetector $episodes,
        private readonly DaypartAggregator $dayparts,
        private readonly CalibrationPairer $pairer,
    ) {}

    /**
     * Últimos N dias com leitura.
     *
     * ⚠️ Ancorado na **última leitura do usuário**, não em `now()`. Mesma decisão
     * do `DashboardPresenter`: quem importa um export de duas semanas atrás quer
     * ver aquele período. E há uma razão a mais aqui — `now()` tornaria o
     * relatório de padrões **não reproduzível**, e reprodutibilidade é o Artigo II.
     */
    public function forLatestPeriod(int $userId, int $days = 14): PatternDataset
    {
        $last = SensorReading::where('user_id', $userId)->max('local_date');

        if ($last === null) {
            $today = (new DateTimeImmutable)->format('Y-m-d');

            return $this->empty($today, $today);
        }

        $to = substr((string) $last, 0, 10);
        $from = (new DateTimeImmutable($to))->modify('-'.($days - 1).' days')->format('Y-m-d');

        return $this->forPeriod($userId, $from, $to);
    }

    public function forPeriod(int $userId, string $from, string $to): PatternDataset
    {
        $series = $this->seriesFor($userId, $from, $to);

        if ($series->isEmpty()) {
            return $this->empty($from, $to);
        }

        $window = $this->patterns->threshold(RuleId::SensorQuality, 'pairing_minutes');
        $deviceEvents = $this->deviceEventsFor($userId, $from, $to);
        $coverage = $this->coverage->calculate($series);

        return new PatternDataset(
            periodStart: $from,
            periodEnd: $to,
            series: $series,
            metrics: $this->statistics->calculate($series),
            coverage: $coverage,
            validity: $this->validityGate->evaluate($coverage),
            hourly: $this->hourlyProfile->build($series),
            dayparts: $this->dayparts->aggregate($series),
            hypoEpisodes: $this->episodes->detect($series, EpisodeType::Hypoglycemia),
            hyperEpisodes: $this->episodes->detect($series, EpisodeType::HyperglycemiaLevel2),
            gaps: $this->gaps->detect($series),
            daily: $this->dailyFor($userId, $from, $to),
            meals: $this->mealsFor($userId, $from, $to),
            autoInsulinByDate: $this->autoInsulinFor($userId, $from, $to),
            deviceEventCounts: $deviceEvents['by_code'],
            deviceCategoryCounts: $deviceEvents['by_category'],
            rewinds: $deviceEvents['rewinds'],
            settings: $this->settingsFor($userId),
            calibrationPairs: $this->pairer->pair(
                $this->calibrationsFor($userId, $from, $to),
                $series,
                $window,
            ),
            calibrationWindowMinutes: $window,
            hasStaleMetrics: $this->hasStaleMetrics($userId, $from, $to),
            metricsVersion: DailyMetricsWriter::VERSION,
        );
    }

    private function seriesFor(int $userId, string $from, string $to): GlucoseSeries
    {
        $readings = SensorReading::where('user_id', $userId)
            ->whereBetween('local_date', [$from, $to])
            ->orderBy('recorded_at_local')
            ->get(['recorded_at_local', 'glucose_mgdl']);

        return GlucoseSeries::of($readings->map(fn (SensorReading $r) => new GlucoseReading(
            new DateTimeImmutable($r->recorded_at_local->format('Y-m-d H:i:s')),
            $r->glucose_mgdl,
        ))->all());
    }

    /** @return list<DailySnapshot> */
    private function dailyFor(int $userId, string $from, string $to): array
    {
        return DailyMetrics::where('user_id', $userId)
            ->whereBetween('local_date', [$from, $to])
            ->orderBy('local_date')
            ->get()
            ->map(fn (DailyMetrics $d): DailySnapshot => new DailySnapshot(
                localDate: (string) $d->local_date,
                readingCount: $d->reading_count,
                coveragePct: $d->coverage_pct,
                meanGlucose: $d->mean_glucose,
                tirPct: $d->tir_pct,
                cvPct: $d->cv_pct,
                tarLevel2Pct: $d->tar_level2_pct,
                // Somados porque nenhuma regra distingue os dois níveis de
                // hipoglicemia; R2 trabalha sobre EPISÓDIOS, não sobre o dia.
                tbrPct: $d->tbr_level1_pct + $d->tbr_level2_pct,
                autoInsulinU: $d->auto_insulin_u,
                bolusInsulinU: $d->bolus_insulin_u,
                totalCarbsG: $d->total_carbs_g,
            ))
            ->all();
    }

    /** @return list<MealPoint> */
    private function mealsFor(int $userId, string $from, string $to): array
    {
        return Meal::where('user_id', $userId)
            ->whereBetween('local_date', [$from, $to])
            ->orderBy('recorded_at_local')
            ->get(['recorded_at_local', 'carbs_g', 'carb_ratio'])
            ->map(fn (Meal $m): MealPoint => new MealPoint(
                at: new DateTimeImmutable($m->recorded_at_local->format('Y-m-d H:i:s')),
                carbsG: (float) $m->carbs_g,
                carbRatio: $m->carb_ratio === null ? null : (float) $m->carb_ratio,
            ))
            ->all();
    }

    /** @return array<string, float> */
    private function autoInsulinFor(int $userId, string $from, string $to): array
    {
        $totals = [];

        foreach (DailyAutoInsulin::where('user_id', $userId)
            ->whereBetween('local_date', [$from, $to])
            ->orderBy('local_date')
            ->get(['local_date', 'units_delivered']) as $row) {
            $totals[(string) $row->local_date] = (float) $row->units_delivered;
        }

        return $totals;
    }

    /**
     * Uma consulta, três agregações.
     *
     * @return array{by_code: array<string, int>, by_category: array<string, int>, rewinds: list<DateTimeImmutable>}
     */
    private function deviceEventsFor(int $userId, string $from, string $to): array
    {
        $byCode = [];
        $byCategory = [];
        $rewinds = [];

        $events = DeviceEvent::where('user_id', $userId)
            ->whereBetween('local_date', [$from, $to])
            ->orderBy('recorded_at_local')
            ->get(['category', 'code', 'recorded_at_local']);

        foreach ($events as $event) {
            $byCode[$event->code] = ($byCode[$event->code] ?? 0) + 1;
            $byCategory[$event->category] = ($byCategory[$event->category] ?? 0) + 1;

            if ($event->category === 'rewind') {
                $rewinds[] = new DateTimeImmutable($event->recorded_at_local->format('Y-m-d H:i:s'));
            }
        }

        return ['by_code' => $byCode, 'by_category' => $byCategory, 'rewinds' => $rewinds];
    }

    /**
     * Calibrações capilares do período.
     *
     * ⚠️ Só as que **calibraram** (`used_for_calibration`). O export de referência
     * tem 44 capilares, das quais 39 de calibração e 5 digitadas à mão sem
     * calibrar — e o gabarito de R10 (erro de 10,7%) é sobre as 39. Incluir as
     * outras 5 mudaria o número sem mudar nada de visível.
     *
     * @return list<array{at: DateTimeImmutable, mgdl: int}>
     */
    private function calibrationsFor(int $userId, string $from, string $to): array
    {
        return BgReading::where('user_id', $userId)
            ->where('used_for_calibration', true)
            ->whereBetween('local_date', [$from, $to])
            ->orderBy('recorded_at_local')
            ->get(['recorded_at_local', 'glucose_mgdl'])
            ->map(fn (BgReading $b): array => [
                'at' => new DateTimeImmutable($b->recorded_at_local->format('Y-m-d H:i:s')),
                'mgdl' => $b->glucose_mgdl,
            ])
            ->all();
    }

    /**
     * O perfil do aparelho reconstruído mais recente.
     *
     * ⚠️ O mais recente por `valid_from`, não o do período: a configuração da
     * bomba é um estado que persiste, e R6 compara o perfil **vigente** com o
     * resultado glicêmico. Um período sem bolus não significa aparelho sem
     * configuração.
     */
    private function settingsFor(int $userId): InferredSettings
    {
        $snapshot = DeviceSettingsSnapshot::where('user_id', $userId)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            return new InferredSettings([], [], null);
        }

        return new InferredSettings(
            carbRatioProfile: $snapshot->carb_ratio_profile ?? [],
            isfValues: $snapshot->isf_values ?? [],
            basalProfile: $snapshot->basal_profile,
        );
    }

    private function hasStaleMetrics(int $userId, string $from, string $to): bool
    {
        return DailyMetrics::where('user_id', $userId)
            ->whereBetween('local_date', [$from, $to])
            ->where('metrics_version', '!=', DailyMetricsWriter::VERSION)
            ->exists();
    }

    /**
     * Dataset vazio — período sem leitura.
     *
     * ⚠️ Mesmo vazio, `coverage` e `validity` vêm preenchidos: não existe caminho
     * que entregue dataset sem denominador (Artigo V). E `dayparts` traz os quatro
     * períodos com `n = 0`, não um array vazio — regra que iterasse sobre eles
     * encontraria a estrutura esperada, com contagem zero.
     */
    private function empty(string $from, string $to): PatternDataset
    {
        $series = GlucoseSeries::of([]);

        return new PatternDataset(
            periodStart: $from,
            periodEnd: $to,
            series: $series,
            metrics: $this->statistics->calculate($series),
            coverage: Coverage::empty(),
            validity: Validity::InsufficientDays,
            hourly: $this->hourlyProfile->build($series),
            dayparts: $this->dayparts->aggregate($series),
            hypoEpisodes: [],
            hyperEpisodes: [],
            gaps: [],
            daily: [],
            meals: [],
            autoInsulinByDate: [],
            deviceEventCounts: [],
            deviceCategoryCounts: [],
            rewinds: [],
            settings: new InferredSettings([], [], null),
            calibrationPairs: [],
            calibrationWindowMinutes: $this->patterns->threshold(RuleId::SensorQuality, 'pairing_minutes'),
            hasStaleMetrics: false,
            metricsVersion: DailyMetricsWriter::VERSION,
        );
    }
}

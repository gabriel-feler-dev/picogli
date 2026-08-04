<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Persistence;

use App\Domain\Metrics\MetricsConfig;
use App\Domain\Metrics\StatisticsCalculator;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Models\DailyAutoInsulin;
use App\Models\InsulinDose;
use App\Models\Meal;
use App\Models\SensorReading;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Calcula e materializa `daily_metrics` (FR-105, FR-108).
 *
 * Única classe da fase 2 que toca banco — o resto de `app/Domain/Metrics/` é
 * PHP puro.
 */
final class DailyMetricsWriter
{
    /**
     * Versão das fórmulas.
     *
     * ⚠️ Mudou fórmula? Incremente. É o que invalida o cache sem precisar de
     * migration nem de apagar a tabela na mão — e é o que impede uma métrica
     * calculada por uma versão antiga de conviver com outra nova sem ninguém
     * perceber.
     */
    public const VERSION = '2026.08.1';

    /** Um dia tem 288 leituras esperadas, sempre. */
    private const READINGS_PER_FULL_DAY = 288;

    public function __construct(
        private readonly MetricsConfig $config,
        private readonly StatisticsCalculator $calculator,
    ) {}

    /**
     * Recalcula os dias do usuário. Devolve quantos foram gravados.
     *
     * @param  list<string>|null  $dates  null = todos os dias com leitura
     */
    public function write(int $userId, ?array $dates = null): int
    {
        $dates ??= SensorReading::where('user_id', $userId)
            ->distinct()
            ->orderBy('local_date')
            ->pluck('local_date')
            ->map(fn ($d): string => substr((string) $d, 0, 10))
            ->all();

        $rows = [];

        foreach ($dates as $date) {
            $row = $this->rowFor($userId, $date);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return 0;
        }

        DB::table('daily_metrics')->upsert(
            $rows,
            ['user_id', 'local_date'],
            [
                'reading_count', 'coverage_pct', 'mean_glucose', 'sd_glucose', 'cv_pct',
                'tir_pct', 'tar_level1_pct', 'tar_level2_pct', 'tbr_level1_pct', 'tbr_level2_pct',
                'total_insulin_u', 'auto_insulin_u', 'bolus_insulin_u', 'total_carbs_g',
                'metrics_version', 'updated_at',
            ],
        );

        return count($rows);
    }

    /** @return array<string, mixed>|null */
    private function rowFor(int $userId, string $date): ?array
    {
        $readings = SensorReading::where('user_id', $userId)
            ->where('local_date', $date)
            ->orderBy('recorded_at_local')
            ->get(['recorded_at_local', 'glucose_mgdl']);

        if ($readings->isEmpty()) {
            return null;
        }

        $series = GlucoseSeries::of($readings->map(fn (SensorReading $r) => new GlucoseReading(
            new DateTimeImmutable($r->recorded_at_local->format('Y-m-d H:i:s')),
            $r->glucose_mgdl,
        ))->all());

        $metrics = $this->calculator->calculate($series);
        $stats = $metrics->statistics;
        $dist = $metrics->distribution;

        // ⚠️ `units_delivered` é a ÚNICA coluna somável (Artigo VIII.3).
        $bolus = (float) InsulinDose::where('user_id', $userId)
            ->where('local_date', $date)
            ->whereNotNull('units_delivered')
            ->sum('units_delivered');

        $auto = (float) DailyAutoInsulin::where('user_id', $userId)
            ->where('local_date', $date)
            ->sum('units_delivered');

        $carbs = (float) Meal::where('user_id', $userId)
            ->where('local_date', $date)
            ->sum('carbs_g');

        return [
            'user_id' => $userId,
            'local_date' => $date,
            'reading_count' => $series->count(),
            'coverage_pct' => ($series->count() / self::READINGS_PER_FULL_DAY) * 100,
            'mean_glucose' => $stats->mean,
            'sd_glucose' => $stats->standardDeviation,
            'cv_pct' => $stats->coefficientOfVariation,
            'tir_pct' => $dist->percentages['target'],
            'tar_level1_pct' => $dist->percentages['high'],
            'tar_level2_pct' => $dist->percentages['very_high'],
            'tbr_level1_pct' => $dist->percentages['low'],
            'tbr_level2_pct' => $dist->percentages['very_low'],
            'total_insulin_u' => $bolus + $auto,
            'auto_insulin_u' => $auto,
            'bolus_insulin_u' => $bolus,
            'total_carbs_g' => $carbs,
            'metrics_version' => self::VERSION,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}

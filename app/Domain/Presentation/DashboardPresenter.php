<?php

declare(strict_types=1);

namespace App\Domain\Presentation;

use App\Domain\Metrics\CoverageCalculator;
use App\Domain\Metrics\GapDetector;
use App\Domain\Metrics\HourlyPercentileBuilder;
use App\Domain\Metrics\HourlyProfileBuilder;
use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Metrics\StatisticsCalculator;
use App\Domain\Metrics\ValidityGate;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Metrics\Value\HourlyBucket;
use App\Domain\Metrics\Value\HourlyPercentiles;
use App\Domain\Metrics\Value\SensorGap;
use App\Domain\Presentation\Value\PeriodSummary;
use App\Models\DailyMetrics;
use App\Models\SensorReading;
use DateTimeImmutable;

/**
 * Monta o pacote que o dashboard consome (FR-204).
 *
 * ⚠️ Esta classe toca banco — como `Persistence/`, é camada de BORDA, não
 * domínio puro. O que é puro é o `MetricTranslator` e tudo em
 * `app/Domain/Metrics/` (fora de `Persistence/`).
 */
final class DashboardPresenter
{
    public function __construct(
        private readonly StatisticsCalculator $statistics,
        private readonly CoverageCalculator $coverage,
        private readonly ValidityGate $validityGate,
        private readonly HourlyProfileBuilder $hourlyProfile,
        private readonly HourlyPercentileBuilder $percentiles,
        private readonly GapDetector $gaps,
        private readonly MetricTranslator $translator,
    ) {}

    /**
     * Últimos N dias com leitura — o recorte padrão do dashboard.
     *
     * Ancorado na ÚLTIMA leitura do usuário, não em `now()`. Quem importa um
     * export de duas semanas atrás quer ver aquele período, não uma tela vazia
     * dizendo que não há dados nos últimos 14 dias.
     */
    public function forLatestPeriod(int $userId, int $days = 14): PeriodSummary
    {
        $last = SensorReading::where('user_id', $userId)->max('local_date');

        if ($last === null) {
            return $this->emptySummary();
        }

        $to = substr((string) $last, 0, 10);
        $from = (new DateTimeImmutable($to))->modify('-'.($days - 1).' days')->format('Y-m-d');

        return $this->forPeriod($userId, $from, $to);
    }

    public function forPeriod(int $userId, string $from, string $to): PeriodSummary
    {
        $series = $this->seriesFor($userId, $from, $to);

        if ($series->isEmpty()) {
            return $this->emptySummary($from, $to);
        }

        $metrics = $this->statistics->calculate($series);
        $coverage = $this->coverage->calculate($series);
        $validity = $this->validityGate->evaluate($coverage);

        return new PeriodSummary(
            from: $from,
            to: $to,
            coverage: $coverage,
            validity: $validity,
            metrics: $this->translator->translate($metrics->statistics, $metrics->distribution, $validity),
            hourlyProfile: array_map(
                fn (HourlyBucket $b): array => [
                    'hour' => $b->hour,
                    'count' => $b->count,
                    'mean' => $b->isEmpty() ? null : round($b->mean, 1),
                    'percent_above' => $b->isEmpty() ? null : round($b->percentAbove, 1),
                    'percent_below' => $b->isEmpty() ? null : round($b->percentBelow, 1),
                ],
                $this->hourlyProfile->build($series),
            ),
            hourlyPercentiles: array_map(
                fn (HourlyPercentiles $p): array => [
                    'hour' => $p->hour,
                    'count' => $p->count,
                    // null, não zero: zero pareceria glicose de 0 mg/dL.
                    'p5' => $p->p5 === null ? null : round($p->p5, 1),
                    'p25' => $p->p25 === null ? null : round($p->p25, 1),
                    'p50' => $p->p50 === null ? null : round($p->p50, 1),
                    'p75' => $p->p75 === null ? null : round($p->p75, 1),
                    'p95' => $p->p95 === null ? null : round($p->p95, 1),
                ],
                $this->percentiles->build($series),
            ),
            dailyMetrics: $this->dailyMetricsFor($userId, $from, $to),
            gaps: array_map(
                fn (SensorGap $g): array => [
                    'start' => $g->start->format('Y-m-d H:i:s'),
                    'end' => $g->end->format('Y-m-d H:i:s'),
                    'minutes' => round($g->minutes),
                ],
                $this->gaps->detect($series),
            ),
            hasStaleMetrics: $this->hasStaleMetrics($userId, $from, $to),
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

    /** @return list<array<string, mixed>> */
    private function dailyMetricsFor(int $userId, string $from, string $to): array
    {
        return DailyMetrics::where('user_id', $userId)
            ->whereBetween('local_date', [$from, $to])
            ->orderBy('local_date')
            ->get()
            ->map(fn (DailyMetrics $d): array => [
                'local_date' => $d->local_date,
                'reading_count' => $d->reading_count,
                // Artigo V também no nível do dia: 34% de captura não é
                // comparável com 100%, e a grade precisa distinguir.
                'coverage_pct' => round($d->coverage_pct, 1),
                'mean_glucose' => round($d->mean_glucose, 1),
                'tir_pct' => round($d->tir_pct, 1),
                'cv_pct' => round($d->cv_pct, 1),
                'below_pct' => round($d->tbr_level1_pct + $d->tbr_level2_pct, 1),
            ])
            ->all();
    }

    /**
     * Há métrica diária calculada por uma versão antiga das fórmulas?
     *
     * ⚠️ Sinaliza em vez de recalcular em silêncio (T204.3). Recalcular
     * escondido faria a tela demorar sem explicação, e — pior — misturaria
     * número de duas versões de fórmula sem ninguém perceber qual é qual.
     */
    private function hasStaleMetrics(int $userId, string $from, string $to): bool
    {
        return DailyMetrics::where('user_id', $userId)
            ->whereBetween('local_date', [$from, $to])
            ->where('metrics_version', '!=', DailyMetricsWriter::VERSION)
            ->exists();
    }

    private function emptySummary(?string $from = null, ?string $to = null): PeriodSummary
    {
        $today = now()->toDateString();

        return new PeriodSummary(
            from: $from ?? $today,
            to: $to ?? $today,
            // Mesmo vazio, cobertura e validade vêm preenchidas — não há
            // caminho que devolva métrica sem denominador.
            coverage: \App\Domain\Metrics\Value\Coverage::empty(),
            validity: \App\Domain\Metrics\Value\Validity::InsufficientDays,
            metrics: [],
            hourlyProfile: [],
            hourlyPercentiles: [],
            dailyMetrics: [],
            gaps: [],
            hasStaleMetrics: false,
        );
    }
}

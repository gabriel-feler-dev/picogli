<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Value;

use App\Domain\Import\Value\InferredSettings;
use App\Domain\Metrics\Value\Coverage;
use App\Domain\Metrics\Value\Episode;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Metrics\Value\HourlyBucket;
use App\Domain\Metrics\Value\SensorGap;
use App\Domain\Metrics\Value\SeriesMetrics;
use App\Domain\Metrics\Value\Validity;
use DateTimeImmutable;

/**
 * Tudo que as dez regras podem ver — e nada além (§D2).
 *
 * ## Esta classe é uma resposta, não um saco de dados
 *
 * A pergunta é **"o que o motor de padrões pode ver?"**, e ela vai ser feita de
 * novo na fase 5, quando o Artigo VII entrar em cena. Ter uma classe que a
 * responde por inteiro vale a indireção.
 *
 * Corolário incômodo mas necessário: **dado que nenhuma regra usa não entra
 * aqui.** O `plan.md` previa um campo `doses`; ao mapear o que cada regra precisa
 * ficou claro que nenhuma das dez o usa — R5 tira o bolus diário de
 * `daily_metrics`. Carregá-lo daria a resposta errada à pergunta acima, além de
 * custar uma consulta e memória por nada.
 *
 * ## Reuso dos value objects da fase 2
 *
 * `GlucoseSeries`, `Episode`, `SensorGap`, `Coverage`, `HourlyBucket` e
 * `InferredSettings` são os mesmos das fases 1 e 2 — não equivalentes "para
 * padrões". Duas definições de episódio divergem, sempre; é a mesma lição do
 * percentil da fase 3, e o `EpisodeDetector` já está conferido contra o gabarito.
 *
 * ## Construível sem banco
 *
 * Todo campo é value object puro, escalar ou array deles. Um teste monta o
 * dataset inteiro à mão, e é isso que faz as dez regras testáveis sem fixture.
 */
final readonly class PatternDataset
{
    /**
     * @param  array<int, HourlyBucket>  $hourly  hora (0–23) → balde
     * @param  array<string, DaypartStats>  $dayparts  `Daypart::value` → estatísticas
     * @param  list<Episode>  $hypoEpisodes
     * @param  list<Episode>  $hyperEpisodes
     * @param  list<SensorGap>  $gaps
     * @param  list<DailySnapshot>  $daily  ordenados por data
     * @param  list<MealPoint>  $meals  ordenadas cronologicamente
     * @param  array<string, float>  $autoInsulinByDate  'Y-m-d' → U
     * @param  array<string, int>  $deviceEventCounts  código do alerta → contagem
     * @param  array<string, int>  $deviceCategoryCounts  categoria → contagem
     * @param  list<DateTimeImmutable>  $rewinds  instantes de troca de reservatório
     * @param  list<CalibrationPair>  $calibrationPairs
     */
    public function __construct(
        public string $periodStart,
        public string $periodEnd,
        public GlucoseSeries $series,
        public SeriesMetrics $metrics,
        // Artigo V atravessa a fase: não existe dataset sem denominador.
        public Coverage $coverage,
        public Validity $validity,
        public array $hourly,
        public array $dayparts,
        public array $hypoEpisodes,
        public array $hyperEpisodes,
        public array $gaps,
        public array $daily,
        public array $meals,
        public array $autoInsulinByDate,
        public array $deviceEventCounts,
        public array $deviceCategoryCounts,
        public array $rewinds,
        public InferredSettings $settings,
        public array $calibrationPairs,
        /** Janela usada no pareamento de R10 — viaja porque é evidência. */
        public int|float $calibrationWindowMinutes,
        /**
         * ⚠️ Métrica diária calculada por versão antiga das fórmulas (§D9).
         * Sinalizado, nunca recalculado em silêncio: achado velho ao lado de
         * métrica nova continua soando plausível.
         */
        public bool $hasStaleMetrics,
        public string $metricsVersion,
    ) {}

    public function isEmpty(): bool
    {
        return $this->series->isEmpty();
    }

    public function daypart(Daypart $daypart): DaypartStats
    {
        return $this->dayparts[$daypart->value];
    }

    /** Dias do período, indexados por 'Y-m-d'. */
    public function dailyByDate(): array
    {
        $indexed = [];

        foreach ($this->daily as $day) {
            $indexed[$day->localDate] = $day;
        }

        return $indexed;
    }

    /** Contagem de um alerta do aparelho por código exato, 0 quando ausente. */
    public function deviceEventCount(string $code): int
    {
        return $this->deviceEventCounts[$code] ?? 0;
    }

    public function deviceCategoryCount(string $category): int
    {
        return $this->deviceCategoryCounts[$category] ?? 0;
    }

    /**
     * Média diária de insulina automática no período.
     *
     * Denominador são os dias COM registro, não os dias do calendário: dia sem
     * linha no bloco 2 é dia sem dado, e tratá-lo como 0 U rebaixaria a média e
     * faria a queda do 22/07 parecer menor do que foi.
     */
    public function meanAutoInsulin(): ?float
    {
        if ($this->autoInsulinByDate === []) {
            return null;
        }

        return array_sum($this->autoInsulinByDate) / count($this->autoInsulinByDate);
    }
}

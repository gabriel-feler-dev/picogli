<?php

declare(strict_types=1);

namespace App\Domain\Import\Pdf\Value;

/**
 * As métricas que um PDF pode trazer (Spec 007, FR-705, §D5).
 *
 * ⚠️ **Lista FECHADA, e é o ponto.** Sem ela, um parser mal escrito criaria
 * nomes de métrica que nenhuma outra parte do produto conhece — e um
 * `pdf_aggregates` com `metric = 'tempo_na_faixa'` ao lado de outro com
 * `'time_in_range_percent'` seria dado inútil que ninguém percebe.
 *
 * ⚠️ **O vocabulário é o MESMO do resto do produto** — as mesmas chaves que o
 * `get_period_metrics` emite e que o `ComparisonPresenter` compara. Não é
 * economia: é o que permite exibir um agregado de PDF ao lado da métrica
 * equivalente de CSV **e dizer qual é qual** (§D7).
 */
enum PdfMetric: string
{
    case MeanGlucose = 'mean_glucose';
    case TimeInRange = 'time_in_range_percent';
    case TimeAbove180 = 'time_above_180_percent';
    case TimeAbove250 = 'time_above_250_percent';
    case TimeBelow70 = 'time_below_70_percent';
    case TimeBelow54 = 'time_below_54_percent';
    case CoefficientOfVariation = 'cv_percent';
    case Gmi = 'gmi';
    case SensorCoverage = 'coverage_percent';
    case TotalInsulin = 'total_insulin_u';

    public function unit(): string
    {
        return match ($this) {
            self::MeanGlucose => 'mg/dL',
            self::TotalInsulin => 'U',
            default => '%',
        };
    }

    /**
     * O valor é plausível para esta métrica?
     *
     * ⚠️ **Não é validação de formulário — é guarda contra extração torta.** Um
     * parser que pegasse o número errado da página devolveria "tempo na faixa
     * 1.483%", e um agregado assim gravado é pior que nenhum: ele apareceria na
     * tela com a mesma aparência dos outros.
     */
    public function accepts(float $value): bool
    {
        return match ($this) {
            self::MeanGlucose => $value >= 20.0 && $value <= 600.0,
            self::Gmi => $value >= 3.0 && $value <= 20.0,
            self::TotalInsulin => $value >= 0.0 && $value <= 10000.0,
            // Os percentuais.
            default => $value >= 0.0 && $value <= 100.0,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Presentation;

use App\Domain\Metrics\Value\GlucoseStatistics;
use App\Domain\Metrics\Value\RangeDistribution;
use App\Domain\Metrics\Value\Validity;
use App\Domain\Presentation\Value\MetricStatus;
use App\Domain\Presentation\Value\TranslatedMetric;

/**
 * Traduz métricas clínicas para linguagem de quem não é médico (FR-203).
 *
 * ## O que esta classe existe para resolver
 *
 * "TIR 83,9%" não diz nada a quem não é endocrinologista. "Sua glicose ficou na
 * faixa boa 20 horas por dia" diz — e é o mesmo dado.
 *
 * A conversão vive aqui, em PHP, por três razões (spec.md §D1):
 *
 * 1. A fase 5 vai narrar os mesmos números. Se a conversão morasse no React,
 *    a narrativa recalcularia e as duas divergiriam por arredondamento.
 * 2. O Artigo IV só é verificável se o texto estiver em arquivo varrível por
 *    teste. Texto interpolado em JSX não é.
 * 3. Artigo III: cada card carrega o valor técnico ao lado do traduzido.
 *
 * ## Artigo IV — o tom
 *
 * Todo texto vem de `lang/`, e um teste varre o diretório proibindo vocabulário
 * que julga a pessoa. O texto descreve mecanismo e consequência, nunca caráter.
 */
final class MetricTranslator
{
    private const HOURS_PER_DAY = 24;

    private const MINUTES_PER_DAY = 1440;

    /** @param array<string, mixed> $targets conteúdo de config('clinical.targets') */
    public function __construct(private readonly array $targets) {}

    /** @return list<TranslatedMetric> */
    public function translate(
        GlucoseStatistics $statistics,
        RangeDistribution $distribution,
        Validity $validity,
    ): array {
        if ($statistics->isEmpty()) {
            return [];
        }

        return [
            $this->timeInRange($distribution),
            $this->variability($statistics, $validity),
            $this->estimatedA1c($statistics, $validity),
            $this->timeBelowRange($distribution),
        ];
    }

    private function timeInRange(RangeDistribution $distribution): TranslatedMetric
    {
        $percent = $distribution->timeInRange();
        $target = $this->targets['time_in_range'];

        return new TranslatedMetric(
            key: 'time_in_range',
            label: __('metrics.time_in_range.label'),
            plainValue: __('metrics.time_in_range.plain', [
                'hours' => $this->formatHours($percent),
            ]),
            technicalValue: __('metrics.time_in_range.technical', [
                'percent' => $this->formatPercent($percent),
            ]),
            targetLabel: __('metrics.time_in_range.target', [
                'hours' => $this->formatHours($target['value']),
                'percent' => $this->formatPercent($target['value']),
            ]),
            status: $this->compare($percent, $target),
            explanation: __('metrics.time_in_range.explanation'),
        );
    }

    private function variability(GlucoseStatistics $statistics, Validity $validity): TranslatedMetric
    {
        $cv = $statistics->coefficientOfVariation;
        $target = $this->targets['coefficient_of_variation'];
        $status = $this->compare($cv, $target);

        return new TranslatedMetric(
            key: 'coefficient_of_variation',
            label: __('metrics.variability.label'),
            // "Estrada plana" vs "montanha-russa": descreve o padrão, não a
            // pessoa. Um "seus dias estão descontrolados" diria a mesma coisa
            // e violaria o Artigo IV.
            plainValue: $status === MetricStatus::Met
                ? __('metrics.variability.plain_stable')
                : __('metrics.variability.plain_unstable'),
            technicalValue: __('metrics.variability.technical', [
                'percent' => $this->formatPercent($cv),
            ]),
            targetLabel: __('metrics.variability.target', [
                'percent' => $this->formatPercent($target['value']),
            ]),
            status: $validity->isValid() ? $status : MetricStatus::Unreliable,
            explanation: __('metrics.variability.explanation'),
        );
    }

    private function estimatedA1c(GlucoseStatistics $statistics, Validity $validity): TranslatedMetric
    {
        return new TranslatedMetric(
            key: 'gmi',
            label: __('metrics.gmi.label'),
            plainValue: __('metrics.gmi.plain', [
                'percent' => number_format($statistics->gmi, 1, ',', '.'),
            ]),
            technicalValue: __('metrics.gmi.technical', [
                'percent' => number_format($statistics->gmi, 2, ',', '.'),
            ]),
            // GMI não tem meta: o alvo de HbA1c é individualizado pelo médico
            // (Artigo VI). Sugerir um número aqui seria prescrever.
            targetLabel: null,
            status: $validity->isValid() ? MetricStatus::Met : MetricStatus::Unreliable,
            explanation: __('metrics.gmi.explanation'),
        );
    }

    private function timeBelowRange(RangeDistribution $distribution): TranslatedMetric
    {
        $percent = $distribution->timeBelowRange();
        $target = $this->targets['time_below_70'];
        $severe = $distribution->percentages['very_low'] ?? 0.0;

        return new TranslatedMetric(
            key: 'time_below_range',
            label: __('metrics.time_below_range.label'),
            plainValue: __('metrics.time_below_range.plain', [
                'minutes' => $this->formatMinutes($percent),
            ]),
            technicalValue: __('metrics.time_below_range.technical', [
                'percent' => $this->formatPercent($percent),
            ]),
            targetLabel: __('metrics.time_below_range.target', [
                'percent' => $this->formatPercent($target['value']),
            ]),
            status: $this->compare($percent, $target),
            explanation: $severe > 0.0
                ? __('metrics.time_below_range.explanation_severe')
                : __('metrics.time_below_range.explanation_none_severe'),
        );
    }

    /**
     * Compara com a meta usando o valor EXATO, nunca o arredondado.
     *
     * ⚠️ Este é o detalhe que o T203.4 protege. TIR de 70,4% e a meta de 70%
     * arredondam para a mesma coisa em horas ("17 h"), mas 70,4 > 70 e a meta
     * ESTÁ atingida. Comparar o valor exibido faria o card dizer que a meta não
     * foi batida quando ela foi.
     *
     * @param  array{value: float, direction: string}  $target
     */
    private function compare(float $actual, array $target): MetricStatus
    {
        $met = $target['direction'] === 'above'
            ? $actual >= $target['value']
            : $actual <= $target['value'];

        return $met ? MetricStatus::Met : MetricStatus::NotMet;
    }

    /** Percentual do dia → horas, arredondado só para exibir. */
    private function formatHours(float $percent): string
    {
        $hours = ($percent / 100) * self::HOURS_PER_DAY;

        return __('metrics.unit.hours', ['value' => (string) round($hours)]);
    }

    private function formatMinutes(float $percent): string
    {
        $minutes = ($percent / 100) * self::MINUTES_PER_DAY;

        return __('metrics.unit.minutes', ['value' => (string) round($minutes)]);
    }

    private function formatPercent(float $value): string
    {
        return number_format($value, 1, ',', '.').'%';
    }
}

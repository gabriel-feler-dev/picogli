<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;
use App\Domain\Metrics\CoverageCalculator;
use App\Domain\Metrics\StatisticsCalculator;
use App\Domain\Metrics\ValidityGate;
use App\Domain\Metrics\Value\Validity;

/**
 * `get_period_metrics` — as métricas globais de um período (FR-602).
 *
 * ⚠️ **Usa os MESMOS calculadores do dashboard.** Não é economia de esforço: é o
 * que garante que chat e gráfico nunca divergem (§D1). Duas fontes de verdade
 * para o mesmo número é o defeito mais corrosivo de confiança que este produto
 * poderia ter — e há teste comparando esta saída com o payload de `/dashboard`.
 *
 * ## ⚠️ O Artigo V mora dentro do resultado (§D8)
 *
 * Quando o portão de validade reprova o período, `gmi` e `cv` saem **`null`**,
 * com o campo `*_unavailable` ao lado explicando.
 *
 * *Por quê no resultado e não no prompt:* "avise o usuário quando o período for
 * curto" é uma instrução que o modelo cumpre quase sempre. Um campo vazio **não
 * tem como ser citado** — não há número ali. E o campo ao lado dá a ele o texto
 * para repassar.
 *
 * ⚠️ **A média continua saindo.** Ela é interpretável em qualquer período; o que
 * exige 14 dias e 70% de captura são GMI e CV (consenso ATTD/ADA). Zerar tudo
 * seria tão errado quanto exibir tudo.
 */
final class PeriodMetricsTool extends PeriodTool
{
    private const UNAVAILABLE = 'requer pelo menos 14 dias e 70% de captura do sensor';

    public function __construct(
        private readonly StatisticsCalculator $statistics,
        private readonly CoverageCalculator $coverage,
        private readonly ValidityGate $validityGate,
    ) {}

    public function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            name: 'get_period_metrics',
            description: 'Métricas glicêmicas globais de um período: média, desvio padrão, '
                .'coeficiente de variação, GMI, tempo na faixa (TIR), tempo acima (TAR) e '
                .'tempo abaixo (TBR), com a cobertura do sensor e o veredito de validade. '
                .'Use para responder "como foi o período", "qual minha média", "meu tempo na faixa".',
            argumentSchema: self::PERIOD_SCHEMA,
            emittedKeys: array_merge(self::PERIOD_KEYS, [
                'reading_count', 'days_span', 'coverage_percent', 'validity',
                'mean_glucose', 'standard_deviation',
                'cv_percent', 'cv_unavailable', 'gmi', 'gmi_unavailable',
                'time_in_range_percent', 'time_above_180_percent', 'time_above_250_percent',
                'time_below_70_percent', 'time_below_54_percent',
            ]),
        );
    }

    public function run(array $args, ChatScope $scope): ToolResult
    {
        [$from, $to] = $this->window($args);

        return ToolResult::ok(
            'get_period_metrics',
            $args,
            $this->envelope($from, $to, $this->metrics($scope, $from, $to)),
        );
    }

    /**
     * ⚠️ Reusado pelo `compare_periods` — os dois lados da comparação precisam
     * das MESMAS métricas, e duplicar seria criar a segunda fonte de verdade que
     * o §D1 existe para impedir.
     *
     * @return array<string, mixed>
     */
    public function metrics(ChatScope $scope, string $from, string $to): array
    {
        $series = $this->series($scope, $from, $to);
        $coverage = $this->coverage->calculate($series);
        $validity = $this->validityGate->evaluate($coverage);
        $metrics = $this->statistics->calculate($series);

        $stats = $metrics->statistics;
        $percentages = $metrics->distribution->percentages;
        $confiavel = $validity->isValid();

        return [
            // ⚠️ Artigo V: o denominador NUNCA some, mesmo quando o número sai.
            'reading_count' => $coverage->readingCount,
            'days_span' => $this->round($coverage->spanInDays, 2),
            'coverage_percent' => $this->round($coverage->percentage),
            'validity' => $validity->value,

            'mean_glucose' => $this->round($stats->mean),
            'standard_deviation' => $this->round($stats->standardDeviation),

            // ⚠️ §D8 — sem procedência clínica, não há número para citar.
            'cv_percent' => $confiavel ? $this->round($stats->coefficientOfVariation) : null,
            'cv_unavailable' => $confiavel ? null : $this->reason($validity),
            'gmi' => $confiavel ? $this->round($stats->gmi, 2) : null,
            'gmi_unavailable' => $confiavel ? null : $this->reason($validity),

            'time_in_range_percent' => $this->round($metrics->distribution->timeInRange()),
            'time_above_180_percent' => $this->round($metrics->distribution->timeAboveRange()),
            'time_above_250_percent' => $this->round($percentages['very_high'] ?? 0.0),
            'time_below_70_percent' => $this->round($metrics->distribution->timeBelowRange()),
            'time_below_54_percent' => $this->round($percentages['very_low'] ?? 0.0),
        ];
    }

    private function reason(Validity $validity): string
    {
        return match ($validity) {
            Validity::InsufficientDays => 'período curto demais — '.self::UNAVAILABLE,
            Validity::InsufficientCoverage => 'captura do sensor baixa demais — '.self::UNAVAILABLE,
            Validity::Valid => '',
        };
    }
}

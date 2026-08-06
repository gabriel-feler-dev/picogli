<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\ChatTool;
use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;

/**
 * `compare_periods` — dois períodos e a diferença entre eles (FR-602).
 *
 * ⚠️ **O delta é calculado em PHP.** Entregar os dois lados e deixar o modelo
 * subtrair seria pedir que ele calculasse — Artigo I, e o erro sairia com
 * aparência de número medido. Aqui `mean_glucose_delta` é um dado como qualquer
 * outro, e o `NumberGuard` o reconhece como procedência.
 *
 * ⚠️ **Cada lado carrega a PRÓPRIA validade** (§D8, T505.3). Comparar 14 dias
 * com 3 dias e apresentar o delta como fato é exatamente o que o Artigo V existe
 * para impedir — e é uma comparação que o usuário pede sem perceber, porque a
 * pergunta "melhorei em relação à semana passada?" não menciona cobertura.
 *
 * ⚠️ **Reusa `PeriodMetricsTool::metrics()`**, não reimplementa. Se as duas
 * calculassem separado, `get_period_metrics` e `compare_periods` poderiam
 * discordar sobre a mesma semana — o defeito do §D1 acontecendo dentro da
 * própria camada de ferramentas.
 */
final class ComparePeriodsTool implements ChatTool
{
    /** As métricas em que a diferença faz sentido. */
    private const COMPARABLE = [
        'mean_glucose',
        'time_in_range_percent',
        'time_above_180_percent',
        'time_below_70_percent',
        'cv_percent',
        'coverage_percent',
    ];

    public function __construct(private readonly PeriodMetricsTool $metrics) {}

    public function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            name: 'compare_periods',
            description: 'Compara dois períodos com as mesmas métricas e devolve a diferença '
                .'entre eles. Cada lado traz a própria cobertura e o próprio veredito de '
                .'validade. Use para "esta semana contra a anterior", "melhorei em relação ao '
                .'mês passado". Diferença de período com pouca captura não é conclusiva.',
            argumentSchema: [
                'a_start' => ['type' => 'date', 'required' => true],
                'a_end' => ['type' => 'date', 'required' => true],
                'b_start' => ['type' => 'date', 'required' => true],
                'b_end' => ['type' => 'date', 'required' => true],
            ],
            emittedKeys: array_merge(
                [
                    'period_a', 'period_b', 'delta', 'comparable',
                    'period_start', 'period_end',
                    'reading_count', 'days_span', 'coverage_percent', 'validity',
                    'mean_glucose', 'standard_deviation',
                    'cv_percent', 'cv_unavailable', 'gmi', 'gmi_unavailable',
                    'time_in_range_percent', 'time_above_180_percent', 'time_above_250_percent',
                    'time_below_70_percent', 'time_below_54_percent',
                ],
                // `mean_glucose_delta`, `cv_percent_delta`, ...
                array_map(fn (string $m): string => $m.'_delta', self::COMPARABLE),
            ),
        );
    }

    public function run(array $args, ChatScope $scope): ToolResult
    {
        $a = $this->side($scope, (string) $args['a_start'], (string) $args['a_end']);
        $b = $this->side($scope, (string) $args['b_start'], (string) $args['b_end']);

        return ToolResult::ok('compare_periods', $args, [
            'period_a' => $a,
            'period_b' => $b,
            'delta' => $this->delta($a, $b),
        ]);
    }

    /** @return array<string, mixed> */
    private function side(ChatScope $scope, string $from, string $to): array
    {
        return array_merge(
            ['period_start' => $from, 'period_end' => $to],
            $this->metrics->metrics($scope, $from, $to),
        );
    }

    /**
     * `b - a`, por métrica — positivo significa que **b** é maior.
     *
     * ⚠️ Só onde os dois lados têm número. Quando o portão de validade zerou o
     * CV de um dos períodos, `cv_percent_delta` sai `null`: uma diferença
     * calculada contra ausência de dado seria número inventado, e é justamente o
     * tipo que passa despercebido por ser plausível.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, float|null>
     */
    private function delta(array $a, array $b): array
    {
        $delta = [];

        foreach (self::COMPARABLE as $metrica) {
            $ladoA = $a[$metrica] ?? null;
            $ladoB = $b[$metrica] ?? null;

            $delta[$metrica.'_delta'] = is_numeric($ladoA) && is_numeric($ladoB)
                ? round((float) $ladoB - (float) $ladoA, 2)
                : null;
        }

        return $delta;
    }
}

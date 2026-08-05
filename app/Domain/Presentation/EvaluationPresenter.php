<?php

declare(strict_types=1);

namespace App\Domain\Presentation;

use App\Domain\Presentation\Value\PresentedFinding;
use App\Models\PeriodReport;

/**
 * Monta o pacote da tela de avaliação (FR-414, FR-415).
 *
 * ⚠️ **Lê o relatório gravado; não roda o motor.** Duas razões:
 *
 * 1. O relatório carrega o denominador **do momento em que foi gerado** (Artigo
 *    V). Recalcular na tela mostraria a cobertura de hoje num relatório de
 *    julho, e o número pareceria certo.
 * 2. Rodar dez regras dentro de um request de tela é trabalho que pertence à
 *    fila (ADR-5).
 *
 * ⚠️ Camada de borda: toca banco, como `DashboardPresenter`.
 */
final class EvaluationPresenter
{
    public function __construct(private readonly FindingTranslator $translator) {}

    /**
     * @return array<string, mixed>
     */
    public function forLatestReport(int $userId): array
    {
        $report = PeriodReport::where('user_id', $userId)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->first();

        // ⚠️ Sem relatório NENHUM é diferente de relatório com zero achados. O
        // primeiro é "você ainda não importou nada"; o segundo é "não
        // encontramos nenhum padrão neste período" — e o segundo é boa notícia
        // (§D10). A tela precisa dos dois casos separados.
        if ($report === null) {
            return [
                'has_report' => false,
                'period' => null,
                'coverage' => null,
                'findings' => [],
                'rule_failures' => [],
                'is_stale' => false,
                'generated_at' => null,
                'narrative' => null,
            ];
        }

        return [
            'has_report' => true,
            'period' => [
                'from' => (string) $report->period_start,
                'to' => (string) $report->period_end,
                'label' => $this->periodLabel($report),
            ],
            // Artigo V — o denominador viaja com os achados, sempre.
            'coverage' => [
                'percentage' => round($report->coverage_pct, 1),
                'span_days' => round($report->span_days, 1),
                'validity' => $report->validity,
                'summary' => __('patterns.coverage_summary', [
                    'span' => $this->number($report->span_days, 1),
                    'coverage' => $this->number($report->coverage_pct, 1),
                ]),
            ],
            'findings' => array_map(
                fn (PresentedFinding $f): array => $f->toArray(),
                array_map(
                    fn (array $finding): PresentedFinding => $this->translator->translate($finding),
                    $report->findings ?? [],
                ),
            ),
            // Falhas de regra aparecem. Esconder falha é o mesmo que não ter
            // falha — mesma decisão dos `parse_warnings` da tela de importação.
            'rule_failures' => $report->rule_failures ?? [],
            // ⚠️ Sinaliza, não recalcula em silêncio (§D9).
            'is_stale' => $report->isStale(),
            'generated_at' => $report->generated_at?->format('d/m/Y H:i'),

            // ⚠️ `null` é o estado NORMAL (§D3). A narrativa ENRIQUECE a tela;
            // não substitui nada. Sem ela, a tela é exatamente a de ontem — e
            // é isso que torna o Artigo I verdadeiro por construção, em vez
            // de por disciplina.
            'narrative' => $report->hasNarrative() ? [
                'text' => (string) $report->narrative,
                // Procedência: qual modelo escreveu e quando. É o que permite
                // investigar um texto estranho sem começar por adivinhar.
                'model' => $report->narrative_model,
                'generated_at' => $report->narrative_generated_at?->format('d/m/Y H:i'),
            ] : null,
        ];
    }

    private function periodLabel(PeriodReport $report): string
    {
        return __('patterns.period_label', [
            'from' => $this->brDate((string) $report->period_start),
            'to' => $this->brDate((string) $report->period_end),
        ]);
    }

    private function brDate(string $isoDate): string
    {
        [$year, $month, $day] = explode('-', $isoDate);

        return "{$day}/{$month}/{$year}";
    }

    private function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }
}

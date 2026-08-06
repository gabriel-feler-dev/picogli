<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;
use App\Models\DailyMetrics;

/**
 * `get_daily_series` — uma linha por dia (FR-602).
 *
 * É a ferramenta de "por que o dia 25 foi diferente?", que é a pergunta que
 * abriu a Spec 006.
 *
 * ⚠️ **Lê `daily_metrics`, o cache da fase 2 — não recalcula.** São os mesmos
 * números que a tela mostra, pela mesma razão do §D1: recalcular aqui criaria
 * uma segunda fonte de verdade que divergiria no dia em que uma fórmula mudasse
 * de versão.
 *
 * ⚠️ **Não carrega a série.** 14 linhas de resposta não justificam trazer 3.616
 * leituras para a memória.
 */
final class DailySeriesTool extends PeriodTool
{
    public function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            name: 'get_daily_series',
            description: 'Uma linha por dia do período: média, tempo na faixa, variabilidade, '
                .'tempo abaixo, cobertura do sensor, insulina automática, bolus e carboidrato. '
                .'Use para comparar dias, achar o dia fora da curva, ou responder '
                .'"por que o dia X foi diferente".',
            argumentSchema: self::PERIOD_SCHEMA,
            emittedKeys: array_merge(self::PERIOD_KEYS, [
                'rows', 'day_count', 'local_date', 'reading_count', 'coverage_percent',
                'mean_glucose', 'time_in_range_percent', 'cv_percent', 'time_below_70_percent',
                'time_above_250_percent', 'auto_insulin_u', 'bolus_insulin_u', 'total_carbs_g',
            ]),
        );
    }

    public function run(array $args, ChatScope $scope): ToolResult
    {
        [$from, $to] = $this->window($args);

        $rows = DailyMetrics::where('user_id', $scope->userId)
            ->whereBetween('local_date', [$from, $to])
            ->orderBy('local_date')
            ->get()
            ->map(fn (DailyMetrics $d): array => [
                'local_date' => (string) $d->local_date,
                'reading_count' => $d->reading_count,
                // Artigo V por linha: o dia carrega a própria cobertura.
                'coverage_percent' => $this->round($d->coverage_pct),
                'mean_glucose' => $this->round($d->mean_glucose),
                'time_in_range_percent' => $this->round($d->tir_pct),
                'cv_percent' => $this->round($d->cv_pct),
                // Os dois níveis somados: a pergunta "quanto tempo abaixo" não
                // distingue, e quem quiser o nível 2 pede episódios.
                'time_below_70_percent' => $this->round($d->tbr_level1_pct + $d->tbr_level2_pct),
                'time_above_250_percent' => $this->round($d->tar_level2_pct),
                'auto_insulin_u' => $this->round($d->auto_insulin_u, 2),
                'bolus_insulin_u' => $this->round($d->bolus_insulin_u, 2),
                'total_carbs_g' => $this->round($d->total_carbs_g, 1),
            ])
            ->all();

        return ToolResult::ok('get_daily_series', $args, $this->envelope($from, $to, [
            'day_count' => count($rows),
            'rows' => $rows,
        ]));
    }
}

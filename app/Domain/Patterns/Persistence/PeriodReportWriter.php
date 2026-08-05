<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Persistence;

use App\Domain\Patterns\PatternEngine;
use App\Domain\Patterns\Value\FindingSet;
use App\Domain\Patterns\Value\PatternDataset;
use App\Models\PeriodReport;
use Illuminate\Support\Carbon;

/**
 * Grava o relatório de padrões de um período (FR-413).
 *
 * ⚠️ Camada de BORDA, como `Persistence/PatternDatasetBuilder`. É a única classe
 * da fase que escreve no banco.
 *
 * ## Reprocessar atualiza, não empilha
 *
 * A chave única `(user_id, period_start, period_end)` mais `updateOrCreate` é o
 * Artigo VIII.4 aplicado por analogia: sem isso, cada recálculo deixaria uma
 * linha nova e o histórico de versões viraria lixo.
 *
 * ## O relatório carrega o próprio denominador
 *
 * `coverage_pct`, `span_days` e `validity` são gravados junto (Artigo V). A tela
 * não precisa recalcular a cobertura para exibir o relatório — e, mais
 * importante, **não pode**: um relatório de julho exibido em setembro tem de
 * mostrar a cobertura de julho, não a de hoje.
 */
final class PeriodReportWriter
{
    public function write(int $userId, PatternDataset $dataset, FindingSet $set): PeriodReport
    {
        $payload = $set->toArray();

        return PeriodReport::updateOrCreate(
            [
                'user_id' => $userId,
                'period_start' => $dataset->periodStart,
                'period_end' => $dataset->periodEnd,
            ],
            [
                // ⚠️ As DUAS versões (§D9). A do motor identifica as regras; a
                // das métricas, as fórmulas de que os achados derivaram.
                'engine_version' => PatternEngine::VERSION,
                'metrics_version' => $dataset->metricsVersion,

                'findings' => $payload['findings'],
                // `null` e não `[]`: a coluna é nullable, e "nenhuma falha" fica
                // distinguível de "coluna nunca preenchida" num relatório antigo.
                'rule_failures' => $payload['rule_failures'] === [] ? null : $payload['rule_failures'],
                'finding_count' => $payload['finding_count'],

                'coverage_pct' => round($dataset->coverage->percentage, 2),
                'span_days' => round($dataset->coverage->spanInDays, 2),
                'validity' => $dataset->validity->value,

                'generated_at' => Carbon::now(),

                // ⚠️⚠️ **REGERAR O RELATÓRIO ZERA A NARRATIVA** (Spec 005, §D8).
                //
                // Um texto escrito sobre a versão anterior das regras, exibido ao
                // lado de achados recalculados, é **plausível e falso** — o pior
                // tipo de erro deste projeto, porque nada denuncia.
                //
                // Zerar aqui, e não em quem gera, é o que torna a regra
                // inescapável: não existe caminho que atualize os achados sem
                // invalidar o texto que os descrevia.
                'narrative' => null,
                'narrative_model' => null,
                'narrative_generated_at' => null,
            ],
        );
    }
}

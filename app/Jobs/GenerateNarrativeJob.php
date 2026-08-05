<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Ai\NarrativeGenerator;
use App\Domain\Ai\Value\DiscardReason;
use App\Domain\Ai\Value\NarrativeAttempt;
use App\Models\PeriodReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Gera a narrativa de um relatório e a grava (FR-504, FR-506).
 *
 * ⚠️ **Job separado do `ComputePatternsJob`, de propósito.** É o único passo da
 * cadeia que depende de rede: pode demorar, pode falhar, pode estar em cooldown.
 * Encadeá-lo no cálculo faria uma cota esgotada atrasar a persistência dos
 * achados — que não dependem de nada externo.
 *
 * ```
 * ImportCsvJob -> ComputeMetricsJob -> ComputePatternsJob -> GenerateNarrativeJob
 *                                          (obrigatório)         (enriquecimento)
 * ```
 *
 * ⚠️ **Este job nunca falha por causa da IA.** Todo desfecho — cota esgotada,
 * número inventado, resposta vazia — é um descarte registrado no log, e o
 * relatório continua exatamente como estava. A tela não muda de forma
 * (Artigo I, §D3).
 */
class GenerateNarrativeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $periodReportId) {}

    public function handle(NarrativeGenerator $generator): NarrativeAttempt
    {
        $report = PeriodReport::find($this->periodReportId);

        if ($report === null) {
            return NarrativeAttempt::discarded(DiscardReason::NothingToNarrate);
        }

        $attempt = $generator->generate($report->findings ?? [], [
            'period_from' => (string) $report->period_start,
            'period_to' => (string) $report->period_end,
            'coverage_percent' => $report->coverage_pct,
            'span_days' => $report->span_days,
            'validity' => $report->validity,
        ]);

        // ⚠️ **É AQUI que o descarte vira log** — o domínio é puro e devolve o
        // motivo em vez de registrar (NFR-401). E o nível importa: "nada a
        // narrar" não é falha (§D10), e logá-lo como erro treinaria quem lê o
        // log a ignorá-lo.
        $level = $attempt->wasPublished() || ! $attempt->discardReason->isFailure()
            ? 'info'
            : 'warning';

        Log::log($level, '[PicoGli] '.$attempt->logMessage(), [
            'period_report_id' => $report->id,
        ]);

        if ($attempt->wasPublished()) {
            $report->update([
                'narrative' => $attempt->result->text,
                'narrative_model' => $attempt->result->model,
                'narrative_generated_at' => $attempt->result->generatedAt,
            ]);
        }

        return $attempt;
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Patterns\PatternEngine;
use App\Domain\Patterns\Persistence\PatternDatasetBuilder;
use App\Domain\Patterns\Persistence\PeriodReportWriter;
use App\Models\PeriodReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Roda o motor de padrões e grava o relatório do período (FR-413).
 *
 * ⚠️ Fila, e não cálculo dentro do request. Dez regras sobre 3.616 leituras é
 * rápido, mas a montagem do dataset custa nove consultas e a série inteira em
 * memória — trabalho que não pertence a um request de tela.
 *
 * Em hospedagem compartilhada a fila é acionada por cron (ADR-5), então o
 * relatório pode demorar até um minuto para aparecer. A tela precisa dizer isso
 * em vez de parecer travada.
 *
 * ## Encadeamento
 *
 * Este job depende de `daily_metrics` estar calculado: o dataset lê a cobertura
 * por dia dali. A ordem é `ImportCsvJob` → `ComputeMetricsJob` →
 * `ComputePatternsJob`, e cada um é uma execução separada do worker — encadear
 * os três numa só arriscaria o `--max-time=55`.
 */
class ComputePatternsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly int $days = 14,
    ) {}

    public function handle(
        PatternDatasetBuilder $builder,
        PatternEngine $engine,
        PeriodReportWriter $writer,
    ): ?PeriodReport {
        $dataset = $builder->forLatestPeriod($this->userId, $this->days);

        // ⚠️ Período sem leitura nenhuma não gera relatório vazio — não há
        // período a que ele se refira. É diferente de um período COM dados e sem
        // padrão detectado, que gera relatório com zero achados (§D10).
        if ($dataset->isEmpty()) {
            return null;
        }

        return $writer->write($this->userId, $dataset, $engine->run($dataset));
    }
}

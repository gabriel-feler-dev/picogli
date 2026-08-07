<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recalcula `daily_metrics` de um usuário (FR-105, FR-108).
 *
 * ⚠️ Fila, não chamada direta dentro do `ImportCsvJob`. Em hospedagem
 * compartilhada o import já consome o orçamento do worker (`--max-time=55`,
 * ADR-5); encadear o cálculo na mesma execução arrisca estourar o limite e
 * deixar métricas pela metade — com o import já commitado.
 */
class ComputeMetricsJob implements ShouldQueue
{
    use Queueable;

    /** @param list<string>|null $dates null = todos os dias com leitura */
    public function __construct(
        public readonly int $userId,
        public readonly ?array $dates = null,
    ) {}

    public function handle(DailyMetricsWriter $writer): void
    {
        $writer->write($this->userId, $this->dates);

        /*
         * ⚠️⚠️ **ESTE ELO ESTAVA FALTANDO ATÉ 07/08/2026.**
         *
         * O `ImportCsvJob` disparava este job, e aqui a corrente PARAVA. Nada no
         * app inteiro disparava o `ComputePatternsJob` — a busca por ele em
         * `app/` só encontrava o próprio arquivo.
         *
         * Consequência: `period_reports` nunca era escrito a partir de uma
         * importação, e a tela `/avaliacao` dizia "ainda não há avaliação" para
         * sempre, com 3.616 leituras e 14 dias de métricas no banco.
         *
         * ⚠️ **Por que os testes não pegaram:** cada job é testado isolado —
         * instancia-se o `ComputePatternsJob`, roda, confere o que gravou. Todos
         * passam. Nenhum teste andava do upload até a avaliação, então o elo
         * ausente não tinha onde aparecer. O `PatternChainTest` existe agora para
         * isso.
         */
        ComputePatternsJob::dispatch($this->userId);
    }
}

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
    }
}

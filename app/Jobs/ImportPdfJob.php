<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Import\Pdf\PdfAggregateReader;
use App\Domain\Import\Pdf\Persistence\PdfAggregateWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Importa um relatório em PDF (Spec 007, FR-705, §D6).
 *
 * ⚠️⚠️ **Separado do `ImportCsvJob`, e não o reusa em nada.**
 *
 * O `ImportCsvJob` tem dez etapas, resolve as armadilhas A1–A10 e alimenta nove
 * tabelas de evento. Este job tem duas etapas e alimenta uma tabela de resumo.
 * Fundi-los faria o PDF passar perto do `EventExploder`, e a única garantia real
 * do §D6 é que ele **não passa**.
 *
 * ⚠️ **PDF é FALLBACK, nunca fonte primária.** Este job não dispara
 * `ComputeMetricsJob`: agregado não é evento, e não há nada para recalcular.
 */
final class ImportPdfJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $userId,
        private readonly string $path,
        private readonly ?int $importId = null,
    ) {}

    /** @return int quantos agregados foram gravados */
    public function handle(PdfAggregateReader $reader, PdfAggregateWriter $writer): int
    {
        $agregados = $reader->read($this->path);

        // ⚠️ Lista vazia é caso previsto, não erro: PDF escaneado, criptografado
        // ou de outro relatório. O log diz o que houve; o job termina limpo.
        if ($agregados === []) {
            Log::info('pdf: nenhum agregado reconhecido', [
                'user' => $this->userId,
                'import' => $this->importId,
            ]);

            return 0;
        }

        $gravados = $writer->write($this->userId, $agregados, $this->importId);

        Log::info('pdf: agregados gravados', [
            'user' => $this->userId,
            'count' => $gravados,
        ]);

        return $gravados;
    }
}

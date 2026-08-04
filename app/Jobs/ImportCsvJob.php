<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Import\BolusLinker;
use App\Domain\Import\CarelinkCsvReader;
use App\Domain\Import\EventExploder;
use App\Domain\Import\Persistence\EventWriter;
use App\Domain\Import\Persistence\ImportContext;
use App\Domain\Import\Value\Events\BolusDeliveryEvent;
use App\Domain\Import\Value\Events\BolusRequestEvent;
use App\Domain\Import\Value\Events\MealEvent;
use App\Models\Import;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Importa um export CSV do CareLink.
 *
 * ⚠️ NFR-002 — roda obrigatoriamente em fila, nunca no ciclo de request. São
 * ~4 mil inserts no export de referência, e ~25 mil num de 90 dias: não cabe no
 * timeout de request de hospedagem compartilhada.
 *
 * Em produção a fila é acionada por cron, porque compartilhada não roda worker
 * em daemon (ADR-5):
 *
 *   * * * * * cd /caminho && php artisan queue:work --stop-when-empty --max-time=55
 *
 * As etapas seguem `plan.md` §ImportCsvJob.
 */
class ImportCsvJob implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        public readonly int $userId,
        public readonly string $path,
        public readonly string $timezone,
        public readonly ?string $originalFilename = null,
    ) {}

    public function handle(
        CarelinkCsvReader $reader,
        EventExploder $exploder,
        BolusLinker $linker,
    ): void {
        // Erro explícito em vez de warning do PHP virando ErrorException:
        // mensagem confusa numa fila é mensagem perdida.
        if (! is_file($this->path)) {
            throw new RuntimeException("Export do CareLink não encontrado: {$this->path}");
        }

        // ── 1. HASH — reenviar o mesmo arquivo é no-op ────────────────────
        // Barra ANTES de processar 4 mil linhas, não depois.
        $hash = hash_file('sha256', $this->path);

        $existing = Import::where('user_id', $this->userId)->where('file_hash', $hash)->first();

        if ($existing !== null) {
            return;
        }

        // ── 2. CABEÇALHO — cria o registro antes de processar ─────────────
        $header = $reader->readHeader($this->path);

        $import = Import::create([
            'user_id' => $this->userId,
            'original_filename' => $this->originalFilename ?? basename($this->path),
            'file_hash' => $hash,
            'timezone' => $this->timezone,
            // §A7 — o formato atual não declara a unidade por campo; mg/dL é o
            // que o export de referência usa. Quando aparecer um mmol/L, isto
            // vira detecção de verdade em vez de default.
            'glucose_unit' => 'mg/dL',
            'period_start' => $header->periodStart?->format('Y-m-d'),
            'period_end' => $header->periodEnd?->format('Y-m-d'),
            'status' => Import::STATUS_PROCESSING,
            ...$header->toImportAttributes(),
        ]);

        foreach ($header->unknownKeys as $key => $value) {
            $this->warn("Chave desconhecida no cabeçalho: {$key}");
        }

        // Período ausente não impede importar — os eventos trazem os próprios
        // instantes. Mas é sinal de export atípico, então fica registrado.
        if ($header->periodStart === null || $header->periodEnd === null) {
            $this->warn('Cabeçalho sem Start Date / End Date');
        }

        $context = new ImportContext($this->userId, $import->id, $this->timezone);
        $writer = new EventWriter($context);

        try {
            DB::transaction(function () use ($reader, $exploder, $linker, $writer, $import) {
                $blockCounts = ['pump' => 0, 'auto_insulin' => 0, 'sensor' => 0];
                $ignored = [];

                // Bolus e refeições precisam do conjunto INTEIRO para parear
                // (§A9, §A10). São ~160 objetos — irrelevante para memória.
                // Todo o resto vai direto para o buffer do writer (NFR-001).
                $requests = [];
                $deliveries = [];
                $meals = [];

                // ── 3-5. STREAM → EXPLODE → LIGA ──────────────────────────
                foreach ($reader->streamRows($this->path, $this->warnFromReader(...)) as $row) {
                    $blockCounts[$row->block->value]++;

                    $result = $exploder->explode($row);

                    if (! $result->producedEvents()) {
                        $reason = $result->ignoredReason->value;
                        $ignored[$reason] = ($ignored[$reason] ?? 0) + 1;

                        if ($result->isWarning()) {
                            $this->warn("Linha {$row->lineNumber} descartada: {$reason}");
                        }

                        continue;
                    }

                    foreach ($result->events as $event) {
                        match (true) {
                            $event instanceof BolusRequestEvent => $requests[] = $event,
                            $event instanceof BolusDeliveryEvent => $deliveries[] = $event,
                            $event instanceof MealEvent => $meals[] = $event,
                            // ── 6-7. TEMPO + UPSERT (bufferizado) ─────────
                            default => $writer->add($event),
                        };
                    }
                }

                $doses = $linker->link($requests, $deliveries, $meals, $this->warn(...));

                $writer->flushAll();
                $writer->writeMealsAndDoses($meals, $doses);

                // ── 10. FINALIZA ──────────────────────────────────────────
                $import->update([
                    'block_row_counts' => [
                        ...$blockCounts,
                        'ignored' => $ignored,
                        'written' => $writer->writtenCounts(),
                    ],
                    'parse_warnings' => $this->warnings === [] ? null : $this->warnings,
                    'status' => Import::STATUS_DONE,
                ]);
            });
        } catch (Throwable $e) {
            // A transação já reverteu os dados. O registro de import sobrevive
            // de propósito: um import falho invisível é pior que um visível.
            $import->update([
                'status' => Import::STATUS_FAILED,
                'parse_warnings' => [...$this->warnings, 'FALHA: '.$e->getMessage()],
            ]);

            throw $e;
        }

        // ── 8-9. Pós-import: MealEnricher (T011) e SettingsInferrer (T010).
        // Ficam FORA da transação de propósito: dependem dos dados já
        // gravados para consultar sensor_readings.
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    private function warnFromReader(string $message, int $line): void
    {
        $this->warn("Linha {$line}: {$message}");
    }
}

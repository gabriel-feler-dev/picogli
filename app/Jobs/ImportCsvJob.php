<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Import\BolusLinker;
use App\Domain\Import\CarelinkCsvReader;
use App\Domain\Import\EventExploder;
use App\Domain\Import\Persistence\EventWriter;
use App\Domain\Import\Persistence\ImportContext;
use App\Domain\Import\Persistence\MealEnricher;
use App\Domain\Import\SettingsInferrer;
use App\Domain\Import\Value\Events\BasalRateEvent;
use App\Domain\Import\Value\Events\BolusDeliveryEvent;
use App\Domain\Import\Value\Events\BolusRequestEvent;
use App\Domain\Import\Value\Events\MealEvent;
use App\Models\DeviceSettingsSnapshot;
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

    /**
     * Refeições e taxas de basal do import, guardadas para a inferência de
     * configuração (etapa 9), que roda depois do commit.
     *
     * São ~190 objetos no export de referência — irrelevante para memória, e
     * reler do banco só para isso seria trabalho a mais sem ganho.
     *
     * @var list<MealEvent>
     */
    private array $meals = [];

    /** @var list<BasalRateEvent> */
    private array $basalRates = [];

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
        MealEnricher $enricher,
        SettingsInferrer $inferrer,
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

        foreach (array_keys($header->unknownKeys) as $key) {
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
                            $event instanceof MealEvent => $this->meals[] = $event,
                            // ── 6-7. TEMPO + UPSERT (bufferizado) ─────────
                            // Basal também é coletada: a etapa 9 reconstrói o
                            // perfil a partir dela, depois do commit.
                            $event instanceof BasalRateEvent => $this->collectBasal($event, $writer),
                            default => $writer->add($event),
                        };
                    }
                }

                $doses = $linker->link($requests, $deliveries, $this->meals, $this->warn(...));

                $writer->flushAll();
                $writer->writeMealsAndDoses($this->meals, $doses);

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

        // ── 8. ENRIQUECE ─────────────────────────────────────────────────
        // FORA da transação de propósito: consulta as leituras de sensor que
        // acabaram de ser gravadas.
        $enricher->enrich($this->userId, $import->id);

        // ── 9. INFERE CONFIGURAÇÃO ───────────────────────────────────────
        $settings = $inferrer->infer($this->meals, $this->basalRates);

        if (! $settings->isEmpty()) {
            // `updateOrCreate` na fingerprint: reimportar não cria snapshot
            // duplicado, e um novo só nasce quando a configuração muda de fato.
            DeviceSettingsSnapshot::updateOrCreate(
                ['user_id' => $this->userId, 'fingerprint' => $settings->fingerprint()],
                [
                    'import_id' => $import->id,
                    'valid_from' => $import->period_start ?? now()->toDateString(),
                    'carb_ratio_profile' => $settings->carbRatioProfile,
                    'isf_values' => $settings->isfValues,
                    'basal_profile' => $settings->basalProfile,
                ],
            );
        }

        // ── Dispara o cálculo de métricas NA FILA (ADR-5, T107.3) ────────
        ComputeMetricsJob::dispatch($this->userId);

        if ($settings->conflicts !== []) {
            $import->update([
                'parse_warnings' => [...$this->warnings, ...$settings->conflicts],
            ]);
        }
    }

    private function collectBasal(BasalRateEvent $event, EventWriter $writer): void
    {
        $this->basalRates[] = $event;
        $writer->add($event);
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

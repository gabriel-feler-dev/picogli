<?php

declare(strict_types=1);

namespace App\Domain\Import\Persistence;

use App\Domain\Import\Value\Events\BasalRateEvent;
use App\Domain\Import\Value\Events\BgReadingEvent;
use App\Domain\Import\Value\Events\DailyAutoInsulinEvent;
use App\Domain\Import\Value\Events\DeviceEvent;
use App\Domain\Import\Value\Events\ImportEvent;
use App\Domain\Import\Value\Events\MealEvent;
use App\Domain\Import\Value\Events\SensorReadingEvent;
use App\Domain\Import\Value\LinkedDose;
use App\Models\InsulinDose;
use Illuminate\Support\Facades\DB;

/**
 * Grava eventos tipados no banco, com upsert em blocos.
 *
 * É a ÚNICA classe do domínio de importação que toca o banco. Todo o resto de
 * `app/Domain/Import/` é PHP puro e testável sem framework.
 *
 * ## Buffer, e por quê
 *
 * Os eventos chegam em streaming e são acumulados por tabela até `CHUNK_SIZE`,
 * então descarregados. NFR-001 exige memória constante: o alvo é hospedagem
 * compartilhada, e um export de 90 dias tem ~25 mil linhas.
 *
 * ⚠️ Exceção deliberada: **refeições e bolus não passam por aqui em streaming**.
 * O `BolusLinker` precisa do conjunto inteiro para parear pedido/entrega dentro
 * de janela e resolver disputa de refeição (§A9, §A10). São ~160 objetos no
 * export de referência — irrelevante para memória, e impossível de fazer
 * incrementalmente sem errar o pareamento.
 *
 * ## Upsert, e por quê
 *
 * FR-006: relatórios se sobrepõem no tempo. Sem upsert nas chaves únicas,
 * reimportar duplica dados e envenena todas as métricas — silenciosamente.
 * `upsert()` do Laravel gera `ON CONFLICT DO UPDATE` no SQLite e
 * `ON DUPLICATE KEY UPDATE` no MySQL: portável, atende o Artigo IX.
 */
final class EventWriter
{
    private const CHUNK_SIZE = 500;

    /** Chave única e colunas atualizáveis de cada tabela. */
    private const TABLES = [
        'sensor_readings' => [
            'unique' => ['user_id', 'recorded_at_local'],
            'update' => ['import_id', 'recorded_at_utc', 'local_date', 'local_hour',
                'glucose_mgdl', 'isig', 'sensor_exception', 'device_index', 'updated_at'],
        ],
        'bg_readings' => [
            'unique' => ['user_id', 'recorded_at_local', 'glucose_mgdl'],
            'update' => ['import_id', 'recorded_at_utc', 'local_date', 'local_hour',
                'source', 'used_for_calibration', 'device_index', 'updated_at'],
        ],
        'basal_rates' => [
            'unique' => ['user_id', 'recorded_at_local'],
            'update' => ['import_id', 'recorded_at_utc', 'local_date', 'local_hour',
                'rate_uh', 'device_index', 'updated_at'],
        ],
        'device_events' => [
            'unique' => ['user_id', 'recorded_at_local', 'category', 'code'],
            'update' => ['import_id', 'recorded_at_utc', 'local_date', 'local_hour',
                'payload', 'device_index', 'updated_at'],
        ],
        'daily_auto_insulin' => [
            'unique' => ['user_id', 'local_date'],
            'update' => ['import_id', 'units_delivered', 'updated_at'],
        ],
        'meals' => [
            'unique' => ['user_id', 'recorded_at_local'],
            'update' => ['import_id', 'recorded_at_utc', 'local_date', 'local_hour',
                'carbs_g', 'carb_ratio', 'insulin_sensitivity', 'target_low', 'target_high',
                'bg_input', 'estimate_u', 'correction_u', 'food_u', 'active_insulin_u',
                'bwz_status', 'device_index', 'updated_at'],
        ],
        'insulin_doses' => [
            'unique' => ['user_id', 'dedupe_key'],
            'update' => ['import_id', 'recorded_at_local', 'recorded_at_utc', 'local_date',
                'local_hour', 'kind', 'raw_source', 'is_automatic', 'units_selected',
                'units_delivered', 'bolus_number', 'cancellation_reason',
                'delivered_at_local', 'meal_id', 'device_index', 'updated_at'],
        ],
    ];

    /** @var array<string, list<array<string, mixed>>> */
    private array $buffers = [];

    /** @var array<string, int> */
    private array $written = [];

    public function __construct(private readonly ImportContext $context) {}

    /**
     * Enfileira um evento em streaming. Descarrega sozinho a cada CHUNK_SIZE.
     *
     * Refeições e doses NÃO entram por aqui — ver `writeMealsAndDoses()`.
     */
    public function add(ImportEvent $event): void
    {
        [$table, $row] = match (true) {
            $event instanceof SensorReadingEvent => ['sensor_readings', [
                'glucose_mgdl' => $event->glucoseMgdl,
                'isig' => $event->isig,
                'sensor_exception' => null,
            ]],
            $event instanceof BgReadingEvent => ['bg_readings', [
                'glucose_mgdl' => $event->glucoseMgdl,
                'source' => $event->source,
                'used_for_calibration' => $event->usedForCalibration,
            ]],
            $event instanceof BasalRateEvent => ['basal_rates', [
                'rate_uh' => $event->rateUh,
            ]],
            $event instanceof DeviceEvent => ['device_events', [
                'category' => $event->category->value,
                'code' => $event->code,
                'payload' => $event->payload === [] ? null : json_encode($event->payload, JSON_THROW_ON_ERROR),
            ]],
            $event instanceof DailyAutoInsulinEvent => ['daily_auto_insulin', [
                'units_delivered' => $event->unitsDelivered,
            ]],
            default => [null, null],
        };

        if ($table === null) {
            return;
        }

        // daily_auto_insulin é agregado do dia: não tem instante, só data.
        $common = $table === 'daily_auto_insulin'
            ? ['local_date' => $event->recordedAtLocal->format('Y-m-d')]
            : [...$this->context->timeColumns($event->recordedAtLocal), 'device_index' => $event->deviceIndex];

        $this->buffers[$table][] = [
            'user_id' => $this->context->userId,
            'import_id' => $this->context->importId,
            ...$common,
            ...$row,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (count($this->buffers[$table]) >= self::CHUNK_SIZE) {
            $this->flush($table);
        }
    }

    /**
     * Grava refeições e doses, nessa ordem, resolvendo o vínculo entre elas.
     *
     * ⚠️ A ordem importa: `insulin_doses.meal_id` é chave estrangeira, então as
     * refeições precisam existir antes. O `LinkedDose` carrega uma REFERÊNCIA ao
     * objeto `MealEvent`, não um id — o id só existe depois da escrita. Por isso
     * a releitura no meio.
     *
     * @param  list<MealEvent>  $meals
     * @param  list<LinkedDose>  $doses
     */
    public function writeMealsAndDoses(array $meals, array $doses): void
    {
        foreach ($meals as $meal) {
            $this->buffers['meals'][] = [
                'user_id' => $this->context->userId,
                'import_id' => $this->context->importId,
                ...$this->context->timeColumns($meal->recordedAtLocal),
                'carbs_g' => $meal->carbsG,
                'carb_ratio' => $meal->carbRatio,
                'insulin_sensitivity' => $meal->insulinSensitivity,
                'target_low' => $meal->targetLow,
                'target_high' => $meal->targetHigh,
                'bg_input' => $meal->bgInput,
                'estimate_u' => $meal->estimateU,
                'correction_u' => $meal->correctionU,
                'food_u' => $meal->foodU,
                'active_insulin_u' => $meal->activeInsulinU,
                'bwz_status' => $meal->bwzStatus,
                'device_index' => $meal->deviceIndex,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->flush('meals');

        $mealIds = $this->mealIdsByInstant();

        foreach ($doses as $dose) {
            $recordedAt = $this->context->timeColumns($dose->recordedAtLocal);
            $mealKey = $dose->meal?->recordedAtLocal->format('Y-m-d H:i:s');

            $this->buffers['insulin_doses'][] = [
                'user_id' => $this->context->userId,
                'import_id' => $this->context->importId,
                ...$recordedAt,
                'kind' => $dose->kind,
                'raw_source' => $dose->rawSource,
                'is_automatic' => $dose->isAutomatic,
                'units_selected' => $dose->unitsSelected,
                'units_delivered' => $dose->unitsDelivered,
                'bolus_number' => $dose->bolusNumber,
                'cancellation_reason' => $dose->cancellationReason,
                'delivered_at_local' => $dose->deliveredAtLocal?->format('Y-m-d H:i:s'),
                'meal_id' => $mealKey === null ? null : ($mealIds[$mealKey] ?? null),
                'device_index' => $dose->deviceIndex,
                'dedupe_key' => InsulinDose::makeDedupeKey(
                    $recordedAt['recorded_at_local'],
                    $dose->kind,
                    $dose->bolusNumber,
                ),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->flush('insulin_doses');
    }

    /** Descarrega todos os buffers pendentes. */
    public function flushAll(): void
    {
        foreach (array_keys($this->buffers) as $table) {
            $this->flush($table);
        }
    }

    /**
     * Linhas efetivamente gravadas por tabela.
     *
     * ⚠️ É contagem de linhas ENVIADAS, não de linhas novas: upsert não
     * distingue insert de update. Para "quantas já existiam" (FR-010), compare
     * a contagem da tabela antes e depois — é o que o Job faz.
     *
     * @return array<string, int>
     */
    public function writtenCounts(): array
    {
        return $this->written;
    }

    private function flush(string $table): void
    {
        $rows = $this->buffers[$table] ?? [];

        if ($rows === []) {
            return;
        }

        $config = self::TABLES[$table];

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            DB::table($table)->upsert($chunk, $config['unique'], $config['update']);
        }

        $this->written[$table] = ($this->written[$table] ?? 0) + count($rows);
        $this->buffers[$table] = [];
    }

    /** @return array<string, int> instante local → id da refeição */
    private function mealIdsByInstant(): array
    {
        return DB::table('meals')
            ->where('user_id', $this->context->userId)
            ->pluck('id', 'recorded_at_local')
            ->all();
    }
}

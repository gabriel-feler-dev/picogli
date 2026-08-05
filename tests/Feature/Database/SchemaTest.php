<?php

declare(strict_types=1);

use App\Models\BasalRate;
use App\Models\BgReading;
use App\Models\DailyAutoInsulin;
use App\Models\DeviceEvent;
use App\Models\DeviceSettingsSnapshot;
use App\Models\Import;
use App\Models\InsulinDose;
use App\Models\Meal;
use App\Models\SensorReading;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * T007 — FR-006 (Idempotência) e NFR-003 (Portabilidade)
 *
 * O que importa aqui NÃO é "a tabela existe". É que a restrição única
 * REJEITA de fato a duplicata — é ela, e não disciplina do código, que impede
 * reimportação de período sobreposto de envenenar todas as métricas.
 */
beforeEach(function () {
    $this->user = User::factory()->create();

    $this->import = Import::create([
        'user_id' => $this->user->id,
        'original_filename' => 'export.csv',
        'file_hash' => str_repeat('a', 64),
        'timezone' => 'America/Sao_Paulo',
        'glucose_unit' => 'mg/dL',
        'period_start' => '2026-07-16',
        'period_end' => '2026-07-29',
        'status' => Import::STATUS_DONE,
    ]);
});

/** Atributos de tempo comuns a todo evento. */
function eventTime(string $local = '2026-07-29 11:49:09'): array
{
    return [
        'recorded_at_local' => $local,
        'recorded_at_utc' => $local,
        'local_date' => substr($local, 0, 10),
        'local_hour' => (int) substr($local, 11, 2),
        'device_index' => 1.0,
    ];
}

describe('chaves únicas rejeitam duplicata (FR-006)', function () {

    it('sensor_readings: um instante, uma leitura', function () {
        $row = ['user_id' => $this->user->id, 'import_id' => $this->import->id,
            'glucose_mgdl' => 117, ...eventTime()];

        SensorReading::create($row);

        expect(fn () => SensorReading::create($row))->toThrow(QueryException::class);
    });

    it('meals: um instante, uma refeição', function () {
        $row = ['user_id' => $this->user->id, 'import_id' => $this->import->id,
            'carbs_g' => 40.0, ...eventTime()];

        Meal::create($row);

        expect(fn () => Meal::create($row))->toThrow(QueryException::class);
    });

    it('daily_auto_insulin: um dia, um total', function () {
        $row = ['user_id' => $this->user->id, 'import_id' => $this->import->id,
            'local_date' => '2026-07-29', 'units_delivered' => 27.895];

        DailyAutoInsulin::create($row);

        expect(fn () => DailyAutoInsulin::create($row))->toThrow(QueryException::class);
    });

    it('device_events: chave inclui categoria e código', function () {
        $base = ['user_id' => $this->user->id, 'import_id' => $this->import->id, ...eventTime()];

        DeviceEvent::create([...$base, 'category' => 'alert', 'code' => 'ALERT BEFORE LOW']);

        // Mesma categoria e código no mesmo instante: duplicata.
        expect(fn () => DeviceEvent::create([...$base, 'category' => 'alert', 'code' => 'ALERT BEFORE LOW']))
            ->toThrow(QueryException::class);

        // Categoria diferente no mesmo instante é evento legítimo — uma linha
        // do CSV pode disparar alerta E registrar calibração (§A4).
        DeviceEvent::create([...$base, 'category' => 'calibration', 'code' => 'SENSOR_CALIBRATION_ACCEPTED']);

        expect(DeviceEvent::count())->toBe(2);
    });

    it('bg_readings: mesmo instante com valores diferentes são registros distintos', function () {
        $base = ['user_id' => $this->user->id, 'import_id' => $this->import->id, ...eventTime()];

        BgReading::create([...$base, 'glucose_mgdl' => 193]);
        BgReading::create([...$base, 'glucose_mgdl' => 140]);

        expect(BgReading::count())->toBe(2);

        expect(fn () => BgReading::create([...$base, 'glucose_mgdl' => 193]))
            ->toThrow(QueryException::class);
    });

    it('basal_rates: um instante, uma taxa', function () {
        $row = ['user_id' => $this->user->id, 'import_id' => $this->import->id,
            'rate_uh' => 2.0, ...eventTime()];

        BasalRate::create($row);

        expect(fn () => BasalRate::create($row))->toThrow(QueryException::class);
    });

    it('imports: reenviar o mesmo arquivo colide pelo hash', function () {
        expect(fn () => Import::create([
            'user_id' => $this->user->id,
            'original_filename' => 'outro-nome.csv',
            'file_hash' => str_repeat('a', 64),   // mesmo conteúdo
            'timezone' => 'America/Sao_Paulo',
            'glucose_unit' => 'mg/dL',
            'period_start' => '2026-07-16',
            'period_end' => '2026-07-29',
        ]))->toThrow(QueryException::class);
    });
});

describe('dedupe_key das doses — o furo do NULL', function () {

    // ⚠️ MySQL e SQLite tratam NULL como DISTINTO em índice único. Uma chave
    // natural com `bolus_number` nullable deixaria doses sem número duplicarem
    // a cada reimportação, sem erro nenhum.
    it('duas doses sem bolus_number no mesmo instante colidem', function () {
        $key = InsulinDose::makeDedupeKey('2026-07-29 11:49:09', 'bolus_normal', null);

        $row = ['user_id' => $this->user->id, 'import_id' => $this->import->id,
            'kind' => 'bolus_normal', 'units_delivered' => 8.0,
            'dedupe_key' => $key, ...eventTime()];

        InsulinDose::create($row);

        expect(fn () => InsulinDose::create($row))->toThrow(QueryException::class);
    });

    // §A10 — no export real há instantes com dois pedidos (cancelou e refez).
    // Eles têm números diferentes, então são doses distintas e devem coexistir.
    it('duas doses no mesmo instante com números diferentes coexistem', function () {
        $base = ['user_id' => $this->user->id, 'import_id' => $this->import->id,
            'kind' => 'bolus_normal', ...eventTime('2026-07-17 21:34:24')];

        InsulinDose::create([...$base, 'bolus_number' => 158,
            'cancellation_reason' => 'User Request',
            'dedupe_key' => InsulinDose::makeDedupeKey('2026-07-17 21:34:24', 'bolus_normal', 158)]);

        InsulinDose::create([...$base, 'bolus_number' => 159, 'units_delivered' => 1.25,
            'dedupe_key' => InsulinDose::makeDedupeKey('2026-07-17 21:34:24', 'bolus_normal', 159)]);

        expect(InsulinDose::count())->toBe(2);
    });

    it('a chave é determinística e distingue nulo de número', function () {
        $a = InsulinDose::makeDedupeKey('2026-07-29 11:49:09', 'bolus_normal', null);
        $b = InsulinDose::makeDedupeKey('2026-07-29 11:49:09', 'bolus_normal', null);
        $c = InsulinDose::makeDedupeKey('2026-07-29 11:49:09', 'bolus_normal', 85);

        expect($a)->toBe($b)->and($a)->not->toBe($c);
    });
});

describe('upsert é idempotente — o mecanismo do importador', function () {

    it('escrever o mesmo lote duas vezes não altera a contagem', function () {
        $batch = [];
        for ($i = 0; $i < 10; $i++) {
            $at = sprintf('2026-07-29 %02d:00:00', $i);
            $batch[] = [
                'user_id' => $this->user->id,
                'import_id' => $this->import->id,
                'glucose_mgdl' => 100 + $i,
                'created_at' => now(), 'updated_at' => now(),
                ...eventTime($at),
            ];
        }

        SensorReading::upsert($batch, ['user_id', 'recorded_at_local'], ['glucose_mgdl']);
        expect(SensorReading::count())->toBe(10);

        SensorReading::upsert($batch, ['user_id', 'recorded_at_local'], ['glucose_mgdl']);
        expect(SensorReading::count())->toBe(10);
    });
});

describe('casts e escopos', function () {

    it('converte tempo, data e json', function () {
        $reading = SensorReading::create(['user_id' => $this->user->id,
            'import_id' => $this->import->id, 'glucose_mgdl' => 117, 'isig' => 25.74,
            ...eventTime()])->fresh();

        expect($reading->recorded_at_local)->toBeInstanceOf(Carbon::class);
        expect($reading->local_hour)->toBe(11);
        expect($reading->isig)->toBe(25.74);

        $event = DeviceEvent::create(['user_id' => $this->user->id,
            'import_id' => $this->import->id, 'category' => 'calibration',
            'code' => 'SENSOR_CALIBRATION_ACCEPTED',
            'payload' => ['glucose_mgdl' => 193], ...eventTime()])->fresh();

        expect($event->payload)->toBe(['glucose_mgdl' => 193]);
    });

    it('filtra por dia local e ordena cronologicamente', function () {
        foreach (['2026-07-28 23:00:00', '2026-07-29 08:00:00', '2026-07-30 01:00:00'] as $i => $at) {
            SensorReading::create(['user_id' => $this->user->id, 'import_id' => $this->import->id,
                'glucose_mgdl' => 100 + $i, ...eventTime($at)]);
        }

        expect(SensorReading::betweenLocalDates('2026-07-29', '2026-07-29')->count())->toBe(1);
        expect(SensorReading::chronological()->first()->glucose_mgdl)->toBe(100);
    });

    it('scopeDelivered exclui cancelados — a base de qualquer soma', function () {
        $base = ['user_id' => $this->user->id, 'import_id' => $this->import->id, 'kind' => 'bolus_normal'];

        InsulinDose::create([...$base, 'units_delivered' => 8.0, ...eventTime('2026-07-29 08:00:00'),
            'dedupe_key' => InsulinDose::makeDedupeKey('2026-07-29 08:00:00', 'bolus_normal', 1),
            'bolus_number' => 1]);

        InsulinDose::create([...$base, 'cancellation_reason' => 'User Request',
            ...eventTime('2026-07-29 09:00:00'),
            'dedupe_key' => InsulinDose::makeDedupeKey('2026-07-29 09:00:00', 'bolus_normal', 2),
            'bolus_number' => 2]);

        expect(InsulinDose::count())->toBe(2);
        expect(InsulinDose::delivered()->count())->toBe(1);
        expect((float) InsulinDose::delivered()->sum('units_delivered'))->toBe(8.0);
    });

    it('fingerprint de configuração é estável independente da ordem', function () {
        $a = DeviceSettingsSnapshot::makeFingerprint([7 => 5.0, 12 => 6.0], [30.0, 20.0], null);
        $b = DeviceSettingsSnapshot::makeFingerprint([12 => 6.0, 7 => 5.0], [20.0, 30.0], null);

        expect($a)->toBe($b);
    });
});

describe('portabilidade — Artigo IX', function () {

    it('as nove tabelas existem', function () {
        foreach ([
            'imports', 'device_settings_snapshots', 'sensor_readings', 'bg_readings',
            'meals', 'insulin_doses', 'daily_auto_insulin', 'basal_rates', 'device_events',
        ] as $table) {
            expect(Schema::hasTable($table))->toBeTrue("tabela {$table} ausente");
        }
    });

    it('local_date e local_hour são colunas normais, não geradas', function () {
        // Colunas geradas quebrariam a portabilidade SQLite/MySQL (a sintaxe da
        // expressão difere). Se forem normais, dá para escrever nelas.
        $reading = SensorReading::create(['user_id' => $this->user->id,
            'import_id' => $this->import->id, 'glucose_mgdl' => 117, ...eventTime()]);

        DB::table('sensor_readings')->where('id', $reading->id)->update(['local_hour' => 23]);

        expect(SensorReading::find($reading->id)->local_hour)->toBe(23);
    });
});

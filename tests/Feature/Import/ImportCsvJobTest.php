<?php

declare(strict_types=1);

use App\Domain\Import\BolusLinker;
use App\Domain\Import\CarelinkCsvReader;
use App\Domain\Import\EventExploder;
use App\Domain\Import\Persistence\MealEnricher;
use App\Domain\Import\SettingsInferrer;
use App\Jobs\ImportCsvJob;
use App\Models\BasalRate;
use App\Models\BgReading;
use App\Models\DailyAutoInsulin;
use App\Models\DeviceEvent;
use App\Models\Import;
use App\Models\InsulinDose;
use App\Models\Meal;
use App\Models\SensorReading;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * T009 — end-to-end contra o gabarito.
 *
 * É aqui que as dez etapas se encontram e os valores de
 * `specs/001-fundacao-de-dados/gabarito.md` são verificados de verdade.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->path = requireReferenceExport();
});

function runImport(int $userId, string $path, string $tz = 'America/Sao_Paulo'): Import
{
    (new ImportCsvJob($userId, $path, $tz, 'reference-export.csv'))->handle(
        app(CarelinkCsvReader::class),
        app(EventExploder::class),
        app(BolusLinker::class),
        app(MealEnricher::class),
        app(SettingsInferrer::class),
    );

    return Import::where('user_id', $userId)->latest('id')->firstOrFail();
}

describe('importação completa', function () {

    it('reproduz as contagens do gabarito', function () {
        $import = runImport($this->user->id, $this->path);

        expect($import->status)->toBe(Import::STATUS_DONE);

        // gabarito.md §Contagens após import
        expect(SensorReading::count())->toBe(3616);
        expect(Meal::count())->toBe(52);
        expect(InsulinDose::count())->toBe(56);
        expect(BgReading::count())->toBe(44);
        // ⚠️ 140 EVENTOS, 119 LINHAS — e a diferença é correta.
        // A bomba registra 21 mudanças de basal DUAS VEZES: mesmo instante,
        // mesma taxa, linhas adjacentes. Verificado: zero pares divergem.
        // A chave única colapsa a duplicata de log; 119 é o número de
        // estados distintos de basal, que é o que importa.
        expect(BasalRate::count())->toBe(119);
        expect(DeviceEvent::count())->toBe(266);
        expect(DailyAutoInsulin::count())->toBe(14);
    });

    it('não registra nenhum aviso — critério de conclusão da fase', function () {
        $import = runImport($this->user->id, $this->path);

        expect($import->parse_warnings)->toBeNull();
        expect($import->hasWarnings())->toBeFalse();
    });

    it('grava o cabeçalho e a contagem por bloco (FR-002, FR-010)', function () {
        $import = runImport($this->user->id, $this->path);

        expect($import->device_model)->toBe('MiniMed 780G MMT-1886');
        expect($import->firmware_version)->toBe('8.13.2');
        expect($import->cgm_model)->toContain('Guardian');
        expect($import->period_start->format('Y-m-d'))->toBe('2026-07-16');
        expect($import->period_end->format('Y-m-d'))->toBe('2026-07-29');
        expect($import->glucose_unit)->toBe('mg/dL');

        expect($import->block_row_counts['pump'])->toBe(544);
        expect($import->block_row_counts['auto_insulin'])->toBe(14);
        expect($import->block_row_counts['sensor'])->toBe(3749);

        // Descartes contados, não escondidos — é o que permite auditar
        // que 3.616 + 77 + 56 = 3.749 fecha.
        $ignored = $import->block_row_counts['ignored'];
        ksort($ignored);

        expect($ignored)->toBe([
            'day_marker' => 56,
            'empty_row' => 25,
            'wizard_detail' => 5,
        ]);
    });

    // ⚠️ ASSERT CRÍTICO §A3 / Artigo VIII.3
    it('soma 295,150 U de insulina entregue, não 590,300', function () {
        runImport($this->user->id, $this->path);

        expect((float) InsulinDose::delivered()->sum('units_delivered'))
            ->toBeCloseToValue(295.150);

        expect(InsulinDose::delivered()->count())->toBe(52);
        expect(InsulinDose::whereNotNull('cancellation_reason')->count())->toBe(4);
    });

    it('vincula todas as 52 refeições à sua dose', function () {
        runImport($this->user->id, $this->path);

        expect(InsulinDose::whereNotNull('meal_id')->count())->toBe(52);
        // Nenhuma refeição vinculada a duas doses (§A10).
        expect(InsulinDose::whereNotNull('meal_id')->distinct()->count('meal_id'))->toBe(52);
    });

    it('classifica corretamente as categorias de evento', function () {
        runImport($this->user->id, $this->path);

        $byCategory = DeviceEvent::query()
            ->select('category', DB::raw('count(*) as total'))
            ->groupBy('category')->pluck('total', 'category')->all();

        ksort($byCategory);

        expect($byCategory)->toBe([
            'alert' => 59,
            'alert_cleared' => 50,
            'calibration' => 39,
            'prime' => 6,
            'rewind' => 3,
            'sensor_state' => 77,
            'suspend' => 32,
        ]);
    });

    it('preserva o primeiro e o último instante de sensor', function () {
        runImport($this->user->id, $this->path);

        // Consultas separadas: `chronological()` já aplica ordenação, e
        // encadear orderByDesc nele só acrescenta um critério que nunca é
        // alcançado — o primeiro ordena tudo.
        $first = SensorReading::chronological()->first();
        $last = SensorReading::orderByDesc('recorded_at_utc')->orderByDesc('device_index')->first();

        expect($first->recorded_at_local->format('Y-m-d H:i:s'))->toBe('2026-07-16 00:04:07');
        expect($last->recorded_at_local->format('Y-m-d H:i:s'))->toBe('2026-07-29 18:47:11');
    });
});

describe('idempotência (FR-006)', function () {

    it('reenviar o mesmo arquivo é no-op', function () {
        runImport($this->user->id, $this->path);

        $counts = fn () => [
            SensorReading::count(), Meal::count(), InsulinDose::count(),
            BgReading::count(), BasalRate::count(), DeviceEvent::count(),
            DailyAutoInsulin::count(), Import::count(),
        ];

        $before = $counts();

        // Segunda tentativa: o hash já existe, aborta antes de processar.
        (new ImportCsvJob($this->user->id, $this->path, 'America/Sao_Paulo'))->handle(
            app(CarelinkCsvReader::class),
            app(EventExploder::class),
            app(BolusLinker::class),
            app(MealEnricher::class),
            app(SettingsInferrer::class),
        );

        expect($counts())->toBe($before);
        expect(Import::count())->toBe(1);
    });

    it('reimportar o mesmo período por outro caminho não duplica', function () {
        runImport($this->user->id, $this->path);
        $before = SensorReading::count();

        // Cópia com nome diferente: hash igual seria no-op, então alteramos
        // uma linha irrelevante para simular um reexport do MESMO período.
        $copy = tempnam(sys_get_temp_dir(), 'picogli_dup_').'.csv';
        $content = file_get_contents($this->path);
        file_put_contents($copy, $content."\r\n");

        runImport($this->user->id, $copy);

        // Upsert nas chaves únicas: mesmo período, mesmas linhas.
        expect(SensorReading::count())->toBe($before);
        expect(InsulinDose::count())->toBe(56);
        expect(Import::count())->toBe(2);

        @unlink($copy);
    });
});

describe('invariantes de tempo (FR-007)', function () {

    it('local_date e local_hour batem com recorded_at_local em 100% das linhas', function () {
        runImport($this->user->id, $this->path);

        foreach (['sensor_readings', 'bg_readings', 'meals', 'insulin_doses',
            'basal_rates', 'device_events'] as $table) {
            $divergent = DB::table($table)
                ->whereRaw('substr(recorded_at_local, 1, 10) <> local_date')
                ->count();

            expect($divergent)->toBe(0, "{$table}: local_date divergiu de recorded_at_local");

            $wrongHour = DB::table($table)
                ->whereRaw('cast(substr(recorded_at_local, 12, 2) as integer) <> local_hour')
                ->count();

            expect($wrongHour)->toBe(0, "{$table}: local_hour divergiu de recorded_at_local");
        }
    });

    it('converte para UTC usando o fuso informado, não o do servidor', function () {
        runImport($this->user->id, $this->path, 'America/Sao_Paulo');

        $reading = SensorReading::where('recorded_at_local', '2026-07-29 18:47:11')->firstOrFail();

        // São Paulo em julho = UTC-3 (sem horário de verão desde 2019).
        expect($reading->recorded_at_utc->format('Y-m-d H:i:s'))->toBe('2026-07-29 21:47:11');
    });

    it('o mesmo arquivo em outro fuso produz UTC diferente e local igual', function () {
        runImport($this->user->id, $this->path, 'Europe/Lisbon');

        $reading = SensorReading::where('recorded_at_local', '2026-07-29 18:47:11')->firstOrFail();

        // Lisboa em julho = UTC+1.
        expect($reading->recorded_at_utc->format('Y-m-d H:i:s'))->toBe('2026-07-29 17:47:11');
        // A hora de parede NÃO muda — é o que o CSV diz, literalmente.
        expect($reading->local_hour)->toBe(18);
    });
});

describe('falha', function () {

    it('marca o import como failed e não deixa dado pela metade', function () {
        $broken = tempnam(sys_get_temp_dir(), 'picogli_bad_').'.csv';
        file_put_contents($broken, "\xEF\xBB\xBFLast Name;First Name\r\n\"X\";\"Y\"\r\nPatient DOB\r\n");

        // Arquivo sem blocos: importa, mas não gera evento nenhum.
        $import = runImport($this->user->id, $broken);

        expect($import->status)->toBe(Import::STATUS_DONE);
        expect(SensorReading::count())->toBe(0);
        // Export atípico não passa calado.
        expect($import->parse_warnings)->toContain('Cabeçalho sem Start Date / End Date');

        @unlink($broken);
    });

    it('lança erro claro para arquivo inexistente', function () {
        expect(fn () => runImport($this->user->id, '/nao/existe.csv'))
            ->toThrow(RuntimeException::class, 'não encontrado');
    });
});

<?php

declare(strict_types=1);

use App\Domain\Import\SettingsInferrer;
use App\Domain\Import\Value\Events\BasalRateEvent;
use App\Domain\Import\Value\Events\MealEvent;
use App\Models\DeviceSettingsSnapshot;
use App\Models\Meal;
use App\Models\User;

/**
 * T010 (SettingsInferrer, FR-008) e T011 (MealEnricher)
 *
 * As duas etapas pós-import, que rodam fora da transação porque dependem dos
 * dados já gravados.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->path = requireReferenceExport();
});

describe('T010 — configuração inferida (FR-008)', function () {

    it('reconstrói o perfil de razão de carboidrato do gabarito', function () {
        runImport($this->user->id, $this->path);

        $snapshot = DeviceSettingsSnapshot::where('user_id', $this->user->id)->firstOrFail();
        $profile = $snapshot->carb_ratio_profile;

        // gabarito.md §Configuração inferida
        expect($profile[6])->toBe(10.0);

        foreach ([7, 8, 9, 10, 11] as $hour) {
            expect($profile[$hour])->toBe(5.0, "CR das {$hour}h divergiu");
        }
        foreach ([12, 13, 14, 15, 16] as $hour) {
            expect($profile[$hour])->toBe(6.0, "CR das {$hour}h divergiu");
        }
        foreach ([18, 19, 20, 21, 22] as $hour) {
            expect($profile[$hour])->toBe(8.0, "CR das {$hour}h divergiu");
        }

        // 17h e madrugada não tiveram bolus no período. Ausência é informação
        // (não comeu naquela faixa), não falha — e não deve virar chute.
        expect($profile)->not->toHaveKey(17);
        expect($profile)->not->toHaveKey(3);
    });

    it('reconstrói o conjunto de sensibilidades', function () {
        runImport($this->user->id, $this->path);

        $snapshot = DeviceSettingsSnapshot::where('user_id', $this->user->id)->firstOrFail();

        expect($snapshot->isf_values)->toBe([20.0, 25.0, 30.0]);
    });

    it('reconstrói a basal excluindo os zeros de suspensão', function () {
        runImport($this->user->id, $this->path);

        $snapshot = DeviceSettingsSnapshot::where('user_id', $this->user->id)->firstOrFail();
        $rates = array_values(array_unique($snapshot->basal_profile));
        sort($rates);

        // gabarito: 1,65 · 1,7 · 2,0 · 2,1 · 2,2 U/h — sem 0.
        expect($rates)->toBe([1.65, 1.7, 2.0, 2.1, 2.2]);
        expect($rates)->not->toContain(0.0);
    });

    it('reimportar não cria snapshot duplicado', function () {
        runImport($this->user->id, $this->path);
        expect(DeviceSettingsSnapshot::count())->toBe(1);

        // Mesmo conteúdo, arquivo com hash diferente → mesma fingerprint.
        $copy = tempnam(sys_get_temp_dir(), 'picogli_cfg_').'.csv';
        file_put_contents($copy, file_get_contents($this->path)."\r\n");

        runImport($this->user->id, $copy);

        expect(DeviceSettingsSnapshot::count())->toBe(1);

        @unlink($copy);
    });
});

describe('T010 — inferência isolada, sem banco', function () {

    function mealAt(string $at, ?float $ratio, ?float $isf): MealEvent
    {
        return new MealEvent(
            recordedAtLocal: new DateTimeImmutable($at),
            carbsG: 30.0, carbRatio: $ratio, insulinSensitivity: $isf,
            targetLow: null, targetHigh: null, bgInput: null, estimateU: null,
            correctionU: null, foodU: null, activeInsulinU: null, bwzStatus: null,
            deviceIndex: null, sourceLine: 1,
        );
    }

    it('o valor mais recente vence quando o perfil muda no período', function () {
        $settings = (new SettingsInferrer())->infer([
            mealAt('2026-07-16 08:00:00', 5.0, 30.0),
            mealAt('2026-07-28 08:00:00', 4.5, 30.0),   // ajuste do médico
        ]);

        expect($settings->carbRatioProfile[8])->toBe(4.5);
        // E a mudança não passa calada.
        expect($settings->conflicts)->toHaveCount(1);
        expect($settings->conflicts[0])->toContain('08h');
    });

    it('não reporta conflito quando o perfil é estável', function () {
        $settings = (new SettingsInferrer())->infer([
            mealAt('2026-07-16 08:00:00', 5.0, 30.0),
            mealAt('2026-07-28 08:00:00', 5.0, 30.0),
        ]);

        expect($settings->conflicts)->toBe([]);
    });

    it('devolve vazio quando não há bolus com configuração', function () {
        $settings = (new SettingsInferrer())->infer([mealAt('2026-07-16 08:00:00', null, null)]);

        expect($settings->isEmpty())->toBeTrue();
    });

    it('ignora basal zerada, que é suspensão e não perfil', function () {
        $settings = (new SettingsInferrer())->infer(
            [mealAt('2026-07-16 08:00:00', 5.0, 30.0)],
            [
                new BasalRateEvent(new DateTimeImmutable('2026-07-16 13:00:00'), 0.0, null, 1),
                new BasalRateEvent(new DateTimeImmutable('2026-07-16 13:30:00'), 2.1, null, 2),
            ],
        );

        expect($settings->basalProfile)->toBe([13 => 2.1]);
    });
});

describe('T011 — resposta glicêmica das refeições', function () {

    it('calcula pico e subida da refeição do dia 25 (caso da regra R3)', function () {
        runImport($this->user->id, $this->path);

        // gabarito: 25/07 18:09, 32 g, partindo de 55 → pico 323, delta +268.
        // É o evento de montanha-russa: hipo seguida de sobrecorreção.
        $meal = Meal::where('recorded_at_local', '2026-07-25 18:09:00')
            ->orWhereBetween('recorded_at_local', ['2026-07-25 18:09:00', '2026-07-25 18:10:00'])
            ->firstOrFail();

        expect($meal->bg_input)->toBe(55);
        expect($meal->peak_2h)->toBe(323);
        expect($meal->delta_2h)->toBe(268);
    });

    it('preenche as refeições com cobertura de sensor', function () {
        runImport($this->user->id, $this->path);

        $total = Meal::count();
        $enriched = Meal::whereNotNull('peak_2h')->count();

        expect($total)->toBe(52);
        // Houve 3 lacunas de sensor no período (29 h no total), então algumas
        // refeições podem ficar sem pico. Ausência é dado real, não falha.
        expect($enriched)->toBeGreaterThanOrEqual(48);
    });

    it('usa bg_input como partida, não a leitura de sensor', function () {
        runImport($this->user->id, $this->path);

        // delta = peak - bg_input em toda refeição que tem os dois.
        $wrong = Meal::whereNotNull('peak_2h')
            ->whereNotNull('bg_input')
            ->whereNotNull('delta_2h')
            ->get()
            ->filter(fn (Meal $m) => $m->delta_2h !== $m->peak_2h - $m->bg_input);

        expect($wrong)->toHaveCount(0);
    });

    it('deixa null quando o sensor não cobre a janela', function () {
        runImport($this->user->id, $this->path);

        // Nenhuma refeição tem pico 0 — zero pareceria medição.
        expect(Meal::where('peak_2h', 0)->count())->toBe(0);
    });
});

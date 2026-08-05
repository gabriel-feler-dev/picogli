<?php

declare(strict_types=1);

use App\Domain\Import\BlockType;
use App\Domain\Import\BolusLinker;
use App\Domain\Import\CarelinkCsvReader;
use App\Domain\Import\EventExploder;
use App\Domain\Import\Value\Events\BolusDeliveryEvent;
use App\Domain\Import\Value\Events\BolusRequestEvent;
use App\Domain\Import\Value\Events\MealEvent;

/**
 * T006.5 — FR-005 (Ligação do trio de bolus)
 *
 * Ver research.md §A3 e plan.md §BolusLinker.
 */
function localAt(string $time): DateTimeImmutable
{
    return new DateTimeImmutable("2026-07-29 {$time}");
}

function bolusRequest(string $time, ?float $selected, ?int $number, ?string $cancel = null, string $type = 'Normal'): BolusRequestEvent
{
    return new BolusRequestEvent(
        recordedAtLocal: localAt($time),
        bolusType: $type,
        rawSource: 'CLOSED_LOOP_BG_CORRECTION_AND_FOOD_BOLUS',
        unitsSelected: $selected,
        bolusNumber: $number,
        cancellationReason: $cancel,
        deviceIndex: 1.0,
        sourceLine: 10,
    );
}

function bolusDelivery(string $time, float $delivered, ?int $number, ?float $selected = null): BolusDeliveryEvent
{
    return new BolusDeliveryEvent(
        recordedAtLocal: localAt($time),
        bolusType: 'Normal',
        rawSource: 'CLOSED_LOOP_BG_CORRECTION_AND_FOOD_BOLUS',
        unitsSelected: $selected ?? $delivered,
        unitsDelivered: $delivered,
        bolusNumber: $number,
        deviceIndex: 2.0,
        sourceLine: 11,
    );
}

function mealEvent(string $time, float $carbs): MealEvent
{
    return new MealEvent(
        recordedAtLocal: localAt($time),
        carbsG: $carbs,
        carbRatio: 5.0, insulinSensitivity: 30.0,
        targetLow: 100, targetHigh: 120, bgInput: 162,
        estimateU: 8.0, correctionU: 0.0, foodU: 8.0, activeInsulinU: 0.0,
        bwzStatus: 'Delivered', deviceIndex: 3.0, sourceLine: 12,
    );
}

beforeEach(function () {
    $this->linker = new BolusLinker;
});

describe('o trio (§A3)', function () {

    it('consolida pedido + wizard + entrega numa ÚNICA dose', function () {
        $doses = $this->linker->link(
            [bolusRequest('11:49:09', 8.0, 85)],
            [bolusDelivery('11:54:31', 8.0, 85)],
            [mealEvent('11:49:09', 40.0)],
        );

        // Uma dose, não duas. Se cada linha virasse registro, "quantos bolus
        // você tomou" daria o dobro.
        expect($doses)->toHaveCount(1);

        $dose = $doses[0];
        expect($dose->unitsSelected)->toBe(8.0);
        expect($dose->unitsDelivered)->toBe(8.0);
        expect($dose->bolusNumber)->toBe(85);
        expect($dose->kind)->toBe('bolus_normal');
        expect($dose->hasMeal())->toBeTrue();
        expect($dose->meal->carbsG)->toBe(40.0);
    });

    it('ancora a dose no timestamp do PEDIDO, guardando o da entrega', function () {
        $doses = $this->linker->link(
            [bolusRequest('11:49:09', 8.0, 85)],
            [bolusDelivery('11:54:31', 8.0, 85)],
            [],
        );

        // O instante do pedido é quando a pessoa agiu — e é o que casa com a
        // refeição. O da entrega fica preservado para insulina ativa.
        expect($doses[0]->recordedAtLocal->format('H:i:s'))->toBe('11:49:09');
        expect($doses[0]->deliveredAtLocal->format('H:i:s'))->toBe('11:54:31');
    });

    // A regra original exigia timestamp idêntico. O arquivo real mostrou que
    // em 2 de 52 casos a linha BWZ vem 1 segundo ANTES do pedido — a calculadora
    // termina e o pedido é registrado no segundo seguinte. Tolerância de 5s.
    it('liga refeição registrada 1 segundo antes do pedido', function () {
        $doses = $this->linker->link(
            [bolusRequest('08:52:00', 6.0, 166)],
            [bolusDelivery('08:56:00', 6.0, 166)],
            [mealEvent('08:51:59', 30.0)],
        );

        expect($doses[0]->hasMeal())->toBeTrue();
        expect($doses[0]->meal->carbsG)->toBe(30.0);
    });

    it('NÃO liga refeição fora da tolerância de 5 segundos', function () {
        $doses = $this->linker->link(
            [bolusRequest('11:49:09', 8.0, 85)],
            [bolusDelivery('11:54:31', 8.0, 85)],
            [mealEvent('11:49:20', 40.0)],
        );

        expect($doses[0]->hasMeal())->toBeFalse();
    });

    it('escolhe a refeição mais próxima, não a primeira encontrada', function () {
        $doses = $this->linker->link(
            [bolusRequest('11:49:09', 8.0, 85)],
            [],
            [mealEvent('11:49:05', 99.0), mealEvent('11:49:08', 40.0)],
        );

        expect($doses[0]->meal->carbsG)->toBe(40.0);
    });

    it('liga pedido a entrega por Bolus Number, não por proximidade', function () {
        // Números trocados de propósito: se ligasse por ordem/proximidade,
        // casaria errado.
        $doses = $this->linker->link(
            [bolusRequest('08:00:00', 3.0, 10), bolusRequest('08:05:00', 7.0, 20)],
            [bolusDelivery('08:10:00', 7.0, 20), bolusDelivery('08:12:00', 3.0, 10)],
            [],
        );

        $byNumber = [];
        foreach ($doses as $dose) {
            $byNumber[$dose->bolusNumber] = $dose;
        }

        expect($byNumber[10]->unitsDelivered)->toBe(3.0);
        expect($byNumber[20]->unitsDelivered)->toBe(7.0);
    });
});

describe('casos incompletos — todos são dado válido', function () {

    it('bolus cancelado fica sem volume algum', function () {
        $doses = $this->linker->link(
            [bolusRequest('11:48:01', null, 22, 'User Request')],
            [],
            [],
        );

        expect($doses)->toHaveCount(1);
        expect($doses[0]->isCancelled())->toBeTrue();
        expect($doses[0]->unitsSelected)->toBeNull();
        expect($doses[0]->unitsDelivered)->toBeNull();
        expect($doses[0]->cancellationReason)->toBe('User Request');
    });

    it('entrega sem pedido é dose válida, mas avisa', function () {
        $warnings = [];
        $doses = $this->linker->link(
            [],
            [bolusDelivery('11:54:31', 8.0, 85)],
            [],
            function (string $m) use (&$warnings) {
                $warnings[] = $m;
            },
        );

        expect($doses)->toHaveCount(1);
        expect($doses[0]->unitsDelivered)->toBe(8.0);
        expect($warnings)->toHaveCount(1);
        expect($warnings[0])->toContain('sem pedido correspondente');
    });

    it('refeição sem bolus no mesmo instante não gera dose fantasma', function () {
        $doses = $this->linker->link([], [], [mealEvent('11:49:09', 40.0)]);

        expect($doses)->toBe([]);
    });

    it('detecta entrega parcial sem confundir com cancelamento', function () {
        $doses = $this->linker->link(
            [bolusRequest('08:00:00', 8.0, 30)],
            [bolusDelivery('08:05:00', 5.5, 30, selected: 8.0)],
            [],
        );

        expect($doses[0]->isPartial())->toBeTrue();
        expect($doses[0]->isCancelled())->toBeFalse();
    });

    it('avisa em vez de escolher quando há duas refeições no mesmo instante', function () {
        $warnings = [];
        $this->linker->link(
            [bolusRequest('11:49:09', 8.0, 85)],
            [bolusDelivery('11:54:31', 8.0, 85)],
            [mealEvent('11:49:09', 40.0), mealEvent('11:49:09', 20.0)],
            function (string $m) use (&$warnings) {
                $warnings[] = $m;
            },
        );

        expect($warnings)->toHaveCount(1);
        expect($warnings[0])->toContain('ambíguo');
    });
});

describe('classificação de origem e tipo', function () {

    // ⚠️ "CLOSED_LOOP" no nome NÃO significa automático.
    it('não marca bolus de refeição em loop fechado como automático', function () {
        $doses = $this->linker->link([bolusRequest('08:00:00', 8.0, 1)], [], []);

        expect($doses[0]->rawSource)->toBe('CLOSED_LOOP_BG_CORRECTION_AND_FOOD_BOLUS');
        expect($doses[0]->isAutomatic)->toBeFalse();
    });

    it('mapeia tipos de bolus sem chutar normal como padrão', function () {
        $square = $this->linker->link([bolusRequest('08:00:00', 8.0, 1, type: 'Square')], [], []);
        $dual = $this->linker->link([bolusRequest('08:00:00', 8.0, 1, type: 'Dual (spike)')], [], []);
        $unknown = $this->linker->link([bolusRequest('08:00:00', 8.0, 1, type: 'Futuro X')], [], []);

        expect($square[0]->kind)->toBe('bolus_square');
        expect($dual[0]->kind)->toBe('bolus_dual');
        // Tipo novo NÃO vira bolus_normal — isso distorceria insulina ativa.
        expect($unknown[0]->kind)->toBe('bolus_futuro_x');
    });
});

describe('contra o arquivo real', function () {

    it('reproduz o gabarito de doses e o assert crítico de 295,150 U', function () {
        $path = requireReferenceExport();
        $reader = new CarelinkCsvReader;
        $exploder = new EventExploder;

        $requests = [];
        $deliveries = [];
        $meals = [];

        foreach ($reader->streamRows($path) as $row) {
            if ($row->block !== BlockType::Pump) {
                continue;
            }

            foreach ($exploder->explode($row)->events as $event) {
                match (true) {
                    $event instanceof BolusRequestEvent => $requests[] = $event,
                    $event instanceof BolusDeliveryEvent => $deliveries[] = $event,
                    $event instanceof MealEvent => $meals[] = $event,
                    default => null,
                };
            }
        }

        expect($requests)->toHaveCount(56);
        expect($deliveries)->toHaveCount(52);
        expect($meals)->toHaveCount(52);

        $warnings = [];
        $doses = $this->linker->link(
            $requests,
            $deliveries,
            $meals,
            function (string $m) use (&$warnings) {
                $warnings[] = $m;
            },
        );

        // Pareamento limpo: nenhuma entrega órfã, nenhum número duplicado,
        // nenhuma refeição ambígua. Critério de conclusão da fase.
        expect($warnings)->toBe([]);

        // gabarito.md: 56 doses = 52 entregues + 4 cancelados
        expect($doses)->toHaveCount(56);

        $cancelled = array_filter($doses, fn ($d) => $d->isCancelled());
        $withDelivery = array_filter($doses, fn ($d) => $d->unitsDelivered !== null);
        $withMeal = array_filter($doses, fn ($d) => $d->hasMeal());
        $partial = array_filter($doses, fn ($d) => $d->isPartial());
        $automatic = array_filter($doses, fn ($d) => $d->isAutomatic);

        expect($cancelled)->toHaveCount(4);
        expect($withDelivery)->toHaveCount(52);
        expect($partial)->toHaveCount(0);

        // Nenhum bolus é automático: a insulina automática do SmartGuard vem
        // agregada por dia no bloco 2, não como bolus.
        expect($automatic)->toHaveCount(0);

        // ⚠️ ASSERT CRÍTICO §A3 / Artigo VIII.3
        $delivered = array_sum(array_map(fn ($d) => $d->unitsDelivered ?? 0.0, $doses));
        $selected = array_sum(array_map(fn ($d) => $d->unitsSelected ?? 0.0, $doses));

        expect($delivered)->toBeCloseToValue(295.150);
        // Se alguém somar os dois por engano, dá 590,300 — o bug §A3.
        expect($delivered + $selected)->toBeCloseToValue(590.300);

        // Por origem (gabarito.md §Insulina)
        $bySource = [];
        foreach ($doses as $dose) {
            if ($dose->unitsDelivered === null) {
                continue;
            }
            $bySource[$dose->rawSource] = ($bySource[$dose->rawSource] ?? 0.0) + $dose->unitsDelivered;
        }

        expect($bySource['CLOSED_LOOP_BG_CORRECTION_AND_FOOD_BOLUS'])->toBeCloseToValue(266.275);
        expect($bySource['BOLUS_WIZARD'])->toBeCloseToValue(28.875);

        // Toda refeição do arquivo encontrou seu bolus por timestamp exato.
        expect($withMeal)->toHaveCount(52);
    });
});

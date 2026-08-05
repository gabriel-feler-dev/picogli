<?php

declare(strict_types=1);

use App\Domain\Import\BlockType;
use App\Domain\Import\CarelinkCsvReader;
use App\Domain\Import\EventExploder;
use App\Domain\Import\LocaleNormalizer;
use App\Domain\Import\Value\CsvRow;
use App\Domain\Import\Value\Events\BasalRateEvent;
use App\Domain\Import\Value\Events\BgReadingEvent;
use App\Domain\Import\Value\Events\BolusDeliveryEvent;
use App\Domain\Import\Value\Events\BolusRequestEvent;
use App\Domain\Import\Value\Events\DailyAutoInsulinEvent;
use App\Domain\Import\Value\Events\DeviceEvent;
use App\Domain\Import\Value\Events\DeviceEventCategory;
use App\Domain\Import\Value\Events\IgnoredReason;
use App\Domain\Import\Value\Events\MealEvent;
use App\Domain\Import\Value\Events\SensorReadingEvent;

/**
 * T005.4 — FR-004 (Explosão de linhas em eventos tipados)
 *
 * A tabela de decisão está em plan.md §EventExploder e é normativa.
 */

/** Colunas do arquivo real, na ordem, para montar CsvRow em teste. */
function exploderColumns(): array
{
    return [
        'Index', 'Date', 'Time', 'New Device Time', 'BG Source', 'BG Reading (mg/dL)',
        'Linked BG Meter ID', 'Basal Rate (U/h)', 'Temp Basal Amount', 'Temp Basal Type',
        'Temp Basal Duration (h:mm:ss)', 'Bolus Type', 'Bolus Volume Selected (U)',
        'Bolus Volume Delivered (U)', 'Bolus Duration (h:mm:ss)', 'Prime Type',
        'Prime Volume Delivered (U)', 'Estimated Reservoir Volume after Fill (U)',
        'Alert', 'User Cleared Alerts', 'Suspend', 'Rewind', 'BWZ Estimate (U)',
        'BWZ Target High BG (mg/dL)', 'BWZ Target Low BG (mg/dL)', 'BWZ Carb Ratio (g/U)',
        'BWZ Insulin Sensitivity (mg/dL/U)', 'BWZ Carb Input (grams)',
        'BWZ BG/SG Input (mg/dL)', 'BWZ Correction Estimate (U)', 'BWZ Food Estimate (U)',
        'BWZ Active Insulin (U)', 'BWZ Status', 'Sensor Calibration BG (mg/dL)',
        'Sensor Glucose (mg/dL)', 'ISIG Value', 'Event Marker', 'Bolus Number',
        'Bolus Cancellation Reason', 'BWZ Unabsorbed Insulin Total (U)',
        'Final Bolus Estimate', 'Scroll Step Size', 'Insulin Action Curve Time',
        'Sensor Calibration Rejected Reason', 'Preset Bolus', 'Bolus Source',
        'BLE Network Device', 'Device Update Event', 'Network Device Associated Reason',
        'Network Device Disassociated Reason', 'Network Device Disconnected Reason',
        'Sensor Exception', 'Preset Temp Basal Name', 'Sensor State',
    ];
}

/** Monta uma CsvRow com as colunas informadas por nome. */
function row(BlockType $block, array $values, string $date = '2026/07/29', string $time = '12:00:00'): CsvRow
{
    $columns = exploderColumns();
    $index = array_combine($columns, array_keys($columns));

    $fields = array_fill(0, count($columns), '');
    $fields[$index['Index']] = '1,00000';
    $fields[$index['Date']] = $date;
    $fields[$index['Time']] = $time;

    foreach ($values as $column => $value) {
        $fields[$index[$column]] = $value;
    }

    return new CsvRow($block, $index, $fields, new LocaleNormalizer, 42);
}

beforeEach(function () {
    $this->exploder = new EventExploder;
});

describe('bloco Sensor', function () {

    it('gera SensorReadingEvent quando há glicose', function () {
        $r = $this->exploder->explode(row(BlockType::Sensor, [
            'Sensor Glucose (mg/dL)' => '117',
            'ISIG Value' => '25,74',
        ]));

        expect($r->events)->toHaveCount(1);
        expect($r->events[0])->toBeInstanceOf(SensorReadingEvent::class);
        expect($r->events[0]->glucoseMgdl)->toBe(117);
        expect($r->events[0]->isig)->toBe(25.74);
    });

    // Isto é o que explica 3.749 linhas produzirem 3.616 leituras.
    it('NÃO gera leitura para linha de Sensor Exception sem glicose', function () {
        $r = $this->exploder->explode(row(BlockType::Sensor, [
            'Sensor Exception' => 'SENSOR_INIT_CODE',
        ]));

        expect($r->events)->toHaveCount(1);
        expect($r->events[0])->toBeInstanceOf(DeviceEvent::class);
        expect($r->events[0]->category)->toBe(DeviceEventCategory::SensorState);
        expect($r->events[0]->code)->toBe('SENSOR_INIT_CODE');
    });

    it('descarta marcador de dia sem virar warning', function (string $marker) {
        $r = $this->exploder->explode(row(BlockType::Sensor, ['Event Marker' => $marker]));

        expect($r->producedEvents())->toBeFalse();
        expect($r->ignoredReason)->toBe(IgnoredReason::DayMarker);
        expect($r->isWarning())->toBeFalse();
    })->with(['Start of the day', 'End of the day']);
});

describe('bloco AutoInsulin', function () {

    it('gera total diário, não bolus pontual', function () {
        $r = $this->exploder->explode(row(
            BlockType::AutoInsulin,
            ['Bolus Type' => 'Normal', 'Bolus Volume Delivered (U)' => '27,895',
                'Bolus Source' => 'CLOSED_LOOP_AUTO_INSULIN'],
            time: '00:00:00',
        ));

        expect($r->events)->toHaveCount(1);
        expect($r->events[0])->toBeInstanceOf(DailyAutoInsulinEvent::class);
        expect($r->events[0]->unitsDelivered)->toBe(27.895);
        expect($r->events[0]->localDate())->toBe('2026-07-29');
    });

    // ⚠️ O erro que isto previne: se a detecção de bloco confundir AutoInsulin
    // com Pump, o total DIÁRIO entra como bolus comum e infla a insulina do dia.
    it('não produz BolusDeliveryEvent para linha de insulina automática', function () {
        $r = $this->exploder->explode(row(
            BlockType::AutoInsulin,
            ['Bolus Volume Delivered (U)' => '27,895', 'Bolus Source' => 'CLOSED_LOOP_AUTO_INSULIN'],
        ));

        foreach ($r->events as $e) {
            expect($e)->not->toBeInstanceOf(BolusDeliveryEvent::class);
        }
    });
});

describe('bloco Pump — bolus (§A3)', function () {

    it('gera entrega quando Delivered está preenchido', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'Bolus Type' => 'Normal',
            'Bolus Volume Selected (U)' => '8,0',
            'Bolus Volume Delivered (U)' => '8,0',
            'Bolus Number' => '85',
            'Bolus Source' => 'CLOSED_LOOP_BG_CORRECTION_AND_FOOD_BOLUS',
        ]));

        expect($r->events)->toHaveCount(1);
        expect($r->events[0])->toBeInstanceOf(BolusDeliveryEvent::class);
        expect($r->events[0]->unitsDelivered)->toBe(8.0);
        expect($r->events[0]->bolusNumber)->toBe(85);
        expect($r->events[0]->isPartial())->toBeFalse();
    });

    it('gera pedido quando há Selected mas não Delivered', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'Bolus Type' => 'Normal',
            'Bolus Volume Selected (U)' => '8,0',
            'Bolus Number' => '85',
        ]));

        expect($r->events)->toHaveCount(1);
        expect($r->events[0])->toBeInstanceOf(BolusRequestEvent::class);
        expect($r->events[0]->unitsSelected)->toBe(8.0);
        expect($r->events[0]->isCancelled())->toBeFalse();
    });

    // Decisão registrada em plan.md §Bolus cancelado.
    it('gera pedido cancelado sem volume nenhum', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'Bolus Type' => 'Normal',
            'Bolus Number' => '22',
            'Bolus Cancellation Reason' => 'User Request',
        ]));

        expect($r->events)->toHaveCount(1);
        expect($r->events[0])->toBeInstanceOf(BolusRequestEvent::class);
        expect($r->events[0]->isCancelled())->toBeTrue();
        expect($r->events[0]->unitsSelected)->toBeNull();
        expect($r->events[0]->cancellationReason)->toBe('User Request');
    });

    it('detecta entrega parcial (Selected ≠ Delivered)', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'Bolus Volume Selected (U)' => '8,0',
            'Bolus Volume Delivered (U)' => '5,5',
        ]));

        expect($r->events[0]->isPartial())->toBeTrue();
    });
});

describe('bloco Pump — refeição, capilar, calibração, basal', function () {

    it('gera refeição com a configuração vigente', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'BWZ Estimate (U)' => '8,0',
            'BWZ Carb Ratio (g/U)' => '5,00',
            'BWZ Carb Input (grams)' => '40,00',
            'BWZ BG/SG Input (mg/dL)' => '162',
            'BWZ Correction Estimate (U)' => '0',
            'BWZ Food Estimate (U)' => '8,0',
            'BWZ Status' => 'Delivered',
        ]));

        expect($r->events)->toHaveCount(1);
        expect($r->events[0])->toBeInstanceOf(MealEvent::class);
        expect($r->events[0]->carbsG)->toBe(40.0);
        expect($r->events[0]->carbRatio)->toBe(5.0);
        expect($r->events[0]->bgInput)->toBe(162);
    });

    it('ignora carboidrato zero', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, ['BWZ Carb Input (grams)' => '0']));

        expect($r->producedEvents())->toBeFalse();
    });

    it('marca capilar enviada para calibração', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'BG Source' => 'BG_SENT_FOR_CALIB',
            'BG Reading (mg/dL)' => '193',
        ]));

        expect($r->events[0])->toBeInstanceOf(BgReadingEvent::class);
        expect($r->events[0]->usedForCalibration)->toBeTrue();
    });

    it('não marca capilar digitada à mão como calibração', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'BG Source' => 'ENTERED_IN_BG_ENTRY',
            'BG Reading (mg/dL)' => '140',
        ]));

        expect($r->events[0]->usedForCalibration)->toBeFalse();
    });

    // A calibração ACEITA vem em linha separada da capilar enviada.
    it('gera evento de calibração aceita pelo sensor', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'Sensor Calibration BG (mg/dL)' => '193',
        ]));

        expect($r->events)->toHaveCount(1);
        expect($r->events[0])->toBeInstanceOf(DeviceEvent::class);
        expect($r->events[0]->category)->toBe(DeviceEventCategory::Calibration);
        expect($r->events[0]->payload)->toBe(['glucose_mgdl' => 193]);
    });

    it('gera basal manual', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, ['Basal Rate (U/h)' => '2,0']));

        expect($r->events[0])->toBeInstanceOf(BasalRateEvent::class);
        expect($r->events[0]->rateUh)->toBe(2.0);
    });
});

describe('bloco Pump — eventos de dispositivo', function () {

    it('separa alerta disparado de alerta dispensado', function () {
        $fired = $this->exploder->explode(row(BlockType::Pump, ['Alert' => 'ALERT BEFORE LOW']));
        $cleared = $this->exploder->explode(row(BlockType::Pump, ['User Cleared Alerts' => 'RESERVOIR']));

        expect($fired->events[0]->category)->toBe(DeviceEventCategory::Alert);
        expect($cleared->events[0]->category)->toBe(DeviceEventCategory::AlertCleared);
    });

    it('gera rewind e suspend', function () {
        expect($this->exploder->explode(row(BlockType::Pump, ['Rewind' => 'Rewind']))
            ->events[0]->category)->toBe(DeviceEventCategory::Rewind);
        expect($this->exploder->explode(row(BlockType::Pump, ['Suspend' => 'suspend feed']))
            ->events[0]->category)->toBe(DeviceEventCategory::Suspend);
    });

    it('leva volume de prime no payload', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'Prime Type' => 'Fixed',
            'Prime Volume Delivered (U)' => '0,3',
            'Estimated Reservoir Volume after Fill (U)' => '180,0',
        ]));

        expect($r->events[0]->category)->toBe(DeviceEventCategory::Prime);
        expect($r->events[0]->payload)->toBe(['volume_u' => 0.3, 'reservoir_after_fill_u' => 180.0]);
    });
});

describe('condições não são mutuamente exclusivas (§A4)', function () {

    // Uma linha NÃO corresponde a um registro. Este é o teste que garante
    // que o exploder avalia TODAS as condições, não faz early return.
    it('gera dois eventos de uma linha com capilar E alerta', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'BG Source' => 'BG_SENT_FOR_CALIB',
            'BG Reading (mg/dL)' => '132',
            'Alert' => 'SMARTGUARD BG REQUIRED',
        ]));

        expect($r->events)->toHaveCount(2);
        expect($r->events[0])->toBeInstanceOf(BgReadingEvent::class);
        expect($r->events[1])->toBeInstanceOf(DeviceEvent::class);
    });

    it('gera três eventos de uma linha com bolus, refeição e basal', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'Bolus Volume Delivered (U)' => '8,0',
            'BWZ Carb Input (grams)' => '40,00',
            'Basal Rate (U/h)' => '2,0',
        ]));

        expect($r->events)->toHaveCount(3);
    });
});

describe('classificação de descarte', function () {

    it('linha só com Index/Date/Time é EmptyRow, sem warning', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, []));

        expect($r->ignoredReason)->toBe(IgnoredReason::EmptyRow);
        expect($r->isWarning())->toBeFalse();
    });

    it('metadado de wizard sem volume é WizardDetail, sem warning', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'Final Bolus Estimate' => '5,175',
            'BWZ Unabsorbed Insulin Total (U)' => '0',
            'Scroll Step Size' => 'STEP_0_POINT_025',
        ]));

        expect($r->ignoredReason)->toBe(IgnoredReason::WizardDetail);
        expect($r->isWarning())->toBeFalse();
    });

    it('timestamp inválido vira warning', function () {
        $r = $this->exploder->explode(row(
            BlockType::Sensor,
            ['Sensor Glucose (mg/dL)' => '117'],
            date: '2026/13/45',
        ));

        expect($r->ignoredReason)->toBe(IgnoredReason::InvalidTimestamp);
        expect($r->isWarning())->toBeTrue();
    });

    it('coluna nova não reconhecida vira warning', function () {
        $r = $this->exploder->explode(row(BlockType::Pump, [
            'Insulin Action Curve Time' => '4',
        ]));

        expect($r->ignoredReason)->toBe(IgnoredReason::Unrecognized);
        expect($r->isWarning())->toBeTrue();
    });
});

describe('contra o arquivo real', function () {

    it('reproduz as contagens de evento do gabarito', function () {
        $path = requireReferenceExport();
        $reader = new CarelinkCsvReader;

        $byType = [];
        $byCategory = [];
        $byIgnored = [];
        $delivered = 0.0;
        $rows = 0;
        $rowsWithEvents = 0;

        foreach ($reader->streamRows($path) as $row) {
            $rows++;
            $result = $this->exploder->explode($row);

            if (! $result->producedEvents()) {
                $key = $result->ignoredReason->value;
                $byIgnored[$key] = ($byIgnored[$key] ?? 0) + 1;

                continue;
            }

            $rowsWithEvents++;

            foreach ($result->events as $event) {
                $short = (new ReflectionClass($event))->getShortName();
                $byType[$short] = ($byType[$short] ?? 0) + 1;

                if ($event instanceof DeviceEvent) {
                    $byCategory[$event->category->value] = ($byCategory[$event->category->value] ?? 0) + 1;
                }
                if ($event instanceof BolusDeliveryEvent) {
                    $delivered += $event->unitsDelivered;
                }
            }
        }

        expect($rows)->toBe(4307);

        // ksort nos dois lados: o que importa são as contagens, não a ordem
        // em que os tipos apareceram no arquivo.
        ksort($byType);
        ksort($byCategory);
        ksort($byIgnored);

        // gabarito.md §Eventos gerados pelo EventExploder
        $expectedTypes = [
            'BasalRateEvent' => 140,
            'BgReadingEvent' => 44,
            'BolusDeliveryEvent' => 52,
            'BolusRequestEvent' => 56,
            'DailyAutoInsulinEvent' => 14,
            'DeviceEvent' => 266,
            'MealEvent' => 52,
            'SensorReadingEvent' => 3616,
        ];
        ksort($expectedTypes);

        expect($byType)->toBe($expectedTypes, 'contagens de evento divergiram do gabarito');

        $expectedCategories = [
            'alert' => 59,
            'alert_cleared' => 50,
            'calibration' => 39,
            'prime' => 6,
            'rewind' => 3,
            'sensor_state' => 77,
            'suspend' => 32,
        ];
        ksort($expectedCategories);

        expect($byCategory)->toBe($expectedCategories);
        expect(array_sum($byCategory))->toBe(266);

        expect($byIgnored)->toBe([
            'day_marker' => 56,
            'empty_row' => 25,
            'wizard_detail' => 5,
        ]);

        // ⚠️ CRITÉRIO DE CONCLUSÃO DA FASE: zero linhas não reconhecidas.
        expect($byIgnored)->not->toHaveKey('unrecognized');
        expect($byIgnored)->not->toHaveKey('invalid_timestamp');

        // Nenhuma linha se perdeu: toda linha ou gerou evento ou foi
        // classificada como descarte conhecido.
        expect($rowsWithEvents + array_sum($byIgnored))->toBe($rows);
        expect(array_sum($byIgnored))->toBe(86);
        expect(array_sum($byType))->toBe(4240);

        // ⚠️ ASSERT CRÍTICO DE §A3 — 295,150 U, não 590,300 U.
        expect($delivered)->toBeCloseToValue(295.150);
    });
});

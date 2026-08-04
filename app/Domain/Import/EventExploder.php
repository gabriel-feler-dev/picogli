<?php

declare(strict_types=1);

namespace App\Domain\Import;

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
use App\Domain\Import\Value\ExplosionResult;
use DateTimeImmutable;

/**
 * Converte uma linha do CSV em 0..N eventos tipados.
 *
 * ⚠️ Uma linha NÃO corresponde a um registro (§A4). O arquivo é uma grade
 * esparsa: cada linha é um timestamp com poucas colunas preenchidas, e a mesma
 * linha do bloco Pump pode ser simultaneamente uma leitura capilar E um alerta.
 *
 * As condições **não são mutuamente exclusivas** — todas são avaliadas.
 *
 * A tabela de decisão está em plan.md §EventExploder e é normativa. Se este
 * código divergir dela, um dos dois está errado; corrija explicitamente.
 */
final class EventExploder
{
    public function explode(CsvRow $row): ExplosionResult
    {
        $at = $row->recordedAtLocal();

        if ($at === null) {
            // Evento sem timestamp é inútil — e um timestamp que falhou de
            // parsear indica formato mudado. Vira warning, não silêncio.
            return ExplosionResult::ignored(IgnoredReason::InvalidTimestamp);
        }

        $events = match ($row->block) {
            BlockType::Sensor => $this->fromSensorBlock($row, $at),
            BlockType::AutoInsulin => $this->fromAutoInsulinBlock($row, $at),
            BlockType::Pump => $this->fromPumpBlock($row, $at),
        };

        if ($events !== []) {
            return ExplosionResult::of($events);
        }

        return ExplosionResult::ignored($this->classifyEmptyHanded($row));
    }

    /** @return list<\App\Domain\Import\Value\Events\ImportEvent> */
    private function fromSensorBlock(CsvRow $row, DateTimeImmutable $at): array
    {
        $events = [];

        $glucose = $row->int('Sensor Glucose (mg/dL)');

        if ($glucose !== null) {
            $events[] = new SensorReadingEvent(
                recordedAtLocal: $at,
                glucoseMgdl: $glucose,
                isig: $row->num('ISIG Value'),
                deviceIndex: $row->deviceIndex(),
                sourceLine: $row->lineNumber,
            );
        }

        // Exceção de sensor explica parte da diferença entre 3.749 linhas do
        // bloco e 3.616 leituras: warm-up, erro, fim de vida, calibração devida.
        $exception = $row->str('Sensor Exception');

        if ($exception !== null) {
            $events[] = new DeviceEvent(
                recordedAtLocal: $at,
                category: DeviceEventCategory::SensorState,
                code: $exception,
                payload: array_filter([
                    'sensor_state' => $row->str('Sensor State'),
                ], fn ($v) => $v !== null),
                deviceIndex: $row->deviceIndex(),
                sourceLine: $row->lineNumber,
            );
        }

        return $events;
    }

    /** @return list<\App\Domain\Import\Value\Events\ImportEvent> */
    private function fromAutoInsulinBlock(CsvRow $row, DateTimeImmutable $at): array
    {
        $units = $row->num('Bolus Volume Delivered (U)');

        if ($units === null) {
            return [];
        }

        return [new DailyAutoInsulinEvent(
            recordedAtLocal: $at,
            unitsDelivered: $units,
            deviceIndex: $row->deviceIndex(),
            sourceLine: $row->lineNumber,
        )];
    }

    /** @return list<\App\Domain\Import\Value\Events\ImportEvent> */
    private function fromPumpBlock(CsvRow $row, DateTimeImmutable $at): array
    {
        $events = [];
        $index = $row->deviceIndex();
        $line = $row->lineNumber;

        // ── Bolus (§A3) ────────────────────────────────────────────────────
        $delivered = $row->num('Bolus Volume Delivered (U)');
        $selected = $row->num('Bolus Volume Selected (U)');
        $cancellation = $row->str('Bolus Cancellation Reason');

        if ($delivered !== null) {
            $events[] = new BolusDeliveryEvent(
                recordedAtLocal: $at,
                bolusType: $row->str('Bolus Type'),
                rawSource: $row->str('Bolus Source'),
                unitsSelected: $selected,
                unitsDelivered: $delivered,
                bolusNumber: $row->int('Bolus Number'),
                deviceIndex: $index,
                sourceLine: $line,
            );
        } elseif ($selected !== null || $cancellation !== null) {
            // Pedido sem entrega. Dois casos, ambos legítimos:
            //   - $selected preenchido → pedido normal, entrega vem em outra linha
            //   - só $cancellation     → bolus cancelado, sem volume nenhum
            $events[] = new BolusRequestEvent(
                recordedAtLocal: $at,
                bolusType: $row->str('Bolus Type'),
                rawSource: $row->str('Bolus Source'),
                unitsSelected: $selected,
                bolusNumber: $row->int('Bolus Number'),
                cancellationReason: $cancellation,
                deviceIndex: $index,
                sourceLine: $line,
            );
        }

        // ── Refeição (linha BWZ) ───────────────────────────────────────────
        $carbs = $row->num('BWZ Carb Input (grams)');

        if ($carbs !== null && $carbs > 0) {
            $events[] = new MealEvent(
                recordedAtLocal: $at,
                carbsG: $carbs,
                carbRatio: $row->num('BWZ Carb Ratio (g/U)'),
                insulinSensitivity: $row->num('BWZ Insulin Sensitivity (mg/dL/U)'),
                targetLow: $row->int('BWZ Target Low BG (mg/dL)'),
                targetHigh: $row->int('BWZ Target High BG (mg/dL)'),
                bgInput: $row->int('BWZ BG/SG Input (mg/dL)'),
                estimateU: $row->num('BWZ Estimate (U)'),
                correctionU: $row->num('BWZ Correction Estimate (U)'),
                foodU: $row->num('BWZ Food Estimate (U)'),
                activeInsulinU: $row->num('BWZ Active Insulin (U)'),
                bwzStatus: $row->str('BWZ Status'),
                deviceIndex: $index,
                sourceLine: $line,
            );
        }

        // ── Glicemia capilar ───────────────────────────────────────────────
        $bg = $row->int('BG Reading (mg/dL)');

        if ($bg !== null) {
            $source = $row->str('BG Source');

            $events[] = new BgReadingEvent(
                recordedAtLocal: $at,
                glucoseMgdl: $bg,
                source: $source,
                usedForCalibration: $source === 'BG_SENT_FOR_CALIB',
                deviceIndex: $index,
                sourceLine: $line,
            );
        }

        // ── Calibração aceita pelo sensor ──────────────────────────────────
        // Linha distinta da glicemia enviada acima. 39 de cada no export de
        // referência. Para MARD, o valor relevante é este.
        $calibration = $row->int('Sensor Calibration BG (mg/dL)');

        if ($calibration !== null) {
            $events[] = new DeviceEvent(
                recordedAtLocal: $at,
                category: DeviceEventCategory::Calibration,
                code: 'SENSOR_CALIBRATION_ACCEPTED',
                payload: ['glucose_mgdl' => $calibration],
                deviceIndex: $index,
                sourceLine: $line,
            );
        }

        // ── Basal manual ───────────────────────────────────────────────────
        $basal = $row->num('Basal Rate (U/h)');

        if ($basal !== null) {
            $events[] = new BasalRateEvent(
                recordedAtLocal: $at,
                rateUh: $basal,
                deviceIndex: $index,
                sourceLine: $line,
            );
        }

        // ── Eventos de dispositivo por coluna ──────────────────────────────
        $events = [...$events, ...$this->deviceEvents($row, $at, $index, $line)];

        return $events;
    }

    /** @return list<DeviceEvent> */
    private function deviceEvents(CsvRow $row, DateTimeImmutable $at, ?float $index, int $line): array
    {
        $events = [];

        // `Alert` é o alerta disparando; `User Cleared Alerts` é o usuário
        // dispensando. Colunas e semânticas distintas — cruzá-las dá tempo de
        // resposta a alerta.
        $simple = [
            'Alert' => DeviceEventCategory::Alert,
            'User Cleared Alerts' => DeviceEventCategory::AlertCleared,
            'Suspend' => DeviceEventCategory::Suspend,
            'Rewind' => DeviceEventCategory::Rewind,
        ];

        foreach ($simple as $column => $category) {
            $code = $row->str($column);

            if ($code !== null) {
                $events[] = new DeviceEvent(
                    recordedAtLocal: $at,
                    category: $category,
                    code: $code,
                    payload: [],
                    deviceIndex: $index,
                    sourceLine: $line,
                );
            }
        }

        // Prime carrega volume, então vai no payload.
        $prime = $row->num('Prime Volume Delivered (U)');

        if ($prime !== null) {
            $events[] = new DeviceEvent(
                recordedAtLocal: $at,
                category: DeviceEventCategory::Prime,
                code: $row->str('Prime Type') ?? 'PRIME',
                payload: array_filter([
                    'volume_u' => $prime,
                    'reservoir_after_fill_u' => $row->num('Estimated Reservoir Volume after Fill (U)'),
                ], fn ($v) => $v !== null),
                deviceIndex: $index,
                sourceLine: $line,
            );
        }

        return $events;
    }

    /**
     * Classifica uma linha que não gerou nenhum evento.
     *
     * A ordem importa: só chega a `Unrecognized` o que não casou com nenhum
     * descarte conhecido — e é isso que vira `parse_warnings`. No export de
     * referência, `Unrecognized` deve ser ZERO (critério de conclusão da fase).
     */
    private function classifyEmptyHanded(CsvRow $row): IgnoredReason
    {
        if ($row->isEmptyEvent()) {
            return IgnoredReason::EmptyRow;
        }

        // `Event Marker` = "Start of the day" / "End of the day": delimitadores
        // do relatório, sem nenhuma medição. 56 linhas = 14 dias × 2.
        if ($row->filled('Event Marker')) {
            return IgnoredReason::DayMarker;
        }

        // Metadados da calculadora sem volume — duplicam o que já está na linha
        // do bolus correspondente. 5 linhas, pareando com os 5 bolus wizard.
        if ($row->filled('Final Bolus Estimate') || $row->filled('Scroll Step Size')) {
            return IgnoredReason::WizardDetail;
        }

        return IgnoredReason::Unrecognized;
    }
}

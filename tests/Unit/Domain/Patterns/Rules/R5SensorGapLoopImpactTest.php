<?php

declare(strict_types=1);

use App\Domain\Metrics\Value\SensorGap;
use App\Domain\Patterns\Rules\R5SensorGapLoopImpact;
use App\Domain\Patterns\Value\DailySnapshot;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\Severity;

/**
 * T305 — R5, no caso positivo E no negativo (§D5).
 */
function gapBetween(string $start, string $end): SensorGap
{
    $from = new DateTimeImmutable($start);
    $to = new DateTimeImmutable($end);

    return new SensorGap($from, $to, ($to->getTimestamp() - $from->getTimestamp()) / 60);
}

function dayWithInsulin(string $date, float $auto, float $bolus, float $coverage = 100.0): DailySnapshot
{
    return new DailySnapshot($date, 288, $coverage, 140.0, 85.0, 25.0, 1.0, 1.0, $auto, $bolus, 120.0);
}

/** O cenário do export de referência, em forma mínima. */
function gapDataset(array $overrides = []): PatternDataset
{
    return makePatternDataset(array_merge([
        'gaps' => [gapBetween('2026-07-21 17:29:00', '2026-07-22 15:56:00')],
        'autoInsulinByDate' => [
            '2026-07-20' => 29.3,
            '2026-07-21' => 25.3,
            '2026-07-22' => 9.0,
            '2026-07-23' => 29.5,
        ],
        'daily' => [
            dayWithInsulin('2026-07-20', 29.3, 20.0, 91.0),
            dayWithInsulin('2026-07-21', 25.3, 20.0, 73.0),
            dayWithInsulin('2026-07-22', 9.0, 24.0, 34.0),
            dayWithInsulin('2026-07-23', 29.5, 20.0, 100.0),
        ],
    ], $overrides));
}

$rule = fn () => new R5SensorGapLoopImpact(patternsConfig(), fakeProseRenderer());

describe('a atribuição da lacuna a um dia', function () use ($rule) {

    // ⚠️ A decisão que a regra precisa tomar: a lacuna atravessa a meia-noite, e
    // "o dia da lacuna" é ambíguo. Atribui-se ao dia com MAIS minutos de lacuna.
    it('escolhe o dia com mais minutos de lacuna, não o de início', function () use ($rule) {
        $findings = $rule()->evaluate(gapDataset());

        expect($findings)->toHaveCount(1);

        // 21/07 tem 6h31 de lacuna; 22/07 tem 15h56. O efeito medido é o
        // SmartGuard sem entrada, e ele é proporcional ao tempo sem sensor.
        expect($findings[0]->evidence['affected_date'])->toBe('2026-07-22');
        expect($findings[0]->evidence['gap_minutes_on_date'])->toBe(956);
        expect($findings[0]->evidence['gap_minutes'])->toBe(1347);
    });

    it('lacuna dentro de um único dia é atribuída a ele', function () use ($rule) {
        $findings = $rule()->evaluate(gapDataset([
            'gaps' => [gapBetween('2026-07-22 02:00:00', '2026-07-22 20:00:00')],
        ]));

        expect($findings[0]->evidence['affected_date'])->toBe('2026-07-22');
        expect($findings[0]->evidence['gap_minutes_on_date'])->toBe(1080);
    });

    it('lacuna que cobre três dias vai para o do meio', function () use ($rule) {
        $findings = $rule()->evaluate(gapDataset([
            'gaps' => [gapBetween('2026-07-21 22:00:00', '2026-07-23 04:00:00')],
        ]));

        // 21/07 tem 2 h, 22/07 tem 24 h, 23/07 tem 4 h.
        expect($findings[0]->evidence['affected_date'])->toBe('2026-07-22');
        expect($findings[0]->evidence['gap_minutes_on_date'])->toBe(1440);
    });
});

describe('a evidência', function () use ($rule) {

    it('registra a lacuna em MINUTOS, com as horas ao lado', function () use ($rule) {
        $evidence = $rule()->evaluate(gapDataset())[0]->evidence;

        // ⚠️ 1.347 min = 22,45 h fica em cima da borda de arredondamento: Python
        // formata 22,4 e PHP arredonda 22,5. O minuto é o dado.
        expect($evidence['gap_minutes'])->toBe(1347);
        expect($evidence['gap_hours'])->toBe(22.5);
        expect($evidence['gap_start'])->toBe('2026-07-21 17:29');
        expect($evidence['gap_end'])->toBe('2026-07-22 15:56');
    });

    it('traz a queda e as duas frações do total', function () use ($rule) {
        $evidence = $rule()->evaluate(gapDataset())[0]->evidence;

        expect($evidence['auto_insulin_u'])->toBe(9.0);
        // média de 29,3 · 25,3 · 9,0 · 29,5 = 23,275 → exibida como 23,3
        expect($evidence['period_mean_auto_insulin_u'])->toBe(23.3);

        // ⚠️ 61,3% e não 61,4%: a queda é calculada sobre a média EXATA (23,275),
        // nunca sobre a exibida. Mesma regra do MetricTranslator da fase 3 — a
        // comparação usa o valor exato, o arredondamento existe só para mostrar.
        // (1 − 9,0/23,275 = 61,33%, exibido com uma casa; se a conta usasse a
        // média já arredondada daria 61,37%, que exibe 61,4 e estaria errado.)
        expect($evidence['drop_percent'])->toBe(61.3);

        // 9,0 de 33,0 = 27,3% no dia, contra a fração do período.
        expect($evidence['day_automatic_fraction_percent'])->toBeCloseToValue(27.3, 0.1);
        // Somas do período: 93,1 U automáticas de 177,1 U totais = 52,6%. Somas,
        // e não média das frações diárias — um dia com pouca insulina total
        // pesaria igual a um dia cheio.
        expect($evidence['period_automatic_fraction_percent'])->toBe(52.6);
        expect($evidence['day_bolus_insulin_u'])->toBe(24.0);
        expect($evidence['day_coverage_percent'])->toBe(34.0);
    });

    // O achado é sobre o equipamento e já passou — não há nada agudo a fazer
    // retroativamente. `Priority` fica reservado para o que representa risco.
    it('é Attention', function () use ($rule) {
        expect($rule()->evaluate(gapDataset())[0]->severity)->toBe(Severity::Attention);
    });
});

describe('casos negativos (§D5)', function () use ($rule) {

    // ⚠️ FR-407 — o par de condições. Lacuna longa SEM queda não é achado.
    it('lacuna longa sem queda na automática NÃO dispara', function () use ($rule) {
        $findings = $rule()->evaluate(gapDataset([
            'autoInsulinByDate' => [
                '2026-07-21' => 30.0,
                '2026-07-22' => 29.0,   // praticamente na média
                '2026-07-23' => 31.0,
            ],
        ]));

        expect($findings)->toBe([]);
    });

    it('queda na automática sem lacuna longa NÃO dispara', function () use ($rule) {
        // Lacuna de 266 min — a terceira do export, abaixo dos 360 exigidos.
        $findings = $rule()->evaluate(gapDataset([
            'gaps' => [gapBetween('2026-07-22 16:51:00', '2026-07-22 21:17:00')],
        ]));

        expect($findings)->toBe([]);
    });

    it('nenhuma lacuna NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(gapDataset(['gaps' => []])))->toBe([]);
    });

    it('sem registro de insulina automática NÃO dispara', function () use ($rule) {
        // Sem o bloco 2 do CSV não há com o que comparar. `null` é "não sei",
        // não "a média é zero".
        expect($rule()->evaluate(gapDataset(['autoInsulinByDate' => []])))->toBe([]);
    });

    it('dia afetado sem registro no bloco 2 é ignorado', function () use ($rule) {
        $findings = $rule()->evaluate(gapDataset([
            'autoInsulinByDate' => ['2026-07-20' => 29.3, '2026-07-23' => 29.5],
        ]));

        expect($findings)->toBe([]);
    });

    it('queda exatamente no limiar dispara; abaixo não', function () use ($rule) {
        // Média de 20 e 20 → 20. Dia com 10 = queda de 50%, igual ao limiar.
        $noLimiar = $rule()->evaluate(gapDataset([
            'autoInsulinByDate' => ['2026-07-21' => 30.0, '2026-07-22' => 10.0],
        ]));

        expect($noLimiar)->toHaveCount(1);
        expect($noLimiar[0]->evidence['drop_percent'])->toBe(50.0);

        // Dia com 11 de média 20,5 → queda de 46,3%, abaixo de 50%.
        $abaixo = $rule()->evaluate(gapDataset([
            'autoInsulinByDate' => ['2026-07-21' => 30.0, '2026-07-22' => 11.0],
        ]));

        expect($abaixo)->toBe([]);
    });
});

it('emite um achado por lacuna qualificada', function () use ($rule) {
    $findings = $rule()->evaluate(gapDataset([
        'gaps' => [
            gapBetween('2026-07-21 17:29:00', '2026-07-22 15:56:00'),
            gapBetween('2026-07-23 01:00:00', '2026-07-23 20:00:00'),
        ],
        'autoInsulinByDate' => [
            '2026-07-20' => 40.0,
            '2026-07-22' => 9.0,
            '2026-07-23' => 8.0,
        ],
    ]));

    expect($findings)->toHaveCount(2);
    expect($findings[0]->evidence['affected_date'])->toBe('2026-07-22');
    expect($findings[1]->evidence['affected_date'])->toBe('2026-07-23');
});

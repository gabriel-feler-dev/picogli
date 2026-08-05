<?php

declare(strict_types=1);

use App\Domain\Metrics\Value\Episode;
use App\Domain\Metrics\Value\EpisodeType;
use App\Domain\Metrics\Value\SensorGap;
use App\Domain\Patterns\Value\CalibrationPair;
use App\Domain\Patterns\Value\DailySnapshot;
use App\Domain\Patterns\Value\Daypart;
use App\Domain\Patterns\Value\MealPoint;
use App\Domain\Patterns\Value\PatternDataset;

/**
 * T302.1 e T302.7 — o dataset se monta INTEIRO a partir de arrays.
 *
 * ⚠️ **Este arquivo é a razão de o `PatternDataset` existir na forma em que
 * existe.** Ele roda sem banco, sem container e sem fixture — e é isso que vai
 * permitir testar as dez regras com dados escritos à mão, cada uma no seu caso
 * positivo e no seu negativo (§D5).
 *
 * Se um dia este teste precisar de banco, o §D2 foi violado em algum lugar.
 */
it('constrói sem banco, sem container e sem fixture', function () {
    $dataset = makePatternDataset();

    expect($dataset->series->count())->toBe(3);
    expect($dataset->isEmpty())->toBeFalse();
    expect($dataset->periodStart)->toBe('2026-07-16');
});

it('reusa os value objects das fases 1 e 2, não equivalentes novos', function () {
    $dataset = makePatternDataset([
        'hypoEpisodes' => [new Episode(
            EpisodeType::Hypoglycemia,
            new DateTimeImmutable('2026-07-21 00:44:00'),
            new DateTimeImmutable('2026-07-21 01:24:00'),
            40.0, 56, 8,
        )],
        'gaps' => [new SensorGap(
            new DateTimeImmutable('2026-07-21 17:29:00'),
            new DateTimeImmutable('2026-07-22 15:56:00'),
            1347.0,
        )],
    ]);

    // Duas definições de episódio divergem, sempre. Estas são as da fase 2, já
    // conferidas contra o gabarito.
    expect($dataset->hypoEpisodes[0])->toBeInstanceOf(Episode::class);
    expect($dataset->hypoEpisodes[0]->nadir())->toBe(56);
    expect($dataset->gaps[0])->toBeInstanceOf(SensorGap::class);
    expect($dataset->gaps[0]->minutes)->toBe(1347.0);
});

it('carrega cobertura e validade sempre (Artigo V)', function () {
    $dataset = makePatternDataset();

    expect($dataset->coverage->percentage)->toBe(91.1);
    expect($dataset->coverage->spanInDays)->toBe(13.8);
    expect($dataset->validity->isValid())->toBeTrue();
});

describe('os acessos de conveniência', function () {

    it('daypart() devolve as estatísticas do período', function () {
        $dataset = makePatternDataset();

        expect($dataset->daypart(Daypart::Afternoon)->count)->toBe(1);
        expect($dataset->daypart(Daypart::Afternoon)->aboveCount)->toBe(1);
        expect($dataset->daypart(Daypart::Morning)->count)->toBe(0);
    });

    it('dailyByDate indexa por data', function () {
        $dataset = makePatternDataset(['daily' => [
            new DailySnapshot('2026-07-25', 281, 97.6, 159.0, 68.7, 42.2, 16.0, 2.5, 37.5, 24.0, 150.0),
            new DailySnapshot('2026-07-26', 288, 100.0, 154.0, 67.7, 35.5, 4.9, 2.8, 37.3, 20.0, 120.0),
        ]]);

        $porData = $dataset->dailyByDate();

        expect($porData)->toHaveKeys(['2026-07-25', '2026-07-26']);
        expect($porData['2026-07-25']->tirPct)->toBe(68.7);
    });

    it('deviceEventCount devolve 0 para código ausente, não null', function () {
        $dataset = makePatternDataset(['deviceEventCounts' => ['SET CHANGE REMINDER' => 3]]);

        expect($dataset->deviceEventCount('SET CHANGE REMINDER'))->toBe(3);
        // Zero e não null: uma regra que somasse `null` viraria erro de tipo
        // longe da causa, e uma que o tratasse como 0 daria no mesmo com mais
        // linhas.
        expect($dataset->deviceEventCount('ALERT ON LOW'))->toBe(0);
    });
});

describe('a média de insulina automática', function () {

    // ⚠️ Denominador são os dias COM registro. Dia sem linha no bloco 2 é dia sem
    // dado — tratá-lo como 0 U rebaixaria a média e faria a queda do 22/07
    // parecer menor do que foi.
    it('divide pelos dias com registro, não pelos dias do calendário', function () {
        $dataset = makePatternDataset(['autoInsulinByDate' => [
            '2026-07-16' => 45.0,
            '2026-07-17' => 37.1,
            '2026-07-22' => 9.0,
        ]]);

        expect($dataset->meanAutoInsulin())->toBeCloseToValue((45.0 + 37.1 + 9.0) / 3);
    });

    it('devolve null sem nenhum registro, não zero', function () {
        // Zero afirmaria "não houve insulina automática". Null diz "não sei".
        expect(makePatternDataset()->meanAutoInsulin())->toBeNull();
    });
});

describe('o dia em forma pura', function () {

    it('DailySnapshot calcula total e fração automática', function () {
        $dia = new DailySnapshot('2026-07-22', 97, 33.7, 136.0, 84.5, 29.6, 0.0, 3.1, 9.0, 24.0, 130.0);

        expect($dia->totalInsulinU())->toBe(33.0);
        // 9,0 de 33,0 = 27% — contra os ~60% habituais. É o caso de R5.
        expect($dia->automaticFraction())->toBeCloseToValue(0.2727, 0.001);
    });

    it('fração automática é null quando não houve insulina', function () {
        $dia = new DailySnapshot('2026-07-22', 0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);

        expect($dia->automaticFraction())->toBeNull();
    });
});

describe('refeição em forma pura', function () {

    it('MealPoint carrega o CR vigente no momento do bolus', function () {
        $refeicao = new MealPoint(new DateTimeImmutable('2026-07-25 18:09:00'), 45.0, 8.0);

        expect($refeicao->hour())->toBe(18);
        expect($refeicao->localDate())->toBe('2026-07-25');
        expect($refeicao->carbRatio)->toBe(8.0);
    });

    it('CR pode ser null — refeição sem linha BWZ completa', function () {
        expect((new MealPoint(new DateTimeImmutable('2026-07-25 18:09:00'), 45.0))->carbRatio)
            ->toBeNull();
    });
});

it('o par de calibração guarda a janela usada', function () {
    $dataset = makePatternDataset([
        'calibrationPairs' => [new CalibrationPair(
            new DateTimeImmutable('2026-07-28 21:14:02'), 122, 130, 2.0,
        )],
        'calibrationWindowMinutes' => 10,
    ]);

    expect($dataset->calibrationWindowMinutes)->toBe(10);
    expect($dataset->calibrationPairs[0]->relativeErrorPercent())->toBeCloseToValue(6.557, 0.001);
});

it('sinaliza métrica de versão antiga em vez de esconder (§D9)', function () {
    $dataset = makePatternDataset(['hasStaleMetrics' => true, 'metricsVersion' => '2026.08.1']);

    expect($dataset->hasStaleMetrics)->toBeTrue();
    expect($dataset->metricsVersion)->toBe('2026.08.1');
});

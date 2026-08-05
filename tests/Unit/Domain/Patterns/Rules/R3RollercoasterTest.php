<?php

declare(strict_types=1);

use App\Domain\Metrics\Value\Episode;
use App\Domain\Metrics\Value\EpisodeType;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Patterns\Rules\R3Rollercoaster;
use App\Domain\Patterns\Value\MealPoint;
use App\Domain\Patterns\Value\PatternDataset;

/**
 * T307 — R3, a mais complexa: três condições encadeadas numa janela temporal.
 */
function hypoEpisode(string $start, int $minutes, int $nadir): Episode
{
    $from = new DateTimeImmutable($start);

    return new Episode(
        EpisodeType::Hypoglycemia,
        $from,
        $from->modify("+{$minutes} minutes"),
        (float) $minutes,
        $nadir,
        (int) ($minutes / 5),
    );
}

function hyperEpisode(string $start, int $minutes, int $peak): Episode
{
    $from = new DateTimeImmutable($start);

    return new Episode(
        EpisodeType::HyperglycemiaLevel2,
        $from,
        $from->modify("+{$minutes} minutes"),
        (float) $minutes,
        $peak,
        (int) ($minutes / 5),
    );
}

function r3Meal(string $at, float $carbs): MealPoint
{
    return new MealPoint(new DateTimeImmutable($at), $carbs);
}

/**
 * Série com o vale do episódio: leituras de 5 em 5 min, com o mínimo no meio.
 * Sem isto a regra não acha o instante do nadir.
 */
function seriesWithDip(string $start, int $minutes, int $nadir, int $nadirOffset = 10): GlucoseSeries
{
    $from = new DateTimeImmutable($start);
    $readings = [];

    for ($m = 0; $m <= $minutes; $m += 5) {
        $readings[] = new GlucoseReading(
            $from->modify("+{$m} minutes"),
            $m === $nadirOffset ? $nadir : $nadir + 8,
        );
    }

    return GlucoseSeries::of($readings);
}

/** O cenário do 25/07, em forma mínima. */
function rollercoasterDataset(array $overrides = []): PatternDataset
{
    return makePatternDataset(array_merge([
        'series' => seriesWithDip('2026-07-25 17:56:00', 20, 55),
        'hypoEpisodes' => [hypoEpisode('2026-07-25 17:56:00', 20, 55)],
        'hyperEpisodes' => [hyperEpisode('2026-07-25 19:41:00', 275, 324)],
        'meals' => [
            r3Meal('2026-07-25 18:09:00', 32.0),
            r3Meal('2026-07-25 20:12:00', 41.0),
            r3Meal('2026-07-25 21:26:00', 36.0),
        ],
    ], $overrides));
}

$rule = fn () => new R3Rollercoaster(patternsConfig(), fakeProseRenderer());

describe('a cadeia completa', function () use ($rule) {

    it('fecha as três condições e emite um achado', function () use ($rule) {
        $findings = $rule()->evaluate(rollercoasterDataset());

        expect($findings)->toHaveCount(1);

        $evidence = $findings[0]->evidence;

        expect($evidence['date'])->toBe('2026-07-25');
        expect($evidence['nadir'])->toBe(55);
        expect($evidence['meals'])->toBe(3);
        expect($evidence['carbs_g'])->toBe(109.0);
        expect($evidence['first_meal_at'])->toBe('18:09');
        expect($evidence['last_meal_at'])->toBe('21:26');
        expect($evidence['hyper_start_at'])->toBe('19:41');
        expect($evidence['hyper_duration_minutes'])->toBe(275);
        expect($evidence['hyper_peak'])->toBe(324);
    });

    // ⚠️ ARMADILHA 1 — a janela conta do NADIR, não do início do episódio.
    it('a janela conta do nadir, e o nadir é achado na série', function () use ($rule) {
        // Episódio começa 17:56; o nadir está 10 min depois, às 18:06.
        $evidence = $rule()->evaluate(rollercoasterDataset())[0]->evidence;

        expect($evidence['nadir_at'])->toBe('18:06');
        expect($evidence['minutes_from_nadir_to_hyper'])->toBe(95);
    });

    it('refeição ANTES do nadir não conta', function () use ($rule) {
        $findings = $rule()->evaluate(rollercoasterDataset([
            'meals' => [
                r3Meal('2026-07-25 17:00:00', 80.0),   // antes do nadir
                r3Meal('2026-07-25 18:09:00', 32.0),
            ],
        ]));

        // Só 32 g contam, e 32 > 30, então ainda dispara — mas com 1 refeição.
        expect($findings[0]->evidence['meals'])->toBe(1);
        expect($findings[0]->evidence['carbs_g'])->toBe(32.0);
    });

    it('refeição além da janela não conta', function () use ($rule) {
        $findings = $rule()->evaluate(rollercoasterDataset([
            'meals' => [
                r3Meal('2026-07-25 18:09:00', 32.0),
                r3Meal('2026-07-25 23:00:00', 90.0),   // ~5 h depois do nadir
            ],
        ]));

        expect($findings[0]->evidence['carbs_g'])->toBe(32.0);
    });
});

describe('as três armadilhas', function () use ($rule) {

    // ⚠️ ARMADILHA 2 — sem a ordenação, a regra casa a hipo com uma hiper
    // ANTERIOR e conta a história de trás para frente, com todos os números
    // certos.
    it('hiper ANTES do nadir não fecha a cadeia', function () use ($rule) {
        $findings = $rule()->evaluate(rollercoasterDataset([
            'hyperEpisodes' => [hyperEpisode('2026-07-25 14:00:00', 275, 324)],
        ]));

        expect($findings)->toBe([]);
    });

    it('hiper tarde demais depois da última refeição não fecha', function () use ($rule) {
        // Última refeição 21:26 + 4 h = 01:26. Uma hiper às 05:00 não é a
        // continuação desta cadeia.
        $findings = $rule()->evaluate(rollercoasterDataset([
            'hyperEpisodes' => [hyperEpisode('2026-07-26 05:00:00', 70, 271)],
        ]));

        expect($findings)->toBe([]);
    });

    // ⚠️ ARMADILHA 3 — duas hipos no mesmo dia com a MESMA hiper depois não são
    // dois eventos. O episódio de hiper é consumido (mesmo claiming do
    // BolusLinker da fase 1).
    it('uma hipo, um achado: a hiper é consumida', function () use ($rule) {
        $series = GlucoseSeries::of([
            ...seriesWithDip('2026-07-25 17:00:00', 20, 60)->readings,
            ...seriesWithDip('2026-07-25 17:56:00', 20, 55)->readings,
        ]);

        $findings = $rule()->evaluate(rollercoasterDataset([
            'series' => $series,
            'hypoEpisodes' => [
                hypoEpisode('2026-07-25 17:56:00', 20, 55),
                hypoEpisode('2026-07-25 17:00:00', 20, 60),
            ],
            'hyperEpisodes' => [hyperEpisode('2026-07-25 19:41:00', 275, 324)],
        ]));

        expect($findings)->toHaveCount(1);
        // A PRIMEIRA hipo em ordem cronológica fica com a hiper — não a
        // primeira do array.
        expect($findings[0]->evidence['nadir'])->toBe(60);
    });

    it('duas cadeias independentes emitem dois achados', function () use ($rule) {
        $series = GlucoseSeries::of([
            ...seriesWithDip('2026-07-25 17:56:00', 20, 55)->readings,
            ...seriesWithDip('2026-07-27 17:56:00', 20, 58)->readings,
        ]);

        $findings = $rule()->evaluate(rollercoasterDataset([
            'series' => $series,
            'hypoEpisodes' => [
                hypoEpisode('2026-07-25 17:56:00', 20, 55),
                hypoEpisode('2026-07-27 17:56:00', 20, 58),
            ],
            'hyperEpisodes' => [
                hyperEpisode('2026-07-25 19:41:00', 275, 324),
                hyperEpisode('2026-07-27 19:41:00', 90, 280),
            ],
            'meals' => [
                r3Meal('2026-07-25 18:09:00', 60.0),
                r3Meal('2026-07-27 18:09:00', 60.0),
            ],
        ]));

        expect($findings)->toHaveCount(2);
    });
});

describe('casos negativos (§D5)', function () use ($rule) {

    // ⚠️ 15 g tratam uma hipoglicemia (regra dos 15). Abaixo do limiar não há
    // sobrecorreção a descrever.
    it('hipo tratada com 15 g NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(rollercoasterDataset([
            'meals' => [r3Meal('2026-07-25 18:09:00', 15.0)],
        ])))->toBe([]);
    });

    it('carboidrato exatamente no limiar NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(rollercoasterDataset([
            'meals' => [r3Meal('2026-07-25 18:09:00', 30.0)],
        ])))->toBe([]);
    });

    it('carboidrato alto SEM hiper depois NÃO dispara', function () use ($rule) {
        // O caso do 27/07 no export: 46 g e nenhuma hiperglicemia.
        expect($rule()->evaluate(rollercoasterDataset([
            'hyperEpisodes' => [],
        ])))->toBe([]);
    });

    it('sem refeição na janela NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(rollercoasterDataset(['meals' => []])))->toBe([]);
    });

    it('sem hipoglicemia NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(rollercoasterDataset(['hypoEpisodes' => []])))->toBe([]);
    });

    it('episódio sem leitura na série é ignorado', function () use ($rule) {
        // Sem série não há como localizar o nadir, e a janela não tem âncora.
        expect($rule()->evaluate(rollercoasterDataset([
            'series' => GlucoseSeries::of([]),
        ])))->toBe([]);
    });
});

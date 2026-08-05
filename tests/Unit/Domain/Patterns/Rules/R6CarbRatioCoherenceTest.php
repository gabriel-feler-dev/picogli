<?php

declare(strict_types=1);

use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Patterns\Rules\R6CarbRatioCoherence;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\MealPoint;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;

/**
 * T306 — R6, no caso positivo E no negativo (§D5).
 *
 * ⚠️ A regra que mais se aproxima de conduta médica. O Artigo VI é imposto em
 * três lugares, e este arquivo testa os três.
 */

/** N refeições numa hora, todas com o mesmo CR. */
function mealsAtHour(int $hour, int $count, float $carbRatio): array
{
    $meals = [];

    for ($i = 0; $i < $count; $i++) {
        $meals[] = new MealPoint(
            new DateTimeImmutable(sprintf('2026-07-16 %02d:%02d:00', $hour, $i % 60)),
            40.0,
            $carbRatio,
        );
    }

    return $meals;
}

/** Série com N leituras numa hora, das quais $above acima de 180. */
function coherenceSeries(array $spec): GlucoseSeries
{
    $readings = [];

    foreach ($spec as [$hour, $count, $above]) {
        for ($i = 0; $i < $count; $i++) {
            $readings[] = new GlucoseReading(
                new DateTimeImmutable(sprintf('2026-07-16 %02d:%02d:%02d', $hour, intdiv($i, 60), $i % 60)),
                $i < $above ? 220 : 120,
            );
        }
    }

    return GlucoseSeries::of($readings);
}

/** O cenário do export: CR enfraquece de manhã para a noite, e o tempo alto cresce. */
function coherenceDataset(array $overrides = []): PatternDataset
{
    return datasetWithSeries(
        $overrides['series'] ?? coherenceSeries([[8, 200, 20], [14, 200, 48], [20, 200, 45]]),
        array_merge(['meals' => [
            ...mealsAtHour(6, 1, 10.0),    // o outlier de amostra mínima
            ...mealsAtHour(8, 17, 5.0),
            ...mealsAtHour(14, 17, 6.0),
            ...mealsAtHour(20, 17, 8.0),
        ]], array_diff_key($overrides, ['series' => null])),
    );
}

$rule = fn () => new R6CarbRatioCoherence(patternsConfig(), fakeProseRenderer());

describe('a observação', function () use ($rule) {

    it('compara o CR mais forte com o mais fraco', function () use ($rule) {
        $findings = $rule()->evaluate(coherenceDataset());

        expect($findings)->toHaveCount(1);

        $evidence = $findings[0]->evidence;

        expect($evidence['strongest_daypart'])->toBe('morning');
        expect($evidence['weakest_daypart'])->toBe('evening');
        expect($evidence['weakest_carb_ratio'])->toBe(8.0);
        expect($evidence['ratio_spread_g'])->toBeGreaterThan(2.0);
        expect($evidence['dayparts_compared'])->toBe(3);
    });

    // ⚠️ A hora 06h tem 1 refeição com CR de 10,0 entre as 18 da manhã. Ponderar
    // por REFEIÇÃO a dilui; ponderar por hora a faria dominar o período.
    it('a média é ponderada por refeição, e o outlier de 06h não domina', function () use ($rule) {
        $evidence = $rule()->evaluate(coherenceDataset())[0]->evidence;

        // (10 + 17×5) / 18 = 5,28 — e não (10 + 5) / 2 = 7,5.
        expect($evidence['strongest_carb_ratio'])->toBe(5.28);
        expect($evidence['strongest_meals'])->toBe(18);
    });

    it('período com poucas refeições sai da comparação', function () use ($rule) {
        // A madrugada tem 2 refeições, abaixo do mínimo de 3, com CR absurdo.
        $findings = $rule()->evaluate(coherenceDataset([
            'meals' => [
                ...mealsAtHour(2, 2, 30.0),
                ...mealsAtHour(8, 17, 5.0),
                ...mealsAtHour(20, 17, 8.0),
            ],
        ]));

        expect($findings[0]->evidence['weakest_carb_ratio'])->toBe(8.0);
        expect($findings[0]->evidence['dayparts_compared'])->toBe(2);
    });

    it('é Attention — observação para levar ao médico, não risco', function () use ($rule) {
        expect($rule()->evaluate(coherenceDataset())[0]->severity)->toBe(Severity::Attention);
    });
});

/**
 * ⚠️⚠️ ARTIGO VI, CAMADA 3 — os três mecanismos.
 */
describe('a fronteira clínica', function () use ($rule) {

    it('1. o achado carrega requiresClinicalHandoff', function () use ($rule) {
        expect($rule()->evaluate(coherenceDataset())[0]->requiresClinicalHandoff)->toBeTrue();
    });

    // ⚠️ O construtor de `Finding` RECUSA um achado de R6 sem o encaminhamento.
    // Convenção de texto se perde num refactor; validação de construtor não.
    it('2. o tipo impede um achado de R6 sem encaminhamento', function () {
        expect(RuleId::CarbRatioCoherence->requiresClinicalHandoff())->toBeTrue();

        expect(fn () => new Finding(
            ruleId: RuleId::CarbRatioCoherence,
            severity: Severity::Attention,
            evidence: ['strongest_carb_ratio' => 5.0],
            fallbackProse: 'texto qualquer',
            requiresClinicalHandoff: false,
        ))->toThrow(InvalidArgumentException::class, 'Artigo VI');
    });

    // ⚠️ 3. Nenhuma chave de evidência sugere valor novo — e como a prosa só
    // pode citar o que está na evidência (Artigo II), ela também não consegue.
    it('3. a evidência só carrega valores OBSERVADOS', function () use ($rule) {
        $evidence = $rule()->evaluate(coherenceDataset())[0]->evidence;

        foreach (array_keys($evidence) as $key) {
            foreach (['suggested', 'recommended', 'target_ratio', 'ideal', 'new_'] as $proibido) {
                expect(str_contains($key, $proibido))->toBeFalse(
                    "a evidência de R6 tem a chave '{$key}', que sugere valor novo"
                );
            }
        }
    });
});

describe('casos negativos (§D5)', function () use ($rule) {

    it('CR constante ao longo do dia NÃO dispara', function () use ($rule) {
        $findings = $rule()->evaluate(coherenceDataset([
            'meals' => [
                ...mealsAtHour(8, 17, 6.0),
                ...mealsAtHour(14, 17, 6.0),
                ...mealsAtHour(20, 17, 6.0),
            ],
        ]));

        expect($findings)->toBe([]);
    });

    it('variação menor que o espalhamento mínimo NÃO dispara', function () use ($rule) {
        // 5,0 contra 5,5 g/U é ruído de configuração, não padrão.
        $findings = $rule()->evaluate(coherenceDataset([
            'meals' => [
                ...mealsAtHour(8, 17, 5.0),
                ...mealsAtHour(20, 17, 5.5),
            ],
        ]));

        expect($findings)->toBe([]);
    });

    // ⚠️ A coerência é a CONDIÇÃO da regra, não uma conclusão dela. Se o período
    // de CR mais fraco tem MENOS tempo alto, não há observação a fazer — e
    // afirmá-la assim mesmo seria inventar correlação (Artigo II).
    it('CR fraco COM MENOS tempo alto NÃO dispara', function () use ($rule) {
        $findings = $rule()->evaluate(coherenceDataset([
            // Manhã com muito tempo alto e CR forte; noite com pouco e CR fraco.
            'series' => coherenceSeries([[8, 200, 100], [20, 200, 10]]),
            'meals' => [
                ...mealsAtHour(8, 17, 5.0),
                ...mealsAtHour(20, 17, 8.0),
            ],
        ]));

        expect($findings)->toBe([]);
    });

    it('menos de dois períodos elegíveis NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(coherenceDataset([
            'meals' => mealsAtHour(8, 17, 5.0),
        ])))->toBe([]);
    });

    it('nenhuma refeição NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(coherenceDataset(['meals' => []])))->toBe([]);
    });

    it('refeição sem CR registrado é ignorada', function () use ($rule) {
        $semCr = array_map(
            fn (MealPoint $m): MealPoint => new MealPoint($m->at, $m->carbsG),
            mealsAtHour(20, 17, 8.0),
        );

        expect($rule()->evaluate(coherenceDataset([
            'meals' => [...mealsAtHour(8, 17, 5.0), ...$semCr],
        ])))->toBe([]);
    });
});

<?php

declare(strict_types=1);

use App\Domain\Metrics\Value\Coverage;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Patterns\Rules\R1DaypartDrift;
use App\Domain\Patterns\Rules\R7SensorAdherence;
use App\Domain\Patterns\Rules\R8ReservoirChanges;
use App\Domain\Patterns\Rules\R9CalibrationBurden;
use App\Domain\Patterns\Value\DailySnapshot;
use App\Domain\Patterns\Value\Severity;

/**
 * T303 — as quatro regras simples, no caso positivo E no negativo (§D5).
 *
 * ⚠️ **O caso negativo é metade do teste, não um extra.** Um motor de padrões
 * falha de dois jeitos que nenhum teste positivo pega:
 *
 *   - regra que dispara sempre → vira ruído, e o usuário para de ler tudo;
 *   - regra que nunca dispara → código morto que ninguém percebe.
 *
 * A primeira passa em qualquer teste positivo. A segunda passa em qualquer
 * negativo. Só os dois juntos provam que a regra **discrimina**.
 *
 * Roda sem banco e sem container: dataset montado à mão, prosa falsa. O texto de
 * verdade é conferido nos testes de feature.
 */

/** Série com N leituras numa hora, das quais $above acima de 180. */
function seriesForHours(array $hourSpec): GlucoseSeries
{
    $readings = [];

    foreach ($hourSpec as [$hour, $count, $above]) {
        for ($i = 0; $i < $count; $i++) {
            $readings[] = new GlucoseReading(
                new DateTimeImmutable(sprintf('2026-07-16 %02d:%02d:%02d', $hour, intdiv($i, 60) % 60, $i % 60)),
                $i < $above ? 220 : 120,
            );
        }
    }

    return GlucoseSeries::of($readings);
}

function daySnapshot(string $date, float $coverage): DailySnapshot
{
    return new DailySnapshot($date, (int) round($coverage * 2.88), $coverage, 140.0, 85.0, 25.0, 1.0, 1.0, 30.0, 20.0, 120.0);
}

// ══════════════════════════════════════════════════════════════════════════
describe('R1 — deriva por período do dia (FR-403)', function () {

    $rule = fn () => new R1DaypartDrift(patternsConfig(), fakeProseRenderer());

    it('dispara com pior = tarde e melhor = madrugada', function () use ($rule) {
        // madrugada 5% · tarde 25% → razão 5x, acima de priority_ratio
        $findings = $rule()->evaluate(datasetWithSeries(
            seriesForHours([[3, 200, 10], [14, 200, 50]])
        ));

        expect($findings)->toHaveCount(1);
        expect($findings[0]->evidence['worst_daypart'])->toBe('afternoon');
        expect($findings[0]->evidence['best_daypart'])->toBe('dawn');
        expect($findings[0]->evidence['ratio'])->toBe(5.0);
        expect($findings[0]->severity)->toBe(Severity::Priority);
    });

    it('a evidência traz os QUATRO períodos com percentual e n (FR-403)', function () use ($rule) {
        $series = seriesForHours([[3, 200, 10], [8, 200, 20], [14, 200, 50], [20, 200, 40]]);
        $findings = $rule()->evaluate(datasetWithSeries($series));

        foreach (['dawn', 'morning', 'afternoon', 'evening'] as $key) {
            expect($findings[0]->evidence)->toHaveKey($key.'_percent_above');
            expect($findings[0]->evidence)->toHaveKey($key.'_readings');
            expect($findings[0]->evidence[$key.'_readings'])->toBe(200);
        }
    });

    it('razão entre o limiar e o de prioridade é Attention, não Priority', function () use ($rule) {
        // madrugada 10% · tarde 30% → 3x: acima de ratio_threshold (2), abaixo
        // de priority_ratio (5).
        $series = seriesForHours([[3, 200, 20], [14, 200, 60]]);
        $findings = $rule()->evaluate(datasetWithSeries($series));

        expect($findings[0]->evidence['ratio'])->toBe(3.0);
        expect($findings[0]->severity)->toBe(Severity::Attention);
    });

    // ⚠️ CASO NEGATIVO
    it('série uniforme entre períodos NÃO dispara', function () use ($rule) {
        $series = seriesForHours([[3, 200, 30], [8, 200, 30], [14, 200, 30], [20, 200, 30]]);

        expect($rule()->evaluate(datasetWithSeries($series)))->toBe([]);
    });

    it('razão abaixo do limiar NÃO dispara', function () use ($rule) {
        // 15% contra 25% = 1,67x, abaixo de 2.
        $series = seriesForHours([[3, 200, 30], [14, 200, 50]]);

        expect($rule()->evaluate(datasetWithSeries($series)))->toBe([]);
    });

    // ⚠️ Sem esta guarda, um período atravessado por lacuna de sensor vira "o
    // seu pior horário" com base em três horas de dado.
    it('ignora período com amostra abaixo do mínimo', function () use ($rule) {
        // A tarde tem 40 leituras (mínimo é 100) e 100% acima. Sobram apenas
        // madrugada e manhã, ambas em 10% — sem deriva.
        $series = seriesForHours([[3, 200, 20], [8, 200, 20], [14, 40, 40]]);

        expect($rule()->evaluate(datasetWithSeries($series)))->toBe([]);
    });

    it('menos de dois períodos elegíveis NÃO dispara', function () use ($rule) {
        $series = seriesForHours([[3, 200, 100]]);

        expect($rule()->evaluate(datasetWithSeries($series)))->toBe([]);
    });

    // O limite: melhor período com 0% torna a razão infinita, e infinito satisfaz
    // qualquer limiar. Não é caso especial inventado.
    it('melhor período com 0% dispara com ratio null e diferença em pontos', function () use ($rule) {
        $series = seriesForHours([[3, 200, 0], [14, 200, 50]]);
        $findings = $rule()->evaluate(datasetWithSeries($series));

        expect($findings)->toHaveCount(1);
        expect($findings[0]->evidence['ratio'])->toBeNull();
        expect($findings[0]->evidence['difference_pp'])->toBe(25.0);
        expect($findings[0]->severity)->toBe(Severity::Priority);
        // A prosa escolhida é a que não cita razão.
        expect($findings[0]->fallbackProse)->toContain('prose_no_ratio');
    });

    it('todos os períodos em 0% NÃO dispara', function () use ($rule) {
        $series = seriesForHours([[3, 200, 0], [14, 200, 0]]);

        expect($rule()->evaluate(datasetWithSeries($series)))->toBe([]);
    });
});

// ══════════════════════════════════════════════════════════════════════════
describe('R7 — aderência ao sensor (FR-409)', function () {

    $rule = fn () => new R7SensorAdherence(patternsConfig(), fakeProseRenderer());

    it('acusa exatamente 1 dia abaixo de 70%, e o 73% passa', function () use ($rule) {
        $findings = $rule()->evaluate(makePatternDataset(['daily' => [
            daySnapshot('2026-07-20', 91.0),
            daySnapshot('2026-07-21', 73.0),   // ⚠️ PASSA — o limiar não é aproximado
            daySnapshot('2026-07-22', 34.0),   // abaixo
            daySnapshot('2026-07-28', 82.0),
        ]]));

        expect($findings)->toHaveCount(1);
        expect($findings[0]->evidence['days_below_threshold'])->toBe(1);
        expect($findings[0]->evidence['worst_date'])->toBe('2026-07-22');
        expect($findings[0]->evidence['worst_coverage_percent'])->toBe(34.0);
        expect($findings[0]->evidence['days_total'])->toBe(4);
        expect($findings[0]->evidence['days_below_100'])->toBe(4);
    });

    it('dia isolado é Attention; período inteiro reprovado é Priority', function () use ($rule) {
        $umDiaRuim = $rule()->evaluate(makePatternDataset(['daily' => [
            daySnapshot('2026-07-22', 34.0),
            daySnapshot('2026-07-23', 100.0),
        ]]));

        expect($umDiaRuim[0]->severity)->toBe(Severity::Attention);

        // Cobertura do período abaixo de 70%: aí o Artigo V entra em cena e as
        // métricas do relatório deixam de ser interpretáveis.
        $periodoRuim = $rule()->evaluate(makePatternDataset([
            'daily' => [daySnapshot('2026-07-22', 34.0)],
            'coverage' => new Coverage(500, 4032, 13.8, 41.0),
        ]));

        expect($periodoRuim[0]->severity)->toBe(Severity::Priority);
    });

    // ⚠️ CASO NEGATIVO
    it('14 dias com 100% NÃO dispara', function () use ($rule) {
        $dias = array_map(
            fn (int $d): DailySnapshot => daySnapshot(sprintf('2026-07-%02d', $d), 100.0),
            range(16, 29),
        );

        expect($rule()->evaluate(makePatternDataset(['daily' => $dias])))->toBe([]);
    });

    it('dia exatamente em 70% NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(makePatternDataset(['daily' => [daySnapshot('2026-07-21', 70.0)]])))
            ->toBe([]);
    });

    it('sem dias no período NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(makePatternDataset()))->toBe([]);
    });
});

// ══════════════════════════════════════════════════════════════════════════
describe('R9 — carga de calibração (FR-411)', function () {

    $rule = fn () => new R9CalibrationBurden(patternsConfig(), fakeProseRenderer());

    it('reporta 39 calibrações em 14 dias = 2,8 por dia', function () use ($rule) {
        $dias = array_map(
            fn (int $d): DailySnapshot => daySnapshot(sprintf('2026-07-%02d', $d), 100.0),
            range(16, 29),
        );

        $findings = $rule()->evaluate(makePatternDataset([
            'daily' => $dias,
            'deviceCategoryCounts' => ['calibration' => 39],
        ]));

        expect($findings)->toHaveCount(1);
        expect($findings[0]->evidence['calibrations'])->toBe(39);
        expect($findings[0]->evidence['days'])->toBe(14);
        expect($findings[0]->evidence['per_day'])->toBe(2.8);
    });

    // ⚠️ Teto imposto pelo enum: o construtor de Finding recusa acima de Info.
    // "2,8 picadas por dia" não é cobrança — o Guardian 3 EXIGE calibração.
    it('nunca passa de Info', function () use ($rule) {
        $findings = $rule()->evaluate(makePatternDataset([
            'daily' => [daySnapshot('2026-07-16', 100.0)],
            'deviceCategoryCounts' => ['calibration' => 500],
        ]));

        expect($findings[0]->severity)->toBe(Severity::Info);
        expect($findings[0]->ruleId->maxSeverity())->toBe(Severity::Info);
    });

    // ⚠️ CASO NEGATIVO
    it('sem calibração NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(makePatternDataset([
            'daily' => [daySnapshot('2026-07-16', 100.0)],
            'deviceCategoryCounts' => ['alert' => 59],
        ])))->toBe([]);
    });

    it('sem dias no período NÃO dispara, e não divide por zero', function () use ($rule) {
        expect($rule()->evaluate(makePatternDataset([
            'deviceCategoryCounts' => ['calibration' => 39],
        ])))->toBe([]);
    });
});

// ══════════════════════════════════════════════════════════════════════════
describe('R8 — trocas de reservatório (FR-410)', function () {

    $rule = fn () => new R8ReservoirChanges(patternsConfig(), fakeProseRenderer());

    $rewindsDoGabarito = fn (): array => [
        new DateTimeImmutable('2026-07-16 17:38:47'),
        new DateTimeImmutable('2026-07-20 21:33:04'),
        new DateTimeImmutable('2026-07-25 13:12:15'),
    ];

    it('reporta 3 trocas, 2 intervalos e média de 4,41 dias (§D8)', function () use ($rule, $rewindsDoGabarito) {
        $findings = $rule()->evaluate(makePatternDataset([
            'rewinds' => $rewindsDoGabarito(),
            'deviceCategoryCounts' => ['prime' => 6],
            'deviceEventCounts' => ['SET CHANGE REMINDER' => 3],
        ]));

        expect($findings)->toHaveCount(1);
        expect($findings[0]->evidence['rewinds'])->toBe(3);
        expect($findings[0]->evidence['primes'])->toBe(6);

        // ⚠️ 2 intervalos, não 3. `14 dias ÷ 3 trocas = 4,7` supõe o período
        // começando e terminando numa troca — e não é o caso.
        expect($findings[0]->evidence['intervals'])->toBe(2);
        expect($findings[0]->evidence['mean_interval_days'])->toBe(4.41);
        expect($findings[0]->evidence['shortest_interval_days'])->toBe(4.16);
        expect($findings[0]->evidence['longest_interval_days'])->toBe(4.65);
        expect($findings[0]->evidence['set_change_reminders'])->toBe(3);
    });

    it('o n de intervalos aparece explicitamente na evidência', function () use ($rule, $rewindsDoGabarito) {
        $findings = $rule()->evaluate(makePatternDataset(['rewinds' => $rewindsDoGabarito()]));

        // Amostra pequena precisa estar à vista, não escondida atrás da média.
        expect($findings[0]->evidence)->toHaveKey('intervals');
        expect($findings[0]->evidence['intervals'])->toBe(count($rewindsDoGabarito()) - 1);
    });

    it('escolhe a prosa com avisos quando a bomba os emitiu', function () use ($rule, $rewindsDoGabarito) {
        $comAviso = $rule()->evaluate(makePatternDataset([
            'rewinds' => $rewindsDoGabarito(),
            'deviceEventCounts' => ['SET CHANGE REMINDER' => 3],
        ]));
        $semAviso = $rule()->evaluate(makePatternDataset(['rewinds' => $rewindsDoGabarito()]));

        expect($comAviso[0]->fallbackProse)->toContain('prose_with_reminders');
        expect($semAviso[0]->fallbackProse)->not->toContain('prose_with_reminders');
    });

    // ⚠️ Sempre Info. Qualquer escalonamento implicaria que a cadência observada
    // está errada, e isso é conduta (Artigo VI).
    it('é sempre Info, mesmo com intervalo longo', function () use ($rule) {
        $findings = $rule()->evaluate(makePatternDataset(['rewinds' => [
            new DateTimeImmutable('2026-07-01 00:00:00'),
            new DateTimeImmutable('2026-07-20 00:00:00'),   // 19 dias
        ]]));

        expect($findings[0]->severity)->toBe(Severity::Info);
        expect($findings[0]->evidence['mean_interval_days'])->toBe(19.0);
    });

    it('mede em segundos, não em dias de calendário', function () use ($rule) {
        // 16/07 17:38 → 20/07 21:33 são 4,16 dias, não 4.
        $findings = $rule()->evaluate(makePatternDataset(['rewinds' => [
            new DateTimeImmutable('2026-07-16 17:38:47'),
            new DateTimeImmutable('2026-07-20 21:33:04'),
        ]]));

        expect($findings[0]->evidence['mean_interval_days'])->toBe(4.16);
        expect($findings[0]->evidence['mean_interval_days'])->not->toBe(4.0);
    });

    // ⚠️ CASO NEGATIVO
    it('uma única troca NÃO dispara — não existe intervalo', function () use ($rule) {
        expect($rule()->evaluate(makePatternDataset([
            'rewinds' => [new DateTimeImmutable('2026-07-16 17:38:47')],
        ])))->toBe([]);
    });

    it('nenhuma troca NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(makePatternDataset()))->toBe([]);
    });
});

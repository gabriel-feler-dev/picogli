<?php

declare(strict_types=1);

use App\Domain\Metrics\Value\Episode;
use App\Domain\Metrics\Value\EpisodeType;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Patterns\HypoWindowFinder;
use App\Domain\Patterns\Rules\R10SensorQuality;
use App\Domain\Patterns\Rules\R2HypoCluster;
use App\Domain\Patterns\Rules\R4OutlierDay;
use App\Domain\Patterns\Value\CalibrationPair;
use App\Domain\Patterns\Value\DailySnapshot;
use App\Domain\Patterns\Value\Severity;

/**
 * T304 — R2, R4 e R10, no caso positivo E no negativo (§D5).
 */
function hypoAt(string $time, int $nadir = 60): Episode
{
    $start = new DateTimeImmutable("2026-07-21 {$time}");

    return new Episode(
        EpisodeType::Hypoglycemia,
        $start,
        $start->modify('+20 minutes'),
        20.0,
        $nadir,
        4,
    );
}

function readingsOn(string $date, int $count, int $mgdl): array
{
    $readings = [];

    for ($i = 0; $i < $count; $i++) {
        $readings[] = new GlucoseReading(
            new DateTimeImmutable($date.' '.sprintf('%02d:%02d:00', 8 + intdiv($i, 12), ($i % 12) * 5)),
            $mgdl,
        );
    }

    return $readings;
}

function pairsWithError(int $count, float $errorPercent, float $offset = 2.0): array
{
    $pairs = [];

    for ($i = 0; $i < $count; $i++) {
        $bg = 100;
        $sensor = (int) round($bg * (1 + $errorPercent / 100));

        $pairs[] = new CalibrationPair(
            new DateTimeImmutable('2026-07-21 08:00:00'),
            $bg,
            $sensor,
            $offset,
        );
    }

    return $pairs;
}

// ══════════════════════════════════════════════════════════════════════════
describe('HypoWindowFinder — o agrupamento de §D11', function () {

    it('acha a janela de cobertura máxima', function () {
        $windows = (new HypoWindowFinder)->find(
            [hypoAt('00:44'), hypoAt('00:51'), hypoAt('03:41'), hypoAt('17:56'), hypoAt('18:01')],
            2,
            2,
        );

        expect($windows)->toHaveCount(2);
        expect($windows[0]['count'])->toBe(2);
        expect($windows[1]['count'])->toBe(2);
        // O de 03:41 fica fora com janela de 2 h.
        expect((new HypoWindowFinder)->uncovered(
            [hypoAt('00:44'), hypoAt('00:51'), hypoAt('03:41'), hypoAt('17:56'), hypoAt('18:01')],
            $windows,
        ))->toBe(1);
    });

    it('com janela de 3 h cobre os três da madrugada', function () {
        $episodes = [hypoAt('00:44'), hypoAt('00:51'), hypoAt('03:41'), hypoAt('17:56'), hypoAt('18:01')];

        $windows = (new HypoWindowFinder)->find($episodes, 3, 2);

        expect($windows[0]['count'])->toBe(3);
        expect((new HypoWindowFinder)->uncovered($episodes, $windows))->toBe(0);
    });

    // ⚠️ A razão nº 2 de §D11: bin fixo poria estes dois nas janelas 11 e 0, as
    // mais distantes possíveis. Eles estão a uma hora um do outro.
    it('a distância é CÍCLICA — 23:30 e 00:30 caem na mesma janela', function () {
        $windows = (new HypoWindowFinder)->find([hypoAt('23:30'), hypoAt('00:30')], 2, 2);

        expect($windows)->toHaveCount(1);
        expect($windows[0]['count'])->toBe(2);
    });

    // ⚠️ A razão nº 1: bin fixo tem fase arbitrária, e estes dois estão a 20 min.
    it('não é sensível à fase: 01:50 e 02:10 ficam juntos', function () {
        $windows = (new HypoWindowFinder)->find([hypoAt('01:50'), hypoAt('02:10')], 2, 2);

        expect($windows)->toHaveCount(1);
        expect($windows[0]['count'])->toBe(2);
    });

    // ⚠️ A janela tem DIREÇÃO: começa no episódio e se estende para as horas
    // seguintes. Se a distância fosse absoluta, ancorar em 09:00 cobriria de
    // 07:00 a 11:00 — quatro horas de largura para uma janela declarada de duas,
    // e os três episódios cairiam num grupo só.
    it('cobre para frente, não para os dois lados', function () {
        $episodes = [hypoAt('08:00'), hypoAt('09:00'), hypoAt('11:00')];

        $windows = (new HypoWindowFinder)->find($episodes, 2, 2);

        // Janela de 2 h a partir de 08:00 pega 08:00 e 09:00. O de 11:00 fica
        // para a segunda janela. Com distância absoluta seriam 3 numa só.
        expect($windows)->toHaveCount(2);
        expect($windows[0]['count'])->toBe(2);
        expect($windows[1]['count'])->toBe(1);
        expect((new HypoWindowFinder)->uncovered($episodes, $windows))->toBe(0);
    });

    it('guarda o menor nadir de cada janela', function () {
        $windows = (new HypoWindowFinder)->find(
            [hypoAt('00:10', 65), hypoAt('00:50', 52)],
            2,
            2,
        );

        expect($windows[0]['nadir'])->toBe(52);
    });

    it('respeita max_windows e deixa o resto de fora', function () {
        $episodes = [hypoAt('01:00'), hypoAt('07:00'), hypoAt('13:00'), hypoAt('19:00')];

        $windows = (new HypoWindowFinder)->find($episodes, 2, 2);

        expect($windows)->toHaveCount(2);
        expect((new HypoWindowFinder)->uncovered($episodes, $windows))->toBe(2);
    });
});

// ══════════════════════════════════════════════════════════════════════════
describe('R2 — cluster de hipoglicemias (FR-404)', function () {

    $rule = fn () => new R2HypoCluster(
        patternsConfig(),
        patternsMetricsConfig(),
        new HypoWindowFinder,
        fakeProseRenderer(),
    );

    it('dispara com 80% em 2 janelas (§D11)', function () use ($rule) {
        $findings = $rule()->evaluate(makePatternDataset(['hypoEpisodes' => [
            hypoAt('00:44', 56), hypoAt('00:51', 63), hypoAt('03:41', 55),
            hypoAt('17:56', 55), hypoAt('18:01', 56),
        ]]));

        expect($findings)->toHaveCount(1);

        $evidence = $findings[0]->evidence;

        expect($evidence['episodes_total'])->toBe(5);
        expect($evidence['episodes_clustered'])->toBe(4);
        // ⚠️ O que ficou de fora aparece: dizer só "80%" deixaria o leitor
        // supondo que todos se encaixam.
        expect($evidence['episodes_outside'])->toBe(1);
        expect($evidence['windows_used'])->toBe(2);
        expect($evidence['concentration_percent'])->toBe(80.0);
        expect($evidence['worst_nadir'])->toBe(55);
        expect($evidence['window1_start'])->toBe('00:44');
        expect($evidence['window1_end'])->toBe('02:44');
        expect($evidence['window2_start'])->toBe('17:56');
    });

    // Nenhum nadir abaixo de 54 no export → Attention. É a leitura honesta: o
    // padrão existe e merece investigação, sem dramatizar o que o corpo resolveu.
    it('é Attention sem nível 2, e Priority com nadir abaixo de 54', function () use ($rule) {
        $semNivel2 = $rule()->evaluate(makePatternDataset(['hypoEpisodes' => [
            hypoAt('00:44', 56), hypoAt('00:51', 63), hypoAt('17:56', 55),
        ]]));

        expect($semNivel2[0]->severity)->toBe(Severity::Attention);

        $comNivel2 = $rule()->evaluate(makePatternDataset(['hypoEpisodes' => [
            hypoAt('00:44', 45), hypoAt('00:51', 63), hypoAt('17:56', 55),
        ]]));

        expect($comNivel2[0]->severity)->toBe(Severity::Priority);
    });

    it('usa a prosa de janela única quando só há uma', function () use ($rule) {
        $findings = $rule()->evaluate(makePatternDataset(['hypoEpisodes' => [
            hypoAt('00:10'), hypoAt('00:40'), hypoAt('01:10'),
        ]]));

        expect($findings[0]->fallbackProse)->toContain('prose_single_window');
        expect($findings[0]->evidence['windows_used'])->toBe(1);
    });

    // ⚠️ CASO NEGATIVO
    it('5 episódios espalhados em janelas distintas NÃO dispara', function () use ($rule) {
        $findings = $rule()->evaluate(makePatternDataset(['hypoEpisodes' => [
            hypoAt('01:00'), hypoAt('06:00'), hypoAt('11:00'),
            hypoAt('16:00'), hypoAt('21:00'),
        ]]));

        // Top-2 janelas cobrem 2 de 5 = 40%, abaixo dos 60%.
        expect($findings)->toBe([]);
    });

    // ⚠️ Concentração sobre n=2 é coincidência, não padrão.
    it('menos de min_episodes NÃO dispara, mesmo com 100% de concentração', function () use ($rule) {
        $findings = $rule()->evaluate(makePatternDataset(['hypoEpisodes' => [
            hypoAt('00:10'), hypoAt('00:20'),
        ]]));

        expect($findings)->toBe([]);
    });

    it('nenhum episódio NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(makePatternDataset()))->toBe([]);
    });
});

// ══════════════════════════════════════════════════════════════════════════
describe('R4 — dia outlier (FR-406)', function () {

    $rule = fn () => new R4OutlierDay(patternsConfig(), patternsMetricsConfig(), fakeProseRenderer());

    it('dispara para >250 com o dia dominante e os dias limpos', function () use ($rule) {
        $series = GlucoseSeries::of([
            ...readingsOn('2026-07-25', 50, 300),
            ...readingsOn('2026-07-26', 20, 300),
            ...readingsOn('2026-07-27', 30, 120),
        ]);

        $findings = $rule()->evaluate(datasetWithSeries($series, [
            'daily' => [
                new DailySnapshot('2026-07-25', 50, 100.0, 300.0, 0.0, 10.0, 100.0, 0.0, 30.0, 20.0, 100.0),
                new DailySnapshot('2026-07-26', 20, 100.0, 300.0, 0.0, 10.0, 100.0, 0.0, 30.0, 20.0, 100.0),
                new DailySnapshot('2026-07-27', 30, 100.0, 120.0, 100.0, 10.0, 0.0, 0.0, 30.0, 20.0, 100.0),
            ],
        ]));

        expect($findings)->toHaveCount(1);

        $evidence = $findings[0]->evidence;

        expect($evidence['metric'])->toBe('above_250');
        expect($evidence['dominant_date'])->toBe('2026-07-25');
        expect($evidence['dominant_readings'])->toBe(50);
        expect($evidence['total_readings'])->toBe(70);
        expect($evidence['contribution_percent'])->toBe(71.4);
        expect($evidence['days_affected'])->toBe(2);
        // O número que sustenta "nos outros dias você não passou nenhum minuto".
        expect($evidence['clean_days'])->toBe(1);
        expect($evidence['dominant_minutes'])->toBe(250);
        expect($findings[0]->severity)->toBe(Severity::Attention);
    });

    it('conta da série, não de daily_metrics', function () use ($rule) {
        // `daily` diz 0% acima de 250; a série diz 50 leituras. A regra segue a
        // série — reconstruir contagem a partir de percentual traria erro de
        // arredondamento para dentro do dado.
        $series = GlucoseSeries::of([
            ...readingsOn('2026-07-25', 50, 300),
            ...readingsOn('2026-07-26', 10, 120),
        ]);

        $findings = $rule()->evaluate(datasetWithSeries($series, [
            'daily' => [
                new DailySnapshot('2026-07-25', 50, 100.0, 300.0, 0.0, 10.0, 0.0, 0.0, 30.0, 20.0, 100.0),
                new DailySnapshot('2026-07-26', 10, 100.0, 120.0, 100.0, 10.0, 0.0, 0.0, 30.0, 20.0, 100.0),
            ],
        ]));

        expect($findings[0]->evidence['dominant_readings'])->toBe(50);
        expect($findings[0]->evidence['contribution_percent'])->toBe(100.0);
    });

    it('avalia as duas métricas e pode emitir dois achados', function () use ($rule) {
        $series = GlucoseSeries::of([
            ...readingsOn('2026-07-25', 40, 300),
            ...readingsOn('2026-07-26', 40, 50),
        ]);

        $findings = $rule()->evaluate(datasetWithSeries($series));

        expect($findings)->toHaveCount(2);
        expect($findings[0]->evidence['metric'])->toBe('above_250');
        expect($findings[1]->evidence['metric'])->toBe('below_70');
    });

    it('250 exato não conta como acima, 70 exato não conta como abaixo', function () use ($rule) {
        $series = GlucoseSeries::of([
            ...readingsOn('2026-07-25', 40, 250),
            ...readingsOn('2026-07-26', 40, 70),
        ]);

        expect($rule()->evaluate(datasetWithSeries($series)))->toBe([]);
    });

    // ⚠️ CASO NEGATIVO
    it('contribuição distribuída por igual NÃO dispara', function () use ($rule) {
        $series = GlucoseSeries::of([
            ...readingsOn('2026-07-20', 20, 300),
            ...readingsOn('2026-07-21', 20, 300),
            ...readingsOn('2026-07-22', 20, 300),
            ...readingsOn('2026-07-23', 20, 300),
        ]);

        // Cada dia contribui 25%, abaixo do limiar de 40%.
        expect($rule()->evaluate(datasetWithSeries($series)))->toBe([]);
    });

    // ⚠️ Sem esta guarda: com 3 leituras acima de 250 no período, uma delas já é
    // 33% — verdade que não significa nada.
    it('total abaixo de min_total_readings NÃO dispara', function () use ($rule) {
        $series = GlucoseSeries::of([
            ...readingsOn('2026-07-25', 5, 300),
            ...readingsOn('2026-07-26', 30, 120),
        ]);

        expect($rule()->evaluate(datasetWithSeries($series)))->toBe([]);
    });

    it('série sem nenhuma leitura ruim NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(datasetWithSeries(
            GlucoseSeries::of(readingsOn('2026-07-25', 50, 120))
        )))->toBe([]);
    });
});

// ══════════════════════════════════════════════════════════════════════════
describe('R10 — qualidade do sensor (FR-412)', function () {

    $rule = fn () => new R10SensorQuality(patternsConfig(), fakeProseRenderer());

    it('reporta erro médio, n e a janela de pareamento', function () use ($rule) {
        $findings = $rule()->evaluate(makePatternDataset([
            'calibrationPairs' => pairsWithError(39, 10.0),
            'calibrationWindowMinutes' => 10,
        ]));

        expect($findings)->toHaveCount(1);

        $evidence = $findings[0]->evidence;

        expect($evidence['pairs'])->toBe(39);
        // ⚠️ Sem a janela o erro médio não é reproduzível.
        expect($evidence['window_minutes'])->toBe(10);
        expect($evidence['mean_error_percent'])->toBe(10.0);
        expect($evidence['expected_error_percent'])->toBe(15.0);
        expect($evidence['pairs_sensor_higher'])->toBe(39);
        expect($findings[0]->severity)->toBe(Severity::Info);
    });

    it('escolhe a prosa de acima do esperado quando passa da margem', function () use ($rule) {
        $dentro = $rule()->evaluate(makePatternDataset([
            'calibrationPairs' => pairsWithError(20, 12.0),
        ]));
        $acima = $rule()->evaluate(makePatternDataset([
            'calibrationPairs' => pairsWithError(20, 25.0),
        ]));

        expect($dentro[0]->fallbackProse)->not->toContain('prose_above_expected');
        expect($acima[0]->fallbackProse)->toContain('prose_above_expected');
        // ⚠️ Mesmo acima do esperado, continua Info: é característica do
        // equipamento, não resultado de escolha de ninguém.
        expect($acima[0]->severity)->toBe(Severity::Info);
    });

    it('a mediana usa interpolação nos pares, como o percentil da fase 3', function () use ($rule) {
        $pairs = [
            ...pairsWithError(1, 0.0),
            ...pairsWithError(1, 10.0),
            ...pairsWithError(1, 20.0),
            ...pairsWithError(1, 40.0),
            ...pairsWithError(16, 10.0),
        ];

        $findings = $rule()->evaluate(makePatternDataset(['calibrationPairs' => $pairs]));

        expect($findings[0]->evidence['median_error_percent'])->toBe(10.0);
        expect($findings[0]->evidence['max_error_percent'])->toBe(40.0);
    });

    // ⚠️ CASO NEGATIVO
    it('menos de min_pairs NÃO dispara', function () use ($rule) {
        // Com 3 pares, uma calibração atípica move o resultado em dezenas de
        // pontos percentuais.
        expect($rule()->evaluate(makePatternDataset([
            'calibrationPairs' => pairsWithError(3, 10.0),
        ])))->toBe([]);
    });

    it('nenhum par NÃO dispara', function () use ($rule) {
        expect($rule()->evaluate(makePatternDataset()))->toBe([]);
    });
});

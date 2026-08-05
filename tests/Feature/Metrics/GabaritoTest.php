<?php

declare(strict_types=1);

use App\Domain\Import\BolusLinker;
use App\Domain\Import\CarelinkCsvReader;
use App\Domain\Import\EventExploder;
use App\Domain\Import\Persistence\MealEnricher;
use App\Domain\Import\SettingsInferrer;
use App\Domain\Metrics\CoverageCalculator;
use App\Domain\Metrics\EpisodeDetector;
use App\Domain\Metrics\GapDetector;
use App\Domain\Metrics\HourlyPercentileBuilder;
use App\Domain\Metrics\MetricsConfig;
use App\Domain\Metrics\StatisticsCalculator;
use App\Domain\Metrics\ValidityGate;
use App\Domain\Metrics\Value\EpisodeType;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Metrics\Value\Validity;
use App\Jobs\ImportCsvJob;
use App\Models\SensorReading;
use App\Models\User;

/**
 * T101–T103 verificados contra o export real.
 *
 * Os valores vêm de `gabarito.md` §Fase 2, apurados por análise INDEPENDENTE do
 * código que está sendo testado — e antes dele existir. É o que dá ao Artigo XI
 * força real: se divergir, presume-se que o código está errado.
 */
beforeEach(function () {
    $this->user = User::factory()->create();

    (new ImportCsvJob($this->user->id, requireReferenceExport(), 'America/Sao_Paulo'))->handle(
        app(CarelinkCsvReader::class),
        app(EventExploder::class),
        app(BolusLinker::class),
        app(MealEnricher::class),
        app(SettingsInferrer::class),
    );

    // A borda: aqui o banco vira domínio puro. Daqui para dentro, nenhuma
    // calculadora sabe que existe Eloquent.
    $this->series = GlucoseSeries::of(
        SensorReading::where('user_id', $this->user->id)
            ->orderBy('recorded_at_local')
            ->get(['recorded_at_local', 'glucose_mgdl'])
            ->map(fn (SensorReading $r) => new GlucoseReading(
                new DateTimeImmutable($r->recorded_at_local->format('Y-m-d H:i:s')),
                $r->glucose_mgdl,
            ))
            ->all()
    );

    $this->config = MetricsConfig::fromArray(config('clinical'));
});

it('a série tem as 3.616 leituras do gabarito', function () {
    expect($this->series->count())->toBe(3616);
    expect($this->series->first()->at->format('Y-m-d H:i:s'))->toBe('2026-07-16 00:04:07');
    expect($this->series->last()->at->format('Y-m-d H:i:s'))->toBe('2026-07-29 18:47:11');
});

it('reproduz as métricas globais (FR-101)', function () {
    $stats = (new StatisticsCalculator($this->config))->calculate($this->series)->statistics;

    // gabarito.md §Métricas globais
    expect(round($stats->mean))->toBe(142.0);
    expect(round($stats->standardDeviation))->toBe(41.0);
    expect(round($stats->coefficientOfVariation, 1))->toBe(28.8);
    expect(round($stats->gmi, 2))->toBe(6.70);
});

it('reproduz o tempo nas faixas e a soma fecha em 100% (FR-102)', function () {
    $d = (new StatisticsCalculator($this->config))->calculate($this->series)->distribution;

    expect(round($d->percentages['target'], 1))->toBe(83.9);
    expect(round($d->percentages['high'], 1))->toBe(12.9);
    expect(round($d->percentages['very_high'], 1))->toBe(1.9);
    expect(round($d->percentages['low'], 1))->toBe(1.3);
    expect(round($d->percentages['very_low'], 1))->toBe(0.0);

    // ⚠️ Invariante obrigatória.
    expect($d->sumsToOneHundred())->toBeTrue();
    expect($d->total)->toBe(3616);

    // Metas de config/clinical.php — todas atingidas neste período.
    expect($d->timeInRange())->toBeGreaterThan(70.0);
    expect($d->timeAboveRange())->toBeLessThan(25.0);
    expect($d->timeBelowRange())->toBeLessThan(4.0);
});

it('reproduz a cobertura e aprova no portão de validade (FR-103)', function () {
    $coverage = (new CoverageCalculator($this->config))->calculate($this->series);

    // gabarito.md §Cobertura
    expect($coverage->readingCount)->toBe(3616);
    expect($coverage->expectedCount)->toBe(3968);
    expect(round($coverage->spanInDays, 1))->toBe(13.8);
    expect(round($coverage->percentage, 1))->toBe(91.1);

    // 13,8 dias arredonda para 14 pela regra documentada, e a captura passa.
    expect((new ValidityGate($this->config))->evaluate($coverage))->toBe(Validity::Valid);
});

it('reproduz as três lacunas de sensor (FR-107)', function () {
    $detector = new GapDetector($this->config);
    $gaps = $detector->detect($this->series);

    // gabarito.md §Lacunas de sensor
    expect($gaps)->toHaveCount(3);
    expect(round($detector->totalHours($gaps), 1))->toBe(29.0);

    $longest = collect($gaps)->sortByDesc(fn ($g) => $g->minutes)->first();

    // Assert em MINUTOS, nao em horas arredondadas: 1347 min = 22,45 h fica
    // exatamente na borda de arredondamento, e Python e PHP escolhem lados
    // opostos (22,4 vs 22,5). O dado e identico; o formato e que enganava.
    expect($longest->minutes)->toBe(1347.0);
    expect($longest->start->format('Y-m-d H:i'))->toBe('2026-07-21 17:29');
    expect($longest->end->format('Y-m-d H:i'))->toBe('2026-07-22 15:56');
});

it('a maior lacuna é a que vai interromper episódios no T104', function () {
    $gaps = (new GapDetector($this->config))->detect($this->series);
    $longest = collect($gaps)->sortByDesc(fn ($g) => $g->minutes)->first();

    // Sem esta lacuna reconhecida, um episódio poderia atravessar 22 h sem
    // nenhuma medição — afirmação sobre período em que ninguém mediu.
    expect($longest->contains(new DateTimeImmutable('2026-07-22 03:00:00')))->toBeTrue();
    expect($longest->contains(new DateTimeImmutable('2026-07-23 03:00:00')))->toBeFalse();
});

it('reproduz os 5 episodios de hipoglicemia, com a regra de termino de §D3 (FR-106)', function () {
    $episodes = (new EpisodeDetector($this->config))
        ->detect($this->series, EpisodeType::Hypoglycemia);

    expect($episodes)->toHaveCount(5);

    $actual = array_map(fn ($e) => [
        $e->start->format('Y-m-d H:i'),
        (int) $e->durationMinutes,
        $e->nadir(),
    ], $episodes);

    // gabarito.md §Episodios, ATUALIZADO em 04/08/2026 pela regra correta.
    // O episodio de 26/07 comeca as 03:41 (nao 03:56) e dura 45 min (nao 30):
    // a volta a faixa durou 10 min, menos que os 15 de recuperacao.
    expect($actual)->toBe([
        ['2026-07-21 00:44', 40, 56],
        ['2026-07-23 00:51', 20, 63],
        ['2026-07-25 17:56', 20, 55],
        ['2026-07-26 03:41', 45, 55],
        ['2026-07-27 18:01', 15, 56],
    ]);

    // Nenhum nadir abaixo de 54 -> TBR nivel 2 = 0,0% no periodo.
    foreach ($episodes as $episode) {
        expect($episode->nadir())->toBeGreaterThanOrEqual(54);
    }
});

it('reproduz os 2 episodios de hiperglicemia nivel 2 (FR-106)', function () {
    $episodes = (new EpisodeDetector($this->config))
        ->detect($this->series, EpisodeType::HyperglycemiaLevel2);

    expect($episodes)->toHaveCount(2);

    // O episodio de 25/07 dura 275 min (nao 245) e ATRAVESSA a meia-noite:
    // a glicose tocou exatamente 250 as 23:51/23:56 e voltou a 255 as 00:01.
    expect($episodes[0]->start->format('Y-m-d H:i'))->toBe('2026-07-25 19:41');
    expect((int) $episodes[0]->durationMinutes)->toBe(275);
    expect($episodes[0]->end->format('Y-m-d H:i'))->toBe('2026-07-26 00:16');
    expect($episodes[0]->peak())->toBe(324);

    expect($episodes[1]->start->format('Y-m-d H:i'))->toBe('2026-07-26 06:16');
    expect((int) $episodes[1]->durationMinutes)->toBe(70);
    expect($episodes[1]->peak())->toBe(271);
});

it('nenhum episodio atravessa a lacuna de 1347 min (FR-106)', function () {
    $detector = new EpisodeDetector($this->config);

    $all = [
        ...$detector->detect($this->series, EpisodeType::Hypoglycemia),
        ...$detector->detect($this->series, EpisodeType::HyperglycemiaLevel2),
    ];

    $gapStart = new DateTimeImmutable('2026-07-21 17:29:07');
    $gapEnd = new DateTimeImmutable('2026-07-22 15:56:07');

    foreach ($all as $episode) {
        // Um episodio que comecasse antes e terminasse depois da lacuna
        // afirmaria 22 h de excursao que ninguem mediu.
        $spansGap = $episode->start < $gapStart && $episode->end > $gapEnd;

        expect($spansGap)->toBeFalse(
            "episodio de {$episode->start->format('Y-m-d H:i')} atravessa a lacuna"
        );
    }
});

it('percentis por hora batem com o perfil do gabarito (FR-202)', function () {
    $profile = (new HourlyPercentileBuilder)->build($this->series);

    expect($profile)->toHaveCount(24);

    // As 04h sao a hora mais estavel do periodo (media 123, 0% acima de 180):
    // a mediana fica proxima da media e a banda e estreita.
    expect($profile[4]->count)->toBe(156);
    expect($profile[4]->median())->toBeGreaterThan(110.0)->toBeLessThan(135.0);

    // As 20h sao a pior hora (media 171): mediana mais alta e banda mais larga.
    expect($profile[20]->median())->toBeGreaterThan(150.0);
    expect($profile[20]->median())->toBeGreaterThan($profile[4]->median());

    // A banda das 20h e mais larga que a das 04h — e a leitura visual do AGP
    // que sustenta "sua tarde e mais instavel que sua madrugada".
    $spread04 = $profile[4]->p95 - $profile[4]->p5;
    $spread20 = $profile[20]->p95 - $profile[20]->p5;

    expect($spread20)->toBeGreaterThan($spread04);
});

it('a invariante de monotonicidade vale em TODA hora do arquivo real', function () {
    foreach ((new HourlyPercentileBuilder)->build($this->series) as $hour => $p) {
        // Banda invertida num grafico nao e lida como bug por ninguem.
        expect($p->isMonotonic())->toBeTrue("percentis fora de ordem na hora {$hour}");
    }
});

<?php

declare(strict_types=1);

use App\Domain\Import\Value\InferredSettings;
use App\Domain\Metrics\HourlyProfileBuilder;
use App\Domain\Metrics\MetricsConfig;
use App\Domain\Metrics\StatisticsCalculator;
use App\Domain\Metrics\Value\Coverage;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Metrics\Value\Validity;
use App\Domain\Patterns\DaypartAggregator;
use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Bindings de caso de teste
|--------------------------------------------------------------------------
|
| `Feature` recebe o TestCase do Laravel + RefreshDatabase: são os testes que
| tocam banco, fila e HTTP.
|
| `Unit` fica DELIBERADAMENTE sem binding, usando o TestCase do PHPUnit puro.
| Os testes de `app/Domain/` não sobem o framework — rodam em milissegundos.
|
| Essa separação é o que torna a convenção do AGENTS.md ("app/Domain é PHP
| puro, sem Eloquent nem facades") verificável em vez de aspiracional: se
| alguém usar um helper do Laravel numa classe de domínio, o teste unitário
| quebra na hora, porque não existe container ali.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Fixture do export de referência
|--------------------------------------------------------------------------
|
| O CSV real fica em storage/carelink/ e NÃO é versionado (contém nome,
| número de série da bomba e histórico de glicemia).
|
| Consequência: quem clonar o projeto não tem o arquivo. Em vez de deixar a
| suíte falhar com "file not found", os testes que dependem dele fazem skip
| com mensagem explícita apontando para a spec.
|
| Ver plan.md §Configuração de ambiente.
|
*/

function referenceExportPath(): string
{
    return dirname(__DIR__).'/storage/carelink/reference-export.csv';
}

function requireReferenceExport(): string
{
    $path = referenceExportPath();

    if (! is_file($path)) {
        test()->markTestSkipped(
            'Export de referência ausente. Copie um CSV do CareLink para '
            .'storage/carelink/reference-export.csv (ver plan.md). '
            .'Valores esperados em specs/001-fundacao-de-dados/gabarito.md.'
        );
    }

    return $path;
}

/*
|--------------------------------------------------------------------------
| Fábricas do domínio de padrões (Spec 004)
|--------------------------------------------------------------------------
|
| Vivem aqui, e não dentro de um arquivo de teste, porque T303–T307 vão montar
| dataset à mão dez vezes — uma por regra, no caso positivo e no negativo (§D5).
|
| ⚠️ Declaradas em `Pest.php` de propósito. Função declarada dentro de um arquivo
| de teste é global por acidente: funciona porque o Pest carrega todos os
| arquivos no mesmo processo, e quebra no dia em que alguém rodar um subconjunto.
| Aqui o carregamento é garantido.
|
*/

function patternsMetricsConfig(): MetricsConfig
{
    return new MetricsConfig(
        ranges: [
            'very_low' => ['max' => 53],
            'low' => ['min' => 54, 'max' => 69],
            'target' => ['min' => 70, 'max' => 180],
            'high' => ['min' => 181, 'max' => 250],
            'very_high' => ['min' => 251],
        ],
        gmi: ['intercept' => 3.31, 'slope' => 0.02392],
        validity: ['min_days' => 14, 'min_coverage' => 0.70, 'min_days_rounding_floor' => 13.5],
        sensor: ['readings_per_day' => 288, 'interval_minutes' => 5, 'gap_threshold_minutes' => 30],
        episodes: [
            'hypoglycemia' => ['threshold' => 70, 'min_duration_minutes' => 15, 'recovery_minutes' => 15],
            'hyperglycemia_level2' => ['threshold' => 250, 'min_duration_minutes' => 30, 'recovery_minutes' => 15],
        ],
    );
}

/** Os mesmos limites de `config/clinical.dayparts`, sem depender do container. */
function patternsDaypartBounds(): array
{
    return [
        'dawn' => ['label' => 'madrugada', 'from' => 0, 'to' => 5],
        'morning' => ['label' => 'manhã', 'from' => 6, 'to' => 11],
        'afternoon' => ['label' => 'tarde', 'from' => 12, 'to' => 17],
        'evening' => ['label' => 'noite', 'from' => 18, 'to' => 23],
    ];
}

/** Limiares reais de `config/patterns.php`, resolvidos sem container. */
function patternsConfig(): PatternsConfig
{
    return PatternsConfig::fromArray(
        require dirname(__DIR__).'/config/patterns.php'
    );
}

/**
 * Um `PatternDataset` montado a partir de arrays — sem banco, sem container.
 *
 * É o que faz cada regra testável no caso positivo e no negativo. Se um dia isto
 * precisar de banco, o §D2 foi violado em algum lugar.
 *
 * @param  array<string, mixed>  $overrides
 */
function makePatternDataset(array $overrides = []): PatternDataset
{
    $series = $overrides['series'] ?? GlucoseSeries::of([
        new GlucoseReading(new DateTimeImmutable('2026-07-16 03:00:00'), 120),
        new GlucoseReading(new DateTimeImmutable('2026-07-16 14:00:00'), 200),
        new GlucoseReading(new DateTimeImmutable('2026-07-17 20:00:00'), 260),
    ]);

    $config = patternsMetricsConfig();

    $defaults = [
        'periodStart' => '2026-07-16',
        'periodEnd' => '2026-07-29',
        'series' => $series,
        'metrics' => (new StatisticsCalculator($config))->calculate($series),
        'coverage' => new Coverage($series->count(), 4032, 13.8, 91.1),
        'validity' => Validity::Valid,
        'hourly' => (new HourlyProfileBuilder($config))->build($series),
        'dayparts' => (new DaypartAggregator($config, patternsDaypartBounds()))
            ->aggregate($series),
        'hypoEpisodes' => [],
        'hyperEpisodes' => [],
        'gaps' => [],
        'daily' => [],
        'meals' => [],
        'autoInsulinByDate' => [],
        'deviceEventCounts' => [],
        'deviceCategoryCounts' => [],
        'rewinds' => [],
        'settings' => new InferredSettings([], [], null),
        'calibrationPairs' => [],
        'calibrationWindowMinutes' => 10,
        'hasStaleMetrics' => false,
        'metricsVersion' => '2026.08.1',
    ];

    return new PatternDataset(...array_merge($defaults, $overrides));
}

/**
 * Dataset com uma série específica, com os períodos do dia **recalculados**.
 *
 * ⚠️ Existe porque trocar a série sem recalcular `dayparts` produziria um dataset
 * internamente incoerente — leituras de um lado, agregação de outro. Regra que o
 * recebesse daria resposta plausível sobre dado que não existe, e o teste
 * confirmaria a resposta errada.
 */
function datasetWithSeries(
    GlucoseSeries $series,
    array $overrides = [],
): PatternDataset {
    $config = patternsMetricsConfig();

    return makePatternDataset(array_merge([
        'series' => $series,
        'dayparts' => (new DaypartAggregator($config, patternsDaypartBounds()))
            ->aggregate($series),
        'hourly' => (new HourlyProfileBuilder($config))->build($series),
        'metrics' => (new StatisticsCalculator($config))->calculate($series),
    ], $overrides));
}

/**
 * Renderizador de prosa que não depende de `lang/`.
 *
 * Usado nos testes de UNIDADE das regras: o que se testa ali é a decisão de
 * disparar e a evidência, não o texto. A prosa de verdade é conferida nos testes
 * de feature, com o container e o arquivo de idioma reais.
 */
function fakeProseRenderer(): ProseRenderer
{
    return new class implements ProseRenderer
    {
        public function render(RuleId $rule, string $key, array $evidence): string
        {
            return $rule->value.'/'.$key.' com '.count($evidence).' evidências';
        }
    };
}

/*
|--------------------------------------------------------------------------
| Expectativas customizadas
|--------------------------------------------------------------------------
*/

/**
 * Nenhum `:placeholder` sobrou na prosa.
 *
 * ⚠️ Não basta procurar `:` — a prosa legítima usa dois-pontos ("Esse número é
 * característica do equipamento: o Guardian Sensor 3 precisa de calibração").
 * Foi o primeiro jeito que escrevi este teste, e ele acusou texto correto.
 *
 * O que caracteriza placeholder é `:` colado numa palavra em snake_case, que é
 * exatamente o formato das chaves de `evidence`.
 */
expect()->extend('toHaveNoUnresolvedPlaceholder', function () {
    $prose = (string) $this->value;

    expect(preg_match_all('/:[a-z][a-z0-9_]*/', $prose, $matches))->toBe(
        0,
        'placeholder sem substituição: '.implode(', ', $matches[0] ?? [])
    );

    return $this;
});

/**
 * Compara float com tolerância explícita.
 *
 * Usada nos asserts do gabarito: somas de insulina em ponto flutuante não
 * batem exatamente. `toBe(295.15)` falharia por 1e-13 e mandaria alguém
 * caçar um bug que não existe.
 */
expect()->extend('toBeCloseToValue', function (float $expected, float $tolerance = 0.005) {
    $actual = (float) $this->value;

    expect(abs($actual - $expected))->toBeLessThanOrEqual(
        $tolerance,
        sprintf('Esperado %.5f (±%.5f), obtido %.5f', $expected, $tolerance, $actual)
    );

    return $this;
});

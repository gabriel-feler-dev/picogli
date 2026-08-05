<?php

declare(strict_types=1);

use App\Domain\Import\BolusLinker;
use App\Domain\Import\CarelinkCsvReader;
use App\Domain\Import\EventExploder;
use App\Domain\Import\Persistence\MealEnricher;
use App\Domain\Import\SettingsInferrer;
use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Patterns\Persistence\PatternDatasetBuilder;
use App\Domain\Patterns\Rules\R10SensorQuality;
use App\Domain\Patterns\Rules\R1DaypartDrift;
use App\Domain\Patterns\Rules\R2HypoCluster;
use App\Domain\Patterns\Rules\R4OutlierDay;
use App\Domain\Patterns\Rules\R5SensorGapLoopImpact;
use App\Domain\Patterns\Rules\R7SensorAdherence;
use App\Domain\Patterns\Rules\R8ReservoirChanges;
use App\Domain\Patterns\Rules\R9CalibrationBurden;
use App\Domain\Patterns\Value\Severity;
use App\Jobs\ComputeMetricsJob;
use App\Jobs\ImportCsvJob;
use App\Models\User;

/**
 * T305 — R5 contra o export de referência.
 *
 * ⚠️ **O achado que atravessa dois blocos do CSV.** A lacuna vem do bloco Sensor;
 * a insulina automática, do bloco `Aggregated Auto Insulin Data`. Nenhum relatório
 * da Medtronic mostra essa conexão.
 *
 * Valores do `gabarito.md` §Lacunas (1.347 min) e §Insulina (9,0 U em 22/07 contra
 * média de 31,4 U).
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

    (new ComputeMetricsJob($this->user->id))->handle(app(DailyMetricsWriter::class));

    $this->dataset = app(PatternDatasetBuilder::class)->forLatestPeriod($this->user->id);
    $this->findings = app(R5SensorGapLoopImpact::class)->evaluate($this->dataset);
});

it('dispara em 22/07 com a lacuna de 1.347 min e 9,0 U de automática', function () {
    expect($this->findings)->toHaveCount(1);

    $evidence = $this->findings[0]->evidence;

    // Gabarito §Lacunas — em MINUTOS, porque 22,45 h fica na borda do
    // arredondamento e o valor formatado criava divergência fantasma.
    expect($evidence['gap_minutes'])->toBe(1347);
    expect($evidence['gap_start'])->toBe('2026-07-21 17:29');
    expect($evidence['gap_end'])->toBe('2026-07-22 15:56');

    // O dia com mais minutos de lacuna, não o de início.
    expect($evidence['affected_date'])->toBe('2026-07-22');

    // Gabarito §Insulina.
    expect($evidence['auto_insulin_u'])->toBe(9.0);
    expect($evidence['period_mean_auto_insulin_u'])->toBe(31.4);

    // ⚠️ 71,4% e não 71,3%. A soma dos 14 dias é 440,0 U, logo a média exata é
    // 31,4286 — exibida como 31,4. A queda usa a EXATA: 1 − 9,0/31,4286 = 71,36%.
    // Com a média já arredondada daria 71,34%, que exibe 71,3.
    //
    // Terceira vez nesta tarefa que o valor formatado e o exato divergem na
    // primeira casa. É a mesma lição dos 295,16 U da fase 1, e a razão de a
    // formatação viver no renderizador e nunca na regra.
    expect($evidence['drop_percent'])->toBe(71.4);

    expect($this->findings[0]->severity)->toBe(Severity::Attention);
});

it('reproduz as duas frações do total: ~27% no dia contra ~60% no período', function () {
    $evidence = $this->findings[0]->evidence;

    // Gabarito: "27% do total contra 60% habituais".
    expect($evidence['day_automatic_fraction_percent'])->toBeCloseToValue(27.0, 1.5);
    expect($evidence['period_automatic_fraction_percent'])->toBeCloseToValue(60.0, 1.5);

    // A cobertura do dia confirma a lacuna por outro caminho.
    expect($evidence['day_coverage_percent'])->toBeCloseToValue(33.7, 0.6);
});

/**
 * ⚠️ Das três lacunas do export (125, 1.347 e 266 min), só uma passa dos 360
 * minutos exigidos. As outras duas são o caso negativo vindo do dado real.
 */
it('as outras duas lacunas do export não qualificam', function () {
    expect($this->dataset->gaps)->toHaveCount(3);

    $longas = array_filter($this->dataset->gaps, fn ($g): bool => $g->minutes >= 360);

    expect($longas)->toHaveCount(1);
    expect($this->findings)->toHaveCount(1);
});

it('a prosa explica o MECANISMO, com os números certos', function () {
    $prose = $this->findings[0]->fallbackProse;

    expect($prose)->toContain('SmartGuard');
    expect($prose)->toContain('modo manual');
    expect($prose)->toContain('2026-07-22');
    expect($prose)->toContain('1.347 minutos');
    expect($prose)->toContain('9 U');
    expect($prose)->toContain('31,4 U');
    expect($prose)->toHaveNoUnresolvedPlaceholder();
});

/**
 * ⚠️ Artigo IV — o achado é sobre o EQUIPAMENTO, não sobre a pessoa. E o dado não
 * sustentaria nenhuma das afirmações abaixo, mesmo que o tom permitisse.
 */
it('a prosa não cobra nada de quem usa o aparelho', function () {
    $prose = mb_strtolower($this->findings[0]->fallbackProse);

    foreach ([
        'você deveria', 'deveria ter', 'falta de', 'descuido', 'culpa',
        'troque o sensor', 'poderia ter evitado', 'faltou',
    ] as $proibido) {
        expect(str_contains($prose, $proibido))->toBeFalse(
            "a prosa de R5 contém '{$proibido}'"
        );
    }

    // Reconhece o trabalho em vez de cobrá-lo: quem passou o dia corrigindo com
    // bolus manual trabalhou mais, não menos.
    expect($prose)->toContain('à mão');
});

/**
 * As oito regras prontas juntas — prévia do `PatternEngine` (T308).
 */
it('as oito regras produzem 8 achados no export de referência', function () {
    $findings = [
        ...app(R1DaypartDrift::class)->evaluate($this->dataset),
        ...app(R2HypoCluster::class)->evaluate($this->dataset),
        ...app(R4OutlierDay::class)->evaluate($this->dataset),
        ...$this->findings,
        ...app(R7SensorAdherence::class)->evaluate($this->dataset),
        ...app(R8ReservoirChanges::class)->evaluate($this->dataset),
        ...app(R9CalibrationBurden::class)->evaluate($this->dataset),
        ...app(R10SensorQuality::class)->evaluate($this->dataset),
    ];

    expect($findings)->toHaveCount(8);

    // NFR-403 — o ensaio do Artigo VII, agora sobre oito regras.
    foreach ($findings as $finding) {
        foreach ($finding->evidence as $key => $value) {
            expect(is_scalar($value) || $value === null)->toBeTrue(
                "{$finding->ruleId->value}.{$key} não é escalar"
            );
            expect(preg_match('/^[a-z][a-z0-9_]*$/', $key))->toBe(1);
        }

        expect(trim($finding->fallbackProse))->not->toBe('');
        expect($finding->fallbackProse)->toHaveNoUnresolvedPlaceholder();
    }
});

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
use App\Domain\Patterns\Rules\R3Rollercoaster;
use App\Domain\Patterns\Rules\R4OutlierDay;
use App\Domain\Patterns\Rules\R5SensorGapLoopImpact;
use App\Domain\Patterns\Rules\R6CarbRatioCoherence;
use App\Domain\Patterns\Rules\R7SensorAdherence;
use App\Domain\Patterns\Rules\R8ReservoirChanges;
use App\Domain\Patterns\Rules\R9CalibrationBurden;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;
use App\Jobs\ComputeMetricsJob;
use App\Jobs\ImportCsvJob;
use App\Models\User;

/**
 * T307 — R3 contra o export de referência, e as DEZ regras juntas.
 *
 * ⚠️ Valores do `gabarito.md` §Fase 4 §R3, apurados em T307 por script Python
 * independente (`specs/004-motor-de-padroes/apuracao/t307_apuracao.py`).
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
    $this->findings = app(R3Rollercoaster::class)->evaluate($this->dataset);
});

it('dispara UMA vez, no evento de 25/07', function () {
    expect($this->findings)->toHaveCount(1);

    $evidence = $this->findings[0]->evidence;

    expect($evidence['date'])->toBe('2026-07-25');
    expect($evidence['nadir'])->toBe(55);
    // ⚠️ 18:06 — o instante do NADIR. O §Episódios registra 17:56, que é o
    // INÍCIO do episódio. A janela conta do nadir.
    expect($evidence['nadir_at'])->toBe('18:06');

    expect($evidence['meals'])->toBe(3);
    expect($evidence['carbs_g'])->toBe(109.0);
    expect($evidence['first_meal_at'])->toBe('18:09');
    expect($evidence['last_meal_at'])->toBe('21:26');

    expect($evidence['hyper_start_at'])->toBe('19:41');
    expect($evidence['hyper_duration_minutes'])->toBe(275);
    expect($evidence['hyper_peak'])->toBe(324);

    expect($this->findings[0]->severity)->toBe(Severity::Attention);
});

/**
 * ⭐ **O caso negativo de §D5, vindo do dado real — e testando duas condições.**
 *
 * Dos 5 episódios de hipo, 4 não fecham a cadeia: três param na segunda condição
 * (sem carboidrato na janela) e o de **27/07 passa da segunda e para na
 * terceira** — 46 g de carboidrato, acima do limiar, e nenhuma hiperglicemia.
 */
it('os outros 4 episódios de hipo NÃO fecham a cadeia', function () {
    expect($this->dataset->hypoEpisodes)->toHaveCount(5);
    expect($this->findings)->toHaveCount(1);
});

it('a prosa começa pelo MECANISMO, antes de qualquer número', function () {
    $prose = $this->findings[0]->fallbackProse;

    // ⚠️ A ORDEM É PARTE DO REQUISITO. Quem lê "109 g" primeiro já se sentiu
    // cobrado antes de chegar à explicação.
    expect(mb_substr($prose, 0, 60))->toContain('Queda de glicose dispara fome intensa');
    expect(mb_strpos($prose, 'reação fisiológica'))
        ->toBeLessThan(mb_strpos($prose, '109'));

    expect($prose)->toContain('não uma questão de controle');
    expect($prose)->toHaveNoUnresolvedPlaceholder();
});

/**
 * ⚠️ O exemplo canônico do Artigo IV. As duas redações carregam o MESMO número;
 * a diferença é o que cada uma diz sobre a pessoa.
 */
it('a prosa não julga a escolha de quem comeu', function () {
    $prose = mb_strtolower($this->findings[0]->fallbackProse);

    foreach ([
        'você comeu', 'você ingeriu', 'exagerou', 'excesso de', 'descontrole',
        'falta de controle', 'deveria ter', 'poderia ter evitado', 'culpa',
    ] as $proibido) {
        expect(str_contains($prose, $proibido))->toBeFalse(
            "a prosa de R3 contém '{$proibido}'"
        );
    }

    // Fecha a leitura: aconteceu UMA vez em 14 dias. Sem esta frase, o achado
    // descreve um episódio e deixa a impressão de rotina.
    expect($prose)->toContain('aconteceu uma vez');
});

/**
 * ⭐⭐ **AS DEZ REGRAS, JUNTAS, PELA PRIMEIRA VEZ.**
 */
it('as DEZ regras produzem 10 achados no export de referência', function () {
    $findings = [
        ...app(R1DaypartDrift::class)->evaluate($this->dataset),
        ...app(R2HypoCluster::class)->evaluate($this->dataset),
        ...$this->findings,
        ...app(R4OutlierDay::class)->evaluate($this->dataset),
        ...app(R5SensorGapLoopImpact::class)->evaluate($this->dataset),
        ...app(R6CarbRatioCoherence::class)->evaluate($this->dataset),
        ...app(R7SensorAdherence::class)->evaluate($this->dataset),
        ...app(R8ReservoirChanges::class)->evaluate($this->dataset),
        ...app(R9CalibrationBurden::class)->evaluate($this->dataset),
        ...app(R10SensorQuality::class)->evaluate($this->dataset),
    ];

    // Uma por regra: R4 emite só o achado de `>250` (o `<70` não dispara).
    expect($findings)->toHaveCount(10);

    $regras = array_map(fn ($f): string => $f->ruleId->value, $findings);
    expect(array_unique($regras))->toHaveCount(10);

    // ⚠️ NFR-403 — o ensaio do Artigo VII sobre o motor COMPLETO. Se este teste
    // passa, a allowlist do `PayloadSanitizer` da fase 5 tem formato conhecido.
    foreach ($findings as $finding) {
        expect($finding->evidence)->not->toBeEmpty();

        foreach ($finding->evidence as $key => $value) {
            expect(is_scalar($value) || $value === null)->toBeTrue(
                "{$finding->ruleId->value}.{$key} não é escalar"
            );
            expect(preg_match('/^[a-z][a-z0-9_]*$/', $key))->toBe(1);
        }

        expect(trim($finding->fallbackProse))->not->toBe('');
        expect($finding->fallbackProse)->toHaveNoUnresolvedPlaceholder();
    }

    // Exatamente um achado exige encaminhamento clínico, e é o de R6.
    $handoff = array_values(array_filter($findings, fn ($f): bool => $f->requiresClinicalHandoff));
    expect($handoff)->toHaveCount(1);
    expect($handoff[0]->ruleId)->toBe(RuleId::CarbRatioCoherence);

    // R9 respeita o teto de severidade imposto pelo enum.
    $r9 = array_values(array_filter(
        $findings,
        fn ($f): bool => $f->ruleId === RuleId::CalibrationBurden,
    ));
    expect($r9[0]->severity)->toBe(Severity::Info);
});

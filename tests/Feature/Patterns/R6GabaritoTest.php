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
 * T306 — R6 contra o export de referência, com a prosa de verdade.
 *
 * ⚠️ Valores do `gabarito.md` §Fase 4 §R6, apurados em T306 por script Python
 * independente (`specs/004-motor-de-padroes/apuracao/t306_apuracao.py`).
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
    $this->finding = app(R6CarbRatioCoherence::class)->evaluate($this->dataset)[0] ?? null;
});

it('dispara com manhã 5,28 g/U contra noite 8,00 g/U', function () {
    expect($this->finding)->not->toBeNull();

    $evidence = $this->finding->evidence;

    // Gabarito §R6: manhã 5,278 (n=18) · tarde 6,000 (n=17) · noite 8,000 (n=17).
    expect($evidence['strongest_daypart'])->toBe('morning');
    expect($evidence['strongest_carb_ratio'])->toBe(5.28);
    expect($evidence['strongest_meals'])->toBe(18);
    expect($evidence['strongest_percent_above'])->toBe(9.44);

    expect($evidence['weakest_daypart'])->toBe('evening');
    expect($evidence['weakest_carb_ratio'])->toBe(8.0);
    expect($evidence['weakest_meals'])->toBe(17);
    expect($evidence['weakest_percent_above'])->toBe(22.62);

    expect($evidence['ratio_spread_g'])->toBe(2.72);
    expect($evidence['percent_above_difference_pp'])->toBe(13.18);

    // Madrugada excluída: zero refeições. Sobram manhã, tarde e noite.
    expect($evidence['dayparts_compared'])->toBe(3);
    expect($evidence['afternoon_carb_ratio'])->toBe(6.0);
    expect($evidence['afternoon_meals'])->toBe(17);

    expect($this->finding->severity)->toBe(Severity::Attention);
});

// ⚠️ A hora 06h tem CR de 10,0 g/U com UMA refeição. A spec previa excluí-la;
// com média ponderada por refeição ela move a manhã de 5,000 para 5,278 — 0,278
// g/U — e a tendência 5 → 6 → 8 sobrevive intacta.
it('o outlier de 06h não quebra a leitura de tendência', function () {
    $evidence = $this->finding->evidence;

    expect($evidence['morning_carb_ratio'])->toBe(5.28);
    expect($evidence['morning_carb_ratio'])->toBeLessThan($evidence['afternoon_carb_ratio']);
    expect($evidence['afternoon_carb_ratio'])->toBeLessThan($evidence['weakest_carb_ratio']);
});

describe('a prosa', function () {

    it('explica que número maior significa MENOS insulina', function () {
        $prose = $this->finding->fallbackProse;

        // Razão de carboidrato é contraintuitiva: 8 g/U dá menos insulina que
        // 5 g/U. Sem esta frase, o número maior parece "mais".
        expect($prose)->toContain('MENOS insulina');
        expect($prose)->toContain('1 unidade para cada 5,3 g');
        expect($prose)->toContain('1 unidade para cada 8 g');
        expect($prose)->toHaveNoUnresolvedPlaceholder();
    });

    // ⚠️ O período de CR mais fraco é a NOITE (22,62%), mas o de mais tempo alto
    // é a TARDE (24,10%). Dizer "é à noite que sua glicose fica mais alta" seria
    // falso — a prosa cita o tempo alto de cada período comparado, e só.
    it('não afirma que o período de CR mais fraco é o pior do dia', function () {
        $prose = mb_strtolower($this->finding->fallbackProse);

        expect($prose)->not->toContain('é à noite que sua glicose fica mais alta');
        expect($prose)->toContain('22,6%');
        expect($prose)->toContain('9,4%');
    });

    it('termina devolvendo a pergunta ao médico', function () {
        $prose = $this->finding->fallbackProse;

        expect($prose)->toContain('endocrinologista');
        expect($prose)->toContain('pergunta');
        // Admite o limite do próprio app, em vez de afirmar que a configuração
        // está errada.
        expect($prose)->toContain('Pode haver motivo clínico');
        expect($prose)->toContain('não tem como saber');
    });
});

/**
 * ⚠️⚠️ **T306.4 — O TESTE ANTI-SUGESTÃO.**
 *
 * A prosa não pode conter nenhum valor de CR, basal ou ISF **sugerido**. Só os
 * observados. É a violação mais fácil de cometer no projeto inteiro, porque a
 * frase "sua bomba dá menos insulina à noite" pede naturalmente um "então
 * deveria dar mais".
 */
describe('o teste anti-sugestão (Artigo VI, camada 3)', function () {

    /**
     * Verbos e construções que transformariam observação em conduta.
     *
     * ⚠️ Fonte ÚNICA desde a fase 5 (`config/tone.php`). A lista era local aqui
     * até o prompt de narrativa (T404) precisar dela: com duas cópias, uma
     * construção acrescentada neste teste não chegaria ao modelo, e ele
     * continuaria autorizado a usá-la.
     */
    // Closure, e nao valor: `config()` no carregamento do arquivo roda antes
    // de o container subir. Mesmo padrao de `$rule = fn () => ...` nas regras.
    $conduta = fn (): array => config('tone.forbidden_conduct');

    it('a prosa de R6 não sugere conduta', function () use ($conduta) {
        // Sem isto, uma lista esvaziada por acidente faria o teste passar
        // sem verificar nada — a falha silenciosa clássica de um guarda.
        expect(count($conduta()))->toBeGreaterThan(10);

        $prose = mb_strtolower($this->finding->fallbackProse);

        foreach ($conduta() as $frase) {
            expect(str_contains($prose, $frase))->toBeFalse(
                "a prosa de R6 contém a conduta '{$frase}'"
            );
        }
    });

    // ⚠️ **AUTOTESTE.** Um detector que nunca acusaria nada é pior que nenhum
    // detector, porque dá a sensação de proteção. Cada frase abaixo é uma
    // violação real que a varredura precisa pegar.
    it('a varredura realmente pega uma sugestão', function () use ($conduta) {
        $violacoes = [
            'sua razão à noite deveria ser 6 g/U',
            'ajuste para 1 unidade a cada 6 gramas',
            'recomendo conversar sobre reduzir para 6',
            'o ideal seria uma razão mais forte à noite',
        ];

        foreach ($violacoes as $texto) {
            $pego = false;

            foreach ($conduta() as $frase) {
                if (str_contains(mb_strtolower($texto), $frase)) {
                    $pego = true;
                    break;
                }
            }

            expect($pego)->toBeTrue("a varredura NÃO pegaria: \"{$texto}\"");
        }
    });

    it('a varredura não acusa a prosa legítima por acidente', function () use ($conduta) {
        // O contrário do autoteste: um guarda hipersensível é desligado na
        // primeira semana, e aí não protege nada.
        $legitimas = [
            'no período da manhã, 1 unidade para cada 5,3 g',
            'é uma boa pergunta para levar ao seu endocrinologista',
            'pode haver motivo clínico para a configuração ser assim',
        ];

        foreach ($legitimas as $texto) {
            foreach ($conduta() as $frase) {
                expect(str_contains(mb_strtolower($texto), $frase))->toBeFalse(
                    "a varredura acusaria texto legítimo: \"{$texto}\""
                );
            }
        }
    });
});

/**
 * As nove regras prontas juntas — prévia do `PatternEngine` (T308).
 */
it('as nove regras produzem 9 achados no export de referência', function () {
    $findings = [
        ...app(R1DaypartDrift::class)->evaluate($this->dataset),
        ...app(R2HypoCluster::class)->evaluate($this->dataset),
        ...app(R4OutlierDay::class)->evaluate($this->dataset),
        ...app(R5SensorGapLoopImpact::class)->evaluate($this->dataset),
        ...app(R6CarbRatioCoherence::class)->evaluate($this->dataset),
        ...app(R7SensorAdherence::class)->evaluate($this->dataset),
        ...app(R8ReservoirChanges::class)->evaluate($this->dataset),
        ...app(R9CalibrationBurden::class)->evaluate($this->dataset),
        ...app(R10SensorQuality::class)->evaluate($this->dataset),
    ];

    expect($findings)->toHaveCount(9);

    // NFR-403 — o ensaio do Artigo VII, agora sobre nove regras.
    foreach ($findings as $finding) {
        foreach ($finding->evidence as $key => $value) {
            expect(is_scalar($value) || $value === null)->toBeTrue(
                "{$finding->ruleId->value}.{$key} não é escalar"
            );
            expect(preg_match('/^[a-z][a-z0-9_]*$/', $key))->toBe(1);
        }

        expect($finding->fallbackProse)->toHaveNoUnresolvedPlaceholder();
    }

    // Exatamente um achado exige encaminhamento clínico, e é o de R6.
    $comHandoff = array_values(array_filter(
        $findings,
        fn ($f): bool => $f->requiresClinicalHandoff,
    ));

    expect($comHandoff)->toHaveCount(1);
    expect($comHandoff[0]->ruleId)->toBe(RuleId::CarbRatioCoherence);
});

<?php

declare(strict_types=1);

use App\Domain\Import\BolusLinker;
use App\Domain\Import\CarelinkCsvReader;
use App\Domain\Import\EventExploder;
use App\Domain\Import\Persistence\MealEnricher;
use App\Domain\Import\SettingsInferrer;
use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Patterns\Persistence\PatternDatasetBuilder;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rules\R1DaypartDrift;
use App\Domain\Patterns\Rules\R7SensorAdherence;
use App\Domain\Patterns\Rules\R8ReservoirChanges;
use App\Domain\Patterns\Rules\R9CalibrationBurden;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;
use App\Jobs\ComputeMetricsJob;
use App\Jobs\ImportCsvJob;
use App\Models\User;

/**
 * T303 — as quatro regras contra o export de referência, com a prosa de verdade.
 *
 * ⚠️ Valores do `gabarito.md` §Fase 4 (apurado em T300 por script Python
 * independente) e §Qualidade/§Eventos (fase 1). Se o PHP divergir, presume-se que
 * o PHP está errado (Artigo XI).
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
});

describe('R1 — deriva por período do dia', function () {

    it('dispara com tarde 24,10% contra madrugada 4,17% = 5,78x', function () {
        $findings = app(R1DaypartDrift::class)->evaluate($this->dataset);

        expect($findings)->toHaveCount(1);

        $evidence = $findings[0]->evidence;

        expect($evidence['worst_daypart'])->toBe('afternoon');
        expect($evidence['worst_percent_above'])->toBe(24.10);
        expect($evidence['worst_readings'])->toBe(917);
        expect($evidence['best_daypart'])->toBe('dawn');
        expect($evidence['best_percent_above'])->toBe(4.17);
        expect($evidence['best_readings'])->toBe(936);
        expect($evidence['ratio'])->toBe(5.78);

        // 5,78x passa de priority_ratio (5,0).
        expect($findings[0]->severity)->toBe(Severity::Priority);
    });

    it('a prosa cita os números certos, em pt-BR, sem placeholder solto', function () {
        $prose = app(R1DaypartDrift::class)->evaluate($this->dataset)[0]->fallbackProse;

        expect($prose)->toContain('madrugada');
        expect($prose)->toContain('tarde');
        expect($prose)->toContain('4,2%');     // 4,17 → uma casa
        expect($prose)->toContain('24,1%');
        expect($prose)->toContain('5,8 vezes');
        expect($prose)->toHaveNoUnresolvedPlaceholder();
    });

    // ⚠️ A prosa começa pelo que está indo bem. Não é gentileza — é precisão: a
    // madrugada realmente está estável, e omiti-la daria um retrato falso.
    it('a prosa não julga a pessoa (Artigo IV)', function () {
        $prose = mb_strtolower(app(R1DaypartDrift::class)->evaluate($this->dataset)[0]->fallbackProse);

        foreach (['você deveria', 'falta de', 'descuido', 'descontrole', 'culpa'] as $proibido) {
            expect(str_contains($prose, $proibido))->toBeFalse("a prosa de R1 contém '{$proibido}'");
        }
    });
});

describe('R7 — aderência ao sensor', function () {

    it('acusa exatamente 1 dia abaixo de 70%: o 22/07 com 34%', function () {
        $findings = app(R7SensorAdherence::class)->evaluate($this->dataset);

        expect($findings)->toHaveCount(1);

        $evidence = $findings[0]->evidence;

        expect($evidence['days_below_threshold'])->toBe(1);
        expect($evidence['days_total'])->toBe(14);
        expect($evidence['worst_date'])->toBe('2026-07-22');
        expect($evidence['worst_coverage_percent'])->toBeCloseToValue(33.7, 0.6);
        expect($evidence['period_coverage_percent'])->toBeCloseToValue(91.1, 0.6);
        // 6 dias abaixo de 100%: 20/07 (91), 21/07 (73), 22/07 (34), 25/07 (98),
        // 28/07 (82) e 29/07 (78) — gabarito §Por dia. É a média que dilui: só um
        // deles cruza o limiar de 70%.
        expect($evidence['days_below_100'])->toBe(6);
    });

    // O período aprova o portão de validade (91,1%), então o achado é Attention:
    // informa sem dramatizar o que é normal acontecer.
    it('é Attention porque o período inteiro aprova', function () {
        expect(app(R7SensorAdherence::class)->evaluate($this->dataset)[0]->severity)
            ->toBe(Severity::Attention);
    });

    // ⚠️ FR-409 — a prosa fala do QUE SE PERDE, não do esforço da pessoa.
    it('a prosa relaciona cobertura baixa com mecanismo, não com esforço', function () {
        $prose = app(R7SensorAdherence::class)->evaluate($this->dataset)[0]->fallbackProse;

        expect($prose)->toContain('SmartGuard');
        expect($prose)->toContain('modo manual');
        expect(mb_strtolower($prose))->not->toContain('você deveria');
        expect($prose)->toHaveNoUnresolvedPlaceholder();
    });
});

describe('R9 — carga de calibração', function () {

    it('reporta 39 calibrações em 14 dias = 2,8 por dia', function () {
        $findings = app(R9CalibrationBurden::class)->evaluate($this->dataset);

        expect($findings)->toHaveCount(1);
        expect($findings[0]->evidence['calibrations'])->toBe(39);
        expect($findings[0]->evidence['days'])->toBe(14);
        expect($findings[0]->evidence['per_day'])->toBe(2.8);
    });

    // ⚠️ FR-411 — O CONTEXTO É O REQUISITO. Sem ele, "2,8 picadas de dedo por
    // dia" soa como cobrança sobre algo que o equipamento exige.
    it('a prosa contextualiza que o Guardian 3 EXIGE calibração', function () {
        $prose = app(R9CalibrationBurden::class)->evaluate($this->dataset)[0]->fallbackProse;

        expect($prose)->toContain('Guardian');
        expect($prose)->toContain('precisa de calibração');
        expect($prose)->toContain('uso normal');
        expect($prose)->toContain('2,8');
    });

    it('nunca passa de Info', function () {
        expect(app(R9CalibrationBurden::class)->evaluate($this->dataset)[0]->severity)
            ->toBe(Severity::Info);
    });
});

describe('R8 — trocas de reservatório', function () {

    it('reporta 3 trocas, 6 primes, 2 intervalos e 4,41 dias', function () {
        $findings = app(R8ReservoirChanges::class)->evaluate($this->dataset);

        expect($findings)->toHaveCount(1);

        $evidence = $findings[0]->evidence;

        expect($evidence['rewinds'])->toBe(3);
        expect($evidence['primes'])->toBe(6);
        // ⚠️ 2 intervalos, não 3 — §D8. O `~4,7 dias` do PicoGli.md era 14 ÷ 3.
        expect($evidence['intervals'])->toBe(2);
        expect($evidence['mean_interval_days'])->toBe(4.41);
        expect($evidence['set_change_reminders'])->toBe(3);
    });

    // ⚠️ FR-410 — o caveat é obrigatório. `Rewind` rastreia RESERVATÓRIO; sem a
    // ressalva o número viraria afirmação sobre aderência a partir de um dado que
    // não a sustenta.
    it('a prosa declara a limitação do dado', function () {
        $prose = app(R8ReservoirChanges::class)->evaluate($this->dataset)[0]->fallbackProse;

        expect($prose)->toContain('RESERVATÓRIO');
        expect($prose)->toContain('cateter');
        expect($prose)->toContain('pode não aparecer');
        // O n pequeno aparece, em vez de se esconder atrás da média.
        expect($prose)->toContain('poucos para falar de rotina');
    });

    it('a prosa cita os 3 avisos que a própria bomba emitiu', function () {
        $prose = app(R8ReservoirChanges::class)->evaluate($this->dataset)[0]->fallbackProse;

        expect($prose)->toContain('3 avisos de troca');
        expect($prose)->toHaveNoUnresolvedPlaceholder();
    });

    // ⚠️ Artigo VI — nenhum texto diz que o intervalo é longo ou curto demais.
    // Dizer isso seria recomendar mudança de conduta.
    it('a prosa não avalia a cadência observada', function () {
        $prose = mb_strtolower(app(R8ReservoirChanges::class)->evaluate($this->dataset)[0]->fallbackProse);

        foreach (['deveria trocar', 'troque', 'com mais frequência', 'ideal é', 'recomenda'] as $conduta) {
            expect(str_contains($prose, $conduta))->toBeFalse("a prosa de R8 sugere conduta: '{$conduta}'");
        }
    });
});

/**
 * T303.6 — o renderizador falha em vez de publicar `:average` na tela.
 */
describe('o renderizador de prosa', function () {

    it('falha se um placeholder não tiver chave na evidência', function () {
        // A prosa de R9 usa :calibrations, :days e :per_day.
        expect(fn () => app(ProseRenderer::class)->render(
            RuleId::CalibrationBurden,
            'prose',
            ['calibrations' => 39],
        ))->toThrow(InvalidArgumentException::class, ':days');
    });

    it('falha se a chave de prosa não existir no arquivo de idioma', function () {
        expect(fn () => app(ProseRenderer::class)->render(
            RuleId::CalibrationBurden,
            'prosa_que_nao_existe',
            ['calibrations' => 39],
        ))->toThrow(InvalidArgumentException::class, 'Falta a prosa');
    });

    it('formata número em pt-BR e descarta a casa decimal inútil', function () {
        $prose = app(ProseRenderer::class)->render(
            RuleId::CalibrationBurden,
            'prose',
            ['calibrations' => 1234, 'days' => 14, 'per_day' => 2.80],
        );

        expect($prose)->toContain('1.234');   // separador de milhar pt-BR
        expect($prose)->toContain('2,8');
        expect($prose)->not->toContain('2,80');
        expect($prose)->not->toContain('14,0');
    });

    // ⚠️ Sem ordenar por comprimento, `:ratio` substituiria o começo de
    // `:ratio_threshold` e deixaria "_threshold" solto no meio da frase.
    it('substitui o placeholder mais longo primeiro', function () {
        $prose = app(ProseRenderer::class)->render(
            RuleId::DaypartDrift,
            'prose',
            [
                'worst_daypart' => 'afternoon',
                'worst_percent_above' => 24.1,
                'best_daypart' => 'dawn',
                'best_percent_above' => 4.17,
                'ratio' => 5.78,
            ],
        );

        expect($prose)->toContain('tarde');
        expect($prose)->toContain('meio-dia às 18h');
        expect($prose)->not->toContain('_label');
        expect($prose)->not->toContain('_range');
    });
});

/**
 * As quatro juntas — a prévia do que o `PatternEngine` vai montar em T308.
 */
it('as quatro regras produzem 4 achados no export de referência', function () {
    $findings = [
        ...app(R1DaypartDrift::class)->evaluate($this->dataset),
        ...app(R7SensorAdherence::class)->evaluate($this->dataset),
        ...app(R9CalibrationBurden::class)->evaluate($this->dataset),
        ...app(R8ReservoirChanges::class)->evaluate($this->dataset),
    ];

    expect($findings)->toHaveCount(4);

    // ⚠️ NFR-403 — o ensaio do Artigo VII: toda evidência de todo achado é
    // escalar. Se este teste passa, a allowlist do PayloadSanitizer da fase 5
    // tem um formato conhecido para operar.
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

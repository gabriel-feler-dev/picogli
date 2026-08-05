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
use App\Domain\Patterns\Rules\R7SensorAdherence;
use App\Domain\Patterns\Rules\R8ReservoirChanges;
use App\Domain\Patterns\Rules\R9CalibrationBurden;
use App\Domain\Patterns\Value\Severity;
use App\Jobs\ComputeMetricsJob;
use App\Jobs\ImportCsvJob;
use App\Models\User;

/**
 * T304 — R2, R4 e R10 contra o export de referência.
 *
 * ⚠️ Valores do `gabarito.md` §Fase 4, apurados em T304 por script Python
 * independente do PHP (`specs/004-motor-de-padroes/apuracao/t304_apuracao.py`).
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

describe('R2 — cluster de hipoglicemias', function () {

    it('dispara com 80% em 2 janelas (§D11)', function () {
        $findings = app(R2HypoCluster::class)->evaluate($this->dataset);

        expect($findings)->toHaveCount(1);

        $evidence = $findings[0]->evidence;

        expect($evidence['episodes_total'])->toBe(5);
        expect($evidence['episodes_clustered'])->toBe(4);
        expect($evidence['episodes_outside'])->toBe(1);
        expect($evidence['windows_used'])->toBe(2);
        expect($evidence['concentration_percent'])->toBe(80.0);
        expect($evidence['window_hours'])->toBe(2);

        // As janelas do gabarito: madrugada (00:44) e pré-jantar (17:56).
        expect($evidence['window1_start'])->toBe('00:44');
        expect($evidence['window1_end'])->toBe('02:44');
        expect($evidence['window1_episodes'])->toBe(2);
        expect($evidence['window2_start'])->toBe('17:56');
        expect($evidence['window2_episodes'])->toBe(2);

        // Nenhum nadir abaixo de 54 no período: TBR nível 2 = 0,0%.
        expect($evidence['worst_nadir'])->toBe(55);
        expect($findings[0]->severity)->toBe(Severity::Attention);
    });

    // ⚠️ O episódio de 03:41 fica de fora com janela de 2 h, e a evidência diz
    // isso. Esconder faria "80%" soar como 100%.
    it('a prosa admite o episódio que ficou fora das janelas', function () {
        $prose = app(R2HypoCluster::class)->evaluate($this->dataset)[0]->fallbackProse;

        expect($prose)->toContain('00:44');
        expect($prose)->toContain('17:56');
        expect($prose)->toContain('ficaram fora');
        expect($prose)->toHaveNoUnresolvedPlaceholder();
    });

    // ⚠️ Artigo VI — queda de glicose em horário fixo aponta para basal, e é
    // exatamente onde a tentação de sugerir ajuste é maior.
    it('a prosa não sugere ajuste de basal nem afirma a causa', function () {
        $prose = mb_strtolower(app(R2HypoCluster::class)->evaluate($this->dataset)[0]->fallbackProse);

        foreach (['basal', 'reduza', 'diminua', 'ajuste', 'unidade', 'u/h'] as $conduta) {
            expect(str_contains($prose, $conduta))->toBeFalse("a prosa de R2 sugere '{$conduta}'");
        }

        // Diz que há padrão e que padrão tem causa — sem afirmar qual.
        expect($prose)->toContain('causa identificável');
    });
});

describe('R4 — dia outlier', function () {

    it('dispara para >250 com 25/07 respondendo por 71,4%', function () {
        $findings = app(R4OutlierDay::class)->evaluate($this->dataset);

        $above = array_values(array_filter(
            $findings,
            fn ($f): bool => $f->evidence['metric'] === 'above_250',
        ));

        expect($above)->toHaveCount(1);

        $evidence = $above[0]->evidence;

        expect($evidence['dominant_date'])->toBe('2026-07-25');
        expect($evidence['dominant_readings'])->toBe(50);
        expect($evidence['total_readings'])->toBe(70);
        expect($evidence['contribution_percent'])->toBe(71.4);
        expect($evidence['days_total'])->toBe(14);
        expect($evidence['days_affected'])->toBe(2);
        // ⚠️ O número que sustenta a frase que muda a leitura da pessoa.
        expect($evidence['clean_days'])->toBe(12);
        expect($evidence['dominant_minutes'])->toBe(250);
        expect($evidence['total_minutes'])->toBe(350);
    });

    /**
     * ⭐ **O caso negativo de §D5 veio do dado real.**
     *
     * A mesma regra, no mesmo período: `>250` concentra 71,4% num dia e dispara;
     * `<70` espalha-se por 8 dias e o maior é 21,7%, abaixo do limiar de 40%.
     *
     * É a prova mais forte possível de que R4 **discrimina** — melhor que qualquer
     * série sintética, porque nenhum dos dois números foi escolhido por mim.
     */
    it('NÃO dispara para <70, que se espalha por 8 dias', function () {
        $findings = app(R4OutlierDay::class)->evaluate($this->dataset);

        $below = array_filter($findings, fn ($f): bool => $f->evidence['metric'] === 'below_70');

        expect($below)->toBe([]);
        // Só o achado de >250 sai.
        expect($findings)->toHaveCount(1);
    });

    it('a prosa reenquadra o problema como evento, não como condição', function () {
        $prose = app(R4OutlierDay::class)->evaluate($this->dataset)[0]->fallbackProse;

        expect($prose)->toContain('2026-07-25');
        expect($prose)->toContain('71,4%');
        expect($prose)->toContain('12 dias');
        expect($prose)->toContain('não passou nenhum minuto');
        expect($prose)->toHaveNoUnresolvedPlaceholder();
    });
});

describe('R10 — qualidade do sensor', function () {

    it('reporta 10,68% de erro médio com n=39 e janela de ±10 min', function () {
        $findings = app(R10SensorQuality::class)->evaluate($this->dataset);

        expect($findings)->toHaveCount(1);

        $evidence = $findings[0]->evidence;

        expect($evidence['pairs'])->toBe(39);
        expect($evidence['window_minutes'])->toBe(10);
        // Gabarito §Qualidade: 10,7%. Apurado em T302 com 3 casas: 10,6836%.
        expect($evidence['mean_error_percent'])->toBe(10.68);
        expect(round($evidence['mean_error_percent'], 1))->toBe(10.7);
        expect($evidence['mean_offset_minutes'])->toBe(1.41);
        expect($findings[0]->severity)->toBe(Severity::Info);
    });

    it('está dentro da margem esperada, e a prosa contextualiza', function () {
        $findings = app(R10SensorQuality::class)->evaluate($this->dataset);

        expect($findings[0]->evidence['mean_error_percent'])
            ->toBeLessThan($findings[0]->evidence['expected_error_percent']);

        $prose = $findings[0]->fallbackProse;

        expect($prose)->toContain('Guardian 3');
        expect($prose)->toContain('não são a mesma coisa');
        // ⚠️ A janela aparece no texto: sem ela o número não é reproduzível.
        expect($prose)->toContain('10 minutos');
        expect($prose)->toHaveNoUnresolvedPlaceholder();
    });

    // ⚠️ Artigo VI — conduta sobre equipamento médico também é conduta.
    it('a prosa não recomenda trocar sensor nem recalibrar', function () {
        $prose = mb_strtolower(app(R10SensorQuality::class)->evaluate($this->dataset)[0]->fallbackProse);

        foreach (['troque', 'substitua', 'recalibre', 'procure suporte', 'entre em contato'] as $conduta) {
            expect(str_contains($prose, $conduta))->toBeFalse("a prosa de R10 sugere '{$conduta}'");
        }
    });
});

/**
 * As sete regras prontas juntas — prévia do `PatternEngine` (T308).
 */
it('as sete regras produzem 7 achados no export de referência', function () {
    $findings = [
        ...app(R1DaypartDrift::class)->evaluate($this->dataset),
        ...app(R2HypoCluster::class)->evaluate($this->dataset),
        ...app(R4OutlierDay::class)->evaluate($this->dataset),
        ...app(R7SensorAdherence::class)->evaluate($this->dataset),
        ...app(R8ReservoirChanges::class)->evaluate($this->dataset),
        ...app(R9CalibrationBurden::class)->evaluate($this->dataset),
        ...app(R10SensorQuality::class)->evaluate($this->dataset),
    ];

    expect($findings)->toHaveCount(7);

    // ⚠️ NFR-403 — o ensaio do Artigo VII, agora sobre sete regras.
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

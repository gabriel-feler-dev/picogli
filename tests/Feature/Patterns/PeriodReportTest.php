<?php

declare(strict_types=1);

use App\Domain\Import\BolusLinker;
use App\Domain\Import\CarelinkCsvReader;
use App\Domain\Import\EventExploder;
use App\Domain\Import\Persistence\MealEnricher;
use App\Domain\Import\SettingsInferrer;
use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Patterns\PatternEngine;
use App\Domain\Patterns\Persistence\PatternDatasetBuilder;
use App\Domain\Patterns\Persistence\PeriodReportWriter;
use App\Jobs\ComputeMetricsJob;
use App\Jobs\ComputePatternsJob;
use App\Jobs\ImportCsvJob;
use App\Models\DailyMetrics;
use App\Models\PeriodReport;
use App\Models\User;

/**
 * T309 — persistência do relatório de padrões (FR-413, §D9).
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
});

function runPatterns(int $userId): ?PeriodReport
{
    return (new ComputePatternsJob($userId))->handle(
        app(PatternDatasetBuilder::class),
        app(PatternEngine::class),
        app(PeriodReportWriter::class),
    );
}

it('grava o relatório do período com os 10 achados', function () {
    $report = runPatterns($this->user->id);

    expect($report)->not->toBeNull();
    expect($report->period_start)->toBe('2026-07-16');
    expect($report->period_end)->toBe('2026-07-29');
    expect($report->finding_count)->toBe(10);
    expect($report->findings)->toHaveCount(10);
    expect($report->hasRuleFailures())->toBeFalse();
    expect($report->rule_failures)->toBeNull();
});

/**
 * ⚠️ §D9 — as DUAS versões. Um achado calculado sobre métricas de uma versão
 * exibido ao lado de métricas de outra é inconsistência que ninguém percebe.
 */
it('grava engine_version E metrics_version', function () {
    $report = runPatterns($this->user->id);

    expect($report->engine_version)->toBe(PatternEngine::VERSION);
    expect($report->metrics_version)->toBe(DailyMetricsWriter::VERSION);
    expect($report->isStale())->toBeFalse();
});

it('sinaliza como desatualizado quando QUALQUER uma das versões muda', function () {
    $report = runPatterns($this->user->id);

    $report->update(['engine_version' => '2020.01.1']);
    expect($report->fresh()->isStale())->toBeTrue();

    $report->update(['engine_version' => PatternEngine::VERSION, 'metrics_version' => '2020.01.1']);
    expect($report->fresh()->isStale())->toBeTrue();
});

/**
 * ⚠️ Artigo V — o relatório carrega o próprio denominador. Um relatório de julho
 * exibido em setembro tem de mostrar a cobertura de julho, não a de hoje.
 */
it('grava a cobertura, o span e a validade junto', function () {
    $report = runPatterns($this->user->id);

    expect($report->coverage_pct)->toBeCloseToValue(91.1, 0.6);
    expect($report->span_days)->toBeCloseToValue(13.8, 0.1);
    expect($report->validity)->toBe('valid');
});

/**
 * ⚠️ Reprocessar ATUALIZA, não empilha (Artigo VIII.4 por analogia). Sem isso,
 * cada recálculo deixaria uma linha nova e o histórico de versões viraria lixo.
 */
it('reprocessar atualiza a mesma linha', function () {
    $primeiro = runPatterns($this->user->id);
    $segundo = runPatterns($this->user->id);

    expect(PeriodReport::count())->toBe(1);
    expect($segundo->id)->toBe($primeiro->id);
    expect($segundo->finding_count)->toBe(10);
});

it('reprocessar depois de uma mudança de versão sobrescreve a versão antiga', function () {
    $report = runPatterns($this->user->id);
    $report->update(['engine_version' => '2020.01.1']);

    expect($report->fresh()->isStale())->toBeTrue();

    runPatterns($this->user->id);

    expect(PeriodReport::count())->toBe(1);
    expect(PeriodReport::first()->isStale())->toBeFalse();
});

it('não mistura relatório de outro usuário', function () {
    $outro = User::factory()->create();

    (new ImportCsvJob($outro->id, requireReferenceExport(), 'America/Sao_Paulo'))->handle(
        app(CarelinkCsvReader::class),
        app(EventExploder::class),
        app(BolusLinker::class),
        app(MealEnricher::class),
        app(SettingsInferrer::class),
    );
    (new ComputeMetricsJob($outro->id))->handle(app(DailyMetricsWriter::class));

    runPatterns($this->user->id);
    runPatterns($outro->id);

    expect(PeriodReport::count())->toBe(2);
    expect(PeriodReport::where('user_id', $this->user->id)->count())->toBe(1);
});

/**
 * ⚠️ Período sem leitura nenhuma NÃO gera relatório — não há período a que ele
 * se refira. É diferente de um período COM dados e sem padrão detectado, que
 * gera relatório com zero achados (§D10).
 */
it('usuário sem leitura não gera relatório', function () {
    $vazio = User::factory()->create();

    expect(runPatterns($vazio->id))->toBeNull();
    expect(PeriodReport::where('user_id', $vazio->id)->count())->toBe(0);
});

describe('o JSON persistido', function () {

    it('sobrevive ao round-trip sem perder tipo nem chave', function () {
        $report = runPatterns($this->user->id);
        $findings = $report->fresh()->findings;

        $r1 = collect($findings)->firstWhere('rule_id', 'R1_DAYPART_DRIFT');

        expect($r1['severity'])->toBe('priority');
        expect($r1['rank'])->toBe(4);
        // Float continua float depois de ida e volta pelo banco — a lição da
        // fase 1, quando `10.0` voltou como `int 10`.
        expect($r1['evidence']['ratio'])->toBe(5.78);
        expect($r1['evidence']['worst_readings'])->toBe(917);
        expect($r1['fallback_prose'])->toBeString();
    });

    it('preserva a ordem dos achados', function () {
        $report = runPatterns($this->user->id);

        $ordem = array_column($report->fresh()->findings, 'rule_id');

        expect($ordem[0])->toBe('R1_DAYPART_DRIFT');
        expect($ordem[1])->toBe('R2_HYPO_CLUSTER');
        expect(end($ordem))->toBe('R9_CALIBRATION_BURDEN');
    });

    it('marca o achado que exige encaminhamento clínico', function () {
        $report = runPatterns($this->user->id);

        $comHandoff = array_values(array_filter(
            $report->fresh()->findings,
            fn (array $f): bool => $f['requires_clinical_handoff'],
        ));

        expect($comHandoff)->toHaveCount(1);
        expect($comHandoff[0]['rule_id'])->toBe('R6_CARB_RATIO_COHERENCE');
    });
});

/**
 * ⚠️ Artigo IX — contar dentro de JSON exige função de dialeto. A coluna
 * denormalizada é o que permite ordenar e filtrar em SQLite e MySQL igual.
 */
it('finding_count é coluna, e confere com o JSON', function () {
    runPatterns($this->user->id);

    $comAchados = PeriodReport::where('finding_count', '>', 0)->get();

    expect($comAchados)->toHaveCount(1);
    expect($comAchados->first()->finding_count)->toBe(count($comAchados->first()->findings));
});

it('o dataset sinaliza métrica velha, e o relatório registra a versão corrente', function () {
    DailyMetrics::where('user_id', $this->user->id)->update(['metrics_version' => '2020.01.1']);

    $dataset = app(PatternDatasetBuilder::class)->forLatestPeriod($this->user->id);

    // O builder SINALIZA em vez de recalcular em silêncio…
    expect($dataset->hasStaleMetrics)->toBeTrue();

    // …e a versão que viaja para o relatório é a corrente, não a encontrada no
    // banco: é ela que descreve as fórmulas que o dataset de fato usou.
    $report = runPatterns($this->user->id);

    expect($report->metrics_version)->toBe(DailyMetricsWriter::VERSION);
});

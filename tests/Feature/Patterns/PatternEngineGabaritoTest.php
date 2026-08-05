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
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;
use App\Jobs\ComputeMetricsJob;
use App\Jobs\ImportCsvJob;
use App\Models\User;

/**
 * T308 — o motor completo sobre o export de referência.
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
    $this->set = app(PatternEngine::class)->run($this->dataset);
});

it('registra as dez regras', function () {
    expect(app(PatternEngine::class)->registeredRules())->toHaveCount(10);
    expect(app(PatternEngine::class)->registeredRules())
        ->toBe(array_map(fn (RuleId $r): string => $r->value, RuleId::cases()));
});

it('produz 10 achados, um por regra, sem nenhuma falha', function () {
    expect($this->set->count())->toBe(10);
    expect($this->set->hasFailures())->toBeFalse();

    $regras = array_map(fn (Finding $f): string => $f->ruleId->value, $this->set->findings);
    expect(array_unique($regras))->toHaveCount(10);
});

/**
 * ⚠️ A ordem que a pessoa lê. Hipoglicemia primeiro porque é o risco agudo; o
 * reenquadramento (R4) antes do detalhe do dia ruim (R3), para que ela leia a
 * perspectiva antes do episódio.
 */
it('ordena por severidade e depois por rank', function () {
    $ordem = array_map(fn (Finding $f): string => $f->ruleId->value, $this->set->findings);

    // R1 é o único Priority (razão 5,78x passa de priority_ratio).
    expect($ordem[0])->toBe('R1_DAYPART_DRIFT');
    expect($this->set->findings[0]->severity)->toBe(Severity::Priority);

    // Depois os Attention, por rank: R2 (1), R4 (2), R3 (3), R5 (5), R6 (6), R7 (7).
    expect(array_slice($ordem, 1, 6))->toBe([
        'R2_HYPO_CLUSTER',
        'R4_OUTLIER_DAY',
        'R3_ROLLERCOASTER',
        'R5_SENSOR_GAP_LOOP_IMPACT',
        'R6_CARB_RATIO_COHERENCE',
        'R7_SENSOR_ADHERENCE',
    ]);

    // Os Info por último, por rank: R8 (8), R10 (9), R9 (10).
    expect(array_slice($ordem, 7))->toBe([
        'R8_RESERVOIR_CHANGES',
        'R10_SENSOR_QUALITY',
        'R9_CALIBRATION_BURDEN',
    ]);
});

it('a severidade nunca sobe ao longo da lista', function () {
    $pesos = array_map(fn (Finding $f): int => $f->severity->weight(), $this->set->findings);

    expect($pesos)->toBe(array_reverse(array_reverse($pesos)));

    for ($i = 1; $i < count($pesos); $i++) {
        expect($pesos[$i])->toBeLessThanOrEqual($pesos[$i - 1]);
    }
});

/**
 * ⚠️⚠️ **T308.5 — NFR-403, o ensaio do Artigo VII.**
 *
 * Percorre TODOS os `evidence` de TODOS os achados do motor completo. Se este
 * teste passa, a allowlist do `PayloadSanitizer` da fase 5 tem um formato
 * conhecido para operar — e nenhum campo de identificação consegue ter virado
 * chave de evidência.
 */
it('toda evidência de todo achado é escalar, com chave em snake_case', function () {
    $chaves = 0;

    foreach ($this->set->findings as $finding) {
        expect($finding->evidence)->not->toBeEmpty();

        foreach ($finding->evidence as $key => $value) {
            $chaves++;

            expect(is_scalar($value) || $value === null)->toBeTrue(
                "{$finding->ruleId->value}.{$key} é ".get_debug_type($value)
            );
            expect(preg_match('/^[a-z][a-z0-9_]*$/', $key))->toBe(
                1,
                "{$finding->ruleId->value}.{$key} não é snake_case"
            );
        }
    }

    // Conferência de que o teste varreu mesmo alguma coisa.
    expect($chaves)->toBeGreaterThan(80);
});

it('nenhum achado sai sem prosa de fallback (Artigo I)', function () {
    foreach ($this->set->findings as $finding) {
        expect(trim($finding->fallbackProse))->not->toBe('');
        expect($finding->fallbackProse)->toHaveNoUnresolvedPlaceholder();
        // Prosa publicável, não técnica: nenhum achado sai com menos de uma frase.
        expect(mb_strlen($finding->fallbackProse))->toBeGreaterThan(80);
    }
});

it('exatamente um achado exige encaminhamento clínico, e é o de R6', function () {
    $handoff = $this->set->requiringClinicalHandoff();

    expect($handoff)->toHaveCount(1);
    expect($handoff[0]->ruleId)->toBe(RuleId::CarbRatioCoherence);
});

it('o conjunto serializa para a forma de period_reports', function () {
    $array = $this->set->toArray();

    expect($array['finding_count'])->toBe(10);
    expect($array['rule_failures'])->toBe([]);
    expect($array['findings'])->toHaveCount(10);

    // Sobrevive ao round-trip de JSON sem perder tipo — a lição da fase 1.
    $decoded = json_decode(json_encode($array, JSON_THROW_ON_ERROR), true);

    expect($decoded['findings'][0]['rule_id'])->toBe('R1_DAYPART_DRIFT');
    expect($decoded['findings'][0]['evidence']['ratio'])->toBe(5.78);
});

/**
 * §D10 — período sem padrão devolve conjunto vazio, sem erro.
 */
it('usuário sem dado devolve conjunto vazio, sem falha de regra', function () {
    $outro = User::factory()->create();

    $set = app(PatternEngine::class)->run(
        app(PatternDatasetBuilder::class)->forLatestPeriod($outro->id)
    );

    expect($set->isEmpty())->toBeTrue();
    // ⚠️ E SEM FALHAS. Dez regras rodando sobre um dataset vazio não podem
    // lançar exceção — se lançassem, a tela de "nenhum padrão" viraria uma
    // tela de erro.
    expect($set->hasFailures())->toBeFalse();
});

/**
 * ⚠️ FR-416 / Artigo X — o motor completo existe e não há uma linha de IA.
 */
it('nenhum arquivo do motor menciona provedor de IA', function () {
    $arquivos = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Domain/Patterns'))
    );

    $encontrados = [];

    foreach ($arquivos as $arquivo) {
        if (! $arquivo->isFile() || $arquivo->getExtension() !== 'php') {
            continue;
        }

        $conteudo = mb_strtolower(file_get_contents($arquivo->getPathname()));

        foreach (['gemini', 'openai', 'anthropic', 'api_key', 'completion'] as $proibido) {
            if (str_contains($conteudo, $proibido)) {
                $encontrados[] = $arquivo->getFilename().': '.$proibido;
            }
        }
    }

    expect($encontrados)->toBe([]);
});

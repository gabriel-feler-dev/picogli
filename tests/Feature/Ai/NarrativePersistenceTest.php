<?php

declare(strict_types=1);

use App\Domain\Ai\NarrativeGenerator;
use App\Domain\Ai\Provider;
use App\Domain\Ai\ProviderFailure;
use App\Domain\Ai\Value\DiscardReason;
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
use App\Jobs\GenerateNarrativeJob;
use App\Jobs\ImportCsvJob;
use App\Models\PeriodReport;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeProvider;

/**
 * T406 — a narrativa persistida (FR-506, §D8).
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

    $this->report = (new ComputePatternsJob($this->user->id))->handle(
        app(PatternDatasetBuilder::class),
        app(PatternEngine::class),
        app(PeriodReportWriter::class),
    );
});

/** Troca o provider do container por um fake, e devolve o gerador real. */
function withFakeProvider(FakeProvider $provider): NarrativeGenerator
{
    app()->instance(Provider::class, $provider);
    app()->forgetInstance(NarrativeGenerator::class);

    return app(NarrativeGenerator::class);
}

it('nasce sem narrativa, e isso é o estado normal', function () {
    // ⚠️ `null` não é a exceção: relatório sem narrativa é o relatório de hoje,
    // e a tela já sabe renderizá-lo (§D3).
    expect($this->report->narrative)->toBeNull();
    expect($this->report->hasNarrative())->toBeFalse();
});

it('grava o texto, o modelo e o instante', function () {
    // A prosa cita só números que existem na evidência do export.
    $provider = FakeProvider::replying(
        'Suas madrugadas ficam acima da faixa em 4,17% do tempo, contra 24,1% da tarde.'
    );

    $attempt = (new GenerateNarrativeJob($this->report->id))->handle(withFakeProvider($provider));

    expect($attempt->wasPublished())->toBeTrue();

    $report = $this->report->fresh();

    expect($report->hasNarrative())->toBeTrue();
    expect($report->narrative)->toContain('4,17%');
    // ⚠️ Procedência: "qual modelo escreveu" e "quando" são as duas perguntas que
    // aparecem quando um texto sai estranho.
    expect($report->narrative_model)->toBe(config('ai.model_chain')[0]);
    expect($report->narrative_generated_at)->not->toBeNull();
});

/**
 * ⚠️⚠️ **A REGRA QUE MAIS IMPORTA DESTA TAREFA (§D8).**
 *
 * Um texto escrito sobre a versão anterior das regras, exibido ao lado de achados
 * recalculados, é **plausível e falso** — o pior tipo de erro deste projeto,
 * porque nada denuncia.
 */
it('regerar o relatório ZERA a narrativa', function () {
    (new GenerateNarrativeJob($this->report->id))->handle(
        withFakeProvider(FakeProvider::replying('A tarde concentra 24,1% do tempo acima.'))
    );

    expect($this->report->fresh()->hasNarrative())->toBeTrue();

    // Recalcula o relatório — como aconteceria após uma importação nova ou uma
    // mudança de versão do motor.
    (new ComputePatternsJob($this->user->id))->handle(
        app(PatternDatasetBuilder::class),
        app(PatternEngine::class),
        app(PeriodReportWriter::class),
    );

    $report = PeriodReport::where('user_id', $this->user->id)->firstOrFail();

    expect($report->hasNarrative())->toBeFalse();
    expect($report->narrative)->toBeNull();
    expect($report->narrative_model)->toBeNull();
    expect($report->narrative_generated_at)->toBeNull();

    // E os achados continuam lá: o que se invalidou foi o texto, não o relatório.
    expect($report->finding_count)->toBe(10);
});

/**
 * ⚠️ Todo desfecho de falha deixa o relatório EXATAMENTE como estava. A tela não
 * muda de forma (Artigo I, NFR-502).
 */
describe('os descartes não tocam o relatório', function () {

    it('cota esgotada não grava nada', function () {
        $attempt = (new GenerateNarrativeJob($this->report->id))->handle(
            withFakeProvider(FakeProvider::failing(ProviderFailure::QuotaExhausted))
        );

        expect($attempt->discardReason)->toBe(DiscardReason::NoModelAvailable);
        expect($this->report->fresh()->hasNarrative())->toBeFalse();
        // Os dez achados continuam intactos.
        expect($this->report->fresh()->finding_count)->toBe(10);
    });

    it('número inventado não grava nada, e o log diz qual', function () {
        $provider = FakeProvider::replying(
            'A tarde concentra 24,1% do tempo, e sua média foi de 142 mg/dL.'
        );

        $attempt = (new GenerateNarrativeJob($this->report->id))->handle(withFakeProvider($provider));

        expect($attempt->discardReason)->toBe(DiscardReason::OrphanNumbers);
        expect($attempt->orphanNumbers)->toContain('142');
        expect($this->report->fresh()->hasNarrative())->toBeFalse();
    });

    it('relatório inexistente devolve descarte, não exceção', function () {
        $attempt = (new GenerateNarrativeJob(999999))->handle(
            withFakeProvider(FakeProvider::replying('qualquer coisa'))
        );

        expect($attempt->wasPublished())->toBeFalse();
        expect($attempt->discardReason)->toBe(DiscardReason::NothingToNarrate);
    });
});

it('gerar duas vezes sobrescreve, sem duplicar relatório', function () {
    (new GenerateNarrativeJob($this->report->id))->handle(
        withFakeProvider(FakeProvider::replying('Primeira versão, com 24,1% da tarde.'))
    );

    (new GenerateNarrativeJob($this->report->id))->handle(
        withFakeProvider(FakeProvider::replying('Segunda versão, com 4,17% da madrugada.'))
    );

    expect(PeriodReport::count())->toBe(1);
    expect($this->report->fresh()->narrative)->toContain('Segunda versão');
});

/**
 * §D8 — persistida, não gerada a cada visita. Um texto que muda a cada F5 mina a
 * confiança mais rápido que um texto ruim.
 */
it('a narrativa gravada não muda ao ser lida de novo', function () {
    (new GenerateNarrativeJob($this->report->id))->handle(
        withFakeProvider(FakeProvider::replying('Texto estável com 24,1%.'))
    );

    $primeira = PeriodReport::findOrFail($this->report->id)->narrative;
    $segunda = PeriodReport::findOrFail($this->report->id)->narrative;

    expect($segunda)->toBe($primeira);
});

it('a migration é portável — as três colunas são nullable', function () {
    $columns = Schema::getColumns('period_reports');
    $byName = collect($columns)->keyBy('name');

    foreach (['narrative', 'narrative_model', 'narrative_generated_at'] as $coluna) {
        expect($byName)->toHaveKey($coluna);
        expect($byName[$coluna]['nullable'])->toBeTrue("{$coluna} deveria ser nullable");
    }
});

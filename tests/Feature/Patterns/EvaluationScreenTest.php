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
use App\Models\PeriodReport;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T310 — a tela de avaliação (FR-414, FR-415).
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function importAndAnalyse(int $userId): ?PeriodReport
{
    (new ImportCsvJob($userId, requireReferenceExport(), 'America/Sao_Paulo'))->handle(
        app(CarelinkCsvReader::class),
        app(EventExploder::class),
        app(BolusLinker::class),
        app(MealEnricher::class),
        app(SettingsInferrer::class),
    );

    (new ComputeMetricsJob($userId))->handle(app(DailyMetricsWriter::class));

    return (new ComputePatternsJob($userId))->handle(
        app(PatternDatasetBuilder::class),
        app(PatternEngine::class),
        app(PeriodReportWriter::class),
    );
}

it('exige autenticação', function () {
    auth()->logout();

    $this->get('/avaliacao')->assertRedirect('/login');
});

it('entrega os 10 achados na ordem do servidor', function () {
    importAndAnalyse($this->user->id);

    $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page
        ->component('Evaluation')
        ->where('has_report', true)
        ->has('findings', 10)
        // ⚠️ A ordem é decisão de produto (rank no enum `RuleId`), resolvida no
        // servidor. O React não reordena.
        ->where('findings.0.rule_id', 'R1_DAYPART_DRIFT')
        ->where('findings.0.severity', 'priority')
        ->where('findings.0.severity_label', 'Leia primeiro')
        ->where('findings.1.rule_id', 'R2_HYPO_CLUSTER')
        ->where('findings.9.rule_id', 'R9_CALIBRATION_BURDEN')
        ->where('findings.9.severity', 'info')
    );
});

/**
 * ⚠️ Artigo V — o denominador aparece, e é o DAQUELE relatório.
 */
it('mostra o período e a cobertura no topo', function () {
    importAndAnalyse($this->user->id);

    $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page
        ->where('period.from', '2026-07-16')
        ->where('period.to', '2026-07-29')
        ->where('period.label', 'Período de 16/07/2026 a 29/07/2026')
        ->where('coverage.validity', 'valid')
        ->where('coverage.summary', '13,8 dias · 91,1% de captura do sensor')
    );
});

it('cada achado chega com título, prosa e evidência rotulada', function () {
    importAndAnalyse($this->user->id);

    $this->get('/avaliacao')->assertInertia(function (Assert $page) {
        $page->where('findings.0.title', 'Sua glicose se comporta de formas diferentes ao longo do dia');

        $finding = $page->toArray()['props']['findings'][0];

        expect($finding['prose'])->toContain('madrugada');
        expect($finding['evidence'])->not->toBeEmpty();

        // Artigo III — o número rastreia até o banco, e o rótulo vem de `lang/`.
        $porChave = collect($finding['evidence'])->keyBy('key');

        expect($porChave['worst_readings']['label'])->toBe('Leituras no pior período');
        expect($porChave['worst_readings']['value'])->toBe('917');
        expect($porChave['ratio']['value'])->toBe('5,78');
    });
});

/**
 * ⚠️ Artigo VI, camada 3 — o achado de R6 chega marcado, e é o único.
 */
it('marca o achado que exige encaminhamento clínico', function () {
    importAndAnalyse($this->user->id);

    $this->get('/avaliacao')->assertInertia(function (Assert $page) {
        $comHandoff = collect($page->toArray()['props']['findings'])
            ->where('requires_clinical_handoff', true);

        expect($comHandoff)->toHaveCount(1);
        expect($comHandoff->first()['rule_id'])->toBe('R6_CARB_RATIO_COHERENCE');
    });
});

/**
 * ⚠️⚠️ **§D10 — OS DOIS ESTADOS VAZIOS SÃO DIFERENTES.**
 *
 * Confundi-los faria o app dizer "nada encontrado" para quem nunca importou, e
 * "importe algo" para quem está com tudo em ordem.
 */
describe('os dois estados vazios', function () {

    it('sem relatório: has_report é falso e não há achado', function () {
        $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page
            ->component('Evaluation')
            ->where('has_report', false)
            ->has('findings', 0)
            ->where('period', null)
            ->where('coverage', null)
            ->where('is_stale', false)
        );
    });

    // ⚠️ O caminho de zero achado é TESTADO, não é um `if` sem prova (FR-415).
    it('com relatório e zero achados: has_report é verdadeiro', function () {
        importAndAnalyse($this->user->id);
        PeriodReport::where('user_id', $this->user->id)
            ->update(['findings' => [], 'finding_count' => 0]);

        $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page
            ->where('has_report', true)
            ->has('findings', 0)
            // O denominador continua lá: o período existiu e foi analisado.
            ->where('coverage.validity', 'valid')
        );
    });
});

/**
 * §D9 — sinaliza, nunca recalcula em silêncio.
 */
it('sinaliza relatório gerado por versão anterior', function () {
    importAndAnalyse($this->user->id);

    $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page->where('is_stale', false));

    PeriodReport::where('user_id', $this->user->id)->update(['engine_version' => '2020.01.1']);

    $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page
        ->where('is_stale', true)
        // ⚠️ E os achados CONTINUAM aparecendo. Esconder o relatório velho
        // deixaria a tela vazia sem explicar por quê.
        ->has('findings', 10)
    );
});

it('mostra falha de regra em vez de esconder', function () {
    importAndAnalyse($this->user->id);

    PeriodReport::where('user_id', $this->user->id)->update([
        'rule_failures' => [['rule_id' => 'R10_SENSOR_QUALITY', 'message' => 'divisão por zero']],
    ]);

    $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page
        ->has('rule_failures', 1)
        ->where('rule_failures.0.rule_id', 'R10_SENSOR_QUALITY')
    );
});

it('não mostra avaliação de outro usuário', function () {
    $outro = User::factory()->create();
    importAndAnalyse($outro->id);

    $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page
        ->where('has_report', false)
        ->has('findings', 0)
    );
});

/**
 * ⚠️ NFR-404 — nenhuma estatística em JavaScript. A tela recebe números prontos.
 */
it('a tela não calcula nem ordena nada', function () {
    $fontes = [
        resource_path('js/Pages/Evaluation.tsx'),
        resource_path('js/Components/FindingCard.tsx'),
        resource_path('js/Components/SeverityBadge.tsx'),
    ];

    foreach ($fontes as $caminho) {
        $codigo = file_get_contents($caminho);

        // Remove comentários antes de varrer — senão o teste acusa a própria
        // documentação que explica a proibição (erro cometido na fase 3).
        $codigo = preg_replace('#\{/\*.*?\*/\}#s', '', $codigo);
        $codigo = preg_replace('#/\*.*?\*/#s', '', (string) $codigo);
        $codigo = preg_replace('#//.*$#m', '', (string) $codigo);

        foreach (['.sort(', '.reduce(', 'toFixed(', 'Math.round', 'Math.max', 'Math.min'] as $proibido) {
            expect(str_contains((string) $codigo, $proibido))->toBeFalse(
                basename($caminho)." usa '{$proibido}' — a decisão escapou para o cliente"
            );
        }
    }
});

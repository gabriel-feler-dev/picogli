<?php

declare(strict_types=1);

use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Presentation\DashboardPresenter;
use App\Domain\Presentation\Value\PeriodSummary;
use App\Jobs\ImportCsvJob;
use App\Models\DailyMetrics;
use App\Models\SensorReading;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T204 — FR-204 (denominador visível), Artigo V
 */
function importFor(User $user): void
{
    (new ImportCsvJob($user->id, requireReferenceExport(), 'America/Sao_Paulo'))->handle(
        app(App\Domain\Import\CarelinkCsvReader::class),
        app(App\Domain\Import\EventExploder::class),
        app(App\Domain\Import\BolusLinker::class),
        app(App\Domain\Import\Persistence\MealEnricher::class),
        app(App\Domain\Import\SettingsInferrer::class),
    );

    app(DailyMetricsWriter::class)->write($user->id);
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('Artigo V imposto por TIPO', function () {

    // ⚠️ O teste estrutural. Se `coverage` ou `validity` ganharem um default,
    // passa a existir caminho que entrega métrica sem denominador — e nenhum
    // teste de comportamento pegaria, porque o caminho novo não seria exercido.
    it('PeriodSummary não permite construir sem cobertura nem sem validade', function () {
        $constructor = (new ReflectionClass(PeriodSummary::class))->getConstructor();

        $required = [];
        foreach ($constructor->getParameters() as $parameter) {
            if (in_array($parameter->getName(), ['coverage', 'validity'], true)) {
                $required[$parameter->getName()] = [
                    'optional' => $parameter->isOptional(),
                    'nullable' => $parameter->getType()?->allowsNull() ?? true,
                ];
            }
        }

        expect($required)->toHaveCount(2);

        foreach ($required as $name => $info) {
            expect($info['optional'])->toBeFalse("{$name} não pode ter valor padrão");
            expect($info['nullable'])->toBeFalse("{$name} não pode ser nulo");
        }
    });

    it('até o período vazio devolve cobertura preenchida', function () {
        $summary = app(DashboardPresenter::class)->forLatestPeriod($this->user->id);

        expect($summary->isEmpty())->toBeTrue();
        // Cobertura zerada é diferente de cobertura ausente.
        expect($summary->coverage->readingCount)->toBe(0);
        expect($summary->toArray())->toHaveKey('coverage');
        expect($summary->toArray()['metrics'])->toBe([]);
    });
});

describe('o payload do dashboard', function () {

    beforeEach(function () {
        importFor($this->user);
        $this->summary = app(DashboardPresenter::class)->forLatestPeriod($this->user->id)->toArray();
    });

    it('traz o SPAN REAL, não só o arredondado', function () {
        // 13,8 dias. Mostrar apenas "14 dias" esconderia um pedaço do
        // denominador — e é justamente o que o Artigo V proíbe.
        expect($this->summary['coverage']['span_in_days'])->toBe(13.8);
        expect($this->summary['coverage']['span_note'])->toContain('13,8');
    });

    it('traz cobertura, leituras e esperadas', function () {
        expect($this->summary['coverage']['reading_count'])->toBe(3616);
        expect($this->summary['coverage']['expected_count'])->toBe(3968);
        expect($this->summary['coverage']['percentage'])->toBe(91.1);
        expect($this->summary['coverage']['readings_note'])->toContain('3616');
        expect($this->summary['coverage']['readings_note'])->toContain('3968');
    });

    it('aprova o período de referência no portão de validade', function () {
        expect($this->summary['validity']['is_valid'])->toBeTrue();
        expect($this->summary['validity']['message'])->toBeNull();
    });

    it('entrega os quatro cards já traduzidos', function () {
        $keys = array_column($this->summary['metrics'], 'key');

        expect($keys)->toBe([
            'time_in_range',
            'coefficient_of_variation',
            'gmi',
            'time_below_range',
        ]);

        $tir = $this->summary['metrics'][0];
        expect($tir['plain_value'])->toBe('20 h por dia');
        expect($tir['technical_value'])->toBe('TIR 83,9%');
        expect($tir['status'])->toBe('met');
    });

    it('entrega perfil horário e percentis para as 24 horas', function () {
        expect($this->summary['hourly_profile'])->toHaveCount(24);
        expect($this->summary['hourly_percentiles'])->toHaveCount(24);

        // Percentil de hora vazia é null, não zero.
        foreach ($this->summary['hourly_percentiles'] as $bucket) {
            if ($bucket['count'] === 0) {
                expect($bucket['p50'])->toBeNull();
            }
        }
    });

    it('entrega as métricas diárias e as lacunas', function () {
        expect($this->summary['daily_metrics'])->toHaveCount(14);
        expect($this->summary['gaps'])->toHaveCount(3);

        // A grade de dias precisa distinguir cobertura baixa (Artigo V no nível
        // do dia): 22/07 teve 34%.
        $day22 = collect($this->summary['daily_metrics'])->firstWhere('local_date', '2026-07-22');
        expect(round($day22['coverage_pct']))->toBe(34.0);
    });
});

describe('portão de validade reprovando', function () {

    it('período curto marca GMI e CV como não confiáveis, com motivo de DIAS', function () {
        importFor($this->user);

        // Três dias do meio do período: captura alta, dias insuficientes.
        $summary = app(DashboardPresenter::class)
            ->forPeriod($this->user->id, '2026-07-17', '2026-07-19')
            ->toArray();

        expect($summary['validity']['status'])->toBe('insufficient_days');
        expect($summary['validity']['message'])->toContain('14 dias');

        $byKey = collect($summary['metrics'])->keyBy('key');

        expect($byKey['gmi']['status'])->toBe('unreliable');
        expect($byKey['coefficient_of_variation']['status'])->toBe('unreliable');

        // ⚠️ O número CONTINUA visível. Esconder faria o usuário achar que
        // faltam dados; mostrar sem marca faria ele confiar.
        expect($byKey['gmi']['plain_value'])->not->toBeEmpty();
        expect($byKey['gmi']['technical_value'])->toContain('GMI');
    });

    it('o motivo distingue dias de captura', function () {
        importFor($this->user);

        // Derruba a captura MANTENDO o span. As horas 4–17 saem; 18–23 e 0–3
        // ficam, o que preserva a primeira leitura (00:04 de 16/07) e a última
        // (18:47 de 29/07).
        //
        // ⚠️ A primeira versão deste teste apagava tudo acima da hora 3 — e aí
        // a última leitura ia junto, o span caía para ~13,1 dias e o portão
        // reprovava por DIAS antes de chegar na captura. O código estava certo;
        // o setup é que media a coisa errada.
        SensorReading::where('user_id', $this->user->id)
            ->whereBetween('local_hour', [4, 17])
            ->delete();

        $summary = app(DashboardPresenter::class)->forLatestPeriod($this->user->id)->toArray();

        expect($summary['validity']['status'])->toBe('insufficient_coverage');
        // "Dados insuficientes" não ajudaria: faltar dias e o sensor ter ficado
        // fora do ar pedem ações diferentes do usuário.
        expect($summary['validity']['message'])->toContain('sensor');
    });
});

describe('métricas de versão antiga', function () {

    it('sinaliza recálculo pendente em vez de recalcular em silêncio', function () {
        importFor($this->user);

        $fresh = app(DashboardPresenter::class)->forLatestPeriod($this->user->id)->toArray();
        expect($fresh['has_stale_metrics'])->toBeFalse();
        expect($fresh['stale_message'])->toBeNull();

        DB::table('daily_metrics')
            ->where('user_id', $this->user->id)
            ->update(['metrics_version' => '2020.01.0']);

        $stale = app(DashboardPresenter::class)->forLatestPeriod($this->user->id)->toArray();

        // Recalcular escondido misturaria número de duas versões de fórmula sem
        // ninguém perceber qual é qual.
        expect($stale['has_stale_metrics'])->toBeTrue();
        expect($stale['stale_message'])->not->toBeNull();

        // E não recalculou: a versão antiga segue no banco.
        expect(DailyMetrics::where('metrics_version', '2020.01.0')->count())->toBe(14);
    });
});

describe('a tela', function () {

    it('renderiza o dashboard com o resumo', function () {
        importFor($this->user);

        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('isEmpty', false)
                ->has('summary.coverage')
                ->has('summary.validity')
                ->has('summary.metrics', 4)
            );
    });

    it('o período é ancorado na última leitura, não em hoje', function () {
        importFor($this->user);

        $summary = app(DashboardPresenter::class)->forLatestPeriod($this->user->id)->toArray();

        // Quem importa um export de duas semanas atrás quer ver aquele período,
        // não uma tela vazia dizendo que não há dados recentes.
        expect($summary['period']['to'])->toBe('2026-07-29');
        expect($summary['period']['from'])->toBe('2026-07-16');
    });

    it('não vaza dado de outro usuário', function () {
        $outro = User::factory()->create();
        importFor($outro);

        $this->actingAs($this->user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('isEmpty', true)
                ->where('summary.coverage.reading_count', 0)
            );
    });
});

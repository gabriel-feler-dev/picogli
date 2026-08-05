<?php

declare(strict_types=1);

use App\Domain\Import\BolusLinker;
use App\Domain\Import\CarelinkCsvReader;
use App\Domain\Import\EventExploder;
use App\Domain\Import\Persistence\MealEnricher;
use App\Domain\Import\SettingsInferrer;
use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Metrics\Value\Validity;
use App\Domain\Patterns\Persistence\PatternDatasetBuilder;
use App\Domain\Patterns\Value\Daypart;
use App\Jobs\ComputeMetricsJob;
use App\Jobs\ImportCsvJob;
use App\Models\DailyMetrics;
use App\Models\SensorReading;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * T302.4 e T302.6 — o construtor do dataset, contra o export de referência.
 *
 * ⚠️ Os valores conferidos aqui vêm do `gabarito.md` **§Fase 4**, apurado em T300
 * por script Python independente do PHP. Se o PHP divergir, presume-se que o PHP
 * está errado (Artigo XI).
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

    (new ComputeMetricsJob($this->user->id))->handle(
        app(DailyMetricsWriter::class),
    );

    $this->builder = app(PatternDatasetBuilder::class);
});

describe('o recorte do período', function () {

    it('ancora na última leitura do usuário, não em now()', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        // A última leitura do export é 2026-07-29 18:47. `now()` daria um
        // período vazio — e, pior, tornaria o relatório NÃO REPRODUTÍVEL, o que
        // viola o Artigo II.
        expect($dataset->periodEnd)->toBe('2026-07-29');
        expect($dataset->periodStart)->toBe('2026-07-16');
        expect($dataset->series->count())->toBe(3616);
    });

    it('usuário sem leitura devolve dataset vazio, não erro', function () {
        $outro = User::factory()->create();

        $dataset = $this->builder->forLatestPeriod($outro->id);

        expect($dataset->isEmpty())->toBeTrue();
        // Mesmo vazio: cobertura preenchida e os quatro períodos presentes com
        // n = 0. Regra que iterasse sobre eles acharia a estrutura esperada.
        expect($dataset->coverage->readingCount)->toBe(0);
        expect($dataset->dayparts)->toHaveCount(4);
        expect($dataset->validity)->toBe(Validity::InsufficientDays);
    });

    it('não mistura dado de outro usuário', function () {
        $outro = User::factory()->create();

        (new ImportCsvJob($outro->id, requireReferenceExport(), 'America/Sao_Paulo'))->handle(
            app(CarelinkCsvReader::class),
            app(EventExploder::class),
            app(BolusLinker::class),
            app(MealEnricher::class),
            app(SettingsInferrer::class),
        );

        expect($this->builder->forLatestPeriod($this->user->id)->series->count())->toBe(3616);
        expect(SensorReading::count())->toBe(7232);
    });
});

describe('os quatro períodos contra o gabarito §Fase 4', function () {

    it('reproduz percentual e n de cada período', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        $esperado = [
            'dawn' => ['n' => 936, 'above' => 39, 'pct' => 4.17],
            'morning' => ['n' => 932, 'above' => 88, 'pct' => 9.44],
            'afternoon' => ['n' => 917, 'above' => 221, 'pct' => 24.10],
            'evening' => ['n' => 831, 'above' => 188, 'pct' => 22.62],
        ];

        foreach ($esperado as $key => $valores) {
            $stats = $dataset->dayparts[$key];

            expect($stats->count)->toBe($valores['n']);
            expect($stats->aboveCount)->toBe($valores['above']);
            expect($stats->percentAbove())->toBeCloseToValue($valores['pct'], 0.01);
        }
    });

    it('a soma dos n dos quatro períodos é 3.616', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        expect(array_sum(array_map(fn ($s): int => $s->count, $dataset->dayparts)))->toBe(3616);
    });

    // ⚠️ O número que a R1 vai usar. 5,78x, não os 4x da análise exploratória —
    // aquela comparava janelas de 12 h e 7 h (§D6).
    it('a razão pior/melhor é 5,78x', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        $pior = $dataset->daypart(Daypart::Afternoon)->percentAbove();
        $melhor = $dataset->daypart(Daypart::Dawn)->percentAbove();

        expect($pior / $melhor)->toBeCloseToValue(5.78, 0.01);
    });
});

describe('o que mais entra no dataset', function () {

    it('traz episódios de hipo e de hiper separados', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        expect($dataset->hypoEpisodes)->toHaveCount(5);
        expect($dataset->hyperEpisodes)->toHaveCount(2);
    });

    it('traz as 3 lacunas de sensor, em minutos', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        expect($dataset->gaps)->toHaveCount(3);

        $maior = max(array_map(fn ($g): float => $g->minutes, $dataset->gaps));
        // 1.347 min, não "22,4 h": o valor formatado fica em cima da borda de
        // arredondamento e criava divergência fantasma (gabarito §Lacunas).
        expect($maior)->toBe(1347.0);
    });

    it('traz os 14 dias com cobertura', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        expect($dataset->daily)->toHaveCount(14);

        $porData = $dataset->dailyByDate();
        expect($porData['2026-07-22']->coveragePct)->toBeCloseToValue(33.7, 0.6);
        expect($porData['2026-07-22']->autoInsulinU)->toBeCloseToValue(9.0, 0.05);
    });

    it('traz as 52 refeições com o CR vigente', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        expect($dataset->meals)->toHaveCount(52);

        $comCr = array_filter($dataset->meals, fn ($m): bool => $m->carbRatio !== null);
        expect(count($comCr))->toBeGreaterThan(0);
    });

    it('traz os totais diários de insulina automática', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        expect($dataset->autoInsulinByDate)->toHaveCount(14);
        expect($dataset->autoInsulinByDate['2026-07-22'])->toBeCloseToValue(9.0, 0.05);
        expect($dataset->meanAutoInsulin())->toBeCloseToValue(31.4, 0.05);
    });

    it('traz os eventos do aparelho agregados por código e por categoria', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        expect($dataset->deviceEventCount('SET CHANGE REMINDER'))->toBe(3);
        expect($dataset->deviceCategoryCount('rewind'))->toBe(3);
        expect($dataset->deviceCategoryCount('prime'))->toBe(6);
        expect($dataset->deviceCategoryCount('calibration'))->toBe(39);
        expect($dataset->deviceEventCount('CODIGO QUE NAO EXISTE'))->toBe(0);
    });

    it('traz os 3 rewinds nos instantes do gabarito', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        $instantes = array_map(fn ($r): string => $r->format('Y-m-d H:i:s'), $dataset->rewinds);

        expect($instantes)->toBe([
            '2026-07-16 17:38:47',
            '2026-07-20 21:33:04',
            '2026-07-25 13:12:15',
        ]);
    });

    it('traz a configuração inferida do aparelho', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        expect($dataset->settings->carbRatioProfile)->not->toBeEmpty();
        // CR enfraquece ao longo do dia: 5 → 6 → 8 g/U. É o caso de R6.
        expect($dataset->settings->carbRatioProfile[7])->toBeCloseToValue(5.0);
        expect($dataset->settings->carbRatioProfile[13])->toBeCloseToValue(6.0);
        expect($dataset->settings->carbRatioProfile[20])->toBeCloseToValue(8.0);
    });

    // ⚠️ O assert mais forte do arquivo. A apuração original (Python, ±10 min)
    // deu erro médio de 10,7% com n=39; esta implementação em PHP, por busca
    // binária, dá 10,6836% com os mesmos 39 pares. **Duas implementações
    // independentes concordando** — é o que o Artigo XI pede.
    it('pareia as 39 calibrações com erro médio de 10,7% (gabarito §Qualidade)', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        expect($dataset->calibrationWindowMinutes)->toBe(10);
        expect($dataset->calibrationPairs)->toHaveCount(39);

        $erros = array_map(
            fn ($p): float => $p->relativeErrorPercent(),
            $dataset->calibrationPairs,
        );

        expect(array_sum($erros) / count($erros))->toBeCloseToValue(10.68, 0.01);
        expect(round(array_sum($erros) / count($erros), 1))->toBe(10.7);
    });

    // A janela de ±10 min é folgada para este arquivo: o pareamento mais
    // distante fica a 4,17 min. Registrado porque significa que NENHUMA
    // calibração foi excluída — e a exclusão, quando ocorrer, será real.
    it('nenhuma calibração ficou perto da borda da janela', function () {
        $dataset = $this->builder->forLatestPeriod($this->user->id);

        $offsets = array_map(fn ($p): float => $p->offsetMinutes, $dataset->calibrationPairs);

        expect(max($offsets))->toBeLessThan(5.0);
        expect(array_sum($offsets) / count($offsets))->toBeCloseToValue(1.41, 0.01);
    });
});

describe('NFR-402 — número fixo de consultas', function () {

    // ⚠️ N+1 nasce invisível: com 14 dias ninguém nota. Um usuário com um ano de
    // dados faria o motor emitir centenas de consultas pela rede — e em produção
    // o banco é MariaDB remoto, onde a suíte já leva 5x mais tempo.
    it('não cresce com a quantidade de dias', function () {
        $contar = function (callable $acao): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $acao();
            $total = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $total;
        };

        $consultasPeriodoCurto = $contar(
            fn () => $this->builder->forPeriod($this->user->id, '2026-07-16', '2026-07-17')
        );

        $consultasPeriodoLongo = $contar(
            fn () => $this->builder->forPeriod($this->user->id, '2026-07-16', '2026-07-29')
        );

        expect($consultasPeriodoLongo)->toBe($consultasPeriodoCurto);
        expect($consultasPeriodoLongo)->toBeLessThanOrEqual(10);
    });

    it('o pareamento de calibração não custa consulta nenhuma', function () {
        // A série já está em memória; o pareamento é algoritmo, não SELECT.
        // Se um dia isto falhar, alguém moveu o pareamento para dentro de um loop.
        DB::flushQueryLog();
        DB::enableQueryLog();

        $dataset = $this->builder->forPeriod($this->user->id, '2026-07-16', '2026-07-29');

        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect(count($dataset->calibrationPairs))->toBeGreaterThan(30);
        // 9 consultas previstas + 1 folga. 39 pares NÃO acrescentam 39 idas.
        expect($total)->toBeLessThanOrEqual(10);
    });
});

describe('§D9 — versão das métricas', function () {

    it('sinaliza métrica de versão antiga em vez de recalcular em silêncio', function () {
        DailyMetrics::where('user_id', $this->user->id)
            ->update(['metrics_version' => '2020.01.1']);

        $dataset = $this->builder->forLatestPeriod($this->user->id);

        expect($dataset->hasStaleMetrics)->toBeTrue();
        // A versão corrente viaja junto: é ela que vai para `period_reports`,
        // e um achado derivado de métrica velha não pode se passar por novo.
        expect($dataset->metricsVersion)->not->toBe('2020.01.1');
    });

    it('não sinaliza quando as métricas estão na versão corrente', function () {
        expect($this->builder->forLatestPeriod($this->user->id)->hasStaleMetrics)->toBeFalse();
    });
});

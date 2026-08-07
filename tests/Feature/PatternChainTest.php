<?php

declare(strict_types=1);

use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Patterns\PatternEngine;
use App\Domain\Patterns\Persistence\PatternDatasetBuilder;
use App\Domain\Patterns\Persistence\PeriodReportWriter;
use App\Jobs\ComputeMetricsJob;
use App\Jobs\ComputePatternsJob;
use App\Jobs\GenerateNarrativeJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * ⚠️⚠️ **A CORRENTE INTEIRA, ELO POR ELO.**
 *
 * Este arquivo existe por causa de um defeito encontrado EM PRODUÇÃO em
 * 07/08/2026: com 3.616 leituras e 14 dias de `daily_metrics` no banco, a tela
 * `/avaliacao` dizia "ainda não há avaliação" — e continuaria dizendo para
 * sempre.
 *
 * A causa: `ImportCsvJob` disparava `ComputeMetricsJob`, e **a corrente parava
 * ali**. Nada no app inteiro disparava `ComputePatternsJob` nem
 * `GenerateNarrativeJob`. A cadeia estava desenhada no docblock do
 * `GenerateNarrativeJob` e no `AGENTS.md` — e nunca existiu no código.
 *
 * ⚠️ **Por que 1.052 testes não pegaram.** Cada job era testado sozinho:
 * instancia, roda, confere o que gravou. Todos passavam, e continuam passando —
 * eles estavam certos sobre o que verificavam. O que ninguém verificava era se
 * alguém CHAMA o job seguinte.
 *
 * A lição é específica: **teste de unidade de um passo nunca prova a existência
 * do passo seguinte.** Um sistema pode ter todos os elos perfeitos e nenhuma
 * corrente. Toda vez que um job disparar outro, o disparo precisa de teste
 * próprio — o comportamento de quem dispara não é o mesmo de quem executa.
 */
it('o cálculo de métricas dispara a busca de padrões', function () {
    Queue::fake();

    $usuario = User::factory()->create();

    (new ComputeMetricsJob($usuario->id))->handle(app(DailyMetricsWriter::class));

    Queue::assertPushed(
        ComputePatternsJob::class,
        fn (ComputePatternsJob $job): bool => $job->userId === $usuario->id,
    );
});

/**
 * ⚠️ O outro lado da guarda: sem leitura não há relatório, e sem relatório não
 * há o que narrar. Disparar mesmo assim pediria ao job da narrativa um
 * `period_report` que não existe.
 */
it('sem leitura nenhuma, a busca de padrões não gera relatório nem narrativa', function () {
    Queue::fake();

    $usuario = User::factory()->create();

    $relatorio = (new ComputePatternsJob($usuario->id))->handle(
        app(PatternDatasetBuilder::class),
        app(PatternEngine::class),
        app(PeriodReportWriter::class),
    );

    expect($relatorio)->toBeNull();

    Queue::assertNotPushed(GenerateNarrativeJob::class);
});

/**
 * ⚠️ A corrente documentada precisa bater com a corrente do código.
 *
 * O `AGENTS.md` e o docblock do `GenerateNarrativeJob` desenham as quatro
 * etapas. Foi essa documentação que fez todo mundo — inclusive quem escreveu o
 * código — acreditar que a ligação existia.
 */
it('cada elo da corrente dispara o próximo, no código e não só no desenho', function () {
    $metrics = (string) file_get_contents(app_path('Jobs/ComputeMetricsJob.php'));
    $patterns = (string) file_get_contents(app_path('Jobs/ComputePatternsJob.php'));
    $import = (string) file_get_contents(app_path('Jobs/ImportCsvJob.php'));

    expect($import)->toContain('ComputeMetricsJob::dispatch');
    expect($metrics)->toContain('ComputePatternsJob::dispatch');
    expect($patterns)->toContain('GenerateNarrativeJob::dispatch');
});

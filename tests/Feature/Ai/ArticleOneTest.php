<?php

declare(strict_types=1);

use App\Domain\Ai\CooldownStore;
use App\Domain\Ai\NarrativeGenerator;
use App\Domain\Ai\Provider;
use App\Domain\Ai\Value\DiscardReason;
use App\Infrastructure\Ai\GeminiProvider;
use App\Jobs\GenerateNarrativeJob;
use App\Models\PeriodReport;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeProvider;

/**
 * ⚠️⚠️⚠️ **T408 — O TESTE DO ARTIGO I (FR-508).**
 *
 * > "Se o provedor de IA estiver indisponível, o produto continua exibindo todos
 * >  os gráficos e todas as métricas — perde apenas a redação, que cai para
 * >  template estático."
 * >
 * > **Teste de conformidade: desligue a chave da API. Se qualquer número
 * >  desaparecer da tela, o artigo foi violado.**
 *
 * A constituição escreveu esse teste em prosa na fase 1. Este arquivo o executa.
 *
 * ⚠️ **É o último da fase de propósito.** Ele valida a propriedade que as cinco
 * fases anteriores existem para preservar, e só faz sentido com tudo montado.
 *
 * ⚠️ **Automatizado, não manual.** "Eu testei desligando" é a forma de garantia
 * que envelhece pior: vale para o dia em que foi feita, e ninguém refaz.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    importAndAnalyse($this->user->id);

    $this->report = PeriodReport::where('user_id', $this->user->id)->firstOrFail();
});

/** O provider REAL, sem chave — o cenário que o artigo descreve. */
function providerSemChave(): Provider
{
    return new GeminiProvider(app(HttpFactory::class), null, 45);
}

/** A tela inteira, como o React a recebe. */
function payloadDaTela(): array
{
    return test()->get('/avaliacao')->viewData('page')['props'];
}

/**
 * ⚠️ **O TESTE CENTRAL.** Os dez achados, com os mesmos números, com e sem IA.
 */
it('com a chave desligada, a tela mostra os MESMOS 10 achados com os MESMOS números', function () {
    // 1. Primeiro, com IA funcionando: gera e grava a narrativa.
    app()->instance(Provider::class, FakeProvider::replying(
        'A tarde concentra 24,1% do tempo acima, contra 4,17% da madrugada.'
    ));
    app()->forgetInstance(NarrativeGenerator::class);

    (new GenerateNarrativeJob($this->report->id))->handle(app(NarrativeGenerator::class));

    $comIa = payloadDaTela();

    expect($comIa['narrative'])->not->toBeNull();
    expect($comIa['findings'])->toHaveCount(10);

    // 2. Agora com a chave desligada — e o relatório regerado do zero.
    app()->instance(Provider::class, providerSemChave());
    app()->forgetInstance(NarrativeGenerator::class);
    Http::fake();

    importAndAnalyse($this->user->id);

    $relatorio = PeriodReport::where('user_id', $this->user->id)->firstOrFail();
    (new GenerateNarrativeJob($relatorio->id))->handle(app(NarrativeGenerator::class));

    $semIa = payloadDaTela();

    // ⚠️ A ÚNICA coisa que muda é o texto do topo.
    expect($semIa['narrative'])->toBeNull();

    // ⚠️⚠️ E TUDO O MAIS É IDÊNTICO — achado por achado, número por número.
    expect($semIa['findings'])->toBe($comIa['findings']);
    expect($semIa['coverage'])->toBe($comIa['coverage']);
    expect($semIa['period'])->toBe($comIa['period']);
    expect($semIa['rule_failures'])->toBe($comIa['rule_failures']);

    // E a rede nunca foi tocada.
    Http::assertNothingSent();
});

it('cada um dos dez achados mantém prosa e evidência sem IA', function () {
    app()->instance(Provider::class, providerSemChave());
    app()->forgetInstance(NarrativeGenerator::class);

    (new GenerateNarrativeJob($this->report->id))->handle(app(NarrativeGenerator::class));

    $props = payloadDaTela();
    $chaves = 0;

    foreach ($props['findings'] as $achado) {
        // ⚠️ A prosa de fallback da fase 4 é o que sustenta o Artigo I. Sem ela
        // publicável, "perde-se apenas a redação" seria falso.
        expect(trim($achado['prose']))->not->toBe('');
        expect(mb_strlen($achado['prose']))->toBeGreaterThan(80);

        // E a evidência continua rastreável (Artigo III).
        expect($achado['evidence'])->not->toBeEmpty();
        $chaves += count($achado['evidence']);
    }

    expect($props['findings'])->toHaveCount(10);
    expect($chaves)->toBeGreaterThan(80);
});

it('a tela não mostra erro de provedor em lugar nenhum', function () {
    app()->instance(Provider::class, providerSemChave());
    app()->forgetInstance(NarrativeGenerator::class);

    (new GenerateNarrativeJob($this->report->id))->handle(app(NarrativeGenerator::class));

    $html = mb_strtolower(json_encode(payloadDaTela(), JSON_THROW_ON_ERROR));

    // ⚠️ NFR-502 — degradação silenciosa. O usuário vê a tela de ontem, nunca
    // uma mensagem sobre cota, chave ou provedor.
    foreach ([
        'gemini_api_key', 'unauthorized', 'quota', 'api key', 'provider',
        'cooldown', 'rate limit', 'exception',
    ] as $vazamento) {
        expect(str_contains($html, $vazamento))->toBeFalse(
            "a tela expõe '{$vazamento}' ao usuário"
        );
    }
});

it('sem chave, o job registra o descarte e não quebra', function () {
    app()->instance(Provider::class, providerSemChave());
    app()->forgetInstance(NarrativeGenerator::class);

    $attempt = (new GenerateNarrativeJob($this->report->id))->handle(app(NarrativeGenerator::class));

    // Descarte com razão, nunca exceção.
    expect($attempt->wasPublished())->toBeFalse();
    expect($attempt->discardReason)->toBe(DiscardReason::NoModelAvailable);

    // E o relatório fica intacto: os achados são produto da fase 4.
    expect($this->report->fresh()->finding_count)->toBe(10);
    expect($this->report->fresh()->narrative)->toBeNull();
});

/**
 * ⚠️ **Chave inválida não põe modelo de castigo** — a cadeia inteira está fora,
 * não um modelo. Sem isso, uma chave ausente em desenvolvimento deixaria os três
 * modelos de castigo e o primeiro teste com chave válida falharia sem motivo
 * aparente.
 */
it('sem chave, nenhum modelo entra em cooldown', function () {
    app()->instance(Provider::class, providerSemChave());
    app()->forgetInstance(NarrativeGenerator::class);

    (new GenerateNarrativeJob($this->report->id))->handle(app(NarrativeGenerator::class));

    $store = app(CooldownStore::class);

    foreach (config('ai.model_chain') as $modelo) {
        expect($store->isCoolingDown($modelo))->toBeFalse(
            "{$modelo} entrou em cooldown por falta de chave"
        );
    }
});

/**
 * O dashboard e a importação nunca dependeram de IA, e o teste registra isso —
 * o Artigo I vale para a aplicação inteira, não só para `/avaliacao`.
 */
it('as outras telas seguem funcionando sem chave', function () {
    app()->instance(Provider::class, providerSemChave());
    Http::fake();

    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        // Os quatro cards traduzidos da fase 3 continuam lá, sem IA nenhuma.
        ->has('summary.metrics', 4)
        ->where('isEmpty', false)
    );

    $this->get('/importar')->assertOk();
    $this->get('/avaliacao')->assertOk();

    Http::assertNothingSent();
});

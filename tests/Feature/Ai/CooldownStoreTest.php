<?php

declare(strict_types=1);

use App\Domain\Ai\CooldownStore;
use App\Domain\Ai\ModelChain;
use App\Domain\Ai\ProviderFailure;
use App\Infrastructure\Ai\CacheCooldownStore;

/**
 * T402.3 — o cooldown PERSISTIDO (§D4).
 *
 * ⚠️ Na hospedagem compartilhada a fila roda por cron com `--stop-when-empty`
 * (ADR-5): o processo morre entre execuções. Cooldown em memória some, e o
 * sistema reaprende que o modelo está esgotado a cada chamada — gastando uma
 * requisição por vez para descobrir o que já sabia.
 */
it('o container entrega a implementação de cache', function () {
    expect(app(CooldownStore::class))->toBeInstanceOf(CacheCooldownStore::class);
});

it('guarda e devolve o tempo restante', function () {
    $store = app(CooldownStore::class);

    expect($store->isCoolingDown('gemini-2.5-flash'))->toBeFalse();
    expect($store->remainingSeconds('gemini-2.5-flash'))->toBeNull();

    $store->penalise('gemini-2.5-flash', 60);

    expect($store->isCoolingDown('gemini-2.5-flash'))->toBeTrue();
    expect($store->remainingSeconds('gemini-2.5-flash'))->toBeGreaterThan(50);
    expect($store->remainingSeconds('gemini-2.5-flash'))->toBeLessThanOrEqual(60);
});

it('o castigo é por modelo, não global', function () {
    $store = app(CooldownStore::class);

    $store->penalise('gemini-2.5-flash', 60);

    // ⚠️ O limite do nível gratuito é POR MODELO — é a premissa que faz a cadeia
    // valer a pena. Um castigo global tornaria a cadeia inútil.
    expect($store->isCoolingDown('gemini-2.5-flash'))->toBeTrue();
    expect($store->isCoolingDown('gemini-2.5-flash-lite'))->toBeFalse();
    expect($store->isCoolingDown('gemini-2.0-flash'))->toBeFalse();
});

/**
 * ⚠️ **A prova da persistência:** uma instância NOVA do store lê o castigo que a
 * anterior gravou. É o que acontece entre duas execuções do cron.
 */
it('sobrevive à troca de instância — é o caso do cron', function () {
    app(CooldownStore::class)->penalise('gemini-2.5-flash', 300);

    $outraInstancia = new CacheCooldownStore(app('cache.store'));

    expect($outraInstancia->isCoolingDown('gemini-2.5-flash'))->toBeTrue();
});

it('release libera o modelo', function () {
    $store = app(CooldownStore::class);

    $store->penalise('gemini-2.5-flash', 300);
    $store->release('gemini-2.5-flash');

    expect($store->isCoolingDown('gemini-2.5-flash'))->toBeFalse();
});

it('a cadeia do container usa os modelos e cooldowns da config', function () {
    $chain = app(ModelChain::class);

    expect($chain->available())->toBe(config('ai.model_chain'));
    expect($chain->isExhausted())->toBeFalse();

    $chain->penalise(config('ai.model_chain')[0], ProviderFailure::QuotaExhausted);

    // O melhor modelo saiu; os outros dois continuam.
    expect($chain->available())->toHaveCount(2);
    expect($chain->available()[0])->toBe(config('ai.model_chain')[1]);
});

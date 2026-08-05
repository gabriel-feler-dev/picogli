<?php

declare(strict_types=1);

use App\Domain\Ai\ModelChain;
use App\Domain\Ai\ProviderFailure;
use App\Domain\Ai\ProviderUnavailable;
use Tests\Support\ArrayCooldownStore;

/**
 * T402 — a cadeia de modelos e o cooldown (FR-502, §D4).
 *
 * Roda sem container e sem cache real: o `ArrayCooldownStore` tem relógio
 * controlável, então "o modelo voltou depois de 60 s" é testável sem esperar 60
 * segundos.
 */
$cooldowns = [
    'rate_limit_per_minute' => 60,
    'quota_exhausted' => 21600,
    'timeout' => 300,
];

function chain(ArrayCooldownStore $store, array $cooldowns, array $models = ['bom', 'medio', 'fraco']): ModelChain
{
    return new ModelChain($models, $cooldowns, $store);
}

describe('sempre do topo, nunca "onde parou"', function () use ($cooldowns) {

    it('usa o melhor modelo quando todos estão livres', function () use ($cooldowns) {
        $tentados = [];

        $resultado = chain(new ArrayCooldownStore, $cooldowns)
            ->attempt(function (string $model) use (&$tentados): string {
                $tentados[] = $model;

                return 'texto de '.$model;
            });

        expect($resultado)->toBe('texto de bom');
        expect($tentados)->toBe(['bom']);
    });

    it('desce na cadeia quando o melhor falha', function () use ($cooldowns) {
        $tentados = [];

        $resultado = chain(new ArrayCooldownStore, $cooldowns)
            ->attempt(function (string $model) use (&$tentados): string {
                $tentados[] = $model;

                if ($model === 'bom') {
                    throw new ProviderUnavailable(ProviderFailure::RateLimitPerMinute, $model);
                }

                return 'texto de '.$model;
            });

        expect($resultado)->toBe('texto de medio');
        expect($tentados)->toBe(['bom', 'medio']);
    });

    /**
     * ⚠️⚠️ **O REQUISITO T402.4, e ele acontece por construção.**
     *
     * Um cursor que descesse e ficasse embaixo continuaria usando o modelo mais
     * fraco depois de o melhor ter voltado — e **ninguém notaria**, porque o texto
     * continuaria saindo. Percorrer sempre do topo elimina a classe de bug.
     */
    it('volta ao melhor modelo assim que o cooldown expira', function () use ($cooldowns) {
        $store = new ArrayCooldownStore;
        $chain = chain($store, $cooldowns);

        // Primeira tentativa: 'bom' estoura o limite por minuto e cai para 'medio'.
        $chain->attempt(function (string $model): string {
            if ($model === 'bom') {
                throw new ProviderUnavailable(ProviderFailure::RateLimitPerMinute, $model);
            }

            return 'ok';
        });

        expect($store->isCoolingDown('bom'))->toBeTrue();
        expect($chain->available())->toBe(['medio', 'fraco']);

        // Segunda tentativa, ainda dentro do cooldown: começa em 'medio'.
        $tentados = [];
        $chain->attempt(function (string $model) use (&$tentados): string {
            $tentados[] = $model;

            return 'ok';
        });
        expect($tentados)->toBe(['medio']);

        // Passados 61 s, 'bom' volta — e é ele que é usado de novo.
        $store->advance(61);

        $tentados = [];
        $chain->attempt(function (string $model) use (&$tentados): string {
            $tentados[] = $model;

            return 'ok';
        });

        expect($tentados)->toBe(['bom']);
        expect($chain->available())->toBe(['bom', 'medio', 'fraco']);
    });
});

describe('cooldown por TIPO de erro', function () use ($cooldowns) {

    // ⚠️ A API devolve 429 para os dois casos. Tratar igual faria o sistema bater
    // no modelo esgotado a cada requisição, o dia inteiro.
    it('limite por minuto e cota diária recebem castigos diferentes', function () use ($cooldowns) {
        $store = new ArrayCooldownStore;
        $chain = chain($store, $cooldowns);

        $chain->penalise('bom', ProviderFailure::RateLimitPerMinute);
        $chain->penalise('medio', ProviderFailure::QuotaExhausted);

        expect($store->remainingSeconds('bom'))->toBe(60);
        expect($store->remainingSeconds('medio'))->toBe(21600);

        // Um minuto depois, só o primeiro voltou.
        $store->advance(61);

        expect($store->isCoolingDown('bom'))->toBeFalse();
        expect($store->isCoolingDown('medio'))->toBeTrue();
    });

    /**
     * ⚠️ `BadResponse` NÃO põe o modelo de castigo. Penalizar por resposta
     * malformada esconderia um problema de PARSING atrás de um cooldown de horas
     * — e o sintoma reportado seria "a IA parou de funcionar".
     */
    it('resposta malformada não gera cooldown', function () use ($cooldowns) {
        $store = new ArrayCooldownStore;
        $chain = chain($store, $cooldowns);

        $chain->penalise('bom', ProviderFailure::BadResponse);
        $chain->penalise('medio', ProviderFailure::Unknown);

        expect($store->isCoolingDown('bom'))->toBeFalse();
        expect($store->isCoolingDown('medio'))->toBeFalse();
    });

    it('falta de cooldown configurado explode, em vez de retentar em laço', function () {
        $chain = chain(new ArrayCooldownStore, ['rate_limit_per_minute' => 60]);

        expect(fn () => $chain->penalise('bom', ProviderFailure::QuotaExhausted))
            ->toThrow(InvalidArgumentException::class, 'quota_exhausted');
    });
});

describe('a cadeia nunca lança para cima (Artigo I)', function () use ($cooldowns) {

    // ⚠️ Exceção de IA não chega à tela. Quem chama recebe `null` e cai para o
    // `fallbackProse`, que já é publicável desde a fase 4.
    it('todos falhando devolve null', function () use ($cooldowns) {
        $resultado = chain(new ArrayCooldownStore, $cooldowns)
            ->attempt(function (string $model): string {
                throw new ProviderUnavailable(ProviderFailure::QuotaExhausted, $model);
            });

        expect($resultado)->toBeNull();
    });

    it('todos de castigo devolve null sem tentar nenhum', function () use ($cooldowns) {
        $store = new ArrayCooldownStore;
        $chain = chain($store, $cooldowns);

        foreach (['bom', 'medio', 'fraco'] as $model) {
            $chain->penalise($model, ProviderFailure::QuotaExhausted);
        }

        $tentativas = 0;
        $resultado = $chain->attempt(function () use (&$tentativas): string {
            $tentativas++;

            return 'nunca';
        });

        expect($resultado)->toBeNull();
        expect($tentativas)->toBe(0);
        expect($chain->isExhausted())->toBeTrue();
    });

    /**
     * ⚠️ Chave inválida é a mesma para todos os modelos: descer na cadeia só
     * gastaria tempo para receber a mesma recusa três vezes.
     */
    it('chave inválida para na primeira tentativa', function () use ($cooldowns) {
        $tentados = [];

        $resultado = chain(new ArrayCooldownStore, $cooldowns)
            ->attempt(function (string $model) use (&$tentados): string {
                $tentados[] = $model;

                throw new ProviderUnavailable(ProviderFailure::Unauthorized, $model);
            });

        expect($resultado)->toBeNull();
        expect($tentados)->toBe(['bom']);
    });
});

it('cadeia vazia explode na construção', function () {
    expect(fn () => new ModelChain([], [], new ArrayCooldownStore))
        ->toThrow(InvalidArgumentException::class, 'vazia');
});

it('status mostra quanto falta para cada modelo', function () use ($cooldowns) {
    $store = new ArrayCooldownStore;
    $chain = chain($store, $cooldowns);

    $chain->penalise('medio', ProviderFailure::RateLimitPerMinute);

    expect($chain->status())->toBe(['bom' => null, 'medio' => 60, 'fraco' => null]);
});

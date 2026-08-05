<?php

declare(strict_types=1);

use App\Domain\Ai\ModelChain;
use App\Domain\Ai\NarrativeGenerator;
use App\Domain\Ai\NumberGuard;
use App\Domain\Ai\PayloadSanitizer;
use App\Domain\Ai\PromptBuilder;
use App\Domain\Ai\Provider;
use App\Domain\Ai\ProviderFailure;
use App\Domain\Ai\Value\AiPayload;
use App\Domain\Ai\Value\DiscardReason;
use Tests\Support\ArrayCooldownStore;
use Tests\Support\FakeProvider;

/**
 * T405 — o orquestrador (FR-504, FR-505).
 *
 * Roda sem container e sem rede: `FakeProvider` + `ArrayCooldownStore`.
 */
function generator(Provider $provider, ?NumberGuard $guard = null, int $maxWords = 350): NarrativeGenerator
{
    return new NarrativeGenerator(
        new PayloadSanitizer(['rule_id', 'severity', 'rank', 'ratio', 'nadir', 'date', 'period_from']),
        new class implements PromptBuilder
        {
            public function build(AiPayload $payload): string
            {
                return 'prompt com '.count($payload->findings).' achados';
            }
        },
        new ModelChain(['bom', 'medio'], ['quota_exhausted' => 21600, 'rate_limit_per_minute' => 60], new ArrayCooldownStore),
        $provider,
        $guard ?? new NumberGuard(0.06, [0, 1, 2, 3, 4, 5, 10, 24, 100]),
        $maxWords,
    );
}

function generatorFindings(): array
{
    return [
        ['rule_id' => 'R1_DAYPART_DRIFT', 'severity' => 'priority', 'rank' => 4, 'evidence' => ['ratio' => 5.78]],
        ['rule_id' => 'R3_ROLLERCOASTER', 'severity' => 'attention', 'rank' => 3, 'evidence' => ['nadir' => 55, 'date' => '2026-07-25']],
    ];
}

describe('o caminho felizes', function () {

    it('publica a narrativa quando tudo confere', function () {
        $provider = FakeProvider::replying('A razão foi de 5,78 vezes, e o mínimo foi 55.');

        $attempt = generator($provider)->generate(generatorFindings(), ['period_from' => '2026-07-16']);

        expect($attempt->wasPublished())->toBeTrue();
        expect($attempt->discardReason)->toBeNull();
        expect($attempt->result->model)->toBe('bom');
        expect($attempt->logMessage())->toContain('Narrativa gerada por bom');
    });

    /**
     * ⚠️ §D7 — UMA chamada por relatório, com todos os achados juntos. Dez
     * chamadas custariam dez vezes mais e dariam dez parágrafos desconexos.
     */
    it('faz UMA chamada, com todos os achados', function () {
        $provider = FakeProvider::replying('5,78 e 55.');

        generator($provider)->generate(generatorFindings(), []);

        expect($provider->callCount())->toBe(1);
        expect($provider->calls[0]['prompt'])->toBe('prompt com 2 achados');
    });

    /**
     * ⚠️ Artigo VII — o provedor recebe o payload SANITIZADO, sempre. Não existe
     * caminho que entregue os achados crus.
     */
    it('o provedor recebe o payload sanitizado, não os achados crus', function () {
        $provider = FakeProvider::replying('5,78.');

        generator($provider)->generate([
            ['rule_id' => 'R1_DAYPART_DRIFT', 'severity' => 'info', 'rank' => 4, 'evidence' => [
                'ratio' => 5.78,
                'patient_name' => 'Fulano de Tal',
            ]],
        ], []);

        $payload = $provider->lastPayload();

        expect($payload)->toBeInstanceOf(AiPayload::class);
        expect($payload->findings[0]['evidence'])->toBe(['ratio' => 5.78]);
        expect($payload->droppedKeys)->toContain('patient_name');
        expect(str_contains($payload->toJson(), 'Fulano'))->toBeFalse();
    });
});

/**
 * ⚠️⚠️ **Todo caminho de falha devolve descarte, nunca exceção.** A tela cai para
 * o `fallbackProse` e o usuário vê o que veria ontem (Artigo I, NFR-502).
 */
describe('os caminhos de descarte', function () {

    it('cadeia esgotada devolve NoModelAvailable', function () {
        $attempt = generator(FakeProvider::failing(ProviderFailure::QuotaExhausted))
            ->generate(generatorFindings(), []);

        expect($attempt->wasPublished())->toBeFalse();
        expect($attempt->discardReason)->toBe(DiscardReason::NoModelAvailable);
    });

    it('chave inválida devolve NoModelAvailable sem tentar o segundo modelo', function () {
        $provider = FakeProvider::failing(ProviderFailure::Unauthorized);

        $attempt = generator($provider)->generate(generatorFindings(), []);

        expect($attempt->discardReason)->toBe(DiscardReason::NoModelAvailable);
        // A chave é a mesma para todos os modelos.
        expect($provider->callCount())->toBe(1);
    });

    it('resposta vazia devolve EmptyResponse', function () {
        $attempt = generator(FakeProvider::replying('   '))->generate(generatorFindings(), []);

        expect($attempt->discardReason)->toBe(DiscardReason::EmptyResponse);
    });

    /**
     * ⚠️ §D5 — o descarte por número inventado. É o motivo de a guarda existir, e
     * só é aceitável porque o `fallbackProse` da fase 4 é publicável.
     */
    it('número inventado descarta a narrativa INTEIRA, com os órfãos no log', function () {
        $provider = FakeProvider::replying(
            'A razão foi de 5,78 vezes, o mínimo foi 55, e sua média ficou em 142.'
        );

        $attempt = generator($provider)->generate(generatorFindings(), []);

        expect($attempt->wasPublished())->toBeFalse();
        expect($attempt->discardReason)->toBe(DiscardReason::OrphanNumbers);
        expect($attempt->orphanNumbers)->toBe(['142']);
        // ⚠️ O log tem de dizer QUAL número, senão investigar começa por adivinhar.
        expect($attempt->logMessage())->toContain('142');
    });

    it('saída em fuga devolve TooLong', function () {
        $provider = FakeProvider::replying(str_repeat('palavra ', 200));

        // Teto de 100 palavras: 200 passa da margem de 50%.
        $attempt = generator($provider, maxWords: 100)->generate(generatorFindings(), []);

        expect($attempt->discardReason)->toBe(DiscardReason::TooLong);
    });

    it('texto um pouco acima do teto ainda passa', function () {
        // ⚠️ A margem de 50% existe porque o teto é instrução de estilo, não
        // contrato. Descartar por 360 palavras quando o teto é 350 seria
        // policiamento; descartar por 1.000 é proteger o layout.
        $provider = FakeProvider::replying(str_repeat('palavra ', 120));

        expect(generator($provider, maxWords: 100)->generate(generatorFindings(), [])->wasPublished())
            ->toBeTrue();
    });
});

/**
 * §D10 — período sem achado não vira narrativa.
 */
it('nenhum achado devolve NothingToNarrate, e isso não é falha', function () {
    $provider = FakeProvider::replying('nunca deveria ser chamado');

    $attempt = generator($provider)->generate([], ['period_from' => '2026-07-16']);

    expect($attempt->discardReason)->toBe(DiscardReason::NothingToNarrate);
    // ⚠️ Não chama o provedor: não há o que conectar, e a tela já diz isso melhor.
    expect($provider->callCount())->toBe(0);
    // ⚠️ E não é falha — logar como erro treinaria quem lê o log a ignorá-lo.
    expect($attempt->discardReason->isFailure())->toBeFalse();
});

it('as outras razões de descarte SÃO falha', function () {
    foreach ([
        DiscardReason::NoModelAvailable,
        DiscardReason::EmptyResponse,
        DiscardReason::OrphanNumbers,
        DiscardReason::TooLong,
    ] as $razao) {
        expect($razao->isFailure())->toBeTrue();
    }
});

it('a cadeia desce para o segundo modelo e a narrativa sai', function () {
    $provider = FakeProvider::failingInOrder(
        [ProviderFailure::RateLimitPerMinute],
        'A razão foi de 5,78 vezes.',
    );

    $attempt = generator($provider)->generate(generatorFindings(), []);

    expect($attempt->wasPublished())->toBeTrue();
    expect($attempt->result->model)->toBe('medio');
    expect($provider->modelsTried())->toBe(['bom', 'medio']);
});

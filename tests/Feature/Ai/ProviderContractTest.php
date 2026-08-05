<?php

declare(strict_types=1);

use App\Domain\Ai\Provider;
use App\Domain\Ai\ProviderFailure;
use App\Domain\Ai\ProviderUnavailable;
use App\Domain\Ai\Value\AiPayload;
use App\Domain\Ai\Value\AiResult;
use Tests\Support\FakeProvider;

/**
 * T401 — o contrato do provedor (FR-502).
 *
 * ⚠️ **Nenhum teste desta fase toca a rede** (NFR-501). Um teste que depende da
 * API falha quando a cota acaba — e gasta cota para rodar, que é exatamente o
 * recurso que a cadeia de modelos existe para economizar.
 */
function fakePayload(): AiPayload
{
    return new AiPayload(
        period: ['period_from' => '2026-07-16', 'coverage_percent' => 91.1],
        findings: [[
            'rule_id' => 'R1_DAYPART_DRIFT',
            'severity' => 'priority',
            'rank' => 4,
            'evidence' => ['ratio' => 5.78],
        ]],
    );
}

it('o fake implementa a interface do domínio', function () {
    expect(FakeProvider::replying())->toBeInstanceOf(Provider::class);
});

it('devolve texto com o modelo e o instante', function () {
    $result = FakeProvider::replying('Sua tarde concentra o tempo alto.')
        ->generate('gemini-2.5-flash', 'prompt', fakePayload());

    expect($result)->toBeInstanceOf(AiResult::class);
    expect($result->text)->toBe('Sua tarde concentra o tempo alto.');
    // ⚠️ Procedência: `narrative_model` e `narrative_generated_at` respondem
    // "qual modelo escreveu" e "quando" quando um texto sai estranho.
    expect($result->model)->toBe('gemini-2.5-flash');
    expect($result->generatedAt->format('Y-m-d'))->toBe('2026-08-05');
    expect($result->isEmpty())->toBeFalse();
    expect($result->wordCount())->toBe(6);
});

it('recebe prompt e payload separados', function () {
    // ⚠️ Separados, e não numa string pronta: é o `AiPayload` que o teste
    // anti-vazamento inspeciona. Se o chamador entregasse texto já montado, a
    // verificação do Artigo VII não teria objeto para varrer.
    $provider = FakeProvider::replying();
    $provider->generate('m1', 'instruções', fakePayload());

    expect($provider->calls[0]['prompt'])->toBe('instruções');
    expect($provider->calls[0]['payload'])->toBeInstanceOf(AiPayload::class);
    expect($provider->lastPayload()->findings[0]['evidence']['ratio'])->toBe(5.78);
});

describe('a classificação da falha', function () {

    it('carrega a razão e o modelo que falhou', function () {
        try {
            FakeProvider::failing(ProviderFailure::QuotaExhausted)
                ->generate('gemini-2.5-flash', 'p', fakePayload());

            expect(false)->toBeTrue('deveria ter lançado');
        } catch (ProviderUnavailable $e) {
            expect($e->failure)->toBe(ProviderFailure::QuotaExhausted);
            expect($e->model)->toBe('gemini-2.5-flash');
        }
    });

    /**
     * ⚠️ **A distinção que faz a cadeia funcionar.** A API devolve 429 para os
     * dois casos, e eles voltam em escalas de tempo completamente diferentes.
     */
    it('separa limite por minuto de cota diária', function () {
        expect(ProviderFailure::RateLimitPerMinute)->not->toBe(ProviderFailure::QuotaExhausted);

        expect(config('ai.cooldown_seconds.rate_limit_per_minute'))->toBe(60);
        expect(config('ai.cooldown_seconds.quota_exhausted'))
            ->toBeGreaterThan(config('ai.cooldown_seconds.rate_limit_per_minute'));
    });

    /**
     * ⚠️ `Unauthorized` é o único caso em que descer na cadeia NÃO ajuda: a chave
     * é a mesma para todos os modelos. Sem isso, a cadeia gastaria três
     * tentativas confirmando o óbvio.
     */
    it('chave inválida não justifica tentar o próximo modelo', function () {
        expect(ProviderFailure::Unauthorized->allowsFallbackToNextModel())->toBeFalse();

        foreach ([
            ProviderFailure::RateLimitPerMinute,
            ProviderFailure::QuotaExhausted,
            ProviderFailure::Timeout,
            ProviderFailure::BadResponse,
            ProviderFailure::Unknown,
        ] as $falha) {
            expect($falha->allowsFallbackToNextModel())->toBeTrue();
        }
    });

    /**
     * ⚠️ `BadResponse` NÃO gera cooldown. Penalizar o modelo por resposta
     * malformada esconderia um problema de parsing atrás de um cooldown de horas
     * — e o sintoma seria "a IA parou de funcionar", não "o parser quebrou".
     */
    it('só limite e timeout geram cooldown', function () {
        expect(ProviderFailure::RateLimitPerMinute->deservesCooldown())->toBeTrue();
        expect(ProviderFailure::QuotaExhausted->deservesCooldown())->toBeTrue();
        expect(ProviderFailure::Timeout->deservesCooldown())->toBeTrue();

        expect(ProviderFailure::BadResponse->deservesCooldown())->toBeFalse();
        expect(ProviderFailure::Unknown->deservesCooldown())->toBeFalse();
        expect(ProviderFailure::Unauthorized->deservesCooldown())->toBeFalse();
    });
});

describe('o fake registra o que a cadeia tentou', function () {

    // ⚠️ Registra a chamada ANTES de falhar. Sem isso, um teste de cooldown não
    // distingue "não tentou o modelo" de "tentou e ele falhou".
    it('guarda os modelos tentados na ordem, mesmo falhando', function () {
        $provider = FakeProvider::failing(ProviderFailure::QuotaExhausted);

        foreach (['m1', 'm2', 'm3'] as $modelo) {
            try {
                $provider->generate($modelo, 'p', fakePayload());
            } catch (ProviderUnavailable) {
                // esperado
            }
        }

        expect($provider->modelsTried())->toBe(['m1', 'm2', 'm3']);
        expect($provider->callCount())->toBe(3);
    });

    it('falha programada esgota e depois responde', function () {
        $provider = FakeProvider::failingInOrder(
            [ProviderFailure::RateLimitPerMinute, ProviderFailure::QuotaExhausted],
            'deu certo na terceira',
        );

        expect(fn () => $provider->generate('m1', 'p', fakePayload()))
            ->toThrow(ProviderUnavailable::class);
        expect(fn () => $provider->generate('m2', 'p', fakePayload()))
            ->toThrow(ProviderUnavailable::class);

        expect($provider->generate('m3', 'p', fakePayload())->text)
            ->toBe('deu certo na terceira');
    });
});

describe('a configuração', function () {

    it('a cadeia tem três modelos, ordenada por qualidade', function () {
        expect(config('ai.model_chain'))->toHaveCount(3);
        expect(config('ai.model_chain')[0])->toBeString();
    });

    /**
     * ⚠️ NFR-503 — a chave NUNCA tem default no código. Este repositório é
     * portfólio público.
     */
    it('a chave vem de env, sem default no código', function () {
        $arquivo = file_get_contents(config_path('ai.php'));

        expect($arquivo)->toContain("env('GEMINI_API_KEY')");
        // `env('X', 'default')` seria um default embutido.
        expect($arquivo)->not->toContain("env('GEMINI_API_KEY',");
        expect(str_contains($arquivo, 'AIza'))->toBeFalse();
    });

    it('há cooldown declarado para cada falha que o merece', function () {
        foreach (ProviderFailure::cases() as $falha) {
            if (! $falha->deservesCooldown()) {
                continue;
            }

            expect(config('ai.cooldown_seconds.'.$falha->value))
                ->toBeGreaterThan(0, "falta cooldown para {$falha->value}");
        }
    });

    it('a guarda de número tem tolerância e isenções', function () {
        expect(config('ai.number_guard.rounding_tolerance'))->toBeGreaterThan(0);
        // Sem a lista de isenção a guarda vira ruído, e alguém a desliga.
        expect(config('ai.number_guard.exempt_numbers'))->toContain(24);
        expect(config('ai.number_guard.exempt_numbers'))->toContain(180);
    });
});

/**
 * ⚠️ O dublê vive em `tests/`, não em `app/`. Um fake em `app/` viaja para
 * produção e dá a alguém a opção de "usar o fake por enquanto" num caminho real.
 */
it('o FakeProvider não está em app/', function () {
    expect(is_file(app_path('Domain/Ai/FakeProvider.php')))->toBeFalse();
    expect(is_file(app_path('Infrastructure/Ai/FakeProvider.php')))->toBeFalse();
    expect(is_file(base_path('tests/Support/FakeProvider.php')))->toBeTrue();
});

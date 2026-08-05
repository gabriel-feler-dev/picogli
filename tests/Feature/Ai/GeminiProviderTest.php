<?php

declare(strict_types=1);

use App\Domain\Ai\Provider;
use App\Domain\Ai\ProviderFailure;
use App\Domain\Ai\ProviderUnavailable;
use App\Domain\Ai\Value\AiPayload;
use App\Infrastructure\Ai\GeminiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/**
 * T405.3/T405.4 — o `GeminiProvider`.
 *
 * ⚠️ **`Http::fake()`, nunca a rede** (NFR-501). Um teste que chamasse a API
 * falharia quando a cota acabasse — e gastaria cota para rodar, que é o recurso
 * que a cadeia de modelos existe para economizar.
 */
function gemini(?string $key = 'chave-de-teste'): Provider
{
    return new GeminiProvider(app(HttpFactory::class), $key, 5);
}

function geminiPayload(): AiPayload
{
    return new AiPayload(
        period: ['period_from' => '2026-07-16'],
        findings: [['rule_id' => 'R1_DAYPART_DRIFT', 'severity' => 'info', 'rank' => 4, 'evidence' => ['ratio' => 5.78]]],
    );
}

/** Resposta bem-formada do Gemini. */
function geminiOk(string $text): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
}

it('devolve o texto da resposta', function () {
    Http::fake([
        '*' => Http::response(geminiOk('  Sua tarde concentra o tempo alto.  ')),
    ]);

    $result = gemini()->generate('gemini-2.5-flash', 'prompt', geminiPayload());

    expect($result->text)->toBe('Sua tarde concentra o tempo alto.');
    expect($result->model)->toBe('gemini-2.5-flash');
});

it('manda o prompt no corpo e a chave no cabeçalho', function () {
    Http::fake(['*' => Http::response(geminiOk('texto'))]);

    gemini('minha-chave')->generate('gemini-2.5-flash', 'INSTRUÇÕES AQUI', geminiPayload());

    Http::assertSent(function ($request): bool {
        expect($request->url())->toContain('gemini-2.5-flash:generateContent');
        expect($request->header('x-goog-api-key')[0])->toBe('minha-chave');
        expect($request->data()['contents'][0]['parts'][0]['text'])->toBe('INSTRUÇÕES AQUI');

        return true;
    });
});

/**
 * ⚠️⚠️ **A classificação do 429 é o trabalho mais importante desta classe.**
 *
 * A API devolve o mesmo status para dois casos com escalas de tempo
 * completamente diferentes. Sem distinguir, a cadeia bate no modelo esgotado a
 * cada requisição, o dia inteiro.
 */
describe('a classificação do erro', function () {

    it('429 com menção a dia é cota diária', function (string $body) {
        Http::fake(['*' => Http::response($body, 429)]);

        try {
            gemini()->generate('m', 'p', geminiPayload());
            expect(false)->toBeTrue('deveria ter lançado');
        } catch (ProviderUnavailable $e) {
            expect($e->failure)->toBe(ProviderFailure::QuotaExhausted);
        }
    })->with([
        ['{"error":{"details":[{"quotaId":"GenerateRequestsPerDayPerProject"}]}}'],
        ['{"error":{"message":"Quota exceeded: requests per day"}}'],
        ['{"error":{"message":"daily limit reached"}}'],
    ]);

    /**
     * ⚠️ **Errar para qual lado é decisão de produto.** Na dúvida, limite por
     * MINUTO — cooldown curto. Errar por otimismo custa uma tentativa perdida em
     * um minuto; errar por pessimismo custaria seis horas de narrativa por um
     * erro que já tinha passado.
     */
    it('429 sem menção a dia é limite por minuto', function () {
        Http::fake(['*' => Http::response('{"error":{"message":"Resource has been exhausted"}}', 429)]);

        try {
            gemini()->generate('m', 'p', geminiPayload());
            expect(false)->toBeTrue('deveria ter lançado');
        } catch (ProviderUnavailable $e) {
            expect($e->failure)->toBe(ProviderFailure::RateLimitPerMinute);
        }
    });

    it('401 e 403 são chave inválida', function (int $status) {
        Http::fake(['*' => Http::response('{"error":"unauthorized"}', $status)]);

        try {
            gemini()->generate('m', 'p', geminiPayload());
            expect(false)->toBeTrue('deveria ter lançado');
        } catch (ProviderUnavailable $e) {
            expect($e->failure)->toBe(ProviderFailure::Unauthorized);
            // A cadeia não desce: a chave é a mesma para todos os modelos.
            expect($e->failure->allowsFallbackToNextModel())->toBeFalse();
        }
    })->with([[401], [403]]);

    /**
     * ⚠️ 404 é **nome de modelo inválido** — problema NOSSO, de configuração.
     * Classificar como limite poria o modelo de castigo por seis horas e
     * esconderia o defeito atrás de "a IA parou de funcionar".
     */
    it('404 é resposta ruim, e NÃO gera cooldown', function () {
        Http::fake(['*' => Http::response('{"error":"model not found"}', 404)]);

        try {
            gemini()->generate('gemini-inexistente', 'p', geminiPayload());
            expect(false)->toBeTrue('deveria ter lançado');
        } catch (ProviderUnavailable $e) {
            expect($e->failure)->toBe(ProviderFailure::BadResponse);
            expect($e->failure->deservesCooldown())->toBeFalse();
        }
    });

    it('timeout de conexão é Timeout', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            gemini()->generate('m', 'p', geminiPayload());
            expect(false)->toBeTrue('deveria ter lançado');
        } catch (ProviderUnavailable $e) {
            expect($e->failure)->toBe(ProviderFailure::Timeout);
            expect($e->failure->deservesCooldown())->toBeTrue();
        }
    });

    /**
     * ⚠️⚠️ **`ConnectionException` NÃO é sinônimo de timeout**, e este projeto
     * tropeçou nisso ao vivo em 05/08/2026.
     *
     * Um PHP sem bundle de certificados (`curl.cainfo` vazio) devolvia
     * `cURL error 60`. A classe classificava como `Timeout`, e a cadeia punha os
     * **três** modelos de castigo por cinco minutos. O sintoma virou "a IA está
     * lenta" quando o defeito era uma linha faltando no `php.ini`.
     *
     * Erro de rede que não é timeout vira `Unknown`, que **não gera cooldown** —
     * falha rápido nos três modelos e aparece como o que é.
     */
    it('falha de TLS, DNS ou conexão recusada NÃO vira timeout nem gera cooldown', function (string $mensagem) {
        Http::fake(fn () => throw new ConnectionException($mensagem));

        try {
            gemini()->generate('m', 'p', geminiPayload());
            expect(false)->toBeTrue('deveria ter lançado');
        } catch (ProviderUnavailable $e) {
            expect($e->failure)->toBe(ProviderFailure::Unknown);
            expect($e->failure->deservesCooldown())->toBeFalse();
        }
    })->with([
        ['cURL error 60: SSL certificate problem: unable to get local issuer certificate'],
        ['cURL error 6: Could not resolve host: generativelanguage.googleapis.com'],
        ['cURL error 7: Failed to connect: Connection refused'],
    ]);

    it('500 é Unknown', function () {
        Http::fake(['*' => Http::response('erro interno', 500)]);

        try {
            gemini()->generate('m', 'p', geminiPayload());
            expect(false)->toBeTrue('deveria ter lançado');
        } catch (ProviderUnavailable $e) {
            expect($e->failure)->toBe(ProviderFailure::Unknown);
        }
    });

    /**
     * ⚠️ Resposta 200 bem-formada mas sem texto — bloqueio de segurança do
     * provedor, por exemplo. É `BadResponse` e não gera cooldown: o modelo não
     * está esgotado, e penalizá-lo esconderia o motivo real atrás de horas de
     * silêncio.
     */
    it('200 sem texto é resposta ruim', function (array $body) {
        Http::fake(['*' => Http::response($body)]);

        try {
            gemini()->generate('m', 'p', geminiPayload());
            expect(false)->toBeTrue('deveria ter lançado');
        } catch (ProviderUnavailable $e) {
            expect($e->failure)->toBe(ProviderFailure::BadResponse);
        }
    })->with([
        [['candidates' => []]],
        [['candidates' => [['finishReason' => 'SAFETY']]]],
        [['erro' => 'formato inesperado']],
    ]);
});

/**
 * ⚠️ **A propriedade que a fase inteira protege.** Sem chave, o provider não
 * tenta a rede: classifica como `Unauthorized`, a cadeia devolve `null`, e a tela
 * cai para o fallback. **Nada quebra por falta de chave** (Artigo I).
 */
describe('sem chave configurada', function () {

    it('não chama a rede e classifica como chave inválida', function (?string $key) {
        Http::fake();

        try {
            gemini($key)->generate('m', 'p', geminiPayload());
            expect(false)->toBeTrue('deveria ter lançado');
        } catch (ProviderUnavailable $e) {
            expect($e->failure)->toBe(ProviderFailure::Unauthorized);
            expect($e->getMessage())->toContain('GEMINI_API_KEY ausente');
        }

        Http::assertNothingSent();
    })->with([[null], [''], ['   ']]);
});

it('o container entrega o GeminiProvider', function () {
    expect(app(Provider::class))->toBeInstanceOf(GeminiProvider::class);
});

/**
 * FR-501 — a marca de endpoint existe em UM arquivo só.
 */
it('só o GeminiProvider conhece o endpoint', function () {
    $arquivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    $encontrados = [];

    foreach ($arquivos as $arquivo) {
        if (! $arquivo->isFile() || $arquivo->getExtension() !== 'php') {
            continue;
        }

        if (str_contains(file_get_contents($arquivo->getPathname()), 'generativelanguage')) {
            $encontrados[] = $arquivo->getFilename();
        }
    }

    expect($encontrados)->toBe(['GeminiProvider.php']);
});

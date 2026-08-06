<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\ChatProvider;
use App\Domain\Ai\Chat\ToolRegistry;
use App\Domain\Ai\Provider;
use App\Domain\Ai\ProviderFailure;
use App\Domain\Ai\ProviderUnavailable;
use App\Domain\Ai\Value\AiPayload;
use App\Infrastructure\Ai\GeminiProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeChatProvider;

/**
 * T509 — o provedor com tool calling (FR-605, NFR-601).
 *
 * ⚠️ **Nenhum teste toca a rede.** `Http::fake()` responde por ela; um teste que
 * dependesse da API falharia quando a cota acabasse, e gastaria cota para rodar.
 */
function chatGemini(?string $key = 'chave-de-teste'): GeminiProvider
{
    return new GeminiProvider(app(HttpFactory::class), $key, 45);
}

function chatTools(): array
{
    return app(ToolRegistry::class)->descriptors();
}

/** A forma real da resposta do provedor quando ele pede uma ferramenta. */
function chatRespostaComFerramenta(string $nome, array $args): array
{
    return ['candidates' => [['content' => ['parts' => [
        ['functionCall' => ['name' => $nome, 'args' => $args]],
    ]]]]];
}

function chatRespostaComTexto(string $texto): array
{
    return [
        'candidates' => [['content' => ['parts' => [['text' => $texto]]]]],
        'usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 180],
    ];
}

/*
|--------------------------------------------------------------------------
| T509.1 e T509.2 — uma classe, duas interfaces
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ **O Artigo VII depende disto.** Um `ChatProvider` em arquivo separado
 * significaria um segundo lugar conhecendo o endpoint — e "quem fala com a
 * rede?" passaria a ter duas respostas.
 */
it('o GeminiProvider implementa as duas interfaces, e continua sendo um arquivo só', function () {
    expect(chatGemini())->toBeInstanceOf(ChatProvider::class);
    expect(chatGemini())->toBeInstanceOf(Provider::class);

    $comEndpoint = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $arquivo) {
        if (! $arquivo->isFile() || $arquivo->getExtension() !== 'php') {
            continue;
        }

        $codigo = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($arquivo->getPathname()));

        if (str_contains($codigo, 'generativelanguage')) {
            $comEndpoint[] = $arquivo->getFilename();
        }
    }

    expect($comEndpoint)->toBe(['GeminiProvider.php']);
});

it('traduz os descritores para o formato de function declaration', function () {
    Http::fake(['*' => Http::response(chatRespostaComTexto('ok'))]);

    chatGemini()->chat('gemini-3.6-flash', 'prompt de sistema', chatTools(), [
        ['role' => 'user', 'content' => 'qual minha média?'],
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();
        $declaracoes = $body['tools'][0]['function_declarations'];

        expect($declaracoes)->toHaveCount(10);

        $episodios = collect($declaracoes)->firstWhere('name', 'get_episodes');

        // ⚠️ `properties` é objeto, não array, de propósito: um schema sem
        // argumentos serializaria como `[]` em JSON, e o provedor recusa —
        // ele espera um objeto vazio, `{}`.
        $propriedades = (array) $episodios['parameters']['properties'];

        // ⚠️ O enum viaja: sem ele o modelo inventaria um terceiro tipo e
        // receberia erro de validação, gastando uma volta do laço.
        expect($propriedades['type']['enum'])->toBe(['hypo', 'hyper_l2']);

        // ⚠️ Data vira string COM descrição de formato — o provedor não tem tipo
        // de data, e sem a dica o modelo manda `16/07/2026`.
        expect($propriedades['start']['type'])->toBe('string');
        expect($propriedades['start']['description'])->toContain('YYYY-MM-DD');

        expect($episodios['parameters']['required'])->toContain('start', 'end', 'type');

        return true;
    });
});

it('manda o prompt de sistema separado da conversa', function () {
    Http::fake(['*' => Http::response(chatRespostaComTexto('ok'))]);

    chatGemini()->chat('gemini-3.6-flash', 'REGRAS DO SISTEMA', chatTools(), [
        ['role' => 'user', 'content' => 'oi'],
        ['role' => 'assistant', 'content' => 'olá'],
    ]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        expect($body['system_instruction']['parts'][0]['text'])->toBe('REGRAS DO SISTEMA');

        // ⚠️ O provedor chama o assistente de `model`; a conversa é gravada com
        // o vocabulário do §5.3. A tradução mora na borda.
        expect($body['contents'][0]['role'])->toBe('user');
        expect($body['contents'][1]['role'])->toBe('model');

        return true;
    });
});

/*
|--------------------------------------------------------------------------
| A leitura da resposta
|--------------------------------------------------------------------------
*/

it('lê um pedido de ferramenta com os argumentos', function () {
    Http::fake(['*' => Http::response(chatRespostaComFerramenta('get_daily_series', [
        'start' => '2026-07-16', 'end' => '2026-07-29',
    ]))]);

    $resposta = chatGemini()->chat('gemini-3.6-flash', 'p', chatTools(), []);

    expect($resposta->wantsTools())->toBeTrue();
    expect($resposta->toolCalls[0]->name)->toBe('get_daily_series');
    expect($resposta->toolCalls[0]->arguments['start'])->toBe('2026-07-16');
    expect($resposta->hasText())->toBeFalse();
});

it('lê texto e contagem de tokens', function () {
    Http::fake(['*' => Http::response(chatRespostaComTexto('Sua média foi 142 mg/dL.'))]);

    $resposta = chatGemini()->chat('gemini-3.6-flash', 'p', chatTools(), []);

    expect($resposta->wantsTools())->toBeFalse();
    expect($resposta->text)->toBe('Sua média foi 142 mg/dL.');
    expect($resposta->inputTokens)->toBe(1200);
    expect($resposta->outputTokens)->toBe(180);
});

/**
 * ⚠️ **Pedido de ferramenta tem precedência sobre texto do mesmo passo.**
 *
 * O texto teria sido escrito SEM o resultado da consulta que o próprio modelo
 * acabou de pedir — publicá-lo seria publicar um palpite.
 */
it('descarta o texto quando o mesmo passo pede ferramenta', function () {
    Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [
        ['text' => 'Acho que sua média foi 150.'],
        ['functionCall' => ['name' => 'get_period_metrics', 'args' => []]],
    ]]]]])]);

    $resposta = chatGemini()->chat('gemini-3.6-flash', 'p', chatTools(), []);

    expect($resposta->wantsTools())->toBeTrue();
    expect($resposta->text)->toBeNull();
});

/**
 * ⚠️ Resposta vazia é `BadResponse`, **não** um `ChatResponse` vazio. O
 * orquestrador encerraria o turno com silêncio, e o usuário veria a tela travada
 * sem nada no log.
 */
it('resposta sem texto e sem ferramenta é falha classificada', function () {
    Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => []]]]])]);

    expect(fn () => chatGemini()->chat('gemini-3.6-flash', 'p', chatTools(), []))
        ->toThrow(ProviderUnavailable::class);
});

/*
|--------------------------------------------------------------------------
| T509.3 — a classificação de falha, reusada da fase 5
|--------------------------------------------------------------------------
*/

it('reusa a classificação de falha da fase 5', function (int $status, string $body, ProviderFailure $esperado) {
    Http::fake(['*' => Http::response($body, $status)]);

    try {
        chatGemini()->chat('gemini-3.6-flash', 'p', chatTools(), []);
        expect(false)->toBeTrue('deveria ter lançado');
    } catch (ProviderUnavailable $e) {
        expect($e->failure)->toBe($esperado);
    }
})->with([
    'limite por minuto' => [429, '{"error":"rate"}', ProviderFailure::RateLimitPerMinute],
    'cota diária' => [429, '{"error":"PerDay quota"}', ProviderFailure::QuotaExhausted],
    'chave inválida' => [403, '{}', ProviderFailure::Unauthorized],
    // ⚠️ 404 é nome de modelo errado — defeito NOSSO. Cooldown esconderia isso
    // atrás de "a IA parou de funcionar".
    'modelo inexistente' => [404, '{}', ProviderFailure::BadResponse],
]);

it('sem chave, classifica Unauthorized sem tocar a rede', function () {
    Http::fake();

    try {
        chatGemini(null)->chat('gemini-3.6-flash', 'p', chatTools(), []);
        expect(false)->toBeTrue('deveria ter lançado');
    } catch (ProviderUnavailable $e) {
        expect($e->failure)->toBe(ProviderFailure::Unauthorized);
    }

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| T509.4 — o fake que encena o laço
|--------------------------------------------------------------------------
*/

it('o FakeChatProvider encena consulta e depois resposta', function () {
    $fake = FakeChatProvider::script([
        FakeChatProvider::wantsTools(['get_period_metrics' => ['start' => '2026-07-16', 'end' => '2026-07-29']]),
        FakeChatProvider::answers('Sua média foi 142 mg/dL.'),
    ]);

    $primeiro = $fake->chat('m', 'p', [], []);
    expect($primeiro->wantsTools())->toBeTrue();
    expect($primeiro->toolCalls[0]->name)->toBe('get_period_metrics');

    $segundo = $fake->chat('m', 'p', [], []);
    expect($segundo->wantsTools())->toBeFalse();
    expect($segundo->text)->toContain('142');

    expect($fake->callCount())->toBe(2);
});

it('o FakeChatProvider sabe encenar o modelo em laço', function () {
    $fake = FakeChatProvider::alwaysWantsTools('get_period_metrics');

    for ($i = 0; $i < 8; $i++) {
        expect($fake->chat('m', 'p', [], [])->wantsTools())->toBeTrue();
    }
});

it('o FakeChatProvider sabe falhar como o real', function () {
    $fake = FakeChatProvider::failing(ProviderFailure::QuotaExhausted);

    expect(fn () => $fake->chat('m', 'p', [], []))->toThrow(ProviderUnavailable::class);
    // ⚠️ Registra ANTES de falhar: sem isso um teste de cadeia não distingue
    // "não tentou" de "tentou e falhou".
    expect($fake->callCount())->toBe(1);
});

it('os dublês vivem em tests/, nunca em app/', function () {
    expect(class_exists('App\\Infrastructure\\Ai\\FakeChatProvider'))->toBeFalse();
    expect(file_exists(base_path('tests/Support/FakeChatProvider.php')))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| A narrativa da fase 5 não mudou
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ O `generate()` foi refatorado para reusar o `post()` novo. Este teste é a
 * rede: a fase 5 não pode ter mudado de comportamento por causa da fase 6.
 */
it('generate continua funcionando exatamente como na fase 5', function () {
    Http::fake(['*' => Http::response(chatRespostaComTexto('Narrativa.'))]);

    $resultado = chatGemini()->generate('gemini-3.6-flash', 'prompt', new AiPayload([], []));

    expect($resultado->text)->toBe('Narrativa.');
    expect($resultado->model)->toBe('gemini-3.6-flash');

    Http::assertSent(function ($request) {
        // Sem `tools` e sem `system_instruction`: a narrativa não conhece
        // ferramentas, e é o motivo de as interfaces serem separadas.
        expect($request->data())->not->toHaveKey('tools');
        expect($request->data())->not->toHaveKey('system_instruction');

        return true;
    });
});

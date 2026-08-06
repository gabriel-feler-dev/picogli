<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\ChatOrchestrator;
use App\Domain\Ai\Chat\ChatProvider;
use App\Domain\Ai\Chat\Value\TurnOutcome;
use App\Domain\Ai\CooldownStore;
use App\Domain\Ai\NumberGuard;
use App\Infrastructure\Ai\GeminiProvider;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeChatProvider;

/**
 * ⚠️⚠️⚠️ **T513 — os testes de artigo da fase 6 (FR-607, FR-611).**
 *
 * ⚠️ **É o último da fase de propósito.** Valida as propriedades que as seis
 * fases anteriores existem para preservar, e só faz sentido com tudo montado —
 * como o `ArticleOneTest` foi para a fase 5.
 *
 * Dois artigos, e a fase 6 os cobra de formas diferentes:
 *
 * | Artigo | O que o teste exige |
 * |---|---|
 * | **III** | todo número da resposta rastreia a um `tool_result` GRAVADO |
 * | **I** | sem chave, o chat avisa — e **nenhuma outra tela muda** |
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    importAndAnalyse($this->user->id);
});

const ARTICLE_PERIODO = ['start' => '2026-07-16', 'end' => '2026-07-29'];

function comChatFake(FakeChatProvider $fake): void
{
    app()->instance(ChatProvider::class, $fake);
    app()->forgetInstance(ChatOrchestrator::class);
}

function conversaNova(): ChatConversation
{
    return ChatConversation::create(['user_id' => test()->user->id]);
}

/*
|--------------------------------------------------------------------------
| T513.1 e T513.2 — ⚠️ Artigo III
|--------------------------------------------------------------------------
*/

/**
 * ⚠️⚠️ **O TESTE CENTRAL DA FASE.**
 *
 * Não é "a resposta parece certa": é que **cada número dela** foi confrontado com
 * o que está gravado em `chat_messages.tool_results` — a mesma estrutura que o
 * rodapé "dados consultados" mostra ao usuário.
 *
 * A verificação é feita contra o BANCO, não contra o objeto em memória. É a
 * diferença entre "o orquestrador checou" e "o que a pessoa vê tem procedência".
 */
it('todo número da resposta publicada rastreia a um tool_result GRAVADO', function () {
    comChatFake(FakeChatProvider::script([
        FakeChatProvider::wantsTools([
            'get_period_metrics' => ARTICLE_PERIODO,
            'get_daily_series' => ARTICLE_PERIODO,
        ]),
        FakeChatProvider::answers(
            'No período de 2026-07-16 a 2026-07-29 sua média foi 142 mg/dL, com 83,9% '
            .'do tempo na faixa. O dia 25 destoou, com média de 159.'
        ),
    ]));

    $conversa = conversaNova();
    $this->post(route('chat.message', $conversa), ['message' => 'como foi meu período?']);

    $resposta = ChatMessage::where('chat_conversation_id', $conversa->id)
        ->where('role', 'assistant')
        ->firstOrFail();

    expect($resposta->outcome)->toBe(TurnOutcome::Published);

    // ⚠️ A guarda roda de novo, aqui, sobre o que está NO BANCO.
    $orfaos = app(NumberGuard::class)->orphansIn(
        (string) $resposta->content,
        $resposta->tool_results ?? [],
    );

    expect($orfaos)->toBe([], 'a resposta publicada cita número sem procedência gravada');
});

it('resposta que cita número não consultado não chega à tela', function () {
    comChatFake(FakeChatProvider::script([
        FakeChatProvider::wantsTools(['get_period_metrics' => ARTICLE_PERIODO]),
        FakeChatProvider::answers('Sua média foi 142 mg/dL e você teve 37 hipoglicemias.'),
    ]));

    $conversa = conversaNova();
    $this->post(route('chat.message', $conversa), ['message' => 'como foi meu período?']);

    $props = $this->get(route('chat.show', $conversa))->viewData('page')['props'];
    $resposta = collect($props['messages'])->firstWhere('role', 'assistant');

    // ⚠️ O texto do modelo NÃO chegou à tela — nem parcialmente. Uma prosa com um
    // número inventado e nove corretos é pior que nenhuma: o usuário não tem como
    // saber qual é qual.
    expect($resposta['content'])->not->toContain('37');
    expect($resposta['content'])->not->toContain('142');
    expect($resposta['content'])->toContain('Não consegui responder agora');
});

/**
 * ⚠️ **A procedência que a tela mostra é a que foi gravada, não uma remontagem.**
 */
it('o rodapé de procedência vem do banco, e bate com a resposta', function () {
    comChatFake(FakeChatProvider::script([
        FakeChatProvider::wantsTools(['get_period_metrics' => ARTICLE_PERIODO]),
        FakeChatProvider::answers('Sua média foi 142 mg/dL.'),
    ]));

    $conversa = conversaNova();
    $this->post(route('chat.message', $conversa), ['message' => 'qual minha média?']);

    $gravado = ChatMessage::where('role', 'assistant')->firstOrFail()->tool_results;
    $naTela = collect($this->get(route('chat.show', $conversa))->viewData('page')['props']['messages'])
        ->firstWhere('role', 'assistant')['consulted'];

    expect($naTela)->toBe($gravado);
});

/*
|--------------------------------------------------------------------------
| T513.6 — ⚠️ a camada 4 não depende da rede
|--------------------------------------------------------------------------
*/

it('emergência responde sem tocar a rede, e fica gravada como tal', function () {
    Http::fake();
    comChatFake(FakeChatProvider::replying('nunca deveria ser chamado'));

    $conversa = conversaNova();
    $this->post(route('chat.message', $conversa), ['message' => 'socorro, estou com 38 agora']);

    $resposta = ChatMessage::where('role', 'assistant')->firstOrFail();

    expect($resposta->outcome)->toBe(TurnOutcome::Emergency);
    expect($resposta->reachedProvider())->toBeFalse();
    expect($resposta->content)->toContain('192');

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| T513.3 e T513.4 — ⚠️⚠️ Artigo I
|--------------------------------------------------------------------------
*/

/**
 * ⚠️⚠️ **A fase 6 é a primeira que não sobrevive à chave desligada, e a spec
 * assume isso** (FR-611).
 *
 * O Artigo I continua verdadeiro **na letra** — *"continua exibindo todos os
 * gráficos e todas as métricas"* — porque chat não é gráfico nem métrica. O que
 * este teste cobra é que **nenhuma outra tela mude**.
 */
it('sem chave: o chat avisa, e nenhuma outra tela muda', function () {
    // 1. Primeiro, tudo funcionando — o retrato de referência.
    comChatFake(FakeChatProvider::replying('Não consultei esse dado ainda.'));

    $dashboardComIa = $this->get('/dashboard')->viewData('page')['props'];
    $avaliacaoComIa = $this->get('/avaliacao')->viewData('page')['props'];

    // 2. Agora com o provider REAL e chave nula — o cenário do artigo.
    app()->instance(ChatProvider::class, new GeminiProvider(app(HttpFactory::class), null, 45));
    app()->forgetInstance(ChatOrchestrator::class);
    Http::fake();

    $conversa = conversaNova();
    $this->post(route('chat.message', $conversa), ['message' => 'qual minha média?']);

    // O chat avisa, com uma frase honesta.
    $resposta = ChatMessage::where('role', 'assistant')->firstOrFail();
    expect($resposta->outcome)->toBe(TurnOutcome::Unavailable);
    expect($resposta->content)->toContain('Não consegui responder agora');

    // ⚠️⚠️ E TUDO O MAIS É IDÊNTICO.
    $dashboardSemIa = $this->get('/dashboard')->viewData('page')['props'];
    $avaliacaoSemIa = $this->get('/avaliacao')->viewData('page')['props'];

    expect($dashboardSemIa['summary'])->toBe($dashboardComIa['summary']);
    expect($avaliacaoSemIa['findings'])->toBe($avaliacaoComIa['findings']);
    expect($avaliacaoSemIa['coverage'])->toBe($avaliacaoComIa['coverage']);
    expect($avaliacaoSemIa['period'])->toBe($avaliacaoComIa['period']);

    // E a rede nunca foi tocada.
    Http::assertNothingSent();
});

it('as outras telas seguem respondendo sem chave', function () {
    app()->instance(ChatProvider::class, new GeminiProvider(app(HttpFactory::class), null, 45));
    Http::fake();

    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('summary.metrics', 4)
        ->where('isEmpty', false)
    );

    $this->get('/avaliacao')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Evaluation')
        ->has('findings', 10)
    );

    $this->get('/importar')->assertOk();
    $this->get('/conversar')->assertOk();

    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| T513.5 — ⚠️ NFR-502: degradação sem vazar detalhe técnico
|--------------------------------------------------------------------------
*/

it('a tela de chat não expõe cota, chave, modelo nem exceção', function () {
    app()->instance(ChatProvider::class, new GeminiProvider(app(HttpFactory::class), null, 45));
    app()->forgetInstance(ChatOrchestrator::class);
    Http::fake();

    $conversa = conversaNova();
    $this->post(route('chat.message', $conversa), ['message' => 'qual minha média?']);

    $html = mb_strtolower(json_encode(
        $this->get(route('chat.show', $conversa))->viewData('page')['props'],
        JSON_THROW_ON_ERROR
    ));

    // ⚠️ Dizer "não consegui" não é o mesmo que expor o erro. A distinção entre
    // os desfechos vive na coluna `outcome`, que é para nós — não para a tela.
    foreach ([
        'gemini_api_key', 'unauthorized', 'quota', 'api key', 'cooldown',
        'rate limit', 'exception', 'gemini',
    ] as $vazamento) {
        expect(str_contains($html, $vazamento))->toBeFalse("a tela expõe '{$vazamento}'");
    }
});

/**
 * ⚠️ Chave ausente não põe modelo de castigo — a cadeia inteira está fora, não
 * um modelo. Sem isso, desenvolver sem chave deixaria os três de castigo por
 * horas, e a primeira execução COM chave falharia sem motivo aparente.
 */
it('sem chave, nenhum modelo entra em cooldown', function () {
    app()->instance(ChatProvider::class, new GeminiProvider(app(HttpFactory::class), null, 45));
    app()->forgetInstance(ChatOrchestrator::class);

    $conversa = conversaNova();
    $this->post(route('chat.message', $conversa), ['message' => 'qual minha média?']);

    $store = app(CooldownStore::class);

    foreach (config('ai.model_chain') as $modelo) {
        expect($store->isCoolingDown($modelo))->toBeFalse("{$modelo} entrou em cooldown por falta de chave");
    }
});

/*
|--------------------------------------------------------------------------
| ⚠️ Artigo II — padrão quem detecta é regra
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ Sem `get_findings`, "que padrões você vê nos meus dados?" empurraria o
 * modelo a procurar padrão na série — e um modelo instruído a achar padrão acha
 * padrão inexistente com a mesma fluência com que acha o real.
 */
it('a ferramenta de achados existe, e devolve o que as dez regras detectaram', function () {
    comChatFake(FakeChatProvider::script([
        FakeChatProvider::wantsTools(['get_findings' => ARTICLE_PERIODO]),
        FakeChatProvider::answers('O motor apontou padrões no seu período.'),
    ]));

    $conversa = conversaNova();
    $this->post(route('chat.message', $conversa), ['message' => 'que padrões você vê nos meus dados?']);

    $resultados = ChatMessage::where('role', 'assistant')->firstOrFail()->tool_results;

    expect($resultados[0]['name'])->toBe('get_findings');
    expect($resultados[0]['result']['finding_count'])->toBe(10);
});

<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\ChatOrchestrator;
use App\Domain\Ai\Chat\ChatProvider;
use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\TurnOutcome;
use App\Domain\Ai\ProviderFailure;
use App\Infrastructure\Ai\GeminiProvider;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeChatProvider;

/**
 * T510 e T511 — o laço, o teto, a persistência e o transporte
 * (FR-605, FR-609, §D5, §D9, §D10).
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    importAndAnalyse($this->user->id);

    $this->scope = new ChatScope($this->user->id, 400);
});

function comProvedor(FakeChatProvider $fake): ChatOrchestrator
{
    app()->instance(ChatProvider::class, $fake);
    app()->forgetInstance(ChatOrchestrator::class);

    return app(ChatOrchestrator::class);
}

const CHAT_PERIODO = ['start' => '2026-07-16', 'end' => '2026-07-29'];

/*
|--------------------------------------------------------------------------
| ⚠️ A camada 4 vem antes de tudo
|--------------------------------------------------------------------------
*/

/**
 * ⚠️⚠️ **O teste mais importante da tarefa.** Segurança não depende do modelo, e
 * aqui ela não depende nem de a rede estar de pé.
 */
it('mensagem de emergência não toca a rede', function () {
    Http::fake();
    $fake = FakeChatProvider::replying('nunca deveria ser chamado');

    $turno = comProvedor($fake)->handle('estou com 40 agora', $this->scope);

    expect($turno->outcome)->toBe(TurnOutcome::Emergency);
    expect($turno->content)->toContain('192');
    expect($turno->reachedProvider())->toBeFalse();

    // O provedor nunca foi chamado, e a rede nunca foi tocada.
    expect($fake->callCount())->toBe(0);
    Http::assertNothingSent();
});

it('pergunta legítima sobre hipoglicemia passa pelo classificador', function () {
    $fake = FakeChatProvider::script([
        FakeChatProvider::wantsTools(['get_episodes' => array_merge(CHAT_PERIODO, ['type' => 'hypo'])]),
        FakeChatProvider::answers('Você teve episódios de hipoglicemia no período.'),
    ]);

    $turno = comProvedor($fake)->handle('qual foi minha pior hipoglicemia?', $this->scope);

    expect($turno->outcome)->toBe(TurnOutcome::Published);
    expect($fake->callCount())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| O laço
|--------------------------------------------------------------------------
*/

it('consulta a ferramenta que o modelo pediu e responde com o resultado', function () {
    $fake = FakeChatProvider::script([
        FakeChatProvider::wantsTools(['get_period_metrics' => CHAT_PERIODO]),
        FakeChatProvider::answers('Sua média no período foi 142 mg/dL, com 83,9% do tempo na faixa.'),
    ]);

    $turno = comProvedor($fake)->handle('como foi meu período?', $this->scope);

    expect($turno->outcome)->toBe(TurnOutcome::Published);
    expect($turno->iterations)->toBe(2);

    // ⚠️ A procedência foi gravada: é dela que o rodapé "dados consultados" sai.
    expect($turno->toolCalls[0]['name'])->toBe('get_period_metrics');
    expect($turno->toolResults[0]['result']['mean_glucose'])->toBeCloseToValue(142.0, 0.5);
});

it('encadeia várias ferramentas antes de responder', function () {
    $fake = FakeChatProvider::script([
        FakeChatProvider::wantsTools(['get_daily_series' => CHAT_PERIODO]),
        FakeChatProvider::wantsTools(['get_episodes' => array_merge(CHAT_PERIODO, ['type' => 'hypo'])]),
        FakeChatProvider::answers('O dia 25 teve média de 159 mg/dL.'),
    ]);

    $turno = comProvedor($fake)->handle('por que o dia 25 foi diferente?', $this->scope);

    expect($turno->outcome)->toBe(TurnOutcome::Published);
    expect($turno->toolResults)->toHaveCount(2);
    expect($turno->iterations)->toBe(3);
});

/**
 * ⚠️ **§D5 — o teto.** É a primeira vez que o MODELO controla o fluxo. Sem teto,
 * um modelo confuso queima as 1.500 requisições do dia, e o sintoma aparece
 * horas depois sem relação com a pergunta que causou.
 */
it('o laço tem teto, e estourar não vira exceção', function () {
    $fake = FakeChatProvider::alwaysWantsTools('get_period_metrics', CHAT_PERIODO);

    $turno = comProvedor($fake)->handle('me conta tudo', $this->scope);

    expect($turno->outcome)->toBe(TurnOutcome::Unavailable);
    expect($turno->iterations)->toBe(config('chat.max_tool_iterations'));
    expect($fake->callCount())->toBe(config('chat.max_tool_iterations'));
});

it('erro de ferramenta volta ao modelo, e o turno continua', function () {
    $fake = FakeChatProvider::script([
        // Período invertido — o validador recusa antes da query.
        FakeChatProvider::wantsTools(['get_period_metrics' => ['start' => '2026-07-29', 'end' => '2026-07-16']]),
        // ⚠️ E o modelo corrige sozinho na volta seguinte. É o motivo de erro de
        // ferramenta ser `ToolResult`, e não exceção.
        FakeChatProvider::wantsTools(['get_period_metrics' => CHAT_PERIODO]),
        FakeChatProvider::answers('Sua média foi 142 mg/dL.'),
    ]);

    $turno = comProvedor($fake)->handle('qual minha média?', $this->scope);

    expect($turno->outcome)->toBe(TurnOutcome::Published);
    expect($turno->toolResults[0]['error'])->toContain('posterior');
    expect($turno->toolResults[1]['result']['mean_glucose'])->toBeCloseToValue(142.0, 0.5);
});

/*
|--------------------------------------------------------------------------
| ⚠️ A guarda de número (§D3, FR-607)
|--------------------------------------------------------------------------
*/

/**
 * ⚠️⚠️ **O Artigo III por construção.** O modelo consultou uma ferramenta e
 * respondeu com um número que ela não devolveu.
 */
it('recusa a resposta que cita número sem procedência', function () {
    $fake = FakeChatProvider::script([
        FakeChatProvider::wantsTools(['get_period_metrics' => CHAT_PERIODO]),
        FakeChatProvider::answers('Sua média foi 142 mg/dL e você teve 37 episódios de hipoglicemia.'),
    ]);

    $turno = comProvedor($fake)->handle('como foi meu período?', $this->scope);

    expect($turno->outcome)->toBe(TurnOutcome::Refused);
    expect($turno->orphanNumbers)->toContain('37');

    // ⚠️ A resposta NÃO é publicada — nem parcialmente. Uma prosa com um número
    // inventado e nove corretos é pior que nenhuma: não há como saber qual é qual.
    expect($turno->content)->toBe('');

    // E a procedência do que foi consultado fica gravada, para investigar.
    expect($turno->toolResults)->toHaveCount(1);
});

it('resposta sem consultar nada é recusada', function () {
    $fake = FakeChatProvider::replying('Sua média foi 154 mg/dL.');

    $turno = comProvedor($fake)->handle('qual minha média?', $this->scope);

    // ⚠️ O modelo respondeu "de cabeça". Sem ferramenta, sem procedência.
    expect($turno->outcome)->toBe(TurnOutcome::Refused);
    expect($turno->orphanNumbers)->toContain('154');
});

it('resposta sem número nenhum é publicada', function () {
    $fake = FakeChatProvider::replying('Não consultei esse dado ainda — quer que eu veja o período todo?');

    expect(comProvedor($fake)->handle('e aí?', $this->scope)->outcome)
        ->toBe(TurnOutcome::Published);
});

/*
|--------------------------------------------------------------------------
| ⚠️ Nada lança para cima (Artigo I)
|--------------------------------------------------------------------------
*/

it('cadeia esgotada devolve desfecho, nunca exceção', function () {
    $fake = FakeChatProvider::failing(ProviderFailure::QuotaExhausted);

    $turno = comProvedor($fake)->handle('qual minha média?', $this->scope);

    expect($turno->outcome)->toBe(TurnOutcome::Unavailable);
    expect($turno->content)->toBe('');
});

it('sem chave, o chat avisa e as outras telas não mudam', function () {
    Http::fake();
    app()->forgetInstance(ChatOrchestrator::class);

    // O provider real, com chave nula — o cenário do Artigo I.
    app()->instance(ChatProvider::class, new GeminiProvider(
        app(Factory::class), null, 45
    ));

    $turno = app(ChatOrchestrator::class)->handle('qual minha média?', $this->scope);

    expect($turno->outcome)->toBe(TurnOutcome::Unavailable);
    Http::assertNothingSent();

    // ⚠️ FR-611: o chat é a primeira funcionalidade que não sobrevive sem IA —
    // e nenhuma outra tela pode mudar por causa disso.
    $this->get('/dashboard')->assertOk();
    $this->get('/avaliacao')->assertOk();
});

/*
|--------------------------------------------------------------------------
| T511 — o transporte e a persistência
|--------------------------------------------------------------------------
*/

it('um turno completo pela rota grava pergunta e resposta', function () {
    comProvedor(FakeChatProvider::script([
        FakeChatProvider::wantsTools(['get_period_metrics' => CHAT_PERIODO]),
        FakeChatProvider::answers('Sua média foi 142 mg/dL.'),
    ]));

    $conversa = ChatConversation::create(['user_id' => $this->user->id]);

    $this->post(route('chat.message', $conversa), ['message' => 'qual minha média?'])
        ->assertRedirect(route('chat.show', $conversa));

    $mensagens = $conversa->messages()->get();

    expect($mensagens)->toHaveCount(2);
    expect($mensagens[0]->role->value)->toBe('user');
    expect($mensagens[1]->outcome)->toBe(TurnOutcome::Published);
    expect($mensagens[1]->hasProvenance())->toBeTrue();

    // ⚠️ O título vem da primeira pergunta, sem gastar uma chamada de modelo.
    expect($conversa->fresh()->title)->toBe('qual minha média?');
});

it('a pergunta é gravada mesmo quando o provedor cai', function () {
    comProvedor(FakeChatProvider::failing(ProviderFailure::QuotaExhausted));

    $conversa = ChatConversation::create(['user_id' => $this->user->id]);

    $this->post(route('chat.message', $conversa), ['message' => 'qual minha média?']);

    // ⚠️ Perder o que a pessoa escreveu é pior que não responder.
    expect($conversa->messages()->where('role', 'user')->count())->toBe(1);

    $resposta = $conversa->messages()->where('role', 'assistant')->first();
    expect($resposta->outcome)->toBe(TurnOutcome::Unavailable);
    // §D9 — no chat, silêncio é tela travada.
    expect($resposta->content)->toContain('Não consegui responder agora');
});

/**
 * ⚠️ NFR-502 continua valendo: dizer "não consegui" não é expor o erro.
 */
it('a mensagem de indisponibilidade não expõe cota, chave nem modelo', function () {
    comProvedor(FakeChatProvider::failing(ProviderFailure::QuotaExhausted));

    $conversa = ChatConversation::create(['user_id' => $this->user->id]);
    $this->post(route('chat.message', $conversa), ['message' => 'qual minha média?']);

    $texto = mb_strtolower((string) $conversa->messages()->where('role', 'assistant')->first()->content);

    foreach (['quota', 'cota', 'api', 'chave', 'gemini', 'modelo', 'cooldown'] as $vazamento) {
        expect(str_contains($texto, $vazamento))->toBeFalse("a tela expõe '{$vazamento}'");
    }
});

it('a conversa de um usuário não é acessível por outro', function () {
    $outro = User::factory()->create();
    $conversa = ChatConversation::create(['user_id' => $outro->id]);

    $this->get(route('chat.show', $conversa))->assertNotFound();
    $this->post(route('chat.message', $conversa), ['message' => 'oi'])->assertNotFound();
});

it('a tela lista as conversas e as sugestões', function () {
    ChatConversation::create(['user_id' => $this->user->id, 'title' => 'primeira']);

    $props = $this->get('/conversar')->viewData('page')['props'];

    expect($props['conversations'])->toHaveCount(1);
    expect($props['suggestions'])->toHaveCount(4);
    expect($props['has_data'])->toBeTrue();
});

/**
 * ⚠️ **FR-608 — a procedência é lida do que foi GRAVADO, não remontada.**
 */
it('a tela devolve os dados consultados de cada resposta', function () {
    comProvedor(FakeChatProvider::script([
        FakeChatProvider::wantsTools(['get_period_metrics' => CHAT_PERIODO]),
        FakeChatProvider::answers('Sua média foi 142 mg/dL.'),
    ]));

    $conversa = ChatConversation::create(['user_id' => $this->user->id]);
    $this->post(route('chat.message', $conversa), ['message' => 'qual minha média?']);

    $props = $this->get(route('chat.show', $conversa))->viewData('page')['props'];
    $resposta = collect($props['messages'])->firstWhere('role', 'assistant');

    expect($resposta['consulted'][0]['name'])->toBe('get_period_metrics');
    expect($resposta['consulted'][0]['result']['mean_glucose'])->toBeCloseToValue(142.0, 0.5);
});

/**
 * ⚠️ **§D11 — a conversa renderiza sem nenhum evento de stream.**
 *
 * ADR-5b: hospedagem compartilhada tem timeout curto e buffer que não se
 * controla do código. Um chat que DEPENDE de SSE é um chat que pode não
 * funcionar no destino — e a descoberta seria no deploy.
 */
it('a conversa renderiza inteira sem streaming', function () {
    comProvedor(FakeChatProvider::replying('Não consultei esse dado ainda.'));

    $conversa = ChatConversation::create(['user_id' => $this->user->id]);
    $this->post(route('chat.message', $conversa), ['message' => 'oi']);

    $props = $this->get(route('chat.show', $conversa))->viewData('page')['props'];

    expect($props['messages'])->toHaveCount(2);
    expect($props['messages'][1]['content'])->not->toBe('');
});

it('mensagem vazia é recusada antes de qualquer chamada', function () {
    $fake = FakeChatProvider::replying('nunca');
    comProvedor($fake);

    $conversa = ChatConversation::create(['user_id' => $this->user->id]);

    $this->post(route('chat.message', $conversa), ['message' => '  '])
        ->assertSessionHasErrors('message');

    expect($fake->callCount())->toBe(0);
    expect(ChatMessage::count())->toBe(0);
});

it('o rate limit protege o produto, não a cota', function () {
    comProvedor(FakeChatProvider::replying('Não consultei esse dado ainda.'));

    $conversa = ChatConversation::create(['user_id' => $this->user->id]);
    $limite = (int) config('chat.rate_limit.messages_per_minute');

    for ($i = 0; $i < $limite; $i++) {
        $this->post(route('chat.message', $conversa), ['message' => "pergunta {$i}"])
            ->assertRedirect();
    }

    // ⚠️ O cooldown da `ModelChain` protege a COTA; este protege o PRODUTO. Um
    // laço no front queimaria as 1.500 requisições do dia antes de qualquer
    // cooldown perceber.
    $this->post(route('chat.message', $conversa), ['message' => 'uma a mais'])
        ->assertStatus(429);
});

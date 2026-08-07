<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\ChatOrchestrator;
use App\Domain\Ai\Chat\ChatProvider;
use App\Models\ChatConversation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeChatProvider;

/**
 * T512 — a tela de chat (FR-608, FR-610, §10.3).
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('exige autenticação', function () {
    auth()->logout();

    $this->get('/conversar')->assertRedirect('/login');
});

it('renderiza o componente Chat com as sugestões', function () {
    $this->get('/conversar')->assertInertia(fn (Assert $page) => $page
        ->component('Chat')
        ->has('suggestions', 4)
        ->where('conversation', null)
        ->where('has_data', false)
    );
});

/**
 * ⚠️ Dois estados vazios diferentes, e a distinção é o requisito — a mesma
 * lição da tela de avaliação: "sem dados" e "sem conversa" não são a mesma
 * coisa, e confundi-los faz o app pedir importação a quem já importou.
 */
it('distingue sem dados de sem conversa', function () {
    expect($this->get('/conversar')->viewData('page')['props']['has_data'])->toBeFalse();

    importAndAnalyse($this->user->id);

    expect($this->get('/conversar')->viewData('page')['props']['has_data'])->toBeTrue();
});

it('abre uma conversa e volta para ela', function () {
    $this->post('/conversar')->assertRedirect();

    $conversa = ChatConversation::forUser($this->user->id)->first();

    $this->get(route('chat.show', $conversa))->assertInertia(fn (Assert $page) => $page
        ->component('Chat')
        ->where('conversation.id', $conversa->id)
        ->has('messages', 0)
    );
});

/**
 * ⚠️ **FR-608 — o Artigo III virando interface.**
 */
it('a resposta chega com os dados consultados anexados', function () {
    importAndAnalyse($this->user->id);

    app()->instance(ChatProvider::class, FakeChatProvider::script([
        FakeChatProvider::wantsTools(['get_period_metrics' => ['start' => '2026-07-16', 'end' => '2026-07-29']]),
        FakeChatProvider::answers('Sua média foi 142 mg/dL.'),
    ]));
    app()->forgetInstance(ChatOrchestrator::class);

    $conversa = ChatConversation::create(['user_id' => $this->user->id]);
    $this->post(route('chat.message', $conversa), ['message' => 'qual minha média?']);

    $this->get(route('chat.show', $conversa))->assertInertia(fn (Assert $page) => $page
        ->has('messages', 2)
        ->where('messages.1.role', 'assistant')
        ->has('messages.1.consulted', 1)
        ->where('messages.1.consulted.0.name', 'get_period_metrics')
    );
});

it('a pergunta do usuário não tem procedência anexada', function () {
    importAndAnalyse($this->user->id);

    app()->instance(ChatProvider::class, FakeChatProvider::replying('Não consultei esse dado.'));
    app()->forgetInstance(ChatOrchestrator::class);

    $conversa = ChatConversation::create(['user_id' => $this->user->id]);
    $this->post(route('chat.message', $conversa), ['message' => 'oi']);

    $props = $this->get(route('chat.show', $conversa))->viewData('page')['props'];

    // A pergunta não consultou nada — e o rodapé não aparece para ela.
    expect($props['messages'][0]['consulted'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| ⚠️ NFR-404 — a tela não calcula
|--------------------------------------------------------------------------
*/

it('os componentes do chat não fazem conta', function (string $arquivo) {
    $codigo = (string) file_get_contents(resource_path("js/{$arquivo}"));

    // ⚠️ Comentário sai ANTES da varredura — a primeira versão deste teste
    // reprovou o docblock que PROMETE não calcular, porque ele cita `Math.`
    // para dizer que não usa. Terceira vez que esta armadilha aparece no
    // projeto (fase 3 no vocabulário, T501 na migration, aqui).
    $codigo = (string) preg_replace('#/\*.*?\*/#s', '', $codigo);
    $codigo = (string) preg_replace('#//.*$#m', '', $codigo);

    // Dividir texto em parágrafos e abrir/fechar um bloco é apresentação.
    // Qualquer conta aqui seria o Artigo I violado no lugar mais difícil de
    // auditar — o número apareceria certo em quase todos os casos.
    foreach (['reduce(', 'toFixed(', 'Math.', 'parseFloat(', 'parseInt('] as $calculo) {
        expect(str_contains($codigo, $calculo))->toBeFalse(
            "{$arquivo} usa '{$calculo}' — o servidor calcula, a tela apresenta"
        );
    }
})->with(['Pages/Chat.tsx', 'Components/ToolTrace.tsx']);

/**
 * ⚠️ Artigo VI, camada 5 — o rodapé permanente.
 */
it('a tela traz o rodapé de fronteira clínica', function () {
    // ⚠️ O rodapé passou a morar na casca (Spec 008 §D6). A garantia do
    // Artigo VI, camada 5, ficou MAIS forte: antes, apagar a linha de uma tela
    // tirava o rodapé só dela; agora ele chega por construção a todas.
    //
    // O que se cobra aqui é a corrente inteira: a tela usa a casca, E a casca
    // renderiza o rodapé. Verificar só um dos elos deixaria o outro livre.
    expect(file_get_contents(resource_path('js/Pages/Chat.tsx')))->toContain('AppShell');
    expect(file_get_contents(resource_path('js/Layouts/AppShell.tsx')))->toContain('ClinicalFooter');
});

/**
 * ⚠️ **§D11 — nada de streaming na tela.** ADR-5b: hospedagem compartilhada tem
 * timeout curto e buffer que não se controla do código. Um chat que dependesse
 * de `EventSource` poderia não funcionar no destino, e a descoberta seria no
 * deploy.
 */
it('a tela não depende de streaming', function () {
    $codigo = file_get_contents(resource_path('js/Pages/Chat.tsx'));

    foreach (['EventSource', 'WebSocket', 'text/event-stream'] as $streaming) {
        expect(str_contains($codigo, $streaming))->toBeFalse(
            "a tela usa '{$streaming}' — a conversa precisa renderizar sem stream (§D11)"
        );
    }
});

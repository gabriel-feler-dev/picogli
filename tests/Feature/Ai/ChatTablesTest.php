<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\Value\ChatRole;
use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\TurnOutcome;
use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Patterns\PatternEngine;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * T501 — as tabelas de conversa (FR-601, §D10, §11.3).
 */
beforeEach(function () {
    $this->user = User::factory()->create();
});

function conversaCom(int $userId, array $atributos = []): ChatConversation
{
    return ChatConversation::create(array_merge(['user_id' => $userId], $atributos));
}

/*
|--------------------------------------------------------------------------
| T501.1 e T501.2 — schema
|--------------------------------------------------------------------------
*/

it('cria as duas tabelas com as colunas que a fase precisa', function () {
    expect(Schema::hasTable('chat_conversations'))->toBeTrue();
    expect(Schema::hasTable('chat_messages'))->toBeTrue();

    expect(Schema::hasColumns('chat_conversations', [
        'user_id', 'title', 'context_start', 'context_end',
    ]))->toBeTrue();

    expect(Schema::hasColumns('chat_messages', [
        'chat_conversation_id', 'role', 'content',
        'tool_calls', 'tool_results', 'outcome', 'model',
        'input_tokens', 'output_tokens',
        'engine_version', 'metrics_version',
    ]))->toBeTrue();
});

/**
 * ⚠️ Artigo IX. A verificação é textual sobre o arquivo porque o efeito só
 * apareceria no MariaDB — e a suíte roda em SQLite, onde `jsonb` passaria batido
 * até o deploy.
 */
it('a migration não usa recurso específico de dialeto', function () {
    $arquivo = file_get_contents(
        base_path('database/migrations/2026_08_06_120000_create_chat_tables.php')
    );

    // ⚠️ Comentário e docblock saem ANTES da varredura. A primeira versão deste
    // teste reprovou a própria documentação da migration, que explica por que
    // usa `json` e não o outro — o mesmo erro que a fase 3 cometeu no detector
    // de vocabulário proibido.
    $codigo = (string) preg_replace('#/\*.*?\*/#s', '', $arquivo);
    $codigo = (string) preg_replace('#//.*$#m', '', $codigo);

    foreach (['jsonb', 'GENERATED ALWAYS', '->enum(', 'interval', 'ARRAY['] as $proibido) {
        expect(str_contains($codigo, $proibido))->toBeFalse(
            "a migration usa '{$proibido}' — Artigo IX"
        );
    }

    // E usa o que deve usar.
    expect($codigo)->toContain("->json('tool_calls')");
    expect($codigo)->toContain("->json('tool_results')");
});

it('apagar o usuário leva conversa e mensagens junto', function () {
    $conversa = conversaCom($this->user->id);
    ChatMessage::create([
        'chat_conversation_id' => $conversa->id,
        'role' => ChatRole::User,
        'content' => 'oi',
    ]);

    $this->user->delete();

    expect(ChatConversation::count())->toBe(0);
    expect(ChatMessage::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| T501.3 — casts
|--------------------------------------------------------------------------
*/

it('grava e lê tool_calls e tool_results como estrutura', function () {
    $conversa = conversaCom($this->user->id);

    $mensagem = ChatMessage::create([
        'chat_conversation_id' => $conversa->id,
        'role' => ChatRole::Assistant,
        'content' => 'A tarde concentra 24,1% do tempo acima.',
        'tool_calls' => [['name' => 'get_hourly_profile', 'arguments' => ['start' => '2026-07-16']]],
        'tool_results' => [['name' => 'get_hourly_profile', 'result' => ['afternoon_above_pct' => 24.1]]],
        'outcome' => TurnOutcome::Published,
        'model' => 'gemini-3.6-flash',
        'input_tokens' => 1200,
        'output_tokens' => 180,
    ]);

    $lida = $mensagem->fresh();

    expect($lida->role)->toBe(ChatRole::Assistant);
    expect($lida->outcome)->toBe(TurnOutcome::Published);
    expect($lida->tool_calls[0]['name'])->toBe('get_hourly_profile');
    expect($lida->tool_results[0]['result']['afternoon_above_pct'])->toBe(24.1);
    expect($lida->input_tokens)->toBe(1200);
});

it('data de contexto volta como data-só, nos dois bancos', function () {
    // ⚠️ Mesma armadilha do `PeriodReport`: o cast `date` grava
    // `2026-07-16 00:00:00`, o MySQL trunca e o SQLite não.
    $conversa = conversaCom($this->user->id, [
        'context_start' => '2026-07-16',
        'context_end' => new DateTimeImmutable('2026-07-29 13:45:00'),
    ]);

    expect($conversa->fresh()->context_start)->toBe('2026-07-16');
    expect($conversa->fresh()->context_end)->toBe('2026-07-29');
});

it('conversa nasce sem título e sem período, e isso é normal', function () {
    $conversa = conversaCom($this->user->id)->fresh();

    expect($conversa->title)->toBeNull();
    expect($conversa->context_start)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| T501.4 — ⚠️ escopo por usuário (§11.3)
|--------------------------------------------------------------------------
*/

it('a conversa de um usuário não aparece para outro', function () {
    $outro = User::factory()->create();

    conversaCom($this->user->id, ['title' => 'minha']);
    conversaCom($outro->id, ['title' => 'do outro']);

    $minhas = ChatConversation::forUser($this->user->id)->get();

    expect($minhas)->toHaveCount(1);
    expect($minhas->first()->title)->toBe('minha');

    expect(ChatConversation::forUser($outro->id)->get())->toHaveCount(1);
});

it('o escopo aceita o ChatScope direto, sem alguém extrair o id na mão', function () {
    conversaCom($this->user->id, ['title' => 'minha']);

    $scope = new ChatScope($this->user->id, 90);

    expect(ChatConversation::forUser($scope)->get())->toHaveCount(1);
});

it('as conversas vêm da mais recente para a mais antiga', function () {
    $velha = conversaCom($this->user->id, ['title' => 'velha']);
    $nova = conversaCom($this->user->id, ['title' => 'nova']);

    $velha->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
    $nova->forceFill(['updated_at' => now()])->saveQuietly();

    expect(ChatConversation::forUser($this->user->id)->pluck('title')->all())
        ->toBe(['nova', 'velha']);
});

/*
|--------------------------------------------------------------------------
| Ordem e visibilidade
|--------------------------------------------------------------------------
*/

it('as mensagens vêm na ordem em que foram gravadas', function () {
    $conversa = conversaCom($this->user->id);

    // ⚠️ Todas no mesmo segundo, de propósito: ordenar por `created_at`
    // embaralharia o turno, e é o defeito que a ordem por `id` evita.
    foreach (['primeira', 'segunda', 'terceira'] as $texto) {
        ChatMessage::create([
            'chat_conversation_id' => $conversa->id,
            'role' => ChatRole::User,
            'content' => $texto,
            'created_at' => '2026-08-06 10:00:00',
            'updated_at' => '2026-08-06 10:00:00',
        ]);
    }

    expect($conversa->messages()->pluck('content')->all())
        ->toBe(['primeira', 'segunda', 'terceira']);
});

it('o passo de ferramenta não vira bolha na tela', function () {
    $conversa = conversaCom($this->user->id);

    foreach ([ChatRole::User, ChatRole::Tool, ChatRole::Assistant] as $papel) {
        ChatMessage::create([
            'chat_conversation_id' => $conversa->id,
            'role' => $papel,
            'content' => $papel->value,
        ]);
    }

    expect($conversa->messages()->count())->toBe(3);
    expect($conversa->visibleMessages()->count())->toBe(2);
    expect(ChatMessage::visible()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| §D9 e §D10 — o que a linha registra
|--------------------------------------------------------------------------
*/

it('distingue a orientação de emergência de uma resposta do modelo', function () {
    $conversa = conversaCom($this->user->id);

    $emergencia = ChatMessage::create([
        'chat_conversation_id' => $conversa->id,
        'role' => ChatRole::Assistant,
        'content' => 'Procure atendimento médico imediatamente.',
        'outcome' => TurnOutcome::Emergency,
    ]);

    $resposta = ChatMessage::create([
        'chat_conversation_id' => $conversa->id,
        'role' => ChatRole::Assistant,
        'content' => 'Sua média na tarde foi 154 mg/dL.',
        'outcome' => TurnOutcome::Published,
        'model' => 'gemini-3.6-flash',
    ]);

    // ⚠️ As duas chegam à tela como texto do assistente. A coluna é o que
    // permite dizer que a primeira saiu SEM tocar a rede.
    expect($emergencia->reachedProvider())->toBeFalse();
    expect($resposta->reachedProvider())->toBeTrue();

    // E `Refused` é o único que pede investigação: é defeito nosso, não do dia.
    expect(TurnOutcome::Refused->deservesInvestigation())->toBeTrue();
    expect(TurnOutcome::Unavailable->deservesInvestigation())->toBeFalse();
});

it('a procedência é lida do que foi gravado, não remontada', function () {
    $conversa = conversaCom($this->user->id);

    $comProcedencia = ChatMessage::create([
        'chat_conversation_id' => $conversa->id,
        'role' => ChatRole::Assistant,
        'content' => 'A tarde concentra 24,1%.',
        'tool_results' => [['name' => 'get_hourly_profile', 'result' => ['pct' => 24.1]]],
    ]);

    $semProcedencia = ChatMessage::create([
        'chat_conversation_id' => $conversa->id,
        'role' => ChatRole::Assistant,
        'content' => 'Procure atendimento médico imediatamente.',
        'outcome' => TurnOutcome::Emergency,
    ]);

    expect($comProcedencia->hasProvenance())->toBeTrue();
    expect($comProcedencia->consultedData()[0]['name'])->toBe('get_hourly_profile');

    // Emergência não consulta nada — e o rodapé não tem o que mostrar.
    expect($semProcedencia->hasProvenance())->toBeFalse();
    expect($semProcedencia->consultedData())->toBe([]);
});

/**
 * ⚠️ **§D10 — o registro não é reescrito, e o teste é sobre a AUSÊNCIA de um
 * caminho.**
 *
 * A narrativa da fase 5 tem `PeriodReportWriter` invalidando o texto quando os
 * achados mudam. Aqui não existe equivalente, de propósito: reescrever o passado
 * apagaria a auditoria do Artigo III.
 */
it('não existe caminho que invalide mensagem por mudança de versão', function () {
    $metodos = get_class_methods(ChatMessage::class);

    foreach (['invalidate', 'invalidar', 'regenerate', 'refresh_content'] as $proibido) {
        expect(in_array($proibido, $metodos, true))->toBeFalse(
            "ChatMessage::{$proibido}() existe — a conversa é registro histórico (§D10)"
        );
    }
});

it('a mensagem guarda as versões vigentes no turno', function () {
    $conversa = conversaCom($this->user->id);

    $mensagem = ChatMessage::create([
        'chat_conversation_id' => $conversa->id,
        'role' => ChatRole::Assistant,
        'content' => 'texto',
        'engine_version' => PatternEngine::VERSION,
        'metrics_version' => DailyMetricsWriter::VERSION,
    ]);

    // ⚠️ Não servem para invalidar — servem para explicar, depois, por que um
    // número citado aqui difere do número da tela de hoje.
    expect($mensagem->fresh()->engine_version)->toBe(PatternEngine::VERSION);
});

/*
|--------------------------------------------------------------------------
| T501.5 — ChatScope
|--------------------------------------------------------------------------
*/

it('ChatScope recusa user_id inválido', function (int $invalido) {
    expect(fn () => new ChatScope($invalido, 90))
        ->toThrow(InvalidArgumentException::class);
})->with([0, -1]);

it('ChatScope recusa span máximo não positivo', function () {
    expect(fn () => new ChatScope(1, 0))
        ->toThrow(InvalidArgumentException::class, 'Span máximo');
});

it('ChatScope decide se um período cabe no teto', function () {
    $scope = new ChatScope(1, 90);

    expect($scope->allowsSpan(14))->toBeTrue();
    expect($scope->allowsSpan(90))->toBeTrue();
    expect($scope->allowsSpan(91))->toBeFalse();
    expect($scope->allowsSpan(0))->toBeFalse();
});

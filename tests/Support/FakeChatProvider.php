<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ai\Chat\ChatProvider;
use App\Domain\Ai\Chat\Value\ChatResponse;
use App\Domain\Ai\Chat\Value\ToolCall;
use App\Domain\Ai\ProviderFailure;
use App\Domain\Ai\ProviderUnavailable;

/**
 * Provedor de mentira que **encena o laço de tool calling** (NFR-601).
 *
 * ⚠️ **Sem isto não há como testar o orquestrador**, e o orquestrador é o coração
 * da fase 6. Um fake que só devolvesse texto testaria uma versão do chat em que o
 * modelo nunca consulta nada — exatamente o caminho que não interessa.
 *
 * ⚠️ Vive em `tests/`, não em `app/`. Dublê em `app/` viaja para produção e, pior,
 * dá a alguém a opção de "usar o fake por enquanto" num caminho real. Há teste.
 *
 * ## Como usar
 *
 * ```php
 * // um turno realista: consulta duas ferramentas, depois responde
 * FakeChatProvider::script([
 *     FakeChatProvider::wantsTools(['get_period_metrics' => ['start' => '...', 'end' => '...']]),
 *     FakeChatProvider::answers('Sua média foi 142 mg/dL.'),
 * ]);
 *
 * // o modelo em laço — para testar o teto do §D5
 * FakeChatProvider::alwaysWantsTools('get_period_metrics', [...]);
 * ```
 */
final class FakeChatProvider implements ChatProvider
{
    /** @var list<array{model: string, prompt: string, history: array}> */
    public array $calls = [];

    /** @param list<callable(string): ChatResponse> $steps */
    private function __construct(
        private array $steps = [],
        private readonly ?ProviderFailure $alwaysFails = null,
        private readonly mixed $repeatingStep = null,
    ) {}

    /**
     * Um passo por chamada, na ordem.
     *
     * @param  list<callable(string): ChatResponse>  $steps
     */
    public static function script(array $steps): self
    {
        return new self($steps);
    }

    /** Responde texto direto, sem consultar nada. */
    public static function replying(string $text): self
    {
        return new self([self::answers($text)]);
    }

    public static function failing(ProviderFailure $failure): self
    {
        return new self([], alwaysFails: $failure);
    }

    /** ⚠️ O modelo que nunca para de pedir — o caso que o teto do §D5 cobre. */
    public static function alwaysWantsTools(string $tool, array $args = []): self
    {
        return new self([], repeatingStep: self::wantsTools([$tool => $args]));
    }

    /**
     * Um passo que pede ferramentas.
     *
     * @param  array<string, array<string, mixed>>  $calls  nome => argumentos
     */
    public static function wantsTools(array $calls): callable
    {
        return function (string $model) use ($calls): ChatResponse {
            $toolCalls = [];

            foreach ($calls as $nome => $args) {
                $toolCalls[] = new ToolCall($nome, $args);
            }

            return new ChatResponse(model: $model, text: null, toolCalls: $toolCalls);
        };
    }

    /** Um passo que responde e encerra o laço. */
    public static function answers(string $text): callable
    {
        return fn (string $model): ChatResponse => new ChatResponse(
            model: $model,
            text: $text,
            inputTokens: 1200,
            outputTokens: 180,
        );
    }

    public function chat(string $model, string $systemPrompt, array $tools, array $history): ChatResponse
    {
        // ⚠️ Registra ANTES de falhar: sem isso, um teste de cadeia não distingue
        // "não tentou" de "tentou e falhou".
        $this->calls[] = ['model' => $model, 'prompt' => $systemPrompt, 'history' => $history];

        if ($this->alwaysFails !== null) {
            throw new ProviderUnavailable($this->alwaysFails, $model, 'fake: sempre falha');
        }

        if ($this->steps !== []) {
            $step = array_shift($this->steps);

            return $step($model);
        }

        if ($this->repeatingStep !== null) {
            return ($this->repeatingStep)($model);
        }

        // Roteiro esgotado: responde algo, para o teste falhar por asserção e não
        // por exceção de índice.
        return (self::answers('Resposta de teste.'))($model);
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /** O prompt de sistema da última chamada — é onde o payload viaja. */
    public function lastPrompt(): ?string
    {
        return $this->calls === [] ? null : end($this->calls)['prompt'];
    }

    /** @return list<string> os modelos tentados, na ordem */
    public function modelsTried(): array
    {
        return array_map(fn (array $call): string => $call['model'], $this->calls);
    }
}

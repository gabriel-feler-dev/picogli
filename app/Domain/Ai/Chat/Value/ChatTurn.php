<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Value;

/**
 * Um turno inteiro, pronto para ser gravado (Spec 006, §D9, §D10).
 *
 * ⚠️ **O orquestrador NUNCA lança.** Provedor fora, guarda reprovando, teto do
 * laço estourado — tudo vira um destes objetos. No chat uma exceção chegaria
 * como página de erro no meio de uma conversa, o que é pior que qualquer
 * resposta ruim (Artigo I).
 *
 * ⚠️ `orphanNumbers` existe para o log, não para a tela. O domínio é puro e não
 * chama `Log::`; ele devolve o que encontrou, e a borda registra. É o mesmo
 * desenho do `droppedKeys` do `AiPayload` na fase 5 — descarte silencioso seria
 * pior que nenhuma guarda, porque pareceria que nada foi reprovado.
 */
final readonly class ChatTurn
{
    /**
     * @param  list<array<string, mixed>>  $toolCalls
     * @param  list<array<string, mixed>>  $toolResults
     * @param  list<string>  $orphanNumbers  os números sem procedência, para o log
     */
    public function __construct(
        public string $content,
        public TurnOutcome $outcome,
        public array $toolCalls = [],
        public array $toolResults = [],
        public ?string $model = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public array $orphanNumbers = [],
        public int $iterations = 0,
    ) {}

    public function wasPublished(): bool
    {
        return $this->outcome === TurnOutcome::Published;
    }

    /** A rede foi tocada para produzir esta resposta? */
    public function reachedProvider(): bool
    {
        return $this->outcome->reachedProvider();
    }

    /** O que vira colunas em `chat_messages`. @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'content' => $this->content,
            'outcome' => $this->outcome,
            'tool_calls' => $this->toolCalls === [] ? null : $this->toolCalls,
            'tool_results' => $this->toolResults === [] ? null : $this->toolResults,
            'model' => $this->model,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
        ];
    }
}

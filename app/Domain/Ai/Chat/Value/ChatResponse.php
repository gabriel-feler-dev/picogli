<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Value;

/**
 * O que o modelo devolveu num passo do laço (Spec 006, FR-605).
 *
 * ⚠️ **Duas formas mutuamente excludentes, e é o laço inteiro:**
 *
 * ```
 * pediu ferramenta  →  o código executa e volta ao modelo
 * escreveu texto    →  o laço termina, a guarda de número entra
 * ```
 *
 * Um provedor que devolvesse as duas coisas ao mesmo tempo estaria dizendo
 * "responda com isto, e consulte aquilo" — e o texto teria sido escrito sem o
 * resultado da consulta. Por isso `wantsTools()` tem precedência: havendo pedido
 * de ferramenta, o texto do mesmo passo é descartado.
 */
final readonly class ChatResponse
{
    /** @param list<ToolCall> $toolCalls */
    public function __construct(
        public string $model,
        public ?string $text,
        public array $toolCalls = [],
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }

    /** Há texto publicável? */
    public function hasText(): bool
    {
        return $this->text !== null && trim($this->text) !== '';
    }
}

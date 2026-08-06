<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Value;

/**
 * O que o modelo pediu (Spec 006, §D2).
 *
 * ⚠️ **Isto é entrada não confiável.** É a única estrutura deste sistema cujo
 * conteúdo o texto do usuário influencia — indiretamente, através do modelo. O
 * `ArgumentValidator` existe por causa dela.
 *
 * Note o que NÃO está aqui: `user_id`. Ele viaja no `ChatScope`, ao lado, vindo
 * da sessão.
 */
final readonly class ToolCall
{
    /** @param array<string, mixed> $arguments como o modelo os escreveu */
    public function __construct(
        public string $name,
        public array $arguments = [],
    ) {}

    /** @param array{name?: mixed, arguments?: mixed} $raw a forma que vem do provedor */
    public static function fromArray(array $raw): self
    {
        return new self(
            (string) ($raw['name'] ?? ''),
            is_array($raw['arguments'] ?? null) ? $raw['arguments'] : [],
        );
    }

    /** @return array<string, mixed> a forma gravada em `chat_messages.tool_calls` */
    public function toArray(): array
    {
        return ['name' => $this->name, 'arguments' => $this->arguments];
    }
}

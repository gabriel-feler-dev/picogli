<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Value;

/**
 * O que o código devolveu — e, no chat, **a evidência** (Spec 006, §D3).
 *
 * ⚠️⚠️ **Esta classe é o Artigo III no chat.** Na fase 5 a procedência era a
 * `evidence` dos achados; aqui é a união dos `ToolResult` do turno. Um número
 * que o modelo escreva sem ter chamado a ferramenta correspondente não tem
 * correspondência aqui — e a resposta inteira é descartada (FR-607).
 *
 * Por isso o resultado é gravado em `chat_messages.tool_results` e é dele que o
 * rodapé "dados consultados" é lido, **sem remontar** (FR-608).
 *
 * ## O erro também é um resultado
 *
 * ⚠️ Argumento inválido não lança exceção: vira um `ToolResult` com `error`, que
 * **volta para o modelo**. Ele costuma corrigir sozinho na iteração seguinte —
 * "período inválido: início depois do fim" é uma instrução perfeitamente
 * acionável. Uma exceção, em comparação, encerraria o turno por um erro de
 * digitação de data.
 */
final readonly class ToolResult
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public string $name,
        public array $arguments,
        public array $data,
        public ?string $error,
    ) {}

    /** @param array<string, mixed> $data */
    public static function ok(string $name, array $arguments, array $data): self
    {
        return new self($name, $arguments, $data, null);
    }

    /** Argumento recusado, ferramenta desconhecida, período grande demais. */
    public static function failed(string $name, array $arguments, string $error): self
    {
        return new self($name, $arguments, [], $error);
    }

    public function succeeded(): bool
    {
        return $this->error === null;
    }

    /**
     * As chaves que este resultado de fato emitiu, em qualquer profundidade.
     *
     * ⚠️ É o que o registry confronta com `emittedKeys` e o que a allowlist do
     * Artigo VII consome. Índice de lista não conta — só chave nomeada.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $found = [];

        $walk = function (array $node) use (&$walk, &$found): void {
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $found[$key] = true;
                }

                if (is_array($value)) {
                    $walk($value);
                }
            }
        };

        $walk($this->data);

        return array_keys($found);
    }

    /** @return array<string, mixed> a forma gravada em `chat_messages.tool_results` */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'arguments' => $this->arguments,
            'result' => $this->data,
            'error' => $this->error,
        ], fn (mixed $v): bool => $v !== null && $v !== []);
    }
}

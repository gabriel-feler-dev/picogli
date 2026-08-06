<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolCall;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;
use InvalidArgumentException;

/**
 * O catálogo, e o único caminho de execução (Spec 006, FR-603).
 *
 * ⚠️ **Nenhum código chama `ChatTool::run()` diretamente.** Passa por aqui, e é
 * aqui que as três verificações acontecem:
 *
 * ```
 * 1. a ferramenta existe?            → senão, ToolResult com erro
 * 2. os argumentos são válidos?      → ArgumentValidator (§D2)
 * 3. a saída respeita o declarado?   → emittedKeys (§D7, Artigo VII)
 * ```
 *
 * A terceira é a que costuma parecer paranoia e não é: `emittedKeys` alimenta a
 * allowlist do Artigo VII. Uma declaração que ninguém confere é uma allowlist
 * que não protege — a ferramenta acrescentaria um campo, ele sairia em direção
 * ao provedor, e a lista continuaria dizendo que não sai.
 *
 * ⚠️ **Nada aqui lança para o chamador** por falha de ferramenta. Erro vira
 * `ToolResult` e **volta para o modelo**, que costuma corrigir sozinho. Exceção
 * encerraria o turno por um erro de digitação de data.
 */
final class ToolRegistry
{
    /** @var array<string, ChatTool> */
    private array $tools = [];

    /** @param list<ChatTool> $tools */
    public function __construct(
        array $tools,
        private readonly ArgumentValidator $validator,
    ) {
        foreach ($tools as $tool) {
            $nome = $tool->describe()->name;

            if (isset($this->tools[$nome])) {
                // Duas ferramentas com o mesmo nome: a segunda silenciaria a
                // primeira, e o modelo chamaria uma achando que chamou a outra.
                throw new InvalidArgumentException("Ferramenta duplicada: '{$nome}'.");
            }

            $this->tools[$nome] = $tool;
        }
    }

    /** O que o modelo recebe como catálogo. @return list<ToolDescriptor> */
    public function descriptors(): array
    {
        return array_values(array_map(
            fn (ChatTool $tool): ToolDescriptor => $tool->describe(),
            $this->tools,
        ));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * ⚠️ **A allowlist do Artigo VII no chat, DERIVADA das ferramentas** (§D7).
     *
     * A união dos `emittedKeys` das dez. Não é uma lista mantida à mão em
     * `config/` — uma lista paralela às ferramentas diverge no primeiro dia
     * corrido, e a divergência é silenciosa: a ferramenta emite, a lista não
     * permite, o campo some do payload e ninguém percebe.
     *
     * *E por quê ainda é revisão editorial:* declarar uma chave nova é editar a
     * ferramenta, o que é um ato revisável. A allowlist não cresce sozinha.
     *
     * @return list<string>
     */
    public function allowedKeys(): array
    {
        $chaves = [];

        foreach ($this->tools as $tool) {
            foreach ($tool->describe()->emittedKeys as $chave) {
                $chaves[$chave] = true;
            }
        }

        return array_keys($chaves);
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * O ÚNICO caminho de execução de ferramenta.
     */
    public function run(ToolCall $call, ChatScope $scope): ToolResult
    {
        $tool = $this->tools[$call->name] ?? null;

        if ($tool === null) {
            return ToolResult::failed(
                $call->name,
                $call->arguments,
                "ferramenta desconhecida: '{$call->name}'. Disponíveis: "
                .implode(', ', $this->names()),
            );
        }

        $descriptor = $tool->describe();
        $erro = $this->validator->validate($descriptor, $call->arguments, $scope);

        if ($erro !== null) {
            return ToolResult::failed($call->name, $call->arguments, $erro);
        }

        $resultado = $tool->run($call->arguments, $scope);

        return $this->enforceDeclaredKeys($descriptor, $resultado);
    }

    /**
     * ⚠️ A saída respeita o que a ferramenta declarou emitir? (§D7)
     *
     * Chave não declarada **não vaza** — o resultado inteiro vira erro. Descartar
     * só a chave seria pior: o modelo receberia um resultado incompleto sem saber
     * disso, e responderia com confiança sobre um dado que faltou.
     */
    private function enforceDeclaredKeys(ToolDescriptor $descriptor, ToolResult $resultado): ToolResult
    {
        if (! $resultado->succeeded()) {
            return $resultado;
        }

        $indevidas = array_diff($resultado->keys(), $descriptor->emittedKeys);

        if ($indevidas === []) {
            return $resultado;
        }

        return ToolResult::failed(
            $resultado->name,
            $resultado->arguments,
            "a ferramenta '{$descriptor->name}' emitiu chave não declarada: "
            .implode(', ', $indevidas)
            .'. A allowlist do Artigo VII sai de `emittedKeys` — declare a chave '
            .'ou não a emita.',
        );
    }
}

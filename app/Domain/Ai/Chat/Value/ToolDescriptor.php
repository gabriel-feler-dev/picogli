<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Value;

use InvalidArgumentException;

/**
 * O que uma ferramenta declara sobre si (Spec 006, FR-603, §D2, §D7).
 *
 * ## Três declarações, três propósitos
 *
 * | Campo | Quem lê | Para quê |
 * |---|---|---|
 * | `description` | **o modelo** | escolher a ferramenta certa |
 * | `argumentSchema` | o validador | recusar argumento inválido antes da query |
 * | `emittedKeys` | a allowlist do Artigo VII | saber o que pode sair daqui |
 *
 * ⚠️ **`description` é o único texto deste projeto escrito para uma máquina
 * ler.** Descrição vaga produz ferramenta chamada na hora errada, e o sintoma é
 * uma resposta ruim sem erro nenhum no log.
 *
 * ## ⚠️ `user_id` é recusado na CONSTRUÇÃO, não no teste
 *
 * O §D2 diz que nenhuma ferramenta aceita `user_id` como argumento. Isso podia
 * ser um teste varrendo os dez schemas — e vai ser, também. Mas um teste avisa
 * depois; o construtor **impede**.
 *
 * *Por quê as duas camadas:* o teste pega a ferramenta que alguém escreveu; o
 * construtor pega a que alguém vai escrever daqui a três meses, sem lembrar
 * deste parágrafo.
 */
final readonly class ToolDescriptor
{
    /** Nomes que o modelo nunca pode influenciar — o escopo vem da sessão. */
    private const FORBIDDEN_ARGUMENTS = ['user_id', 'userid', 'user', 'account_id', 'owner_id'];

    /**
     * @param  array<string, array<string, mixed>>  $argumentSchema  nome → regra
     * @param  list<string>  $emittedKeys  toda chave que pode aparecer no resultado
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $argumentSchema,
        public array $emittedKeys,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $this->name) !== 1) {
            throw new InvalidArgumentException(
                "Nome de ferramenta inválido: '{$this->name}'. Use snake_case."
            );
        }

        if (trim($this->description) === '') {
            throw new InvalidArgumentException(
                "A ferramenta '{$this->name}' não tem descrição. É o texto que o "
                .'modelo lê para escolhê-la — sem ele, ele escolhe no chute.'
            );
        }

        foreach (array_keys($this->argumentSchema) as $argumento) {
            // ⚠️ O §D2 em forma de exceção.
            if (in_array(mb_strtolower((string) $argumento), self::FORBIDDEN_ARGUMENTS, true)) {
                throw new InvalidArgumentException(
                    "A ferramenta '{$this->name}' declara o argumento '{$argumento}'. "
                    .'O escopo de usuário vem da sessão (ChatScope), nunca do modelo — '
                    .'e o campo não pode existir no schema, porque aceitar e ignorar '
                    .'deixa a proteção dependendo de alguém lembrar de ignorar.'
                );
            }

            if (preg_match('/^[a-z][a-z0-9_]*$/', (string) $argumento) !== 1) {
                throw new InvalidArgumentException(
                    "Argumento '{$argumento}' de '{$this->name}' fora do padrão snake_case."
                );
            }
        }

        if ($this->emittedKeys === []) {
            throw new InvalidArgumentException(
                "A ferramenta '{$this->name}' não declara chaves emitidas. É de onde "
                .'sai a allowlist do Artigo VII — sem declaração, não há o que permitir.'
            );
        }

        foreach ($this->emittedKeys as $chave) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', (string) $chave) !== 1) {
                throw new InvalidArgumentException(
                    "Chave emitida '{$chave}' de '{$this->name}' fora do padrão snake_case. "
                    .'As chaves do CareLink (`Last Name`, `Patient ID`) não passam por aqui.'
                );
            }
        }
    }

    /** O argumento é obrigatório? */
    public function requires(string $argument): bool
    {
        return (bool) ($this->argumentSchema[$argument]['required'] ?? false);
    }

    /** @return array<string, mixed>|null a regra declarada, ou `null` se não existe */
    public function rule(string $argument): ?array
    {
        return $this->argumentSchema[$argument] ?? null;
    }
}

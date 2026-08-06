<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Domain\Ai\Chat\ChatPromptBuilder;
use App\Domain\Ai\Chat\Value\ChatPayload;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use RuntimeException;

/**
 * Monta o prompt lendo `resources/prompts/chat.pt_BR.md` (FR-606).
 *
 * ## As listas dos Artigos IV e VI são INTERPOLADAS
 *
 * ⚠️ Mesma decisão da fase 5, pela mesma razão. O arquivo tem
 * `:vocabulario_proibido` e `:conduta_proibida`, não as palavras escritas:
 *
 *   - o **arquivo** fica limpo, e a varredura de vocabulário passa a cobrir este
 *     prompt sem acusá-lo;
 *   - o **prompt renderizado** carrega exatamente a lista que o teste cobra,
 *     porque as duas vêm de `config/tone.php`.
 *
 * Sem fonte única, uma palavra acrescentada ao teste não chegaria ao modelo.
 *
 * ## O catálogo de ferramentas também é interpolado
 *
 * ⚠️ `:ferramentas` é renderizado a partir dos `ToolDescriptor` reais. Escrever
 * a lista à mão no arquivo criaria a divergência mais cara possível: o prompt
 * anunciando uma ferramenta que não existe, ou omitindo uma que existe. O modelo
 * chamaria a inexistente e receberia erro; ou nunca chamaria a real, e ninguém
 * saberia por quê.
 */
final class FileChatPromptBuilder implements ChatPromptBuilder
{
    /**
     * @param  list<string>  $forbiddenVocabulary
     * @param  list<string>  $forbiddenConduct
     */
    public function __construct(
        private readonly string $path,
        private readonly array $forbiddenVocabulary,
        private readonly array $forbiddenConduct,
    ) {}

    public function build(ChatPayload $payload, array $tools): string
    {
        $template = @file_get_contents($this->path);

        if ($template === false) {
            throw new RuntimeException("Prompt de chat não encontrado em {$this->path}.");
        }

        return str_replace(
            [':vocabulario_proibido', ':conduta_proibida', ':ferramentas', ':contexto', ':resultados'],
            [
                $this->asList($this->forbiddenVocabulary),
                $this->asList($this->forbiddenConduct),
                $this->asTools($tools),
                $this->asJson($payload->context),
                $this->asJson($payload->toolResults),
            ],
            $template,
        );
    }

    /** @param list<string> $items */
    private function asList(array $items): string
    {
        return implode("\n", array_map(fn (string $item): string => '- "'.$item.'"', $items));
    }

    /**
     * O catálogo, com o schema de cada ferramenta.
     *
     * ⚠️ A descrição vem do `ToolDescriptor`, não daqui — é o único texto do
     * projeto escrito para uma máquina ler, e ele mora junto da ferramenta que
     * descreve.
     *
     * @param  list<ToolDescriptor>  $tools
     */
    private function asTools(array $tools): string
    {
        $linhas = [];

        foreach ($tools as $tool) {
            $argumentos = array_keys($tool->argumentSchema);

            $linhas[] = sprintf(
                "### `%s(%s)`\n\n%s",
                $tool->name,
                implode(', ', $argumentos),
                $tool->description,
            );
        }

        return implode("\n\n", $linhas);
    }

    /**
     * ⚠️ JSON, e não prosa. O modelo lê estrutura melhor que texto corrido, e o
     * payload serializado é **exatamente** o que o teste anti-vazamento
     * inspeciona. Reformatá-lo em frases criaria uma segunda representação do
     * dado, fora do alcance da verificação do Artigo VII.
     */
    private function asJson(array $data): string
    {
        if ($data === []) {
            return '_(nada consultado ainda)_';
        }

        return "```json\n"
            .json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            ."\n```";
    }
}

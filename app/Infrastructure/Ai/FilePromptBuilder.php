<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Domain\Ai\PromptBuilder;
use App\Domain\Ai\Value\AiPayload;
use RuntimeException;

/**
 * Monta o prompt lendo `resources/prompts/narrative.pt_BR.md` (FR-503, §D6).
 *
 * ## As listas dos Artigos IV e VI são INTERPOLADAS
 *
 * ⚠️ O arquivo do prompt tem `:vocabulario_proibido` e `:conduta_proibida`, não as
 * palavras escritas. É o que resolve a tensão do §D6:
 *
 *   - o **arquivo** fica limpo, então a varredura de vocabulário passa a cobrir
 *     `resources/prompts/` sem acusar o próprio prompt (o erro que a fase 3
 *     cometeu ao acusar a documentação da regra que ela explicava);
 *   - o **prompt renderizado** carrega exatamente a mesma lista que o teste
 *     cobra, porque as duas vêm de `config/tone.php`.
 *
 * Sem a fonte única, uma palavra acrescentada ao teste não chegaria ao modelo — e
 * o modelo continuaria autorizado a usá-la.
 */
final class FilePromptBuilder implements PromptBuilder
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

    public function build(AiPayload $payload): string
    {
        $template = @file_get_contents($this->path);

        if ($template === false) {
            throw new RuntimeException("Prompt de narrativa não encontrado em {$this->path}.");
        }

        return str_replace(
            [':vocabulario_proibido', ':conduta_proibida', ':periodo', ':achados'],
            [
                $this->asList($this->forbiddenVocabulary),
                $this->asList($this->forbiddenConduct),
                $this->asJson($payload->period),
                $this->asJson($payload->findings),
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
     * ⚠️ JSON, e não prosa. O modelo lê estrutura melhor que texto corrido, e —
     * mais importante — o payload serializado é **exatamente** o que o teste
     * anti-vazamento inspeciona. Reformatá-lo em frases criaria uma segunda
     * representação do dado, que a verificação do Artigo VII não cobriria.
     */
    private function asJson(array $data): string
    {
        return "```json\n"
            .json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            ."\n```";
    }
}

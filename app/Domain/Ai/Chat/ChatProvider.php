<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat;

use App\Domain\Ai\Chat\Value\ChatResponse;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\ProviderUnavailable;

/**
 * Um provedor que sabe pedir ferramentas (Spec 006, FR-605, ADR-6).
 *
 * ## Por que uma interface separada de `Provider`
 *
 * ⚠️ `Provider::generate()` tem contrato de **uma ida e volta**, e é o que a
 * narrativa usa. Somar tool calling nele obrigaria a narrativa a conhecer
 * ferramentas que nunca vai chamar — e o `FakeProvider` da fase 5 passaria a
 * precisar encenar um laço para testar um texto único.
 *
 * A mesma classe pode implementar as duas (`GeminiProvider` implementa), o que
 * mantém o Artigo VII intacto: continua havendo **um** arquivo que conhece o
 * endpoint.
 *
 * ## Contrato de falha
 *
 * Idêntico ao da fase 5: lança `ProviderUnavailable` com a razão **classificada**
 * pela implementação. Só ela sabe traduzir o corpo da resposta em
 * `ProviderFailure` — e é essa classificação que faz o cooldown por tipo de erro
 * funcionar.
 */
interface ChatProvider
{
    /**
     * Um passo do laço: manda o estado, recebe texto **ou** pedido de ferramenta.
     *
     * @param  string  $systemPrompt  o prompt de sistema já renderizado
     * @param  list<ToolDescriptor>  $tools  o catálogo que o modelo pode chamar
     * @param  list<array{role: string, content: string}>  $history  a conversa até aqui
     *
     * @throws ProviderUnavailable com a razão classificada
     */
    public function chat(string $model, string $systemPrompt, array $tools, array $history): ChatResponse;
}

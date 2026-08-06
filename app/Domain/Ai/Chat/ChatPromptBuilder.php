<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat;

use App\Domain\Ai\Chat\Value\ChatPayload;
use App\Domain\Ai\Chat\Value\ToolDescriptor;

/**
 * Monta o prompt de sistema do chat (Spec 006, FR-606).
 *
 * ⚠️ Interface no domínio puro; quem lê arquivo é a borda
 * (`Infrastructure/Ai/FileChatPromptBuilder`). Mesma separação do
 * `PromptBuilder` da fase 5 — e pela mesma razão: o domínio não toca disco.
 */
interface ChatPromptBuilder
{
    /**
     * @param  list<ToolDescriptor>  $tools  o catálogo que o modelo pode chamar
     */
    public function build(ChatPayload $payload, array $tools): string;
}

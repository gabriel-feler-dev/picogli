<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Value\AiPayload;

/**
 * Monta o prompt de narrativa a partir do payload sanitizado (FR-503).
 *
 * ⚠️ Interface no domínio, implementação na borda — mesmo desenho do
 * `ProseRenderer` da fase 4. Ler arquivo e resolver `config()` é trabalho de
 * borda; o `NarrativeGenerator` recebe a interface e continua PHP puro.
 *
 * ⚠️ **Recebe o `AiPayload`, não os achados crus.** O prompt é montado a partir
 * do que JÁ passou pelo `PayloadSanitizer` — se ele recebesse os achados
 * diretamente, existiria um segundo caminho até o texto que vai ao provedor, e o
 * Artigo VII deixaria de ter uma porta só.
 */
interface PromptBuilder
{
    public function build(AiPayload $payload): string;
}

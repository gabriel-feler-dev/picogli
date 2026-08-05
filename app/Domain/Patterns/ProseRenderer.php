<?php

declare(strict_types=1);

namespace App\Domain\Patterns;

use App\Domain\Patterns\Value\RuleId;

/**
 * Renderiza a prosa de fallback de um achado (§D3).
 *
 * ## Por que existe uma interface aqui
 *
 * Duas exigências colidem de frente:
 *
 *   - **§D3 / Artigo I:** `Finding` nasce com `fallbackProse` completa. Se a IA
 *     cair, o achado continua legível — e o construtor recusa prosa vazia.
 *   - **NFR-401 / NFR-405:** a regra é PHP puro e não chama `__()`; todo texto
 *     voltado ao usuário vive em `lang/`, senão a varredura do Artigo IV não o vê.
 *
 * A regra precisa da prosa e não pode buscá-la. A saída é **inverter a
 * dependência**: a regra recebe um renderizador, e quem sabe ler `lang/` é a
 * implementação, na borda (`LangProseRenderer`).
 *
 * O teste de pureza continua valendo — nenhuma regra menciona `__()` — e o texto
 * continua em arquivo de idioma, coberto pelo teste de vocabulário proibido da
 * fase 3.
 */
interface ProseRenderer
{
    /**
     * @param  array<string, int|float|string|bool|null>  $evidence
     *
     * @throws \InvalidArgumentException se um placeholder do texto não tiver
     *                                   chave correspondente na evidência
     */
    public function render(RuleId $rule, string $key, array $evidence): string;
}

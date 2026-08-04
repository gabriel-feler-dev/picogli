<?php

declare(strict_types=1);

namespace App\Domain\Presentation\Value;

/**
 * Situação de uma métrica frente à sua meta.
 *
 * ⚠️ `Met`/`NotMet` e não `Above`/`Below`: a direção sozinha não diz nada.
 * TIR acima da meta é bom; CV acima da meta é ruim. Quem conhece a direção é a
 * config (`targets.*.direction`), e o resultado dessa comparação é o que a tela
 * precisa — não a direção crua.
 *
 * `Unreliable` não é falha de cálculo: é o Artigo V agindo. O número existe, foi
 * calculado, e não deve ser lido como se fosse confiável.
 */
enum MetricStatus: string
{
    case Met = 'met';
    case NotMet = 'not_met';
    case Unreliable = 'unreliable';
}

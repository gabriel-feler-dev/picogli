<?php

declare(strict_types=1);

namespace App\Domain\Presentation\Value;

use InvalidArgumentException;

/**
 * Refeições agrupadas por rótulo (Spec 007, FR-702, §D3).
 *
 * ## ⚠️ `mealCount` não é enfeite — é o denominador
 *
 * "Pizza sobe 87 mg/dL em média" sobre **duas** refeições é ruído com cara de
 * conclusão. O Artigo V manda nunca esconder o denominador, e aqui o denominador
 * é quantas vezes aquilo aconteceu.
 *
 * Por isso a contagem é obrigatória na construção, e não um campo opcional que
 * alguém esquece de passar para a tela.
 *
 * ## ⚠️ E este objeto NÃO conclui nada (§D3)
 *
 * Ele agrupa, conta e calcula média. Dizer "pizza é pior que arroz" seria a R11 —
 * regra determinística nova, com limiar, severidade, prosa de fallback e valor de
 * gabarito apurado. As dez estão fechadas desde a fase 4.
 *
 * E o limiar de uma regra sobre comida precisaria de **amostra**: rotular
 * refeições por alguns meses é o pré-requisito, e é justamente o que esta fase
 * começa a coletar. Entregar a regra agora seria calibrar sobre zero rótulos.
 */
final readonly class MealGroup
{
    public function __construct(
        public string $label,
        public int $mealCount,
        public ?float $meanDelta2h,
        public ?float $meanCarbsG,
        /** Quantas do grupo têm resposta glicêmica apurada. */
        public int $withResponseCount,
    ) {
        if (trim($this->label) === '') {
            throw new InvalidArgumentException(
                'Grupo sem rótulo. Refeição sem rótulo não entra em agrupamento — '
                .'ela aparece na lista, e a lista é outra coisa.'
            );
        }

        if ($this->mealCount < 1) {
            throw new InvalidArgumentException(
                "Grupo '{$this->label}' com {$this->mealCount} refeições. A contagem é o "
                .'denominador (Artigo V) — grupo vazio não deveria existir.'
            );
        }

        if ($this->withResponseCount > $this->mealCount) {
            throw new InvalidArgumentException(
                "Grupo '{$this->label}': {$this->withResponseCount} respostas em "
                ."{$this->mealCount} refeições."
            );
        }
    }

    /**
     * A média é sustentada por amostra suficiente para ser lida como tendência?
     *
     * ⚠️ **Isto NÃO é limiar de regra** (§D3) — é decisão de apresentação, e o
     * número é baixo de propósito. A tela usa para escolher entre mostrar a média
     * com destaque ou apenas listar; ela nunca esconde a contagem, nem em um caso
     * nem no outro.
     *
     * *Por quê no servidor:* decidir se um número é interpretável é significado,
     * não layout — a mesma decisão do `dominant_range` na fase 3.
     */
    public function hasEnoughSample(): bool
    {
        return $this->withResponseCount >= 3;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            // ⚠️ Primeiro campo depois do rótulo, de propósito: quem lê o JSON
            // vê a contagem antes da média.
            'meal_count' => $this->mealCount,
            'with_response_count' => $this->withResponseCount,
            'mean_delta_2h' => $this->meanDelta2h,
            'mean_carbs_g' => $this->meanCarbsG,
            'has_enough_sample' => $this->hasEnoughSample(),
        ];
    }
}

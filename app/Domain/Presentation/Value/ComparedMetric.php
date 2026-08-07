<?php

declare(strict_types=1);

namespace App\Domain\Presentation\Value;

/**
 * Uma métrica nos dois períodos, com a diferença (Spec 007, FR-704, §D4).
 *
 * ## ⚠️ `conclusive` é decidido no SERVIDOR
 *
 * Escolher se uma diferença pode ser lida como melhora é **significado clínico**,
 * não layout — a mesma decisão do `dominant_range` na fase 3. Se o componente
 * decidisse, a regra viveria em TypeScript, fora do alcance da suíte de testes.
 *
 * ## ⚠️ E `delta` é `null` quando um dos lados não tem número
 *
 * Diferença calculada contra `null` é número inventado — e do pior tipo, porque
 * sai plausível. O `ComparePeriodsTool` já propaga isso (§D8 da fase 6): quando o
 * portão de validade zera o CV de um período, `cv_percent_delta` vem `null`.
 * Este objeto preserva a ausência em vez de preenchê-la com zero.
 */
final readonly class ComparedMetric
{
    public function __construct(
        public string $key,
        public string $label,
        public ?float $valueA,
        public ?float $valueB,
        public ?float $delta,
        public string $unit,
        /**
         * A diferença pode ser lida como tendência?
         *
         * ⚠️ `false` **não** significa "não mostre". Significa "mostre com o
         * aviso do lado". Esconder o número seria pior: a pessoa veria uma tela
         * vazia e não saberia por quê.
         */
        public bool $conclusive,
        /** Por que não é conclusivo — texto para a tela, não código de erro. */
        public ?string $inconclusiveReason = null,
    ) {}

    /**
     * A direção da mudança, sem julgamento sobre ela.
     *
     * ⚠️ **Não devolve "melhorou" nem "piorou"**, e isso é deliberado: para o
     * tempo na faixa, subir é bom; para o tempo acima, subir é o contrário. Quem
     * conhece o sentido clínico de cada métrica é o `MetricTranslator` e as metas
     * de `config/clinical.php` — não este objeto.
     */
    public function direction(): string
    {
        return match (true) {
            $this->delta === null => 'unknown',
            $this->delta > 0.0 => 'up',
            $this->delta < 0.0 => 'down',
            default => 'flat',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value_a' => $this->valueA,
            'value_b' => $this->valueB,
            'delta' => $this->delta,
            'unit' => $this->unit,
            'direction' => $this->direction(),
            // ⚠️ Os dois campos viajam JUNTOS. Um `conclusive: false` sem motivo
            // deixaria a tela dizendo "não é conclusivo" sem dizer por quê.
            'conclusive' => $this->conclusive,
            'inconclusive_reason' => $this->inconclusiveReason,
        ];
    }
}

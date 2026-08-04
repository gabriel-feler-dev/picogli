<?php

declare(strict_types=1);

namespace App\Domain\Presentation\Value;

/**
 * Uma métrica pronta para a tela (FR-203, spec.md §D1).
 *
 * ⚠️ O componente React recebe ISTO e desenha. Não formata número, não compara
 * com meta, não escolhe cor por valor. Se um dia algum desses passos aparecer no
 * JSX, a fase 5 vai recalcular o mesmo número e as duas versões vão divergir por
 * arredondamento.
 *
 * `$technicalValue` acompanha sempre o traduzido — Artigo III. "20 h por dia"
 * sem "TIR 83,9%" ao lado seria um número sem procedência.
 */
final readonly class TranslatedMetric
{
    public function __construct(
        public string $key,
        public string $label,
        public string $plainValue,
        public string $technicalValue,
        public ?string $targetLabel,
        public MetricStatus $status,
        public string $explanation,
    ) {}

    /** @return array<string, mixed> forma que o React consome */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'plain_value' => $this->plainValue,
            'technical_value' => $this->technicalValue,
            'target_label' => $this->targetLabel,
            'status' => $this->status->value,
            'explanation' => $this->explanation,
        ];
    }
}

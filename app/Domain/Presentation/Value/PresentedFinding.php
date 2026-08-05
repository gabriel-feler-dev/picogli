<?php

declare(strict_types=1);

namespace App\Domain\Presentation\Value;

/**
 * Um achado pronto para a tela.
 *
 * ⚠️ **Tudo resolvido no servidor.** O componente React recebe título, prosa,
 * rótulo de severidade e a evidência com rótulos legíveis — e não decide nada.
 *
 * `$evidence` aqui é `[['label' => 'Leituras no pior período', 'value' => '917']]`,
 * não o mapa cru. A tradução de `worst_readings` para "Leituras no pior período"
 * mora em `lang/`, junto do resto do texto, e é o que mantém a varredura do
 * Artigo IV cobrindo tudo que o usuário lê.
 */
final readonly class PresentedFinding
{
    /**
     * @param  list<array{label: string, value: string, key: string}>  $evidence
     */
    public function __construct(
        public string $ruleId,
        public string $title,
        public string $prose,
        public string $severity,
        public string $severityLabel,
        public int $rank,
        public bool $requiresClinicalHandoff,
        public array $evidence,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'title' => $this->title,
            'prose' => $this->prose,
            'severity' => $this->severity,
            'severity_label' => $this->severityLabel,
            'rank' => $this->rank,
            'requires_clinical_handoff' => $this->requiresClinicalHandoff,
            'evidence' => $this->evidence,
        ];
    }
}

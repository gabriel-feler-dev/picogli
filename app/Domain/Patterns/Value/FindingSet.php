<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Value;

/**
 * O resultado de uma passada do motor: achados ordenados **e** falhas de regra.
 *
 * ⚠️ **As falhas viajam junto, não são engolidas.** Uma regra que lançou exceção
 * some do relatório, e sem este campo ela sumiria em silêncio — o relatório teria
 * nove achados e pareceria completo.
 *
 * ⚠️ **Conjunto vazio é resultado legítimo** (§D10), não erro. Período sem
 * nenhum padrão detectado é boa notícia, e a tela precisa poder dizer isso sem
 * inventar achado de enchimento.
 */
final readonly class FindingSet
{
    /**
     * @param  list<Finding>  $findings  já ordenados por (severidade, rank)
     * @param  list<array{rule_id: string, message: string}>  $failures
     */
    public function __construct(
        public array $findings = [],
        public array $failures = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->findings === [];
    }

    public function count(): int
    {
        return count($this->findings);
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    /** @return list<Finding> */
    public function ofRule(RuleId $rule): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (Finding $finding): bool => $finding->ruleId === $rule,
        ));
    }

    /** @return list<Finding> */
    public function ofSeverity(Severity $severity): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (Finding $finding): bool => $finding->severity === $severity,
        ));
    }

    /** O achado que exige encaminhamento clínico, se houver (Artigo VI). */
    public function requiringClinicalHandoff(): array
    {
        return array_values(array_filter(
            $this->findings,
            fn (Finding $finding): bool => $finding->requiresClinicalHandoff,
        ));
    }

    /** @return array<string, mixed> forma persistida em `period_reports` */
    public function toArray(): array
    {
        return [
            'findings' => array_map(fn (Finding $f): array => $f->toArray(), $this->findings),
            'rule_failures' => $this->failures,
            'finding_count' => $this->count(),
        ];
    }
}

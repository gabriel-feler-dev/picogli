<?php

declare(strict_types=1);

namespace App\Domain\Patterns;

use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\FindingSet;
use App\Domain\Patterns\Value\PatternDataset;
use Throwable;

/**
 * Roda as dez regras e devolve os achados ordenados (FR-401).
 *
 * ## `VERSION` — por que existe
 *
 * Fica gravada em `period_reports.engine_version`. Quando uma regra muda, os
 * relatórios antigos continuam identificados pela versão que os gerou, e dá para
 * reprocessar seletivamente em vez de invalidar tudo.
 *
 * ⚠️ **Mudar o valor de um case de `RuleId`, uma chave de `evidence` ou o limiar
 * de uma regra é mudança de versão.** Relatórios já gravados carregam o formato
 * antigo, e um leitor que não saiba disso compara maçãs com laranjas.
 *
 * ## Isolamento de falha
 *
 * ⚠️ **Uma regra que lança exceção não derruba o relatório.** Ela é registrada em
 * `rule_failures` e as outras nove seguem.
 *
 * É a diferença entre "o motor de padrões está fora do ar" e "nove dos dez
 * achados apareceram" — e num produto em que o valor está distribuído por dez
 * regras independentes, a segunda é claramente melhor. Uma exceção em R10, que é
 * informativa, não pode esconder o cluster de hipoglicemias de R2.
 *
 * ## Ordenação
 *
 * `(severidade desc, rank asc)`. A ordenação do PHP 8 é **estável**, então
 * achados com a mesma severidade e o mesmo rank — como os dois que R4 pode
 * emitir — mantêm a ordem em que a regra os produziu.
 */
final class PatternEngine
{
    /**
     * Versão do motor.
     *
     * `AAAA.MM.N` — mesma convenção de `DailyMetricsWriter::VERSION`.
     */
    public const VERSION = '2026.08.1';

    /** @param list<Rule> $rules */
    public function __construct(private readonly array $rules) {}

    public function run(PatternDataset $dataset): FindingSet
    {
        $findings = [];
        $failures = [];

        foreach ($this->rules as $rule) {
            try {
                foreach ($rule->evaluate($dataset) as $finding) {
                    $findings[] = $finding;
                }
            } catch (Throwable $exception) {
                // ⚠️ Registrada, nunca engolida: sem isto a regra sumiria do
                // relatório em silêncio e ele pareceria completo com nove.
                $failures[] = [
                    'rule_id' => $rule->id()->value,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return new FindingSet($this->sorted($findings), $failures);
    }

    /**
     * Severidade primeiro, rank como desempate.
     *
     * ⚠️ **A ordem de saída não depende da ordem de registro das regras.** Se
     * dependesse, reordenar o array do service provider por estética mudaria o
     * que o usuário lê primeiro — acoplamento invisível entre configuração e
     * produto.
     *
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function sorted(array $findings): array
    {
        usort($findings, function (Finding $a, Finding $b): int {
            $bySeverity = $b->severity->weight() <=> $a->severity->weight();

            return $bySeverity !== 0 ? $bySeverity : $a->rank() <=> $b->rank();
        });

        return $findings;
    }

    /** @return list<string> os `ruleId` registrados, na ordem de registro */
    public function registeredRules(): array
    {
        return array_map(fn (Rule $rule): string => $rule->id()->value, $this->rules);
    }
}

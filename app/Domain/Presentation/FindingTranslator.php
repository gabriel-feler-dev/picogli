<?php

declare(strict_types=1);

namespace App\Domain\Presentation;

use App\Domain\Patterns\Value\RuleId;
use App\Domain\Presentation\Value\PresentedFinding;

/**
 * Traduz um achado gravado para a forma que a tela consome (FR-414).
 *
 * ⚠️ Recebe o **array persistido** de `period_reports.findings`, não um objeto
 * `Finding`. A tela lê o relatório gravado; reconstruir o objeto exigiria
 * revalidar a evidência de um dado que já passou pela validação quando foi
 * criado — e faria um relatório antigo, gravado por versão anterior do motor,
 * explodir na leitura em vez de ser exibido com aviso de desatualizado.
 *
 * ## Os rótulos de evidência vivem em `lang/`
 *
 * `worst_readings` vira "Leituras no pior período" pelo arquivo de idioma. Chave
 * sem rótulo cai para a própria chave — um relatório antigo com evidência que a
 * versão atual não conhece continua legível, só menos bonito.
 *
 * *Por quê não falhar:* o Artigo III exige que o número rastreie até o banco.
 * Uma chave sem tradução ainda rastreia; recusar a exibi-la perderia informação
 * verdadeira por causa de um rótulo faltando.
 */
final class FindingTranslator
{
    /** @param array<string, mixed> $finding uma entrada de `period_reports.findings` */
    public function translate(array $finding): PresentedFinding
    {
        $rule = RuleId::from($finding['rule_id']);
        $severity = (string) $finding['severity'];

        return new PresentedFinding(
            ruleId: $rule->value,
            title: (string) __($rule->langKey().'.title'),
            prose: (string) $finding['fallback_prose'],
            severity: $severity,
            severityLabel: (string) __('patterns.severity.'.$severity),
            rank: (int) $finding['rank'],
            requiresClinicalHandoff: (bool) $finding['requires_clinical_handoff'],
            evidence: $this->evidenceFor($rule, $finding['evidence']),
        );
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return list<array{label: string, value: string, key: string}>
     */
    private function evidenceFor(RuleId $rule, array $evidence): array
    {
        $labels = __($rule->langKey().'.evidence');
        $labels = is_array($labels) ? $labels : [];

        $rows = [];

        foreach ($evidence as $key => $value) {
            $rows[] = [
                'key' => $key,
                'label' => (string) ($labels[$key] ?? $key),
                'value' => $this->format($value),
            ];
        }

        return $rows;
    }

    /**
     * ⚠️ Formata para leitura, em pt-BR — a mesma convenção do
     * `LangProseRenderer`, para que a evidência expandida e a prosa mostrem o
     * mesmo número. Se divergissem, quem expandisse para conferir encontraria
     * uma terceira versão do valor.
     */
    private function format(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'sim' : 'não',
            is_int($value) => number_format($value, 0, ',', '.'),
            is_float($value) => $this->formatFloat($value),
            default => (string) $value,
        };
    }

    private function formatFloat(float $value): string
    {
        $formatted = number_format($value, 2, ',', '.');

        // Descarta casas decimais nulas: 8,00 vira "8"; 5,28 fica "5,28".
        $formatted = rtrim(rtrim($formatted, '0'), ',');

        return $formatted === '' ? '0' : $formatted;
    }
}

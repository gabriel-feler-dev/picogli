<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Rules;

use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rule;
use App\Domain\Patterns\Value\DailySnapshot;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;

/**
 * R7 — Aderência ao uso do sensor (FR-409).
 *
 * Cobertura por **dia**, não por período.
 *
 * *No export de referência:* a cobertura do período é 91,1% e **aprova** o portão
 * de validade. Mesmo assim, o 22/07 tem 34%. É a média diluindo o problema — e
 * encontrar o que a média dilui é o objetivo da fase.
 *
 * ⚠️ O limiar de 70% não é aproximado: o 21/07 tem 73% e **passa**. Um limiar
 * "por volta de 70%" acusaria dois dias, e a diferença entre 1 e 2 dias muda a
 * frase que a pessoa lê.
 *
 * ## Sobre o tom (Artigo IV)
 *
 * A prosa fala do **que se perde** — sem leitura o SmartGuard não age, e a média
 * do dia fala por menos tempo do que parece. As duas coisas são mecanismo
 * verificável. "Você deveria usar mais o sensor" carregaria a mesma informação,
 * seria uma violação do Artigo IV, e ainda por cima inútil: quem ficou sem sensor
 * sabe que ficou.
 */
final class R7SensorAdherence implements Rule
{
    public function __construct(
        private readonly PatternsConfig $config,
        private readonly ProseRenderer $prose,
    ) {}

    public function id(): RuleId
    {
        return RuleId::SensorAdherence;
    }

    public function evaluate(PatternDataset $dataset): array
    {
        if ($dataset->daily === []) {
            return [];
        }

        $thresholdPercent = $this->config->threshold($this->id(), 'coverage_threshold') * 100;

        $below = array_values(array_filter(
            $dataset->daily,
            fn (DailySnapshot $day): bool => $day->coveragePct < $thresholdPercent,
        ));

        if ($below === []) {
            return [];
        }

        $worst = $below[0];

        foreach ($below as $day) {
            if ($day->coveragePct < $worst->coveragePct) {
                $worst = $day;
            }
        }

        $evidence = [
            'days_below_threshold' => count($below),
            'days_total' => count($dataset->daily),
            'threshold_percent' => round($thresholdPercent, 1),
            'worst_date' => $worst->localDate,
            'worst_coverage_percent' => round($worst->coveragePct, 1),
            'period_coverage_percent' => round($dataset->coverage->percentage, 1),
            'days_below_100' => count(array_filter(
                $dataset->daily,
                fn (DailySnapshot $day): bool => $day->coveragePct < 100.0,
            )),
        ];

        return [new Finding(
            ruleId: $this->id(),
            severity: $this->severityFor($dataset, $thresholdPercent),
            evidence: $evidence,
            fallbackProse: $this->prose->render($this->id(), 'prose', $evidence),
        )];
    }

    /**
     * ⚠️ `Priority` só quando o PERÍODO inteiro fica abaixo do limiar — porque aí
     * o Artigo V entra em cena e GMI e CV do relatório deixam de ser
     * interpretáveis. Um dia ruim isolado é `Attention`: informa, sem dramatizar
     * o que é normal acontecer.
     */
    private function severityFor(PatternDataset $dataset, float $thresholdPercent): Severity
    {
        return $dataset->coverage->percentage < $thresholdPercent
            ? Severity::Priority
            : Severity::Attention;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Rules;

use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rule;
use App\Domain\Patterns\Value\CalibrationPair;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;

/**
 * R10 — Qualidade do sensor (FR-412).
 *
 * *No export de referência:* erro relativo médio de **10,68%** contra a calibração
 * capilar, com **n=39** e janela de **±10 min** — dentro do esperado para o
 * Guardian Sensor 3.
 *
 * ## A janela é parte do número
 *
 * ⚠️ Duas janelas de pareamento diferentes produzem dois erros médios, e **os dois
 * estão certos**. O que os distingue é a janela. Por isso ela vai na evidência:
 * sem ela, "erro médio de 10,7%" não é reproduzível — é só um número plausível.
 *
 * ## Por que a severidade é sempre `Info`
 *
 * O erro relativo do sensor é característica do equipamento. Um MARD de ~10% é o
 * que o Guardian 3 entrega por projeto; não é resultado de nada que a pessoa fez.
 *
 * A regra compara com `expected_error_percent` e escolhe entre duas prosas — a que
 * contextualiza como esperado e a que diz que está acima. **Nenhuma das duas
 * recomenda trocar sensor, recalibrar ou procurar suporte:** isso seria conduta
 * sobre equipamento médico (Artigo VI). Ela relata e para.
 */
final class R10SensorQuality implements Rule
{
    public function __construct(
        private readonly PatternsConfig $config,
        private readonly ProseRenderer $prose,
    ) {}

    public function id(): RuleId
    {
        return RuleId::SensorQuality;
    }

    public function evaluate(PatternDataset $dataset): array
    {
        $pairs = $dataset->calibrationPairs;

        // Amostra pequena não sustenta média de erro: com 3 pares, uma calibração
        // atípica move o resultado em dezenas de pontos percentuais.
        if (count($pairs) < $this->config->threshold($this->id(), 'min_pairs')) {
            return [];
        }

        $errors = array_map(fn (CalibrationPair $p): float => $p->relativeErrorPercent(), $pairs);
        $offsets = array_map(fn (CalibrationPair $p): float => $p->offsetMinutes, $pairs);

        $meanError = array_sum($errors) / count($errors);
        $expected = $this->config->threshold($this->id(), 'expected_error_percent');

        $evidence = [
            'pairs' => count($pairs),
            // ⚠️ A janela É evidência: sem ela o erro médio não é reproduzível.
            'window_minutes' => $dataset->calibrationWindowMinutes,
            'mean_error_percent' => round($meanError, 2),
            'median_error_percent' => round($this->median($errors), 2),
            'max_error_percent' => round(max($errors), 1),
            'mean_offset_minutes' => round(array_sum($offsets) / count($offsets), 2),
            'expected_error_percent' => $expected,
            // Quantos pares o sensor leu ACIMA do capilar — mostra se o desvio
            // tem direção ou é ruído simétrico.
            'pairs_sensor_higher' => count(array_filter(
                $pairs,
                fn (CalibrationPair $p): bool => $p->signedDifference() > 0,
            )),
        ];

        return [new Finding(
            ruleId: $this->id(),
            // Sempre Info: é característica do equipamento, não resultado de
            // escolha de ninguém.
            severity: Severity::Info,
            evidence: $evidence,
            fallbackProse: $this->prose->render(
                $this->id(),
                $meanError <= $expected ? 'prose' : 'prose_above_expected',
                $evidence,
            ),
        )];
    }

    /**
     * Mediana com interpolação nos pares — mesma convenção do
     * `HourlyPercentileBuilder` da fase 3.
     *
     * *Por quê declarar:* existem várias definições de mediana para `n` par. Sem
     * fixar a escolha, uma reimplementação futura daria número diferente e alguém
     * caçaria bug onde só há convenção.
     *
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Rules;

use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rule;
use App\Domain\Patterns\Value\DaypartStats;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;

/**
 * R1 — Deriva por período do dia (FR-403).
 *
 * Compara o tempo acima da faixa entre os quatro períodos de 6 h e dispara quando
 * o pior tem `ratio_threshold`× o do melhor.
 *
 * *No export de referência:* tarde 24,10% contra madrugada 4,17% = **5,78×**
 * (gabarito §Fase 4).
 *
 * ## O que esta regra encontra que a média esconde
 *
 * O TIR do período é 83,9% — um número bom. Ele é a média de uma madrugada quase
 * perfeita com uma tarde em que a glicose passa de 180 um quarto do tempo. A média
 * está certa e não conta a história.
 *
 * ## Por que períodos fixos, e não a janela que maximiza o efeito
 *
 * A análise exploratória comparou `00h–12h` com `15h–22h` e achou 4,01×. Não está
 * errado — mas é um recorte escolhido **depois** de ver o resultado, e sempre
 * existe um que maximiza a diferença. Blocos fixos de 6 h (§D6) dão 5,78×, e o
 * número é reproduzível por quem não viu o dado antes.
 */
final class R1DaypartDrift implements Rule
{
    public function __construct(
        private readonly PatternsConfig $config,
        private readonly ProseRenderer $prose,
    ) {}

    public function id(): RuleId
    {
        return RuleId::DaypartDrift;
    }

    public function evaluate(PatternDataset $dataset): array
    {
        $minimum = $this->config->threshold($this->id(), 'min_readings_per_daypart');

        // ⚠️ Período com amostra pequena sai da comparação. Sem isto, um período
        // atravessado por lacuna de sensor pode virar "o seu pior horário" com
        // base em três horas de dado.
        $eligible = array_values(array_filter(
            $dataset->dayparts,
            fn (DaypartStats $stats): bool => $stats->hasEnoughReadings($minimum),
        ));

        if (count($eligible) < 2) {
            return [];
        }

        $worst = $eligible[0];
        $best = $eligible[0];

        foreach ($eligible as $stats) {
            if ($stats->percentAbove() > $worst->percentAbove()) {
                $worst = $stats;
            }

            if ($stats->percentAbove() < $best->percentAbove()) {
                $best = $stats;
            }
        }

        $ratio = $this->ratio($worst, $best);
        $threshold = $this->config->threshold($this->id(), 'ratio_threshold');

        if ($ratio !== null && $ratio < $threshold) {
            return [];
        }

        // Melhor período com 0% e pior com algo: a razão tende ao infinito, o que
        // satisfaz qualquer limiar. Não é caso especial inventado — é o limite.
        if ($ratio === null && $worst->percentAbove() <= 0.0) {
            return [];
        }

        $evidence = $this->evidenceFor($dataset, $worst, $best, $ratio);

        return [new Finding(
            ruleId: $this->id(),
            severity: $this->severityFor($ratio),
            evidence: $evidence,
            fallbackProse: $this->prose->render(
                $this->id(),
                $ratio === null ? 'prose_no_ratio' : 'prose',
                $evidence,
            ),
        )];
    }

    /** `null` quando o melhor período tem 0% — divisão impossível, razão infinita. */
    private function ratio(DaypartStats $worst, DaypartStats $best): ?float
    {
        return $best->percentAbove() > 0.0
            ? $worst->percentAbove() / $best->percentAbove()
            : null;
    }

    private function severityFor(?float $ratio): Severity
    {
        $priority = $this->config->threshold($this->id(), 'priority_ratio');

        // Razão infinita satisfaz qualquer limiar de prioridade.
        if ($ratio === null || $ratio >= $priority) {
            return Severity::Priority;
        }

        return Severity::Attention;
    }

    /**
     * @return array<string, int|float|string|bool|null>
     */
    private function evidenceFor(
        PatternDataset $dataset,
        DaypartStats $worst,
        DaypartStats $best,
        ?float $ratio,
    ): array {
        $evidence = [
            'worst_daypart' => $worst->daypart->value,
            'worst_percent_above' => round($worst->percentAbove(), 2),
            'worst_readings' => $worst->count,
            'best_daypart' => $best->daypart->value,
            'best_percent_above' => round($best->percentAbove(), 2),
            'best_readings' => $best->count,
            'ratio' => $ratio === null ? null : round($ratio, 2),
            'difference_pp' => round($worst->percentAbove() - $best->percentAbove(), 2),
        ];

        // FR-403 — os QUATRO períodos entram na evidência, não só os dois
        // extremos. Quem confere precisa poder ver que a tarde é o pior sem ter
        // de acreditar; e a fase 5 precisa dos quatro para escrever "manhãs e
        // madrugadas estáveis" sem inventar nada.
        foreach ($dataset->dayparts as $key => $stats) {
            $evidence[$key.'_percent_above'] = round($stats->percentAbove(), 2);
            $evidence[$key.'_readings'] = $stats->count;
        }

        return $evidence;
    }
}

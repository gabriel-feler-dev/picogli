<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Rules;

use App\Domain\Metrics\MetricsConfig;
use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rule;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;

/**
 * R4 — Dia outlier, concentração de Pareto (FR-406).
 *
 * *No export de referência:* das 70 leituras acima de 250, **50 são do 25/07 =
 * 71,4%**, e **12 dos 14 dias não têm nenhuma**.
 *
 * ## Por que o `PicoGli.md` chama esta de a regra mais valiosa do conjunto
 *
 * Porque ela **inverte a leitura que a pessoa faz de si mesma**. "1,9% do tempo
 * acima de 250" parece um problema crônico, difuso, de quem não consegue
 * controlar. A verdade é que foram dois dias, e um deles responde por 71%. Nos
 * outros doze, zero minutos.
 *
 * É o oposto de acusatório sem precisar de nenhum eufemismo: só de medir a coisa
 * certa.
 *
 * ## Duas métricas, dois achados possíveis
 *
 * FR-406 pede a regra para **cada** métrica ruim. E o export de referência
 * entrega, de graça, o par perfeito para o §D5:
 *
 * ```
 * >250  →  dia dominante 25/07 com 71,4%   DISPARA
 *  <70  →  dia dominante 23/07 com 21,7%   NÃO dispara (espalhado por 8 dias)
 * ```
 *
 * ⚠️ **Mesma regra, mesmo período, resultados opostos.** É a prova mais forte de
 * que R4 discrimina — e não veio de série sintética, veio do dado real.
 *
 * ## `min_total_readings`
 *
 * Com 3 leituras acima de 250 no período inteiro, uma delas já é 33%. "Um terço
 * do seu tempo muito alto veio de um dia" seria verdade e não significaria nada.
 */
final class R4OutlierDay implements Rule
{
    /** Métricas ruins avaliadas, na ordem de exibição. */
    private const METRICS = ['above_250', 'below_70'];

    public function __construct(
        private readonly PatternsConfig $config,
        private readonly MetricsConfig $clinical,
        private readonly ProseRenderer $prose,
    ) {}

    public function id(): RuleId
    {
        return RuleId::OutlierDay;
    }

    public function evaluate(PatternDataset $dataset): array
    {
        $findings = [];

        foreach (self::METRICS as $metric) {
            $finding = $this->evaluateMetric($dataset, $metric);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function evaluateMetric(PatternDataset $dataset, string $metric): ?Finding
    {
        $byDate = $this->countByDate($dataset, $metric);
        $total = array_sum($byDate);

        if ($total < $this->config->threshold($this->id(), 'min_total_readings')) {
            return null;
        }

        arsort($byDate);
        $dominantDate = (string) array_key_first($byDate);
        $dominantCount = $byDate[$dominantDate];
        $contribution = $dominantCount / $total;

        if ($contribution < $this->config->threshold($this->id(), 'pareto_threshold')) {
            return null;
        }

        $daysWithReadings = count($dataset->daily) ?: count($this->datesInSeries($dataset));

        $evidence = [
            'metric' => $metric,
            'dominant_date' => $dominantDate,
            'dominant_readings' => $dominantCount,
            'dominant_minutes' => $this->toMinutes($dominantCount),
            'total_readings' => $total,
            'total_minutes' => $this->toMinutes($total),
            'contribution_percent' => round($contribution * 100, 1),
            'days_total' => $daysWithReadings,
            'days_affected' => count($byDate),
            // ⚠️ O número que sustenta "nos outros doze dias". Sem ele a prosa
            // afirmaria algo que a evidência não carrega (Artigo II).
            'clean_days' => $daysWithReadings - count($byDate),
        ];

        return new Finding(
            ruleId: $this->id(),
            // ⚠️ `Attention`, nunca `Priority`. Uma concentração alta é justamente
            // a boa notícia: o problema é pontual, não crônico. O dia em si merece
            // atenção, mas o achado tranquiliza mais do que alerta — e escalar a
            // severidade por concentração alta inverteria a mensagem.
            severity: Severity::Attention,
            evidence: $evidence,
            fallbackProse: $this->prose->render($this->id(), 'prose_'.$metric, $evidence),
        );
    }

    /**
     * Leituras da métrica, agrupadas por `local_date` (§D7).
     *
     * ⚠️ Contadas da SÉRIE, não derivadas de `daily_metrics`. Reconstruir a
     * contagem a partir de `tar_level2_pct × reading_count` traria erro de
     * arredondamento para dentro do dado — a mesma armadilha de somar valores já
     * formatados que produziu os 295,16 U da fase 1.
     *
     * ⚠️ Dia civil, e não episódio: a regra se chama "dia outlier" e a frase que
     * ela produz é "veio de um único dia". A parte do episódio de 25/07 que
     * atravessa a meia-noite conta em 26/07.
     *
     * @return array<string, int>
     */
    private function countByDate(PatternDataset $dataset, string $metric): array
    {
        $veryHighFloor = $this->clinical->ranges['very_high']['min'];
        $targetFloor = $this->clinical->ranges['target']['min'];

        $counts = [];

        foreach ($dataset->series->readings as $reading) {
            $matches = match ($metric) {
                'above_250' => $reading->mgdl >= $veryHighFloor,
                'below_70' => $reading->mgdl < $targetFloor,
            };

            if ($matches) {
                $date = $reading->at->format('Y-m-d');
                $counts[$date] = ($counts[$date] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @return list<string> */
    private function datesInSeries(PatternDataset $dataset): array
    {
        $dates = [];

        foreach ($dataset->series->readings as $reading) {
            $dates[$reading->at->format('Y-m-d')] = true;
        }

        return array_keys($dates);
    }

    /**
     * Leituras × intervalo do sensor.
     *
     * ⚠️ É uma **aproximação**, e o número de leituras fica na evidência ao lado
     * para quem quiser conferir. Minutos medidos exigiriam olhar o intervalo real
     * entre leituras consecutivas, que é o que o `Episode` já faz — e ali a
     * duração é medida, não estimada.
     */
    private function toMinutes(int $readings): int
    {
        return $readings * $this->clinical->sensor['interval_minutes'];
    }
}

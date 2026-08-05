<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Rules;

use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rule;
use App\Domain\Patterns\Value\Daypart;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;

/**
 * R6 — Coerência entre configuração e resultado (FR-408).
 *
 * *No export de referência:* CR de **5,278 g/U de manhã** (n=18), **6,000 à tarde**
 * (n=17) e **8,000 à noite** (n=17) — enfraquecendo ao longo do dia — enquanto o
 * tempo acima da faixa vai de 9,44% para 22,62%.
 *
 * ## ⚠️ A regra que mais se aproxima de conduta médica
 *
 * Ela **descreve a observação e devolve a pergunta ao médico**. Nunca propõe valor
 * novo de CR, basal ou ISF. Isto é o Artigo VI, camada 3, e é imposto em três
 * lugares — não em um:
 *
 * 1. `RuleId::CarbRatioCoherence->requiresClinicalHandoff()` é `true`, e o
 *    construtor de `Finding` **recusa** um achado desta regra sem o encaminhamento;
 * 2. a prosa em `lang/` termina no encaminhamento, por escrito;
 * 3. um teste varre a prosa procurando verbo de conduta, com autoteste provando
 *    que a varredura funciona.
 *
 * Um único mecanismo seria contornável por descuido. Três não.
 *
 * ## O que "CR mais fraco" quer dizer
 *
 * Razão de carboidrato é **gramas por unidade**: 5 g/U significa 1 unidade para
 * cada 5 gramas; 8 g/U significa 1 unidade para cada 8 gramas. Número **maior**
 * = **menos** insulina por grama = razão mais fraca. É contraintuitivo, e é a
 * primeira coisa que a prosa precisa deixar clara.
 *
 * ## Média ponderada por refeição, e não por hora
 *
 * A hora 06h do export tem CR de 10,0 g/U com **uma** refeição. Ponderando por
 * refeição, ela move a manhã de 5,000 para 5,278 — 0,278 g/U — e a tendência
 * 5 → 6 → 8 sobrevive. Ponderar por hora daria peso igual a uma hora com 1
 * refeição e a uma com 7, e o outlier passaria a dominar o período.
 *
 * O que `min_boluses_per_daypart` de fato exclui é a **madrugada, com zero
 * refeições**: não há CR a comparar num período em que a pessoa não comeu.
 */
final class R6CarbRatioCoherence implements Rule
{
    public function __construct(
        private readonly PatternsConfig $config,
        private readonly ProseRenderer $prose,
    ) {}

    public function id(): RuleId
    {
        return RuleId::CarbRatioCoherence;
    }

    public function evaluate(PatternDataset $dataset): array
    {
        $minimumMeals = $this->config->threshold($this->id(), 'min_boluses_per_daypart');
        $eligible = [];

        foreach (Daypart::cases() as $daypart) {
            $profile = $this->profileFor($dataset, $daypart);

            if ($profile !== null && $profile['meals'] >= $minimumMeals) {
                $eligible[$daypart->value] = $profile;
            }
        }

        if (count($eligible) < 2) {
            return [];
        }

        $strongest = null;   // menor g/U = MAIS insulina por grama
        $weakest = null;     // maior g/U = MENOS insulina por grama

        foreach ($eligible as $profile) {
            if ($strongest === null || $profile['carb_ratio'] < $strongest['carb_ratio']) {
                $strongest = $profile;
            }

            if ($weakest === null || $profile['carb_ratio'] > $weakest['carb_ratio']) {
                $weakest = $profile;
            }
        }

        $spread = $weakest['carb_ratio'] - $strongest['carb_ratio'];

        // Variação irrelevante não sustenta observação: 5,0 contra 5,5 g/U é
        // ruído de configuração, não padrão.
        if ($spread < $this->config->threshold($this->id(), 'min_ratio_spread_g')) {
            return [];
        }

        // ⚠️ A coerência é a CONDIÇÃO da regra, não uma conclusão dela. Se o
        // período de CR mais fraco tem MENOS tempo alto, não há observação a
        // fazer — e afirmá-la assim mesmo seria inventar correlação.
        if ($weakest['percent_above'] <= $strongest['percent_above']) {
            return [];
        }

        $evidence = $this->evidenceFor($eligible, $strongest, $weakest, $spread);

        return [new Finding(
            ruleId: $this->id(),
            // Nunca `Priority`: não é risco, é observação para levar ao médico.
            severity: Severity::Attention,
            evidence: $evidence,
            fallbackProse: $this->prose->render($this->id(), 'prose', $evidence),
            // Redundante com o enum de propósito — quem lê a regra vê a decisão
            // sem ter de abrir outro arquivo.
            requiresClinicalHandoff: true,
        )];
    }

    /**
     * CR médio do período, ponderado por refeição, com o tempo-acima ao lado.
     *
     * @return array{daypart: string, carb_ratio: float, meals: int, percent_above: float, readings: int}|null
     */
    private function profileFor(PatternDataset $dataset, Daypart $daypart): ?array
    {
        $stats = $dataset->daypart($daypart);
        $bounds = $this->hoursOf($daypart);

        $ratios = [];

        foreach ($dataset->meals as $meal) {
            if ($meal->carbRatio !== null && in_array($meal->hour(), $bounds, true)) {
                $ratios[] = $meal->carbRatio;
            }
        }

        if ($ratios === []) {
            return null;
        }

        return [
            'daypart' => $daypart->value,
            'carb_ratio' => array_sum($ratios) / count($ratios),
            'meals' => count($ratios),
            'percent_above' => $stats->percentAbove(),
            'readings' => $stats->count,
        ];
    }

    /**
     * As horas de um período.
     *
     * ⚠️ Derivadas da mesma fonte que o `DaypartAggregator` usou para montar
     * `$dataset->dayparts` — o enum tem quatro casos de 6 h em ordem, então a
     * hora inicial é `índice × 6`. Não é hardcode disfarçado: é a consequência
     * do §D6, que fixa blocos **iguais** de 6 h. Se um dia os blocos deixarem de
     * ser iguais, o `DaypartStats` precisará carregar as próprias horas.
     *
     * @return list<int>
     */
    private function hoursOf(Daypart $daypart): array
    {
        $index = array_search($daypart, Daypart::cases(), true);
        $start = (int) $index * 6;

        return range($start, $start + 5);
    }

    /**
     * @param  array<string, array{daypart: string, carb_ratio: float, meals: int, percent_above: float, readings: int}>  $eligible
     * @return array<string, int|float|string|bool|null>
     */
    private function evidenceFor(array $eligible, array $strongest, array $weakest, float $spread): array
    {
        $evidence = [
            // ⚠️ Todos os valores são OBSERVADOS. Não existe nesta evidência
            // nenhuma chave que sugira valor novo — e é por isso que a prosa,
            // que só pode citar o que está aqui, também não consegue sugerir.
            'strongest_daypart' => $strongest['daypart'],
            'strongest_carb_ratio' => round($strongest['carb_ratio'], 2),
            'strongest_meals' => $strongest['meals'],
            'strongest_percent_above' => round($strongest['percent_above'], 2),

            'weakest_daypart' => $weakest['daypart'],
            'weakest_carb_ratio' => round($weakest['carb_ratio'], 2),
            'weakest_meals' => $weakest['meals'],
            'weakest_percent_above' => round($weakest['percent_above'], 2),

            'ratio_spread_g' => round($spread, 2),
            'percent_above_difference_pp' => round(
                $weakest['percent_above'] - $strongest['percent_above'],
                2,
            ),
            'dayparts_compared' => count($eligible),
        ];

        foreach ($eligible as $key => $profile) {
            $evidence[$key.'_carb_ratio'] = round($profile['carb_ratio'], 2);
            $evidence[$key.'_meals'] = $profile['meals'];
            $evidence[$key.'_percent_above'] = round($profile['percent_above'], 2);
        }

        return $evidence;
    }
}

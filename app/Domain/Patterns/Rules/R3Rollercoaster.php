<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Rules;

use App\Domain\Metrics\Value\Episode;
use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rule;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\MealPoint;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;
use DateTimeImmutable;

/**
 * R3 — Montanha-russa: hipo → sobrecorreção → hiper (FR-405).
 *
 * *No export de referência:* 25/07, **nadir 55 às 18:06** → **109 g em 3
 * refeições** (18:09, 20:12, 21:26) → **hiperglicemia iniciada às 19:41**, 275 min,
 * pico 324.
 *
 * ## ⚠️ O exemplo canônico do Artigo IV
 *
 * A constituição usa exatamente este achado para mostrar a diferença entre
 * descrever mecanismo e julgar caráter:
 *
 * > ❌ "Você comeu 109 g de carboidrato depois de uma hipoglicemia."
 * > ✅ "Quedas de glicose disparam fome intensa — é reação fisiológica do corpo,
 * >    não falta de controle. Aconteceu 1 vez em 14 dias e custou 4 horas em
 * >    glicose alta."
 *
 * As duas frases carregam o **mesmo número**. A primeira faz alguém desinstalar o
 * app; a segunda explica um mecanismo que a pessoa pode reconhecer em si.
 *
 * ## As três armadilhas
 *
 * **1. A janela conta do NADIR, não do início do episódio.** No export são 10
 * minutos de diferença (17:56 contra 18:06) — e é o nadir o momento em que a fome
 * dispara. `Episode` não carrega o instante do nadir, então a regra o localiza na
 * série.
 *
 * **2. A hiper tem de começar DEPOIS do nadir.** Sem essa ordenação, a regra casa
 * uma hipo com uma hiper anterior e conta a história de trás para frente — com
 * todos os números certos.
 *
 * **3. Uma hipo, um achado.** Duas hipos no mesmo dia com a mesma hiper depois não
 * são dois eventos de montanha-russa. O episódio de hiper é **consumido**, mesmo
 * *claiming* do `BolusLinker` da fase 1.
 */
final class R3Rollercoaster implements Rule
{
    public function __construct(
        private readonly PatternsConfig $config,
        private readonly ProseRenderer $prose,
    ) {}

    public function id(): RuleId
    {
        return RuleId::Rollercoaster;
    }

    public function evaluate(PatternDataset $dataset): array
    {
        $windowHours = $this->config->threshold($this->id(), 'window_hours');
        $carbsThreshold = $this->config->threshold($this->id(), 'carbs_threshold_g');

        $findings = [];
        $claimed = [];

        // Em ordem cronológica: quando duas hipos disputam a mesma hiper, a
        // primeira fica com ela. Qualquer outra escolha dependeria da ordem de
        // chegada dos episódios.
        $episodes = $dataset->hypoEpisodes;
        usort($episodes, fn (Episode $a, Episode $b): int => $a->start <=> $b->start);

        foreach ($episodes as $episode) {
            $nadirAt = $this->nadirInstant($dataset, $episode);

            if ($nadirAt === null) {
                continue;
            }

            $meals = $this->mealsAfter($dataset, $nadirAt, $windowHours);
            $carbs = array_sum(array_map(fn (MealPoint $m): float => $m->carbsG, $meals));

            if ($carbs <= $carbsThreshold) {
                continue;
            }

            $hyper = $this->hyperAfter($dataset, $nadirAt, $meals, $windowHours, $claimed);

            if ($hyper === null) {
                continue;
            }

            // ⚠️ Consumido: uma hipo, um achado.
            $claimed[] = $hyper->start->format('Y-m-d H:i:s');

            $evidence = $this->evidenceFor($episode, $nadirAt, $meals, $carbs, $hyper);

            $findings[] = new Finding(
                ruleId: $this->id(),
                severity: Severity::Attention,
                evidence: $evidence,
                fallbackProse: $this->prose->render($this->id(), 'prose', $evidence),
            );
        }

        return $findings;
    }

    /**
     * O instante da leitura mais baixa do episódio.
     *
     * ⚠️ `Episode` guarda o VALOR do nadir (`extreme`), não o instante. Localizá-lo
     * na série é preferível a alterar um value object da fase 2 que já está
     * conferido contra o gabarito — e é barato, porque a série já está em memória.
     */
    private function nadirInstant(PatternDataset $dataset, Episode $episode): ?DateTimeImmutable
    {
        $lowest = null;

        foreach ($dataset->series->readings as $reading) {
            if ($reading->at < $episode->start) {
                continue;
            }

            if ($reading->at > $episode->end) {
                break;
            }

            if ($lowest === null || $reading->mgdl < $lowest->mgdl) {
                $lowest = $reading;
            }
        }

        return $lowest?->at;
    }

    /**
     * Refeições na janela que começa NO NADIR.
     *
     * @return list<MealPoint>
     */
    private function mealsAfter(PatternDataset $dataset, DateTimeImmutable $nadirAt, int|float $windowHours): array
    {
        $limit = $nadirAt->modify('+'.(int) round($windowHours * 60).' minutes');

        return array_values(array_filter(
            $dataset->meals,
            fn (MealPoint $meal): bool => $meal->at > $nadirAt && $meal->at <= $limit,
        ));
    }

    /**
     * A hiperglicemia que fecha a cadeia.
     *
     * Precisa começar **depois do nadir** e até `window_hours` após a última
     * refeição contada — o que delimita a cadeia inteira: nadir → refeições →
     * subida. Sem o teto, uma hiper de madrugada fecharia com uma hipo da tarde.
     *
     * @param  list<MealPoint>  $meals
     * @param  list<string>  $claimed
     */
    private function hyperAfter(
        PatternDataset $dataset,
        DateTimeImmutable $nadirAt,
        array $meals,
        int|float $windowHours,
        array $claimed,
    ): ?Episode {
        $lastMeal = max(array_map(fn (MealPoint $m): DateTimeImmutable => $m->at, $meals));
        $ceiling = $lastMeal->modify('+'.(int) round($windowHours * 60).' minutes');

        $candidates = array_filter(
            $dataset->hyperEpisodes,
            fn (Episode $e): bool => $e->start > $nadirAt
                && $e->start <= $ceiling
                && ! in_array($e->start->format('Y-m-d H:i:s'), $claimed, true),
        );

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (Episode $a, Episode $b): int => $a->start <=> $b->start);

        return $candidates[0];
    }

    /**
     * @param  list<MealPoint>  $meals
     * @return array<string, int|float|string|bool|null>
     */
    private function evidenceFor(
        Episode $episode,
        DateTimeImmutable $nadirAt,
        array $meals,
        float $carbs,
        Episode $hyper,
    ): array {
        $instants = array_map(fn (MealPoint $m): DateTimeImmutable => $m->at, $meals);
        sort($instants);

        return [
            'date' => $nadirAt->format('Y-m-d'),
            'nadir' => $episode->nadir(),
            'nadir_at' => $nadirAt->format('H:i'),
            'hypo_duration_minutes' => (int) round($episode->durationMinutes),

            'meals' => count($meals),
            'carbs_g' => round($carbs, 1),
            'first_meal_at' => $instants[0]->format('H:i'),
            'last_meal_at' => end($instants)->format('H:i'),

            'hyper_start_at' => $hyper->start->format('H:i'),
            'hyper_duration_minutes' => (int) round($hyper->durationMinutes),
            'hyper_duration_hours' => round($hyper->durationMinutes / 60, 1),
            'hyper_peak' => $hyper->peak(),

            // Do nadir ao início da subida — o intervalo que a pessoa viveu.
            'minutes_from_nadir_to_hyper' => (int) round(
                ($hyper->start->getTimestamp() - $nadirAt->getTimestamp()) / 60
            ),
        ];
    }
}

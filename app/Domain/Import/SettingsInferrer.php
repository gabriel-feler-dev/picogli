<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Import\Value\Events\BasalRateEvent;
use App\Domain\Import\Value\Events\MealEvent;
use App\Domain\Import\Value\InferredSettings;

/**
 * Reconstrói o perfil do aparelho a partir dos bolus (FR-008).
 *
 * O relatório "Definições do dispositivo" não existe no CSV — é o único item do
 * conjunto de relatórios do CareLink que o export não cobre. Mas as colunas
 * `BWZ Carb Ratio` e `BWZ Insulin Sensitivity` gravam a configuração vigente no
 * instante de cada bolus, e isso reconstrói o perfil por horário.
 *
 * No export de referência sai exatamente:
 *
 *   06h → 10 g/U · 07–11h → 5 g/U · 12–16h → 6 g/U · 18–22h → 8 g/U
 *   ISF: {20, 25, 30} mg/dL/U
 *
 * Isso alimenta a regra R6 da Spec 004, que cruza o perfil configurado com o
 * tempo acima da faixa em cada período — e devolve a pergunta ao médico, nunca
 * um valor novo (Artigo VI).
 */
final class SettingsInferrer
{
    /**
     * @param  list<MealEvent>  $meals
     * @param  list<BasalRateEvent>  $basalRates
     */
    public function infer(array $meals, array $basalRates = []): InferredSettings
    {
        $meals = $this->sortedByTime($meals);

        $carbRatio = [];
        $seenRatios = [];
        $isf = [];

        foreach ($meals as $meal) {
            $hour = (int) $meal->recordedAtLocal->format('G');

            if ($meal->carbRatio !== null) {
                // O MAIS RECENTE vence: se o perfil mudou no meio do período, o
                // valor atual é o que interessa. Como a lista está ordenada,
                // sobrescrever basta.
                $carbRatio[$hour] = $meal->carbRatio;
                $seenRatios[$hour][$meal->carbRatio] = true;
            }

            if ($meal->insulinSensitivity !== null) {
                $isf[(string) $meal->insulinSensitivity] = $meal->insulinSensitivity;
            }
        }

        ksort($carbRatio);

        $isf = array_values($isf);
        sort($isf);

        return new InferredSettings(
            carbRatioProfile: $carbRatio,
            isfValues: $isf,
            basalProfile: $this->basalProfile($basalRates),
            conflicts: $this->conflictingHours($seenRatios),
        );
    }

    /**
     * Perfil basal por hora, **excluindo taxas de 0 U/h**.
     *
     * ⚠️ Um perfil basal programado não tem faixa de 0 U/h. Os zeros no arquivo
     * vêm de suspensão da bomba e de transições do loop fechado — são eventos,
     * não configuração. Incluí-los faria o perfil reconstruído mostrar basal
     * zerada em horas onde ela não é zero, e qualquer leitura disso estaria
     * errada.
     *
     * Sem os zeros, o export de referência devolve exatamente as taxas
     * observadas: 1,65 · 1,7 · 2,0 · 2,1 · 2,2 U/h.
     *
     * @param  list<BasalRateEvent>  $basalRates
     * @return array<int, float>|null
     */
    private function basalProfile(array $basalRates): ?array
    {
        $profile = [];

        foreach ($this->sortedByTime($basalRates) as $rate) {
            if ($rate->rateUh <= 0.0) {
                continue;
            }

            $profile[(int) $rate->recordedAtLocal->format('G')] = $rate->rateUh;
        }

        if ($profile === []) {
            return null;
        }

        ksort($profile);

        return $profile;
    }

    /**
     * Horas em que a razão de carboidrato mudou dentro do período.
     *
     * Não é erro — o perfil pode ter sido ajustado pelo médico no meio das duas
     * semanas. Mas é informação: um snapshot que representa duas configurações
     * diferentes precisa ser lido com essa ressalva.
     *
     * @param  array<int, array<string, true>>  $seenRatios
     * @return list<string>
     */
    private function conflictingHours(array $seenRatios): array
    {
        $conflicts = [];

        foreach ($seenRatios as $hour => $values) {
            if (count($values) > 1) {
                $conflicts[] = sprintf(
                    '%02dh teve razões diferentes no período: %s g/U',
                    $hour,
                    implode(', ', array_keys($values)),
                );
            }
        }

        return $conflicts;
    }

    /**
     * @template T of MealEvent|BasalRateEvent
     *
     * @param  list<T>  $events
     * @return list<T>
     */
    private function sortedByTime(array $events): array
    {
        usort($events, fn ($a, $b): int => $a->recordedAtLocal <=> $b->recordedAtLocal);

        return $events;
    }
}

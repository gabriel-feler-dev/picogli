<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Import\Value\Events\BolusDeliveryEvent;
use App\Domain\Import\Value\Events\BolusRequestEvent;
use App\Domain\Import\Value\Events\MealEvent;
use App\Domain\Import\Value\LinkedDose;
use DateTimeImmutable;

/**
 * Resolve o trio pedido / wizard / entrega de um bolus (§A3).
 *
 * O CSV representa um bolus com wizard em até três linhas:
 *
 *   (11:49:09)  Selected=8,0   Delivered=(vazio)  Bolus Number=85   ← pedido
 *   (11:49:09)  BWZ Carb Input=40  BG Input=162   (SEM Bolus Number) ← wizard
 *   (11:54:31)  Selected=8,0   Delivered=8,0      Bolus Number=85    ← entrega
 *
 * Duas junções, com chaves DIFERENTES:
 *   - pedido ↔ entrega  por `Bolus Number`
 *   - pedido ↔ refeição por **proximidade temporal** (≤5s; a linha BWZ não tem número)
 *
 * ⚠️ Artigo VIII.3 — `units_selected` NUNCA entra em soma de insulina. Esta
 * classe preserva os dois valores lado a lado justamente para que a diferença
 * seja visível (entrega parcial), mas somar os dois dobra o total: no export de
 * referência, 295,150 U viram 590,300 U.
 */
final class BolusLinker
{
    /**
     * Janela máxima entre pedido e entrega do mesmo bolus.
     *
     * ⚠️ §A9 — `Bolus Number` é um contador de 1 byte que CICLA. No export de
     * referência ele varia de 6 a 255, e o número 214 reaparece três vezes
     * (19/07, 22/07 e 27/07). Indexar entregas por número globalmente casaria
     * um pedido do dia 19 com uma entrega do dia 27.
     *
     * Por isso a junção é por **número + proximidade temporal**. Intervalos
     * medidos no arquivo real: mínimo 44s, mediana 215s, máximo 587s (~9,8 min).
     *
     * 30 minutos dá ~3× de folga sobre o máximo observado e é ~140× menor que o
     * menor intervalo de reuso do número (~3 dias). Bolus estendido (square ou
     * dual) pode passar disso; nesse caso o pedido fica sem par e um aviso é
     * emitido, em vez de casar errado em silêncio.
     */
    private const MAX_DELIVERY_DELAY_SECONDS = 1800;

    /**
     * Tolerância entre a linha BWZ (refeição) e a linha do pedido.
     *
     * ⚠️ A versão inicial exigia timestamp IDÊNTICO, e isso deixava 2 das 52
     * refeições do export de referência sem vínculo. Medido no arquivo: em 50
     * casos o par compartilha o segundo cravado; em 2 a linha BWZ vem **1
     * segundo antes** do pedido — a calculadora termina e o pedido é registrado
     * no segundo seguinte. É a mesma ação do usuário.
     *
     * 5 segundos cobre isso com folga e continua ordens de grandeza abaixo do
     * intervalo entre refeições distintas (horas). Empate resolvido pelo mais
     * próximo; nunca "o primeiro que achar".
     */
    private const MEAL_MATCH_TOLERANCE_SECONDS = 5;

    /**
     * @param  list<BolusRequestEvent>  $requests
     * @param  list<BolusDeliveryEvent>  $deliveries
     * @param  list<MealEvent>  $meals
     * @param  null|callable(string): void  $onWarning
     * @return list<LinkedDose>
     */
    public function link(array $requests, array $deliveries, array $meals, ?callable $onWarning = null): array
    {
        $warn = $onWarning ?? static function (string $message): void {};

        $mealPool = $this->sortedMeals($meals, $warn);
        $deliveryQueue = $this->indexDeliveriesByNumber($deliveries, $warn);

        // Ordem cronológica explícita: o CSV vem em ordem DECRESCENTE de tempo,
        // e o pareamento depende de "a entrega vem depois do pedido".
        $requests = $this->sortedChronologically($requests);

        $doses = [];
        $consumed = [];
        $claimedMeals = [];

        foreach ($requests as $request) {
            $delivery = $this->matchDelivery($request, $deliveryQueue, $consumed, $warn);

            if ($delivery !== null) {
                $consumed[spl_object_id($delivery)] = true;
            }

            $doses[] = $this->fromPair(
                $request,
                $delivery,
                $this->claimMealAt(
                    $request->recordedAtLocal,
                    $mealPool,
                    $claimedMeals,
                    $warn,
                    $request->sourceLine,
                    $request->isCancelled(),
                ),
            );
        }

        // Entrega sem pedido é dose VÁLIDA — o pedido pode estar num export
        // anterior, se o período foi cortado no meio de um bolus. Grava com
        // selected = null, e avisa para não passar batido.
        foreach ($deliveryQueue as $number => $candidates) {
            foreach ($candidates as $delivery) {
                if (isset($consumed[spl_object_id($delivery)])) {
                    continue;
                }

                $warn(sprintf(
                    'Entrega de bolus sem pedido correspondente (Bolus Number %d, linha %d)',
                    $number,
                    $delivery->sourceLine,
                ));

                $doses[] = $this->fromDeliveryOnly(
                    $delivery,
                    $this->claimMealAt($delivery->recordedAtLocal, $mealPool, $claimedMeals, $warn, $delivery->sourceLine),
                );
            }
        }

        return $doses;
    }

    /**
     * Acha a entrega do pedido: mesmo `Bolus Number`, posterior ao pedido, mais
     * próxima no tempo, dentro da janela, ainda não consumida.
     *
     * @param  array<int, list<BolusDeliveryEvent>>  $queue
     * @param  array<int, true>  $consumed
     * @param  callable(string): void  $warn
     */
    private function matchDelivery(
        BolusRequestEvent $request,
        array $queue,
        array $consumed,
        callable $warn,
    ): ?BolusDeliveryEvent {
        if ($request->bolusNumber === null) {
            return null;
        }

        $requestedAt = $request->recordedAtLocal->getTimestamp();
        $best = null;
        $bestDelta = null;
        $beyondWindow = null;

        foreach ($queue[$request->bolusNumber] ?? [] as $candidate) {
            if (isset($consumed[spl_object_id($candidate)])) {
                continue;
            }

            $delta = $candidate->recordedAtLocal->getTimestamp() - $requestedAt;

            if ($delta < 0) {
                continue;
            }

            if ($delta > self::MAX_DELIVERY_DELAY_SECONDS) {
                $beyondWindow ??= $candidate;

                continue;
            }

            if ($bestDelta === null || $delta < $bestDelta) {
                $best = $candidate;
                $bestDelta = $delta;
            }
        }

        // Existe entrega com o mesmo número, mas fora da janela. Provável
        // reciclagem do contador — ou bolus estendido. Não casa, e avisa.
        // Bolus cancelado não gera aviso: é esperado que não tenha entrega.
        if ($best === null && $beyondWindow !== null && ! $request->isCancelled()) {
            $warn(sprintf(
                'Bolus Number %d reaparece fora da janela de %d min '
                .'(pedido linha %d, entrega linha %d) — não pareado',
                $request->bolusNumber,
                (int) (self::MAX_DELIVERY_DELAY_SECONDS / 60),
                $request->sourceLine,
                $beyondWindow->sourceLine,
            ));
        }

        return $best;
    }

    /**
     * Ordem cronológica, com desempate: no MESMO instante, pedido não cancelado
     * vem antes do cancelado.
     *
     * ⚠️ §A10 — o desempate não é detalhe. Três dos quatro bolus cancelados do
     * export de referência compartilham o segundo exato com uma refeição: é a
     * sequência "rodou a calculadora → pediu → cancelou → pediu de novo".
     *
     * A refeição pertence à dose que DE FATO entregou insulina. Se o cancelado
     * reivindicasse primeiro, a refeição ficaria pendurada num bolus de 0 U, e
     * toda análise de resposta glicêmica pós-refeição olharia para a dose errada.
     *
     * @param  list<BolusRequestEvent>  $requests
     * @return list<BolusRequestEvent>
     */
    private function sortedChronologically(array $requests): array
    {
        usort($requests, function (BolusRequestEvent $a, BolusRequestEvent $b): int {
            return [$a->recordedAtLocal, $a->isCancelled()]
                <=> [$b->recordedAtLocal, $b->isCancelled()];
        });

        return $requests;
    }

    private function fromPair(
        BolusRequestEvent $request,
        ?BolusDeliveryEvent $delivery,
        ?MealEvent $meal,
    ): LinkedDose {
        return new LinkedDose(
            recordedAtLocal: $request->recordedAtLocal,
            kind: $this->kindFrom($request->bolusType),
            rawSource: $request->rawSource ?? $delivery?->rawSource,
            isAutomatic: $this->isAutomatic($request->rawSource ?? $delivery?->rawSource),
            unitsSelected: $request->unitsSelected ?? $delivery?->unitsSelected,
            unitsDelivered: $delivery?->unitsDelivered,
            bolusNumber: $request->bolusNumber,
            cancellationReason: $request->cancellationReason,
            deliveredAtLocal: $delivery?->recordedAtLocal,
            meal: $meal,
            sourceLine: $request->sourceLine,
        );
    }

    private function fromDeliveryOnly(BolusDeliveryEvent $delivery, ?MealEvent $meal): LinkedDose
    {
        return new LinkedDose(
            recordedAtLocal: $delivery->recordedAtLocal,
            kind: $this->kindFrom($delivery->bolusType),
            rawSource: $delivery->rawSource,
            isAutomatic: $this->isAutomatic($delivery->rawSource),
            unitsSelected: $delivery->unitsSelected,
            unitsDelivered: $delivery->unitsDelivered,
            bolusNumber: $delivery->bolusNumber,
            cancellationReason: null,
            deliveredAtLocal: $delivery->recordedAtLocal,
            meal: $meal,
            sourceLine: $delivery->sourceLine,
        );
    }

    /**
     * Refeições em ordem cronológica, prontas para casamento por proximidade.
     *
     * @param  list<MealEvent>  $meals
     * @param  callable(string): void  $warn
     * @return list<MealEvent>
     */
    private function sortedMeals(array $meals, callable $warn): array
    {
        $seen = [];

        foreach ($meals as $meal) {
            $key = $this->instantKey($meal->recordedAtLocal);

            if (isset($seen[$key])) {
                $warn(sprintf(
                    'Duas refeições no mesmo instante (%s, linhas %d e %d) — vínculo com bolus é ambíguo',
                    $key,
                    $seen[$key],
                    $meal->sourceLine,
                ));
            }

            $seen[$key] = $meal->sourceLine;
        }

        usort(
            $meals,
            fn (MealEvent $a, MealEvent $b): int => $a->recordedAtLocal <=> $b->recordedAtLocal,
        );

        return $meals;
    }

    /**
     * Agrupa entregas por `Bolus Number`, cada grupo em ordem cronológica.
     *
     * ⚠️ Um número pode ter VÁRIAS entregas ao longo do período — o contador
     * cicla em 255 (§A9). Guardar apenas a primeira, como uma versão anterior
     * desta classe fazia, descartaria entregas reais de insulina. Por isso
     * `array<int, list<...>>` e não `array<int, ...>`.
     *
     * @param  list<BolusDeliveryEvent>  $deliveries
     * @param  callable(string): void  $warn
     * @return array<int, list<BolusDeliveryEvent>>
     */
    private function indexDeliveriesByNumber(array $deliveries, callable $warn): array
    {
        $index = [];

        foreach ($deliveries as $delivery) {
            if ($delivery->bolusNumber === null) {
                $warn(sprintf(
                    'Entrega de bolus sem Bolus Number (linha %d) — não é possível ligar ao pedido',
                    $delivery->sourceLine,
                ));

                continue;
            }

            $index[$delivery->bolusNumber][] = $delivery;
        }

        foreach ($index as &$candidates) {
            usort(
                $candidates,
                fn (BolusDeliveryEvent $a, BolusDeliveryEvent $b): int => $a->recordedAtLocal <=> $b->recordedAtLocal,
            );
        }

        return $index;
    }

    /**
     * Reivindica a refeição mais próxima daquele instante — no máximo UMA vez.
     *
     * ⚠️ Uma refeição pertence a uma dose só. Sem consumo, dois pedidos no mesmo
     * segundo apontariam para o MESMO `MealEvent`, e o carboidrato seria contado
     * duas vezes em qualquer análise de resposta glicêmica. Acontece de verdade
     * no export de referência (3 casos, §A10).
     *
     * Escolhe sempre o menor |delta| dentro de MEAL_MATCH_TOLERANCE_SECONDS,
     * nunca o primeiro encontrado — com dois candidatos, proximidade é o único
     * critério defensável.
     *
     * @param  list<MealEvent>  $pool
     * @param  array<int, true>  $claimed
     * @param  callable(string): void  $warn
     */
    private function claimMealAt(
        DateTimeImmutable $at,
        array $pool,
        array &$claimed,
        callable $warn,
        int $sourceLine,
        bool $isCancelled = false,
    ): ?MealEvent {
        $target = $at->getTimestamp();
        $best = null;
        $bestDelta = null;
        $blockedByClaim = null;

        foreach ($pool as $meal) {
            $delta = abs($meal->recordedAtLocal->getTimestamp() - $target);

            if ($delta > self::MEAL_MATCH_TOLERANCE_SECONDS) {
                continue;
            }

            if (isset($claimed[spl_object_id($meal)])) {
                if ($blockedByClaim === null || $delta < $blockedByClaim[1]) {
                    $blockedByClaim = [$meal, $delta];
                }

                continue;
            }

            if ($bestDelta === null || $delta < $bestDelta) {
                $best = $meal;
                $bestDelta = $delta;
            }
        }

        if ($best !== null) {
            $claimed[spl_object_id($best)] = true;

            return $best;
        }

        // Havia refeição por perto, mas já pertence a outra dose.
        // Cancelamento perdendo para a dose entregue é o padrão esperado
        // (§A10), não anomalia — não gera aviso.
        if ($blockedByClaim !== null && ! $isCancelled) {
            $warn(sprintf(
                'Refeição de %s (linha %d) já vinculada a outra dose; '
                .'dose da linha %d fica sem refeição',
                $this->instantKey($blockedByClaim[0]->recordedAtLocal),
                $blockedByClaim[0]->sourceLine,
                $sourceLine,
            ));
        }

        return null;
    }

    private function instantKey(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }

    /**
     * `Bolus Type` do CSV → `kind` do banco.
     *
     * Tipo desconhecido é preservado em forma normalizada em vez de virar
     * `bolus_normal` por padrão: um bolus quadrado classificado como normal
     * distorceria cálculo de insulina ativa, e em silêncio.
     */
    private function kindFrom(?string $bolusType): string
    {
        if ($bolusType === null) {
            return 'bolus_unknown';
        }

        return match (true) {
            str_starts_with($bolusType, 'Normal') => 'bolus_normal',
            str_starts_with($bolusType, 'Square') => 'bolus_square',
            str_starts_with($bolusType, 'Dual') => 'bolus_dual',
            default => 'bolus_'.strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $bolusType) ?? 'unknown'),
        };
    }

    /**
     * Automática = decidida pela bomba, sem ação do usuário.
     *
     * ⚠️ `CLOSED_LOOP_BG_CORRECTION_AND_FOOD_BOLUS` NÃO é automática, apesar do
     * "CLOSED_LOOP" no nome. É um bolus de refeição que o usuário pediu enquanto
     * o SmartGuard estava ativo. O que é automático é `CLOSED_LOOP_AUTO_INSULIN`
     * — a entrega própria da bomba, que vem agregada por dia no bloco 2.
     *
     * Confundir os dois faria 60% da insulina do usuário parecer decisão dele,
     * ou o contrário — e é o tipo de erro que nenhum teste pega por acidente.
     */
    private function isAutomatic(?string $rawSource): bool
    {
        return $rawSource !== null && str_contains($rawSource, 'AUTO_INSULIN');
    }
}

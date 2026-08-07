<?php

declare(strict_types=1);

namespace App\Domain\Presentation;

use App\Domain\Presentation\Value\MealGroup;
use App\Models\Meal;
use App\Models\SensorReading;
use DateTimeImmutable;
use Illuminate\Support\Collection;

/**
 * A tela de refeições (Spec 007, FR-702, §10.5).
 *
 * ⚠️ **LÊ as colunas. Não recalcula nada** (§D1).
 *
 * `peak_2h`, `delta_2h` e `glucose_4h` são preenchidos na importação pelo
 * `MealEnricher` (passo 8 do pipeline, `PicoGli.md` §6.1). A fase 6 tropeçou
 * exatamente aqui: a `MealsTool` nasceu recalculando, e o resultado divergia de
 * propósito, porque o enricher parte de `bg_input` — a glicose que a calculadora
 * da bomba usou, e que a pessoa **viu na tela**.
 *
 * Se esta classe calculasse a resposta glicêmica, a tela de refeições e o chat
 * discordariam sobre a mesma refeição.
 *
 * ⚠️ Esta classe toca banco — é BORDA, como `Persistence/`, não domínio puro. O
 * que é puro é o `MealGroup`.
 */
final class MealsPresenter
{
    /**
     * Últimos N dias com leitura — o mesmo recorte do dashboard.
     *
     * ⚠️ Ancorado na **última leitura do usuário**, não em `now()`. Quem importa
     * um export de duas semanas atrás quer ver aquele período, não uma tela vazia.
     * Mesma decisão do `DashboardPresenter` e do `PatternDatasetBuilder`.
     *
     * @return array<string, mixed>
     */
    public function forLatestPeriod(int $userId, int $days = 14): array
    {
        $ultima = SensorReading::where('user_id', $userId)->max('local_date');

        if ($ultima === null) {
            return $this->empty();
        }

        $to = substr((string) $ultima, 0, 10);
        $from = (new DateTimeImmutable($to))->modify('-'.($days - 1).' days')->format('Y-m-d');

        return $this->forPeriod($userId, $from, $to);
    }

    /** @return array<string, mixed> */
    public function forPeriod(int $userId, string $from, string $to): array
    {
        $refeicoes = Meal::where('user_id', $userId)
            ->whereBetween('local_date', [$from, $to])
            ->orderByDesc('recorded_at_local')
            ->get();

        return [
            'period' => ['from' => $from, 'to' => $to],
            'meals' => $refeicoes->map(fn (Meal $m): array => $this->row($m))->all(),
            'groups' => $this->groupsFrom($refeicoes),
            'meal_count' => $refeicoes->count(),
            'labelled_count' => $refeicoes->filter(
                fn (Meal $m): bool => $m->label !== null && trim((string) $m->label) !== ''
            )->count(),
        ];
    }

    /**
     * Uma refeição, como a tela a recebe.
     *
     * ⚠️ Todo número aqui vem de coluna. `null` é estado normal: refeição sem
     * leitura de sensor por perto não tem resposta glicêmica apurável, e inventar
     * uma seria pior que não mostrar.
     *
     * @return array<string, mixed>
     */
    private function row(Meal $meal): array
    {
        return [
            'id' => $meal->id,
            'at' => $meal->recorded_at_local->format('Y-m-d H:i'),
            'local_date' => (string) $meal->local_date,
            'label' => $meal->label,
            'carbs_g' => $meal->carbs_g === null ? null : round((float) $meal->carbs_g, 1),
            'carb_ratio' => $meal->carb_ratio === null ? null : round((float) $meal->carb_ratio, 1),
            // A glicose que a calculadora usou para dosar — é ela que explica a
            // decisão do bolus, e é o número que a pessoa viu.
            'bg_input' => $meal->bg_input,
            'peak_2h' => $meal->peak_2h,
            'delta_2h' => $meal->delta_2h,
            'glucose_4h' => $meal->glucose_4h,
            'has_response' => $meal->hasGlycemicResponse(),
        ];
    }

    /**
     * Agrupa por rótulo, ordenado pela maior subida média.
     *
     * ⚠️ **Refeição sem rótulo não entra em grupo.** Ela aparece na lista — o
     * agrupamento é sobre o que a pessoa nomeou, e um grupo "sem rótulo" com 38
     * refeições diria menos que a lista já diz.
     *
     * ⚠️ A média de `delta_2h` usa só as refeições **com resposta apurada**, e o
     * grupo carrega as duas contagens. Dividir por todas trataria `null` como
     * zero, e "pizza sobe 41 mg/dL" quando metade não tem leitura é número errado
     * com aparência de medição.
     *
     * @param  Collection<int, Meal>  $refeicoes
     * @return list<array<string, mixed>>
     */
    private function groupsFrom($refeicoes): array
    {
        $grupos = [];

        foreach ($refeicoes as $refeicao) {
            $rotulo = trim((string) ($refeicao->label ?? ''));

            if ($rotulo === '') {
                continue;
            }

            // ⚠️ Agrupa por rótulo em minúsculas: "Pizza" e "pizza" são a mesma
            // comida, e exigir grafia idêntica faria a pessoa manter dois grupos
            // sem entender por quê.
            $chave = mb_strtolower($rotulo);

            // ⚠️ **A grafia exibida é a da refeição MAIS RECENTE** — a lista vem
            // em ordem decrescente, então a primeira vista é a última digitada.
            // É a escolha certa: se a pessoa passou a escrever "Pizza", é assim
            // que ela quer ver. A alternativa (a mais antiga) congelaria um
            // typo do primeiro registro para sempre.
            $grupos[$chave] ??= ['label' => $rotulo, 'meals' => 0, 'deltas' => [], 'carbs' => []];
            $grupos[$chave]['meals']++;

            if ($refeicao->delta_2h !== null) {
                $grupos[$chave]['deltas'][] = (float) $refeicao->delta_2h;
            }

            if ($refeicao->carbs_g !== null) {
                $grupos[$chave]['carbs'][] = (float) $refeicao->carbs_g;
            }
        }

        $montados = [];

        foreach ($grupos as $grupo) {
            $montados[] = (new MealGroup(
                label: $grupo['label'],
                mealCount: $grupo['meals'],
                meanDelta2h: $this->mean($grupo['deltas']),
                meanCarbsG: $this->mean($grupo['carbs']),
                withResponseCount: count($grupo['deltas']),
            ))->toArray();
        }

        // Maior subida primeiro. Grupo sem resposta apurada vai para o fim: não
        // há como ordená-lo por algo que não foi medido.
        usort($montados, fn (array $a, array $b): int => ($b['mean_delta_2h'] ?? -INF) <=> ($a['mean_delta_2h'] ?? -INF));

        return $montados;
    }

    /** @param list<float> $valores */
    private function mean(array $valores): ?float
    {
        return $valores === [] ? null : round(array_sum($valores) / count($valores), 1);
    }

    /** @return array<string, mixed> */
    private function empty(): array
    {
        $hoje = (new DateTimeImmutable)->format('Y-m-d');

        return [
            'period' => ['from' => $hoje, 'to' => $hoje],
            'meals' => [],
            'groups' => [],
            'meal_count' => 0,
            'labelled_count' => 0,
        ];
    }
}

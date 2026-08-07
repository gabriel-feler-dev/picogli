<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;
use App\Models\Meal;

/**
 * `get_meals` — carboidrato declarado e o que a glicose fez depois (FR-602).
 *
 * ## ⚠️ LÊ a resposta glicêmica, não a recalcula
 *
 * `peak_2h`, `delta_2h` e `glucose_4h` são colunas de `meals`, preenchidas na
 * importação pelo `MealEnricher` (passo 8 do pipeline, `PicoGli.md` §6.1). Esta
 * ferramenta **lê**.
 *
 * ⚠️ **A primeira versão desta classe recalculava, e estava errada** — não por
 * bug de aritmética, mas por ter criado a segunda fonte de verdade que o §D1
 * existe para impedir. E os dois cálculos divergiam de propósito:
 *
 * | | Linha de partida do delta |
 * |---|---|
 * | `MealEnricher` (fase 1) | **`bg_input`** — a glicose que a calculadora usou |
 * | esta ferramenta, antes | leitura de sensor mais próxima, ±10 min |
 *
 * A escolha do `bg_input` é decisão registrada da fase 1: é o número que a pessoa
 * **viu na tela da bomba**, e é ele que explica a decisão do bolus. O comentário
 * de lá diz por quê — *"meu app discorda da minha bomba" é a forma mais rápida de
 * perder confiança*. Uma ferramenta de chat que devolvesse um delta diferente do
 * que a tela de refeições mostra produziria exatamente esse desacordo, com o
 * agravante de ser o modelo dizendo o número.
 *
 * ⚠️ **E não carrega a série.** Ler colunas prontas dispensa trazer 3.616
 * leituras para a memória para responder sobre 40 refeições.
 *
 * ## Artigo VI, e este é o caso mais escorregadio da fase
 *
 * `carb_ratio` é a razão **vigente** reconstruída do histórico da bomba —
 * descrição do que estava configurado, nunca proposta de valor novo. Nenhum campo
 * aqui compara o CR com o resultado e sugere outro número; quando um padrão
 * aponta nessa direção, quem responde é a R6, e ela termina devolvendo a pergunta
 * ao médico.
 */
final class MealsTool extends PeriodTool
{
    public function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            name: 'get_meals',
            description: 'Refeições registradas no bolus wizard num período: carboidrato '
                .'declarado, o rótulo que o usuário deu à refeição (quando houver), razão de '
                .'carboidrato vigente na bomba, glicose que a calculadora '
                .'usou, pico glicêmico nas 2 horas seguintes, a subida em relação à partida e '
                .'onde a glicose estava 4 horas depois. Aceita filtrar por carboidrato mínimo. '
                .'Use para "como reagi ao almoço", "quais refeições me subiram mais".',
            argumentSchema: array_merge(self::PERIOD_SCHEMA, [
                'min_carbs' => [
                    'type' => 'int',
                    'required' => false,
                    'min' => 0,
                    'max' => 500,
                ],
            ]),
            emittedKeys: array_merge(self::PERIOD_KEYS, [
                'meal_count', 'min_carbs', 'total_carbs_g', 'rows',
                'at', 'carbs_g', 'carb_ratio', 'label',
                'bg_input', 'peak_2h', 'delta_2h', 'glucose_4h',
            ]),
        );
    }

    public function run(array $args, ChatScope $scope): ToolResult
    {
        [$from, $to] = $this->window($args);
        $minimo = isset($args['min_carbs']) ? (float) $args['min_carbs'] : null;

        $query = Meal::where('user_id', $scope->userId)
            ->whereBetween('local_date', [$from, $to]);

        if ($minimo !== null) {
            $query->where('carbs_g', '>=', $minimo);
        }

        $rows = [];
        $somaCarbs = 0.0;

        foreach ($query->orderBy('recorded_at_local')->get() as $refeicao) {
            $somaCarbs += (float) $refeicao->carbs_g;

            $rows[] = [
                'at' => $refeicao->recorded_at_local->format('Y-m-d H:i'),
                // ⚠️ Rótulo do usuário (Spec 007). Entra em `emittedKeys`, e a
                // allowlist do Artigo VII é DERIVADA dos descritores — a chave
                // passa a ser permitida sozinha, sem editar config.
                'label' => $refeicao->label,
                'carbs_g' => $this->round((float) $refeicao->carbs_g),
                'carb_ratio' => $refeicao->carb_ratio === null
                    ? null
                    : $this->round((float) $refeicao->carb_ratio),
                // ⚠️ Tudo daqui para baixo vem de coluna, não de cálculo. `null`
                // é estado normal: refeição sem leitura de sensor por perto não
                // tem resposta glicêmica apurável, e inventar uma seria pior.
                'bg_input' => $refeicao->bg_input,
                'peak_2h' => $refeicao->peak_2h,
                'delta_2h' => $refeicao->delta_2h,
                'glucose_4h' => $refeicao->glucose_4h,
            ];
        }

        return ToolResult::ok('get_meals', $args, $this->envelope($from, $to, [
            'meal_count' => count($rows),
            'min_carbs' => $minimo === null ? null : (int) $minimo,
            'total_carbs_g' => $this->round($somaCarbs),
            'rows' => $rows,
        ]));
    }
}

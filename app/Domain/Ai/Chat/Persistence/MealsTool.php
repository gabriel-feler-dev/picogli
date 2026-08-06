<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Models\Meal;
use DateTimeImmutable;

/**
 * `get_meals` — carboidrato declarado e o que a glicose fez depois (FR-602).
 *
 * ⚠️ **A resposta de 2 h é calculada aqui, não descrita para o modelo somar.**
 * Ele receberia "glicose inicial 118, leituras seguintes: 132, 154, 171…" e
 * teria de achar o pico — isto é, calcular. O Artigo I proíbe, e a aritmética
 * sobre listas longas é justamente onde modelos erram com naturalidade.
 *
 * ⚠️ **Artigo VI, e este é o caso mais escorregadio da fase.** O campo
 * `carb_ratio` é a razão **vigente** reconstruída do histórico da bomba — é
 * descrição do que estava configurado, nunca proposta de valor novo. Nenhum
 * campo aqui compara o CR com o resultado e sugere outro número; quando um
 * padrão aponta nessa direção, quem responde é a R6, e ela termina devolvendo a
 * pergunta ao médico.
 *
 * ⚠️ **Janela de ±10 min para a glicose inicial.** O sensor mede de 5 em 5
 * minutos; se a leitura mais próxima da refeição está a mais de 10 minutos, não
 * existe glicose inicial confiável e o campo sai `null` — em vez de um número
 * que parece medido e é chute.
 */
final class MealsTool extends PeriodTool
{
    private const START_WINDOW_MINUTES = 10;

    private const RESPONSE_WINDOW_MINUTES = 120;

    public function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            name: 'get_meals',
            description: 'Refeições registradas no bolus wizard num período: carboidrato '
                .'declarado, razão de carboidrato vigente na bomba, glicose no momento da '
                .'refeição, pico glicêmico nas 2 horas seguintes e a variação entre os dois. '
                .'Aceita filtrar por carboidrato mínimo. Use para "como reagi ao almoço", '
                .'"quais refeições me subiram mais".',
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
                'at', 'carbs_g', 'carb_ratio',
                'glucose_at_start', 'peak_2h', 'delta_2h',
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

        $refeicoes = $query->orderBy('recorded_at_local')
            ->get(['recorded_at_local', 'carbs_g', 'carb_ratio']);

        // A série entra uma vez só — as refeições são varridas contra ela em
        // memória, como o `CalibrationPairer` faz com as calibrações.
        $leituras = $this->series($scope, $from, $to)->readings;

        $rows = [];
        $somaCarbs = 0.0;

        foreach ($refeicoes as $refeicao) {
            $momento = new DateTimeImmutable($refeicao->recorded_at_local->format('Y-m-d H:i:s'));
            $inicial = $this->glucoseNear($leituras, $momento);
            $pico = $this->peakWithin($leituras, $momento);

            $somaCarbs += (float) $refeicao->carbs_g;

            $rows[] = [
                'at' => $momento->format('Y-m-d H:i'),
                'carbs_g' => $this->round((float) $refeicao->carbs_g),
                'carb_ratio' => $refeicao->carb_ratio === null
                    ? null
                    : $this->round((float) $refeicao->carb_ratio),
                'glucose_at_start' => $inicial,
                'peak_2h' => $pico,
                // ⚠️ A variação só existe se os DOIS lados existirem. Um delta
                // calculado contra um início ausente seria número inventado.
                'delta_2h' => $inicial !== null && $pico !== null ? $pico - $inicial : null,
            ];
        }

        return ToolResult::ok('get_meals', $args, $this->envelope($from, $to, [
            'meal_count' => count($rows),
            'min_carbs' => $minimo === null ? null : (int) $minimo,
            'total_carbs_g' => $this->round($somaCarbs),
            'rows' => $rows,
        ]));
    }

    /**
     * A leitura mais próxima do momento, dentro da janela.
     *
     * @param  list<GlucoseReading>  $leituras
     */
    private function glucoseNear(array $leituras, DateTimeImmutable $momento): ?int
    {
        $melhor = null;
        $menorDistancia = null;

        foreach ($leituras as $leitura) {
            $distancia = abs($leitura->at->getTimestamp() - $momento->getTimestamp()) / 60;

            if ($distancia > self::START_WINDOW_MINUTES) {
                continue;
            }

            if ($menorDistancia === null || $distancia < $menorDistancia) {
                $menorDistancia = $distancia;
                $melhor = $leitura->mgdl;
            }
        }

        return $melhor;
    }

    /**
     * O maior valor nas 2 horas seguintes.
     *
     * @param  list<GlucoseReading>  $leituras
     */
    private function peakWithin(array $leituras, DateTimeImmutable $momento): ?int
    {
        $limite = $momento->modify('+'.self::RESPONSE_WINDOW_MINUTES.' minutes');
        $pico = null;

        foreach ($leituras as $leitura) {
            if ($leitura->at < $momento || $leitura->at > $limite) {
                continue;
            }

            $pico = $pico === null ? $leitura->mgdl : max($pico, $leitura->mgdl);
        }

        return $pico;
    }
}

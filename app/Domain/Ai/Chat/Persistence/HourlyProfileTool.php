<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;
use App\Domain\Metrics\HourlyProfileBuilder;
use App\Domain\Metrics\Value\HourlyBucket;

/**
 * `get_hourly_profile` — as 24 horas do dia, lado a lado (FR-602).
 *
 * É a ferramenta de "qual meu pior horário?", que é a pergunta mais frequente
 * que este produto existe para responder.
 *
 * ⚠️ **Hora vazia devolve `null`, nunca zero.** Zero pareceria glicose de
 * 0 mg/dL, e o modelo escreveria "sua média às 3h foi 0". A mesma decisão do
 * `DashboardPresenter` — e o `count` ao lado diz quantas leituras sustentam a
 * linha (Artigo V: nunca esconder o denominador).
 */
final class HourlyProfileTool extends PeriodTool
{
    public function __construct(private readonly HourlyProfileBuilder $hourlyProfile) {}

    public function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            name: 'get_hourly_profile',
            description: 'Perfil por hora do dia (24 linhas) num período: média, percentual acima '
                .'e abaixo da faixa, e quantas leituras sustentam cada hora. Use para responder '
                .'"qual meu pior horário", "como estão minhas madrugadas", "que hora eu subo".',
            argumentSchema: self::PERIOD_SCHEMA,
            emittedKeys: array_merge(self::PERIOD_KEYS, [
                'rows', 'hour', 'reading_count', 'mean_glucose',
                'percent_above', 'percent_below', 'dominant_range',
            ]),
        );
    }

    public function run(array $args, ChatScope $scope): ToolResult
    {
        [$from, $to] = $this->window($args);

        $rows = array_map(
            fn (HourlyBucket $b): array => [
                'hour' => $b->hour,
                'reading_count' => $b->count,
                // ⚠️ `null` e não `0` — ver o bloco da classe.
                'mean_glucose' => $b->isEmpty() ? null : $this->round($b->mean),
                'percent_above' => $b->isEmpty() ? null : $this->round($b->percentAbove),
                'percent_below' => $b->isEmpty() ? null : $this->round($b->percentBelow),
                'dominant_range' => $b->dominantRange,
            ],
            $this->hourlyProfile->build($this->series($scope, $from, $to)),
        );

        return ToolResult::ok(
            'get_hourly_profile',
            $args,
            $this->envelope($from, $to, ['rows' => $rows]),
        );
    }
}

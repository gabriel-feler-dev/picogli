<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;
use App\Domain\Metrics\GapDetector;
use App\Domain\Metrics\Value\SensorGap;

/**
 * `get_sensor_gaps` — quando o sensor deixou de medir (FR-602).
 *
 * ⚠️ **É a ferramenta que explica os buracos das outras.** Uma média baixa numa
 * madrugada pode ser madrugada boa ou sensor desligado, e a diferença é esta
 * consulta. Sem ela, o modelo interpretaria ausência de dado como dado.
 */
final class SensorGapsTool extends PeriodTool
{
    public function __construct(private readonly GapDetector $gaps) {}

    public function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            name: 'get_sensor_gaps',
            description: 'Lacunas do sensor num período: trechos acima de 30 minutos sem leitura, '
                .'com início, fim e duração. Use quando a pergunta envolver um horário ou dia com '
                .'poucos dados, ou para checar se um resultado estranho é falta de medição.',
            argumentSchema: self::PERIOD_SCHEMA,
            emittedKeys: array_merge(self::PERIOD_KEYS, [
                'gap_count', 'total_hours', 'longest_minutes', 'rows',
                'start', 'end', 'minutes',
            ]),
        );
    }

    public function run(array $args, ChatScope $scope): ToolResult
    {
        [$from, $to] = $this->window($args);

        $lacunas = $this->gaps->detect($this->series($scope, $from, $to));

        $rows = array_map(fn (SensorGap $g): array => [
            'start' => $g->start->format('Y-m-d H:i'),
            'end' => $g->end->format('Y-m-d H:i'),
            'minutes' => $this->round($g->minutes, 0),
        ], $lacunas);

        $minutos = array_map(fn (SensorGap $g): float => $g->minutes, $lacunas);

        return ToolResult::ok('get_sensor_gaps', $args, $this->envelope($from, $to, [
            'gap_count' => count($rows),
            'total_hours' => $this->round($this->gaps->totalHours($lacunas), 1),
            'longest_minutes' => $minutos === [] ? null : $this->round(max($minutos), 0),
            'rows' => $rows,
        ]));
    }
}

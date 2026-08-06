<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;
use App\Models\DailyMetrics;

/**
 * `get_insulin_summary` — automática contra bolus (FR-602).
 *
 * ⚠️ **A fração automática é calculada aqui, em PHP.** O modelo receberia
 * "automática 440,0 U, bolus 295,15 U" e teria de dividir para dizer "60% do
 * total é automático" — e é exatamente isso que o Artigo I proíbe. Todo número
 * que a resposta pode citar já sai calculado.
 *
 * ⚠️ **Artigo VI:** este resultado descreve o que a bomba entregou. Não existe
 * campo que sugira dose, basal ou ajuste — a fronteira clínica começa na forma
 * do dado, não no prompt.
 */
final class InsulinSummaryTool extends PeriodTool
{
    public function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            name: 'get_insulin_summary',
            description: 'Insulina do período, dia a dia e no total: automática (entregue pelo '
                .'algoritmo da bomba), bolus (pedida pelo usuário) e a fração automática do total. '
                .'Use para responder "quanto da minha insulina é automática", "quanto de bolus eu dei". '
                .'Descreve o que foi entregue; não sugere dose.',
            argumentSchema: self::PERIOD_SCHEMA,
            emittedKeys: array_merge(self::PERIOD_KEYS, [
                'rows', 'day_count', 'local_date',
                'auto_insulin_u', 'bolus_insulin_u', 'total_insulin_u',
                'automatic_fraction_percent',
                'total_auto_insulin_u', 'total_bolus_insulin_u', 'days_with_insulin',
            ]),
        );
    }

    public function run(array $args, ChatScope $scope): ToolResult
    {
        [$from, $to] = $this->window($args);

        $rows = [];
        $somaAuto = 0.0;
        $somaBolus = 0.0;
        $diasComInsulina = 0;

        foreach (DailyMetrics::where('user_id', $scope->userId)
            ->whereBetween('local_date', [$from, $to])
            ->orderBy('local_date')
            ->get() as $dia) {
            $auto = (float) ($dia->auto_insulin_u ?? 0.0);
            $bolus = (float) ($dia->bolus_insulin_u ?? 0.0);
            $total = $auto + $bolus;

            $somaAuto += $auto;
            $somaBolus += $bolus;

            if ($total > 0.0) {
                $diasComInsulina++;
            }

            $rows[] = [
                'local_date' => (string) $dia->local_date,
                'auto_insulin_u' => $this->round($auto, 2),
                'bolus_insulin_u' => $this->round($bolus, 2),
                'total_insulin_u' => $this->round($total, 2),
                // ⚠️ `null` quando não houve insulina: 0% sugeriria que a bomba
                // entregou algo e nada foi automático.
                'automatic_fraction_percent' => $total > 0.0
                    ? $this->round($auto / $total * 100)
                    : null,
            ];
        }

        $somaTotal = $somaAuto + $somaBolus;

        return ToolResult::ok('get_insulin_summary', $args, $this->envelope($from, $to, [
            'day_count' => count($rows),
            'days_with_insulin' => $diasComInsulina,
            'total_auto_insulin_u' => $this->round($somaAuto, 2),
            'total_bolus_insulin_u' => $this->round($somaBolus, 2),
            'total_insulin_u' => $this->round($somaTotal, 2),
            'automatic_fraction_percent' => $somaTotal > 0.0
                ? $this->round($somaAuto / $somaTotal * 100)
                : null,
            'rows' => $rows,
        ]));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\ChatTool;
use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Models\SensorReading;
use DateTimeImmutable;

/**
 * O que as dez ferramentas têm em comum (Spec 006, FR-602).
 *
 * ⚠️ **Esta camada toca banco, e é por isso que mora em `Persistence/`** — e não
 * em `Chat/Tools/` como sugere o `PicoGli.md` §9.3. O §9.3 é esboço de produto;
 * a regra de pureza (NFR-401) é a que vale, e a varredura reprovaria na primeira
 * execução.
 *
 * ## Todo resultado carrega o próprio período
 *
 * ⚠️ `period_start` e `period_end` estão em **todos** os resultados, e não é
 * decoração: o modelo cita datas na resposta, e o `NumberGuard` confronta os
 * números da prosa com os `tool_results` do turno (§D3). Sem a data no
 * resultado, "no dia 25" seria um número órfão e a resposta inteira cairia.
 *
 * ## Uma consulta de série, reusada
 *
 * As ferramentas que precisam da série glicêmica leem daqui. As que não precisam
 * — `get_daily_series`, `get_meals`, `get_device_events` — **não carregam
 * 3.616 leituras para devolver 14 linhas**.
 */
abstract class PeriodTool implements ChatTool
{
    /** O par de datas que quase toda ferramenta recebe. */
    protected const PERIOD_SCHEMA = [
        'start' => ['type' => 'date', 'required' => true],
        'end' => ['type' => 'date', 'required' => true],
    ];

    /** As duas chaves que todo resultado emite. */
    protected const PERIOD_KEYS = ['period_start', 'period_end'];

    /**
     * @param  array<string, mixed>  $args
     * @return array{0: string, 1: string} `[from, to]`
     */
    protected function window(array $args): array
    {
        return [(string) $args['start'], (string) $args['end']];
    }

    /**
     * O resultado, com o período na frente.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function envelope(string $from, string $to, array $data): array
    {
        return array_merge(['period_start' => $from, 'period_end' => $to], $data);
    }

    /**
     * A série glicêmica do período, escopada pelo usuário da SESSÃO.
     *
     * ⚠️ `$scope->userId`, sempre. Não existe caminho em que este `where` receba
     * algo vindo de argumento do modelo (§D2).
     */
    protected function series(ChatScope $scope, string $from, string $to): GlucoseSeries
    {
        $readings = SensorReading::where('user_id', $scope->userId)
            ->whereBetween('local_date', [$from, $to])
            ->orderBy('recorded_at_local')
            ->get(['recorded_at_local', 'glucose_mgdl']);

        return GlucoseSeries::of($readings->map(fn (SensorReading $r): GlucoseReading => new GlucoseReading(
            new DateTimeImmutable($r->recorded_at_local->format('Y-m-d H:i:s')),
            $r->glucose_mgdl,
        ))->all());
    }

    /** Arredondamento único, para o resultado não sair com 28.000000000000004. */
    protected function round(?float $value, int $decimals = 1): ?float
    {
        return $value === null ? null : round($value, $decimals);
    }
}

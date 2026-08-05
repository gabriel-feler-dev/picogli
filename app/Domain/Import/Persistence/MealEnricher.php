<?php

declare(strict_types=1);

namespace App\Domain\Import\Persistence;

use App\Models\Meal;
use App\Models\SensorReading;
use Illuminate\Support\Carbon;

/**
 * Preenche a resposta glicêmica de cada refeição a partir das leituras de
 * sensor já gravadas.
 *
 * ⚠️ Roda **depois** da escrita e **fora** da transação de import, porque
 * consulta dados que precisam existir. É a única razão de as etapas 8-9 do Job
 * virem após o commit.
 *
 * Os três campos:
 *
 *   `peak_2h`      maior glicose nas 2 h seguintes à refeição
 *   `delta_2h`     peak_2h menos a glicose de partida — a subida real
 *   `glucose_4h`   onde a pessoa estava 4 h depois (voltou? ficou alta?)
 *
 * São a matéria-prima da análise de resposta pós-refeição da Spec 004. No
 * export de referência, a refeição de 25/07 18:09 (32 g, partindo de 55) tem
 * pico 323 e delta +268 — é o evento de montanha-russa da regra R3.
 */
final class MealEnricher
{
    /** Janela de observação do pico após a refeição. */
    private const PEAK_WINDOW_HOURS = 2;

    /** Quanto depois medir o "onde parou". */
    private const SETTLE_HOURS = 4;

    /**
     * Tolerância para achar a leitura das 4 h.
     *
     * O CGM lê a cada 5 min, então uma janela de ±10 min encontra a leitura em
     * qualquer alinhamento normal. Se não achar, o campo fica `null` — e null
     * aqui significa "sensor não estava cobrindo", que é informação real (houve
     * 3 lacunas no período), não falha de cálculo.
     */
    private const SETTLE_TOLERANCE_MINUTES = 10;

    /**
     * Enriquece as refeições de um import. Devolve quantas foram preenchidas.
     */
    public function enrich(int $userId, int $importId): int
    {
        $enriched = 0;

        Meal::query()
            ->where('user_id', $userId)
            ->where('import_id', $importId)
            ->orderBy('recorded_at_local')
            ->chunkById(200, function ($meals) use ($userId, &$enriched) {
                foreach ($meals as $meal) {
                    $values = $this->responseFor($userId, $meal);

                    if ($values === null) {
                        continue;
                    }

                    $meal->forceFill($values)->save();
                    $enriched++;
                }
            });

        return $enriched;
    }

    /**
     * @return array{peak_2h: int, delta_2h: int|null, glucose_4h: int|null}|null
     */
    private function responseFor(int $userId, Meal $meal): ?array
    {
        $start = $meal->recorded_at_local;
        $peakEnd = $start->copy()->addHours(self::PEAK_WINDOW_HOURS);

        $peak = SensorReading::query()
            ->where('user_id', $userId)
            ->whereBetween('recorded_at_local', [$start, $peakEnd])
            ->max('glucose_mgdl');

        if ($peak === null) {
            // Refeição sem cobertura de sensor na janela. Deixa os três campos
            // nulos — melhor ausência visível que um zero que parece medição.
            return null;
        }

        return [
            'peak_2h' => (int) $peak,
            'delta_2h' => $this->delta($userId, $meal, (int) $peak),
            'glucose_4h' => $this->settledGlucose($userId, $start->copy()->addHours(self::SETTLE_HOURS)),
        ];
    }

    /**
     * Subida real: pico menos o ponto de partida.
     *
     * ⚠️ A partida preferida é `bg_input` — a glicose que a **calculadora usou**
     * para dosar. É ela que explica a decisão do bolus, e é o número que a
     * pessoa viu na tela. Só quando falta é que se recorre à leitura de sensor
     * mais próxima.
     *
     * Usar sempre o sensor daria um delta ligeiramente diferente do que o
     * usuário lembra de ter visto, e "meu app discorda da minha bomba" é a
     * forma mais rápida de perder confiança.
     */
    private function delta(int $userId, Meal $meal, int $peak): ?int
    {
        $baseline = $meal->bg_input;

        if ($baseline === null) {
            $baseline = SensorReading::query()
                ->where('user_id', $userId)
                ->whereBetween('recorded_at_local', [
                    $meal->recorded_at_local->copy()->subMinutes(self::SETTLE_TOLERANCE_MINUTES),
                    $meal->recorded_at_local,
                ])
                ->orderByDesc('recorded_at_local')
                ->value('glucose_mgdl');
        }

        return $baseline === null ? null : $peak - (int) $baseline;
    }

    /**
     * Leitura mais próxima de um instante, dentro da tolerância.
     *
     * ⚠️ O desempate por proximidade é feito em PHP, não em SQL, de propósito.
     * A versão óbvia seria `orderByRaw('abs(strftime(...) - ?)')` — e `strftime`
     * é **exclusivo do SQLite**. Funcionaria em desenvolvimento e explodiria no
     * MySQL de produção (Artigo IX).
     *
     * O custo é nulo: a janela de ±10 min contém no máximo ~5 leituras, já que
     * o CGM lê a cada 5 minutos.
     */
    private function settledGlucose(int $userId, Carbon $at): ?int
    {
        $candidates = SensorReading::query()
            ->where('user_id', $userId)
            ->whereBetween('recorded_at_local', [
                $at->copy()->subMinutes(self::SETTLE_TOLERANCE_MINUTES),
                $at->copy()->addMinutes(self::SETTLE_TOLERANCE_MINUTES),
            ])
            ->get(['recorded_at_local', 'glucose_mgdl']);

        if ($candidates->isEmpty()) {
            return null;
        }

        $target = $at->getTimestamp();

        $nearest = $candidates->sortBy(
            fn (SensorReading $reading): int => abs($reading->recorded_at_local->getTimestamp() - $target)
        )->first();

        return (int) $nearest->glucose_mgdl;
    }
}

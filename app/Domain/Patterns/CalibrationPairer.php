<?php

declare(strict_types=1);

namespace App\Domain\Patterns;

use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Patterns\Value\CalibrationPair;
use DateTimeImmutable;

/**
 * Pareia cada calibração capilar com a leitura de sensor mais próxima (R10).
 *
 * ## Por que isto é domínio puro e não persistência
 *
 * O `plan.md` previa o pareamento dentro do `PatternDatasetBuilder`, com o
 * argumento de que "achar o vizinho mais próximo é consulta" — a lição do
 * `MealEnricher` da fase 1.
 *
 * ⚠️ **Na fase 1 era consulta; aqui não é.** O builder já carrega a série inteira
 * do período para calcular métricas, então as duas listas estão em memória e o
 * pareamento é algoritmo, não `SELECT`. Movê-lo para cá ganhou duas coisas: é
 * testável sem banco, e entra na varredura de pureza do NFR-401.
 *
 * ## Custo
 *
 * Busca binária sobre a série ordenada: 39 calibrações contra 3.616 leituras
 * custam ~39 × log₂(3.616) ≈ 440 comparações. A varredura linear ingênua
 * custaria 141 mil — irrelevante em 14 dias, e 3,7 milhões num ano.
 *
 * ## O que fica de fora
 *
 * Calibração sem leitura de sensor dentro da janela é **excluída**, e o `n` do
 * achado reflete a exclusão. Aparear com a leitura mais próxima "de qualquer
 * distância" produziria pares separados por horas — e um erro relativo enorme
 * que diria respeito à lacuna, não ao sensor.
 */
final class CalibrationPairer
{
    /**
     * @param  list<array{at: DateTimeImmutable, mgdl: int}>  $calibrations
     * @return list<CalibrationPair>
     */
    public function pair(array $calibrations, GlucoseSeries $series, int|float $windowMinutes): array
    {
        $readings = $series->readings;

        if ($readings === []) {
            return [];
        }

        // Timestamps em segundos, para comparação sem custo de DateTime.
        $stamps = array_map(
            fn ($reading): int => (int) $reading->at->format('U'),
            $readings,
        );

        $windowSeconds = $windowMinutes * 60;
        $pairs = [];

        foreach ($calibrations as $calibration) {
            $target = (int) $calibration['at']->format('U');
            $index = $this->closestIndex($stamps, $target);

            $offsetSeconds = abs($stamps[$index] - $target);

            if ($offsetSeconds > $windowSeconds) {
                continue;
            }

            $pairs[] = new CalibrationPair(
                at: $calibration['at'],
                bgMgdl: $calibration['mgdl'],
                sensorMgdl: $readings[$index]->mgdl,
                offsetMinutes: $offsetSeconds / 60,
            );
        }

        return $pairs;
    }

    /**
     * Índice da leitura mais próxima de `$target`, por busca binária.
     *
     * @param  list<int>  $stamps  ordenados crescentemente
     */
    private function closestIndex(array $stamps, int $target): int
    {
        $low = 0;
        $high = count($stamps) - 1;

        if ($target <= $stamps[$low]) {
            return $low;
        }

        if ($target >= $stamps[$high]) {
            return $high;
        }

        while ($high - $low > 1) {
            $middle = intdiv($low + $high, 2);

            if ($stamps[$middle] === $target) {
                return $middle;
            }

            if ($stamps[$middle] < $target) {
                $low = $middle;
            } else {
                $high = $middle;
            }
        }

        // Empate resolvido para a leitura ANTERIOR. Consistente e arbitrário —
        // o que importa é não depender da ordem de chegada dos dados.
        return ($target - $stamps[$low]) <= ($stamps[$high] - $target) ? $low : $high;
    }
}

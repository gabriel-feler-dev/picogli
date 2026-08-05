<?php

declare(strict_types=1);

namespace App\Domain\Patterns;

use App\Domain\Metrics\Value\Episode;

/**
 * Agrupa episódios por hora do dia em janelas deslizantes cíclicas (§D11).
 *
 * ## O procedimento
 *
 * Para cada episódio, considera-se a janela de `windowHours` **começando na hora
 * dele**. Escolhe-se a que cobre mais episódios, remove-se os cobertos, repete-se
 * até `maxWindows`. Empate resolvido pelo menor início — determinístico.
 *
 * ## Por que deslizante, e não bin fixo
 *
 * Três razões, medidas no export de referência:
 *
 * 1. **Bin fixo tem fase arbitrária.** Episódios às 01:50 e 02:10 estão a 20
 *    minutos e cairiam em bins diferentes. O agrupamento passaria a depender de
 *    onde a grade começa.
 * 2. **A distância é cíclica.** Um episódio às 23:30 e outro às 00:30 estão a
 *    1 hora; bin fixo os põe nas janelas 11 e 0, as mais distantes possíveis. E
 *    hipoglicemia de madrugada atravessando a meia-noite é exatamente o padrão
 *    que R2 existe para encontrar.
 * 3. **Bin fixo caía EM CIMA do limiar.** Apurado em T304: com bins de 2 h a
 *    concentração é 60,0% e o limiar é 0,60. A regra ficaria decidida por
 *    arredondamento de ponto flutuante.
 *
 * ⚠️ **Ancorar no dado aqui não é o pecado que o §D6 proíbe em R1.** A diferença
 * está no que cada regra afirma: R1 afirma algo sobre uma parte fixa do dia
 * ("suas tardes"), e ali a janela precisa ser definida a priori. R2 afirma que
 * **existe** concentração ("suas quedas não são aleatórias") — a afirmação é
 * existencial, e ancorar é a definição dela, não uma escolha conveniente.
 */
final class HypoWindowFinder
{
    /**
     * @param  list<Episode>  $episodes
     * @return list<array{start_hour: float, count: int, nadir: int}>
     */
    public function find(array $episodes, int|float $windowHours, int|float $maxWindows): array
    {
        $remaining = array_map(
            fn (Episode $episode): array => [
                'hour' => $this->hourOfDay($episode),
                'nadir' => $episode->nadir(),
            ],
            $episodes,
        );

        $windows = [];

        while ($remaining !== [] && count($windows) < $maxWindows) {
            $best = $this->bestWindow($remaining, $windowHours);

            $windows[] = [
                'start_hour' => $best['start'],
                'count' => count($best['covered']),
                // O nadir mais baixo da janela: é o que dá gravidade ao grupo.
                'nadir' => min(array_map(fn (array $e): int => $e['nadir'], $best['covered'])),
            ];

            $remaining = array_values(array_filter(
                $remaining,
                fn (array $e): bool => ! in_array($e, $best['covered'], true),
            ));
        }

        return $windows;
    }

    /**
     * Quantos episódios ficam FORA das janelas escolhidas.
     *
     * @param  list<Episode>  $episodes
     * @param  list<array{start_hour: float, count: int, nadir: int}>  $windows
     */
    public function uncovered(array $episodes, array $windows): int
    {
        $covered = array_sum(array_map(fn (array $w): int => $w['count'], $windows));

        return count($episodes) - $covered;
    }

    /**
     * @param  list<array{hour: float, nadir: int}>  $episodes
     * @return array{start: float, covered: list<array{hour: float, nadir: int}>}
     */
    private function bestWindow(array $episodes, int|float $windowHours): array
    {
        $best = ['start' => 0.0, 'covered' => []];

        // Ordenado para o empate resolver pelo menor início — sem isso, o
        // resultado dependeria da ordem de chegada dos episódios.
        $starts = array_map(fn (array $e): float => $e['hour'], $episodes);
        sort($starts);

        foreach ($starts as $start) {
            $covered = array_values(array_filter(
                $episodes,
                fn (array $e): bool => $this->forwardDistance($start, $e['hour']) <= $windowHours,
            ));

            if (count($covered) > count($best['covered'])) {
                $best = ['start' => $start, 'covered' => $covered];
            }
        }

        return $best;
    }

    /**
     * Distância PARA FRENTE de `$start` até `$hour`, no círculo de 24 h.
     *
     * Para frente, e não absoluta, porque a janela tem direção: começa no
     * episódio e se estende para as horas seguintes. Uma distância absoluta faria
     * a janela cobrir `start ± windowHours`, que é o dobro da largura declarada.
     */
    private function forwardDistance(float $start, float $hour): float
    {
        $distance = fmod($hour - $start + 24.0, 24.0);

        // Tolerância para o próprio ponto de ancoragem sob aritmética de float.
        return $distance < 1e-9 ? 0.0 : $distance;
    }

    /** Hora do dia do INÍCIO do episódio, em horas decimais. */
    private function hourOfDay(Episode $episode): float
    {
        return (int) $episode->start->format('G')
            + ((int) $episode->start->format('i')) / 60
            + ((int) $episode->start->format('s')) / 3600;
    }
}

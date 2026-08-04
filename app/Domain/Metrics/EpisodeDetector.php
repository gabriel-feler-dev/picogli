<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Metrics\Value\Episode;
use App\Domain\Metrics\Value\EpisodeType;
use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;

/**
 * Detecta episódios de hipo e hiperglicemia (FR-106).
 *
 * ## A regra de término (spec.md §D3)
 *
 * Um episódio INICIA na primeira leitura fora da faixa e só TERMINA depois de
 * `recovery_minutes` **consecutivos de volta** na faixa. Uma volta breve não
 * encerra: a glicose que sai, volta por 5 minutos e sai de novo é UM episódio
 * oscilante, não dois.
 *
 * ⚠️ Isto difere da apuração original do gabarito, que encerrava na primeira
 * leitura de volta à faixa e portanto FRAGMENTAVA episódios oscilantes. A
 * divergência foi declarada em §D3 **antes** deste código existir, justamente
 * para que o número cedesse à regra, e não o contrário (Artigo XI).
 *
 * ## As três regras que não são óbvias
 *
 * **1. Lacuna interrompe.** Se o intervalo até a próxima leitura passa de
 * `gap_threshold_minutes`, o episódio encerra na última leitura MEDIDA. Sem
 * isso, a lacuna de 1347 min do export de referência poderia virar um episódio
 * de quase um dia — afirmação sobre um período em que ninguém mediu nada.
 *
 * **2. Duração é medida, não contada.** `fim − início` em minutos reais.
 *
 * **3. O fim é a última leitura FORA da faixa**, não a leitura de recuperação.
 * A janela de recuperação serve para CONFIRMAR o término, não para esticá-lo.
 */
final class EpisodeDetector
{
    public function __construct(private readonly MetricsConfig $config) {}

    /** @return list<Episode> */
    public function detect(GlucoseSeries $series, EpisodeType $type): array
    {
        $settings = $this->config->episodes[$type->configKey()];
        $threshold = $settings['threshold'];
        $minDuration = $settings['min_duration_minutes'];
        $recovery = $settings['recovery_minutes'];
        $gapThreshold = $this->config->sensor['gap_threshold_minutes'];

        $episodes = [];
        $open = null;
        $previous = null;

        foreach ($series->readings as $reading) {
            // ── Lacuna: encerra o que estiver aberto na última leitura medida.
            if ($previous !== null && $this->minutesBetween($previous, $reading) > $gapThreshold) {
                if ($open !== null) {
                    $episodes = $this->close($episodes, $open, $type, $minDuration, interruptedByGap: true);
                    $open = null;
                }
            }

            $isExcursion = $type->isExcursion($reading->mgdl, $threshold);

            if ($open === null) {
                if ($isExcursion) {
                    $open = [
                        'start' => $reading,
                        'lastOutside' => $reading,
                        'extreme' => $reading->mgdl,
                        'count' => 1,
                        'recoveryStart' => null,
                    ];
                }

                $previous = $reading;

                continue;
            }

            if ($isExcursion) {
                // Voltou a sair antes de completar a recuperação: MESMO episódio.
                $open['lastOutside'] = $reading;
                $open['extreme'] = $type->moreExtreme($open['extreme'], $reading->mgdl);
                $open['count']++;
                $open['recoveryStart'] = null;
            } else {
                $open['recoveryStart'] ??= $reading;

                if ($this->minutesBetween($open['recoveryStart'], $reading) >= $recovery) {
                    $episodes = $this->close($episodes, $open, $type, $minDuration);
                    $open = null;
                }
            }

            $previous = $reading;
        }

        // Série termina com episódio aberto: a recuperação nunca foi confirmada.
        // Encerra na última leitura fora da faixa e marca como interrompido —
        // não se sabe o que veio depois do fim do arquivo.
        if ($open !== null) {
            $episodes = $this->close($episodes, $open, $type, $minDuration, interruptedByGap: true);
        }

        return $episodes;
    }

    /**
     * @param  list<Episode>  $episodes
     * @param  array<string, mixed>  $open
     * @return list<Episode>
     */
    private function close(
        array $episodes,
        array $open,
        EpisodeType $type,
        int $minDuration,
        bool $interruptedByGap = false,
    ): array {
        $duration = $this->minutesBetween($open['start'], $open['lastOutside']);

        // Excursão curta não é episódio. O limiar é INCLUSIVO: 15 min exatos
        // contam — é o que o gabarito registra (episódio de 27/07 18:01).
        if ($duration < $minDuration) {
            return $episodes;
        }

        $episodes[] = new Episode(
            type: $type,
            start: $open['start']->at,
            end: $open['lastOutside']->at,
            durationMinutes: $duration,
            extreme: $open['extreme'],
            readingCount: $open['count'],
            interruptedByGap: $interruptedByGap,
        );

        return $episodes;
    }

    private function minutesBetween(GlucoseReading $from, GlucoseReading $to): float
    {
        return ($to->at->getTimestamp() - $from->at->getTimestamp()) / 60;
    }
}

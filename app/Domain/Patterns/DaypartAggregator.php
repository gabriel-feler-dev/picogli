<?php

declare(strict_types=1);

namespace App\Domain\Patterns;

use App\Domain\Metrics\MetricsConfig;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Patterns\Value\Daypart;
use App\Domain\Patterns\Value\DaypartStats;
use InvalidArgumentException;

/**
 * Agrega a série em quatro períodos de 6 h (§D6).
 *
 * ⚠️ **Conta leituras; não faz média de percentuais horários.**
 *
 * A diferença é real, não teórica. As horas do export de referência têm entre
 * 132 e 156 leituras: a média dos 6 percentuais da tarde daria um número, e
 * `Σ acima ÷ Σ leituras` dá outro. O segundo é o certo — e é a mesma decisão que
 * a Spec 002 §D1 tomou para o período inteiro. Usar a mesma regra em todo nível
 * de agregação é o que mantém dashboard e motor mostrando o mesmo número.
 *
 * ⚠️ Hora LOCAL de cada leitura (Artigo VIII.5). Com UTC, o perfil inteiro
 * desliza e os números continuam plausíveis — é a falha que não quebra teste.
 *
 * Usado por R1 (deriva) e R6 (coerência de CR). Uma classe só: duas
 * implementações do mesmo agrupamento é como as duas regras passariam a
 * discordar sobre o que é "tarde".
 */
final class DaypartAggregator
{
    /**
     * @param  array<string, array{label: string, from: int, to: int}>  $bounds  `config/clinical.dayparts`
     */
    public function __construct(
        private readonly MetricsConfig $config,
        private readonly array $bounds,
    ) {
        $this->assertBoundsMatchEnum();
    }

    /**
     * @return array<string, DaypartStats> chave = `Daypart::value`
     */
    public function aggregate(GlucoseSeries $series): array
    {
        $targetLow = $this->config->ranges['target']['min'];
        $targetHigh = $this->config->ranges['target']['max'];

        $hourToDaypart = $this->hourToDaypart();

        $count = [];
        $above = [];
        $below = [];
        $sum = [];

        foreach (Daypart::cases() as $daypart) {
            $count[$daypart->value] = 0;
            $above[$daypart->value] = 0;
            $below[$daypart->value] = 0;
            $sum[$daypart->value] = 0;
        }

        foreach ($series->readings as $reading) {
            $key = $hourToDaypart[(int) $reading->at->format('G')];

            $count[$key]++;
            $sum[$key] += $reading->mgdl;

            if ($reading->mgdl > $targetHigh) {
                $above[$key]++;
            } elseif ($reading->mgdl < $targetLow) {
                $below[$key]++;
            }
        }

        $stats = [];

        foreach (Daypart::cases() as $daypart) {
            $stats[$daypart->value] = new DaypartStats(
                daypart: $daypart,
                count: $count[$daypart->value],
                aboveCount: $above[$daypart->value],
                belowCount: $below[$daypart->value],
                sum: $sum[$daypart->value],
            );
        }

        return $stats;
    }

    /**
     * As 24 horas mapeadas para seu período.
     *
     * Construído a cada chamada a partir da config — barato, e evita cache que
     * ficaria desatualizado num teste que troca os limites.
     *
     * @return array<int, string>
     */
    private function hourToDaypart(): array
    {
        $map = [];

        foreach ($this->bounds as $key => $bound) {
            for ($hour = $bound['from']; $hour <= $bound['to']; $hour++) {
                $map[$hour] = $key;
            }
        }

        return $map;
    }

    /**
     * A config e o enum descrevem os mesmos quatro períodos, cobrindo as 24 h.
     *
     * ⚠️ Três coisas verificadas aqui, e cada uma corresponde a um jeito real de
     * o dado ficar errado sem erro visível:
     *
     *   - **chave a mais ou a menos:** um período novo na config seria ignorado
     *     em silêncio, e suas leituras não entrariam em nenhuma soma;
     *   - **hora sem período:** as leituras daquela hora desapareceriam da
     *     agregação, e a soma dos `n` deixaria de fechar com o total da série;
     *   - **hora em dois períodos:** as leituras entrariam duas vezes, e o
     *     percentual continuaria entre 0 e 100 — plausível e errado.
     */
    private function assertBoundsMatchEnum(): void
    {
        $configKeys = array_keys($this->bounds);
        $enumKeys = array_map(fn (Daypart $d): string => $d->value, Daypart::cases());

        sort($configKeys);
        sort($enumKeys);

        if ($configKeys !== $enumKeys) {
            throw new InvalidArgumentException(
                'config/clinical.dayparts descreve ['.implode(', ', $configKeys)
                .'] e o enum Daypart descreve ['.implode(', ', $enumKeys).'].'
            );
        }

        $seen = [];

        foreach ($this->bounds as $key => $bound) {
            for ($hour = $bound['from']; $hour <= $bound['to']; $hour++) {
                if (isset($seen[$hour])) {
                    throw new InvalidArgumentException(
                        "A hora {$hour} está em '{$seen[$hour]}' e em '{$key}'. "
                        .'Leitura contada duas vezes mantém o percentual plausível.'
                    );
                }

                $seen[$hour] = $key;
            }
        }

        for ($hour = 0; $hour < 24; $hour++) {
            if (! isset($seen[$hour])) {
                throw new InvalidArgumentException(
                    "A hora {$hour} não pertence a nenhum período. As leituras "
                    .'dela sairiam da agregação sem erro nenhum.'
                );
            }
        }
    }
}

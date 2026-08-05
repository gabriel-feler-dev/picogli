<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Rules;

use App\Domain\Metrics\MetricsConfig;
use App\Domain\Patterns\HypoWindowFinder;
use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rule;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;

/**
 * R2 — Cluster de hipoglicemias (FR-404).
 *
 * *No export de referência:* 5 episódios → **80% em 2 janelas** de 2 h (§D11), com
 * a janela da madrugada (00:44) e a do pré-jantar (17:56). O episódio de 03:41
 * fica fora, e a evidência mostra isso.
 *
 * ## Por que esta regra é a primeira da lista (rank 1)
 *
 * Hipoglicemia é o risco agudo. É o único achado em que atrasar a leitura tem
 * custo real — e é o único cuja causa costuma ser mais identificável, porque
 * queda em horário fixo aponta para basal, para o intervalo entre refeições ou
 * para atividade rotineira.
 *
 * A prosa alvo do `PicoGli.md` §8.2 diz o essencial:
 *
 * > "Suas quedas não são aleatórias: acontecem em dois horários — antes do jantar
 * >  e de madrugada. Isso é um padrão, e padrões têm causa identificável."
 *
 * ⚠️ Note o que ela **não** faz: não sugere reduzir basal, não estima dose, não
 * afirma a causa. Diz que existe padrão e que padrão tem causa — o que é
 * verdadeiro, útil, e devolve a investigação a quem pode fazê-la.
 *
 * ## `min_episodes` não é excesso de cautela
 *
 * Com dois episódios, a chance de os dois cairem na mesma janela por acaso é
 * alta. Chamar isso de padrão gastaria a credibilidade da regra no caso em que
 * ela tem menos a dizer.
 */
final class R2HypoCluster implements Rule
{
    public function __construct(
        private readonly PatternsConfig $config,
        private readonly MetricsConfig $clinical,
        private readonly HypoWindowFinder $finder,
        private readonly ProseRenderer $prose,
    ) {}

    public function id(): RuleId
    {
        return RuleId::HypoCluster;
    }

    public function evaluate(PatternDataset $dataset): array
    {
        $episodes = $dataset->hypoEpisodes;
        $total = count($episodes);

        if ($total < $this->config->threshold($this->id(), 'min_episodes')) {
            return [];
        }

        $windows = $this->finder->find(
            $episodes,
            $this->config->threshold($this->id(), 'window_hours'),
            $this->config->threshold($this->id(), 'max_windows'),
        );

        $covered = array_sum(array_map(fn (array $w): int => $w['count'], $windows));
        $concentration = $covered / $total;

        if ($concentration < $this->config->threshold($this->id(), 'concentration_threshold')) {
            return [];
        }

        $evidence = $this->evidenceFor($dataset, $windows, $total, $covered, $concentration);

        return [new Finding(
            ruleId: $this->id(),
            severity: $this->severityFor($dataset),
            evidence: $evidence,
            fallbackProse: $this->prose->render(
                $this->id(),
                count($windows) === 1 ? 'prose_single_window' : 'prose',
                $evidence,
            ),
        )];
    }

    /**
     * ⚠️ `Priority` quando algum nadir é hipoglicemia **nível 2** (<54 mg/dL), que
     * é o limiar de consenso para "clinicamente significativa" — e vem de
     * `config/clinical.ranges`, não de um número inventado aqui.
     *
     * No export de referência nenhum nadir passa de 55, então o achado é
     * `Attention`. É a leitura honesta: o padrão existe e merece investigação, sem
     * dramatizar episódios que o corpo resolveu.
     */
    private function severityFor(PatternDataset $dataset): Severity
    {
        $level2Ceiling = $this->clinical->ranges['very_low']['max'];

        foreach ($dataset->hypoEpisodes as $episode) {
            if ($episode->nadir() <= $level2Ceiling) {
                return Severity::Priority;
            }
        }

        return Severity::Attention;
    }

    /**
     * @param  list<array{start_hour: float, count: int, nadir: int}>  $windows
     * @return array<string, int|float|string|bool|null>
     */
    private function evidenceFor(
        PatternDataset $dataset,
        array $windows,
        int $total,
        int $covered,
        float $concentration,
    ): array {
        $windowHours = $this->config->threshold($this->id(), 'window_hours');

        $evidence = [
            'episodes_total' => $total,
            'episodes_clustered' => $covered,
            // ⚠️ O que ficou FORA aparece. Esconder faria "80%" soar como 100%.
            'episodes_outside' => $this->finder->uncovered($dataset->hypoEpisodes, $windows),
            'windows_used' => count($windows),
            'window_hours' => $windowHours,
            'concentration_percent' => round($concentration * 100, 1),
            'worst_nadir' => min(array_map(
                fn ($episode): int => $episode->nadir(),
                $dataset->hypoEpisodes,
            )),
        ];

        foreach ($windows as $index => $window) {
            $position = $index + 1;

            $evidence['window'.$position.'_start'] = $this->formatHour($window['start_hour']);
            $evidence['window'.$position.'_end'] = $this->formatHour(
                fmod($window['start_hour'] + $windowHours, 24.0)
            );
            $evidence['window'.$position.'_episodes'] = $window['count'];
            $evidence['window'.$position.'_nadir'] = $window['nadir'];
        }

        return $evidence;
    }

    /** Hora decimal em `HH:MM` — string porque a evidência só aceita escalar (§D1). */
    private function formatHour(float $hour): string
    {
        $minutes = (int) round($hour * 60);

        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}

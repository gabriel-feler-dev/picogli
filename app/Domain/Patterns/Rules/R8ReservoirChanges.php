<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Rules;

use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rule;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;

/**
 * R8 — Trocas de reservatório (FR-410).
 *
 * *No export de referência:* 3 rewinds, 6 primes, **2 intervalos observados**, com
 * média de **4,41 dias** (gabarito §Fase 4).
 *
 * ## Duas honestidades obrigatórias
 *
 * **1. O `n`.** Três trocas dão dois intervalos. `14 dias ÷ 3 trocas = 4,7` supõe
 * que o período começa e termina numa troca, e não é o caso — os três rewinds
 * cobrem 8,81 dos 13,8 dias. A regra relata os **intervalos observados** e mostra
 * o `n`, porque uma média de dois valores é frágil e esconder isso atrás de um
 * número seria dar precisão falsa (§D8).
 *
 * **2. O caveat.** `Rewind` rastreia troca de **reservatório**. Trocar o cateter
 * sem trocar o reservatório pode não aparecer no arquivo. Sem a ressalva, o número
 * viraria afirmação sobre aderência a partir de um dado que não a sustenta.
 *
 * ## Por que a severidade é sempre `Info`
 *
 * ⚠️ Não existe limiar de "intervalo ideal" nesta regra, e a ausência é
 * deliberada. Dizer que 4,41 dias é muito ou pouco seria **recomendar mudança de
 * conduta** — Artigo VI, sem exceção. A regra relata a cadência observada e os
 * alertas que o **próprio aparelho** emitiu; a leitura clínica é do médico.
 *
 * Isso a torna a única regra que descreve sem avaliar. É o formato certo para um
 * dado que não sustenta avaliação.
 */
final class R8ReservoirChanges implements Rule
{
    public function __construct(
        private readonly PatternsConfig $config,
        private readonly ProseRenderer $prose,
    ) {}

    public function id(): RuleId
    {
        return RuleId::ReservoirChanges;
    }

    public function evaluate(PatternDataset $dataset): array
    {
        $rewinds = $dataset->rewinds;

        // Com menos de duas trocas não existe intervalo para relatar — e a regra
        // não tem nada a dizer que a contagem sozinha já não diga.
        if (count($rewinds) < $this->config->threshold($this->id(), 'min_rewinds')) {
            return [];
        }

        $intervals = $this->intervalsInDays($rewinds);
        $reminders = $dataset->deviceEventCount('SET CHANGE REMINDER');

        $evidence = [
            'rewinds' => count($rewinds),
            'primes' => $dataset->deviceCategoryCount('prime'),
            'intervals' => count($intervals),
            'mean_interval_days' => round(array_sum($intervals) / count($intervals), 2),
            'shortest_interval_days' => round(min($intervals), 2),
            'longest_interval_days' => round(max($intervals), 2),
            'set_change_reminders' => $reminders,
        ];

        return [new Finding(
            ruleId: $this->id(),
            // Sempre Info: qualquer escalonamento implicaria que a cadência
            // observada está errada, e isso é conduta (Artigo VI).
            severity: Severity::Info,
            evidence: $evidence,
            fallbackProse: $this->prose->render(
                $this->id(),
                $reminders > 0 ? 'prose_with_reminders' : 'prose',
                $evidence,
            ),
        )];
    }

    /**
     * Intervalos entre trocas consecutivas, em dias.
     *
     * ⚠️ Medidos em segundos e convertidos, não contados em dias de calendário:
     * 16/07 17:38 → 20/07 21:33 são 4,16 dias, não 4. A diferença aparece na
     * média, e é ela que vai à evidência.
     *
     * @param  list<\DateTimeImmutable>  $rewinds
     * @return list<float>
     */
    private function intervalsInDays(array $rewinds): array
    {
        usort($rewinds, fn ($a, $b): int => $a <=> $b);

        $intervals = [];

        for ($i = 1; $i < count($rewinds); $i++) {
            $seconds = $rewinds[$i]->getTimestamp() - $rewinds[$i - 1]->getTimestamp();
            $intervals[] = $seconds / 86400;
        }

        return $intervals;
    }
}

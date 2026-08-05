<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Rules;

use App\Domain\Metrics\Value\SensorGap;
use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rule;
use App\Domain\Patterns\Value\DailySnapshot;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;
use DateTimeImmutable;

/**
 * R5 — Falha de sensor derrubando o loop fechado (FR-407).
 *
 * *No export de referência:* lacuna de **1.347 min** em 21–22/07 → insulina
 * automática de **9,0 U** em 22/07 contra média de **31,4 U** no período. Queda de
 * 71%, e a fração automática do dia cai de ~60% para ~27%.
 *
 * ## Esta é a regra que justifica o projeto existir
 *
 * ⚠️ **O achado atravessa dois blocos diferentes do CSV.** A lacuna vem do bloco
 * Sensor; a insulina automática vem do bloco `Aggregated Auto Insulin Data`.
 * **Nenhum relatório da Medtronic mostra essa conexão** — cada um mora numa página
 * separada, e o leitor teria de suspeitar da relação para ir procurá-la.
 *
 * O mecanismo é concreto e verificável: sem leitura de sensor, o SmartGuard não
 * tem entrada para decidir, e a bomba volta ao modo manual. A pessoa passa a
 * compensar com bolus — e o dia inteiro muda de caráter sem que nada no aparelho
 * avise que mudou.
 *
 * ## Sobre o tom (Artigo IV)
 *
 * O achado é **sobre o equipamento**, não sobre a pessoa. A prosa explica o
 * mecanismo e para ali. Não diz que o sensor deveria ter sido trocado antes, não
 * estima quanta insulina "faltou", não sugere nada — inclusive porque o dado não
 * sustenta nenhuma dessas afirmações.
 */
final class R5SensorGapLoopImpact implements Rule
{
    public function __construct(
        private readonly PatternsConfig $config,
        private readonly ProseRenderer $prose,
    ) {}

    public function id(): RuleId
    {
        return RuleId::SensorGapLoopImpact;
    }

    public function evaluate(PatternDataset $dataset): array
    {
        $periodMean = $dataset->meanAutoInsulin();

        // Sem média não há com o que comparar. `null` aqui significa "nenhum dia
        // tem registro do bloco 2", não "a média é zero".
        if ($periodMean === null || $periodMean <= 0.0) {
            return [];
        }

        $minimumGap = $this->config->threshold($this->id(), 'min_gap_minutes');
        $dropRatio = $this->config->threshold($this->id(), 'auto_insulin_drop_ratio');
        $daily = $dataset->dailyByDate();

        $findings = [];

        foreach ($dataset->gaps as $gap) {
            if ($gap->minutes < $minimumGap) {
                continue;
            }

            $affected = $this->mostAffectedDate($gap);

            if (! array_key_exists($affected['date'], $dataset->autoInsulinByDate)) {
                continue;
            }

            $dayAuto = $dataset->autoInsulinByDate[$affected['date']];
            $drop = 1.0 - ($dayAuto / $periodMean);

            if ($drop < $dropRatio) {
                continue;
            }

            $evidence = $this->evidenceFor($dataset, $gap, $affected, $dayAuto, $periodMean, $drop, $daily);

            $findings[] = new Finding(
                ruleId: $this->id(),
                // ⚠️ `Attention`, não `Priority`. O mecanismo é do equipamento e
                // já passou — não há nada de agudo a fazer retroativamente.
                // `Priority` fica reservado para o que representa risco (R2).
                severity: Severity::Attention,
                evidence: $evidence,
                fallbackProse: $this->prose->render($this->id(), 'prose', $evidence),
            );
        }

        return $findings;
    }

    /**
     * O dia que a lacuna mais afetou, e quantos minutos dela caíram nele.
     *
     * ⚠️ **Decisão que a regra tem de tomar:** a lacuna do export atravessa a
     * meia-noite (21/07 17:29 → 22/07 15:56), então "o dia da lacuna" é ambíguo.
     * A escolha é o dia com **mais minutos de lacuna** — 22/07, com 16 h contra
     * 6,5 h do 21/07.
     *
     * *Por quê o dia com mais minutos, e não o dia de início:* o efeito que se
     * quer medir é o SmartGuard sem entrada, e ele é proporcional ao tempo sem
     * sensor. Atribuir pelo início daria 21/07 — um dia com 91% de cobertura, em
     * que o loop funcionou quase o dia inteiro.
     *
     * @return array{date: string, minutes: float}
     */
    private function mostAffectedDate(SensorGap $gap): array
    {
        $byDate = $this->minutesByDate($gap);

        arsort($byDate);
        $date = (string) array_key_first($byDate);

        return ['date' => $date, 'minutes' => $byDate[$date]];
    }

    /**
     * Minutos da lacuna que caem em cada dia civil.
     *
     * @return array<string, float>
     */
    private function minutesByDate(SensorGap $gap): array
    {
        $minutes = [];
        $cursor = $gap->start;

        while ($cursor < $gap->end) {
            $nextMidnight = (new DateTimeImmutable($cursor->format('Y-m-d').' 00:00:00'))
                ->modify('+1 day');

            $segmentEnd = $nextMidnight < $gap->end ? $nextMidnight : $gap->end;

            $minutes[$cursor->format('Y-m-d')] =
                ($segmentEnd->getTimestamp() - $cursor->getTimestamp()) / 60;

            $cursor = $segmentEnd;
        }

        return $minutes;
    }

    /**
     * @param  array{date: string, minutes: float}  $affected
     * @param  array<string, DailySnapshot>  $daily
     * @return array<string, int|float|string|bool|null>
     */
    private function evidenceFor(
        PatternDataset $dataset,
        SensorGap $gap,
        array $affected,
        float $dayAuto,
        float $periodMean,
        float $drop,
        array $daily,
    ): array {
        $snapshot = $daily[$affected['date']] ?? null;

        return [
            // ⚠️ MINUTOS, não horas formatadas. 1.347 min = 22,45 h fica em cima
            // da borda de arredondamento: Python formata 22,4 e PHP arredonda
            // 22,5. Ancorar evidência em valor já formatado criava divergência
            // fantasma (gabarito §Lacunas).
            'gap_minutes' => (int) round($gap->minutes),
            'gap_hours' => round($gap->minutes / 60, 1),
            'gap_start' => $gap->start->format('Y-m-d H:i'),
            'gap_end' => $gap->end->format('Y-m-d H:i'),

            'affected_date' => $affected['date'],
            'gap_minutes_on_date' => (int) round($affected['minutes']),

            'auto_insulin_u' => round($dayAuto, 1),
            'period_mean_auto_insulin_u' => round($periodMean, 1),
            'drop_percent' => round($drop * 100, 1),

            // As duas frações do total — é o par que mostra a troca de regime.
            'day_automatic_fraction_percent' => $snapshot?->automaticFraction() === null
                ? null
                : round($snapshot->automaticFraction() * 100, 1),
            'period_automatic_fraction_percent' => $this->periodAutomaticFraction($dataset),

            'day_bolus_insulin_u' => $snapshot === null ? null : round($snapshot->bolusInsulinU, 1),
            'day_coverage_percent' => $snapshot === null ? null : round($snapshot->coveragePct, 1),
        ];
    }

    /**
     * Fração da insulina do período entregue pelo SmartGuard.
     *
     * Somas do período, não média das frações diárias: um dia com pouca insulina
     * total pesaria igual a um dia cheio, e a fração perderia sentido.
     */
    private function periodAutomaticFraction(PatternDataset $dataset): ?float
    {
        $auto = 0.0;
        $bolus = 0.0;

        foreach ($dataset->daily as $day) {
            $auto += $day->autoInsulinU;
            $bolus += $day->bolusInsulinU;
        }

        $total = $auto + $bolus;

        return $total > 0.0 ? round($auto / $total * 100, 1) : null;
    }
}

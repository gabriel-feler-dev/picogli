<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Value;

/**
 * Estatísticas de um período do dia (§D6).
 *
 * ⚠️ **Guarda contagens, não percentuais.** `percentAbove()` é derivado na hora.
 *
 * *Por quê:* percentual guardado é percentual que alguém vai somar. A apuração de
 * T300 mostrou o custo disso na prática — o `4,7 dias` de intervalo entre trocas
 * veio de dividir dias por contagem, e os `295,16 U` da fase 1 vieram de somar
 * dois valores já arredondados. Contagem é o dado; percentual é uma vista dele.
 *
 * ⚠️ **`$count` acompanha todo percentual daqui** (Artigo V dentro da regra). Um
 * período atravessado por lacuna de sensor tem `n` pequeno, e 30% sobre 40
 * leituras não pode ser exibido igual a 24% sobre 917.
 */
final readonly class DaypartStats
{
    public function __construct(
        public Daypart $daypart,
        public int $count,
        public int $aboveCount,
        public int $belowCount,
        public int $sum,
    ) {}

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    /** Percentual de tempo acima da faixa, SOBRE LEITURAS (§D6). */
    public function percentAbove(): float
    {
        return $this->count === 0 ? 0.0 : ($this->aboveCount / $this->count) * 100;
    }

    public function percentBelow(): float
    {
        return $this->count === 0 ? 0.0 : ($this->belowCount / $this->count) * 100;
    }

    public function mean(): float
    {
        return $this->count === 0 ? 0.0 : $this->sum / $this->count;
    }

    /**
     * Amostra suficiente para o período entrar numa comparação?
     *
     * Sem isto, um período com 40 leituras (sensor fora do ar quase todo o
     * intervalo) pode vencer a comparação de R1 por ruído e virar "o seu pior
     * horário" com base em três horas de dado.
     */
    public function hasEnoughReadings(int|float $minimum): bool
    {
        return $this->count >= $minimum;
    }
}

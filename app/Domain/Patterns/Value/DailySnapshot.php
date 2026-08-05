<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Value;

/**
 * Um dia de `daily_metrics`, em forma pura.
 *
 * ⚠️ O `plan.md` previa `DailyMetrics[]` (o model) dentro do `PatternDataset`.
 * Isso violaria o §D2: Eloquent no domínio faz as regras precisarem de banco para
 * serem testadas, e permite que uma regra navegue relação e vá ao banco por trás
 * das costas do builder.
 *
 * ⚠️ `$coveragePct` viaja com todo o resto (Artigo V no nível do dia). Uma média
 * de 142 sobre 34% de captura — o 22/07 do export de referência — não é comparável
 * com uma sobre 100%, e a regra que as tratasse igual estaria certa na aritmética
 * e errada no significado.
 */
final readonly class DailySnapshot
{
    public function __construct(
        public string $localDate,
        public int $readingCount,
        public float $coveragePct,
        public float $meanGlucose,
        public float $tirPct,
        public float $cvPct,
        public float $tarLevel2Pct,
        public float $tbrPct,
        public float $autoInsulinU,
        public float $bolusInsulinU,
        public float $totalCarbsG,
    ) {}

    public function totalInsulinU(): float
    {
        return $this->autoInsulinU + $this->bolusInsulinU;
    }

    /** Fração da insulina do dia entregue pelo SmartGuard. Null quando não houve insulina. */
    public function automaticFraction(): ?float
    {
        $total = $this->totalInsulinU();

        return $total > 0 ? $this->autoInsulinU / $total : null;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ai\CooldownStore;

/**
 * Cooldown em memória, com relógio controlável.
 *
 * ⚠️ **O relógio é injetado e avança à mão** (`advance()`). É o que permite testar
 * "o modelo 1 voltou depois de 60 s" sem esperar 60 segundos e sem depender de
 * `Carbon::setTestNow()` — que não existiria num teste de unidade sem container.
 */
final class ArrayCooldownStore implements CooldownStore
{
    /** @var array<string, int> modelo → instante em que o castigo termina */
    private array $until = [];

    public function __construct(private int $now = 1000) {}

    public function isCoolingDown(string $model): bool
    {
        return $this->remainingSeconds($model) !== null;
    }

    public function penalise(string $model, int $seconds): void
    {
        $this->until[$model] = $this->now + $seconds;
    }

    public function remainingSeconds(string $model): ?int
    {
        $until = $this->until[$model] ?? null;

        if ($until === null) {
            return null;
        }

        $remaining = $until - $this->now;

        return $remaining > 0 ? $remaining : null;
    }

    public function release(string $model): void
    {
        unset($this->until[$model]);
    }

    /** Avança o relógio de mentira. */
    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}

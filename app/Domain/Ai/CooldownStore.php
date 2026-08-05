<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Guarda quais modelos estão de castigo, e por quanto tempo (§D4).
 *
 * ⚠️ **Precisa ser PERSISTIDO, não em memória.** Na hospedagem compartilhada a
 * fila roda por cron com `--stop-when-empty` (ADR-5): o processo morre entre
 * execuções. Estado em memória some, e o sistema reaprende que o modelo está
 * esgotado a cada chamada — gastando uma requisição por vez para descobrir o que
 * já sabia.
 *
 * ⚠️ **O tempo mora aqui, não na `ModelChain`.** A implementação usa TTL de
 * cache, então a expiração é responsabilidade de quem sabe que horas são. É o que
 * mantém `ModelChain` determinística e testável sem congelar o relógio (NFR-401).
 */
interface CooldownStore
{
    public function isCoolingDown(string $model): bool;

    /** Põe o modelo de castigo por `$seconds`. */
    public function penalise(string $model, int $seconds): void;

    /** Segundos restantes, ou `null` se o modelo está disponível. */
    public function remainingSeconds(string $model): ?int;

    public function release(string $model): void;
}

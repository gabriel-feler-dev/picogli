<?php

declare(strict_types=1);

namespace App\Domain\Import\Value;

use App\Domain\Import\Value\Events\IgnoredReason;
use App\Domain\Import\Value\Events\ImportEvent;

/**
 * Resultado de explodir uma linha do CSV.
 *
 * Ou a linha gerou eventos, ou foi descartada com um motivo classificado.
 * Nunca as duas coisas, e nunca nenhuma das duas — é essa totalidade que
 * permite auditar que nenhuma linha se perdeu.
 *
 * Ver plan.md §Ignorada ≠ desconhecida.
 */
final readonly class ExplosionResult
{
    /** @param list<ImportEvent> $events */
    private function __construct(
        public array $events,
        public ?IgnoredReason $ignoredReason,
    ) {}

    /** @param list<ImportEvent> $events */
    public static function of(array $events): self
    {
        return new self($events, null);
    }

    public static function ignored(IgnoredReason $reason): self
    {
        return new self([], $reason);
    }

    public function producedEvents(): bool
    {
        return $this->events !== [];
    }

    /** Se este descarte deve entrar em `parse_warnings` (FR-010). */
    public function isWarning(): bool
    {
        return $this->ignoredReason?->isWarning() ?? false;
    }
}

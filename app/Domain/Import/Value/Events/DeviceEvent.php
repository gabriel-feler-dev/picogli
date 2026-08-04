<?php

declare(strict_types=1);

namespace App\Domain\Import\Value\Events;

use DateTimeImmutable;

/**
 * Alerta, dispensa de alerta, suspensão, troca de reservatório, prime,
 * estado do sensor ou calibração aceita.
 *
 * `$payload` guarda o que é específico da categoria (volume de prime, valor da
 * calibração) sem inflar a tabela com colunas quase sempre nulas.
 */
final readonly class DeviceEvent implements ImportEvent
{
    /** @param array<string, mixed> $payload campos extras da categoria */
    public function __construct(
        public DateTimeImmutable $recordedAtLocal,
        public DeviceEventCategory $category,
        public string $code,
        public array $payload,
        public ?float $deviceIndex,
        public int $sourceLine,
    ) {}
}

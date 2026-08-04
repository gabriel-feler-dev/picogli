<?php

declare(strict_types=1);

namespace App\Domain\Import\Persistence;

use DateTimeImmutable;
use DateTimeZone;

/**
 * O que toda linha gravada precisa saber: de quem é, de qual import veio, e
 * em que fuso o aparelho estava.
 *
 * ⚠️ O fuso é a peça que o CSV **não** traz (§A5). Sem ele não há como derivar
 * `recorded_at_utc`, e errá-lo desloca todo insight de horário mantendo os
 * números plausíveis. Por isso ele é obrigatório aqui, e não um parâmetro
 * opcional com default — um default silencioso seria exatamente o bug.
 */
final readonly class ImportContext
{
    public DateTimeZone $timezone;

    public function __construct(
        public int $userId,
        public int $importId,
        string $timezone,
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    /**
     * Deriva as quatro colunas de tempo a partir do instante local do CSV.
     *
     * `recorded_at_local` é gravado literalmente como veio — é a fonte da
     * verdade para bucketing por horário. `recorded_at_utc` é derivado, e serve
     * para ordenação cronológica.
     *
     * Em hora ambígua de fim de horário de verão o PHP escolhe a primeira
     * ocorrência. A ordem correta não depende disso: `device_index` é monotônico
     * e desempata (§A6, FR-007).
     *
     * @return array{recorded_at_local: string, recorded_at_utc: string, local_date: string, local_hour: int}
     */
    public function timeColumns(DateTimeImmutable $local): array
    {
        $wallClock = $local->format('Y-m-d H:i:s');

        $inZone = new DateTimeImmutable($wallClock, $this->timezone);
        $utc = $inZone->setTimezone(new DateTimeZone('UTC'));

        return [
            'recorded_at_local' => $wallClock,
            'recorded_at_utc' => $utc->format('Y-m-d H:i:s'),
            'local_date' => $local->format('Y-m-d'),
            'local_hour' => (int) $local->format('G'),
        ];
    }
}

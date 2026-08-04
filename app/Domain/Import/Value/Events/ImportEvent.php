<?php

declare(strict_types=1);

namespace App\Domain\Import\Value\Events;

use DateTimeImmutable;

/**
 * Contrato comum dos eventos extraídos de uma linha do CSV.
 *
 * Usa declaração de propriedade em interface (PHP 8.4) em vez de getters: os
 * eventos são DTOs imutáveis, e três getters repetidos em oito classes seriam
 * 24 linhas sem informação. `public readonly` promovido satisfaz `{ get; }`.
 *
 * ⚠️ `$recordedAtLocal` é HORA LOCAL DE PAREDE do dispositivo (§A5). O arquivo
 * não carrega fuso. Derivar `recorded_at_utc`, `local_date` e `local_hour` é
 * responsabilidade do Job, que conhece `imports.timezone` — nunca do evento.
 * Ver constitution.md Artigo VIII.5.
 */
interface ImportEvent
{
    public DateTimeImmutable $recordedAtLocal { get; }

    /** Coluna `Index` do CSV — única ordem confiável, desempata DST (§A6). */
    public ?float $deviceIndex { get; }

    /** Linha de origem no arquivo, para diagnóstico em `parse_warnings`. */
    public int $sourceLine { get; }
}

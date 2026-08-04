<?php

declare(strict_types=1);

namespace App\Domain\Import\Value\Events;

/**
 * Motivo pelo qual uma linha não gerou nenhum evento.
 *
 * A distinção entre "ignorada" e "desconhecida" é o que torna a contagem
 * auditável. Sem ela, o único jeito de não poluir `parse_warnings` seria
 * descartar linhas em silêncio — e aí ninguém consegue verificar que
 * 3.616 + 77 + 56 = 3.749 fecha.
 *
 * Ver plan.md §Ignorada ≠ desconhecida.
 */
enum IgnoredReason: string
{
    /** Nada além de Index/Date/Time. Ocorre 25x no bloco Pump. Silenciosa. */
    case EmptyRow = 'empty_row';

    /** `Event Marker` = Start/End of the day. 56x no bloco Sensor. Silenciosa. */
    case DayMarker = 'day_marker';

    /** Metadado da calculadora sem volume. 5x no bloco Pump. Silenciosa. */
    case WizardDetail = 'wizard_detail';

    /** Data/hora não parseável. Vira warning — evento sem timestamp é inútil. */
    case InvalidTimestamp = 'invalid_timestamp';

    /** Forma de linha nova. Vira warning, NÃO aborta o import. */
    case Unrecognized = 'unrecognized';

    /** Se este descarte deve aparecer em `parse_warnings`. */
    public function isWarning(): bool
    {
        return match ($this) {
            self::InvalidTimestamp, self::Unrecognized => true,
            default => false,
        };
    }
}

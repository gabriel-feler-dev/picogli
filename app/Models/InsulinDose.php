<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RecordsEventTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma dose de insulina — pedido, cálculo e entrega já consolidados (§A3).
 *
 * ⚠️ Artigo VIII.3: `units_selected` NUNCA entra em soma de insulina. Somá-lo
 * junto com `units_delivered` dobra o total (295,150 U viram 590,300 U). Use
 * sempre `scopeDelivered()` ou some `units_delivered` explicitamente.
 */
class InsulinDose extends Model
{
    use RecordsEventTime;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            ...$this->eventTimeCasts(),
            'delivered_at_local' => 'datetime',
            'units_selected' => 'float',
            'units_delivered' => 'float',
            'bolus_number' => 'integer',
            'is_automatic' => 'boolean',
        ];
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    /**
     * Chave de deduplicação — resolve um furo real no FR-006.
     *
     * A chave natural (user_id, recorded_at_local, kind, bolus_number) tem
     * coluna NULLABLE, e MySQL e SQLite tratam NULL como DISTINTO em índice
     * único. Duas doses sem número no mesmo instante não colidiriam, e cada
     * reimportação inseriria de novo.
     *
     * Aqui o nulo vira explícito (`-`), então a chave é sempre comparável.
     * Preferido a um valor-sentinela como `bolus_number = 0`, que seria um
     * chute sobre o domínio do contador da bomba.
     */
    public static function makeDedupeKey(string $recordedAtLocal, string $kind, ?int $bolusNumber): string
    {
        return sha1(implode('|', [$recordedAtLocal, $kind, $bolusNumber ?? '-']));
    }

    public function isCancelled(): bool
    {
        return $this->cancellation_reason !== null;
    }

    /** Pediu X, entregou menos. Diferente de cancelado, que não tem volume. */
    public function isPartial(): bool
    {
        return $this->units_selected !== null
            && $this->units_delivered !== null
            && abs($this->units_selected - $this->units_delivered) > 0.0005;
    }

    /** Doses que de fato entregaram insulina — a base de qualquer soma. */
    public function scopeDelivered($query)
    {
        return $query->whereNotNull('units_delivered');
    }
}

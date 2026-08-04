<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Total DIÁRIO de insulina entregue pelo SmartGuard (bloco 2 do CSV).
 *
 * ⚠️ NÃO é bolus pontual — é agregado do dia, e não tem hora. Ignorar este
 * bloco subestima a insulina total em ~60% num usuário de 780G com loop
 * fechado: 31,4 U/dia automáticas contra 21,1 U/dia de bolus no export de
 * referência.
 */
class DailyAutoInsulin extends Model
{
    protected $table = 'daily_auto_insulin';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['units_delivered' => 'float'];
    }

    /** Ver RecordsEventTime::localDate() — mesma armadilha SQLite/MySQL. */
    protected function localDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : substr($value, 0, 10),
            set: fn (mixed $value): ?string => match (true) {
                $value === null => null,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                default => substr((string) $value, 0, 10),
            },
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}

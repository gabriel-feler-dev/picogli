<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma importação de export do CareLink.
 *
 * `file_hash` + a chave única `(user_id, file_hash)` são o que torna reenviar o
 * mesmo arquivo um no-op (FR-006), antes de processar 4 mil linhas de novo.
 */
class Import extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'block_row_counts' => 'array',
            'parse_warnings' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sensorReadings(): HasMany
    {
        return $this->hasMany(SensorReading::class);
    }

    public function insulinDoses(): HasMany
    {
        return $this->hasMany(InsulinDose::class);
    }

    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class);
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    /** FR-010 — nenhuma linha desconhecida no arquivo. */
    public function hasWarnings(): bool
    {
        return ! empty($this->parse_warnings);
    }
}

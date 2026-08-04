<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Métricas de um dia local (FR-108).
 *
 * ⚠️ Artigo V — `coverage_pct` acompanha todo consumo destes números. Uma média
 * de 142 sobre 34% de captura (como o dia 22/07) não é comparável com uma sobre
 * 100%, e exibi-las lado a lado sem o denominador engana.
 */
class DailyMetrics extends Model
{
    protected $table = 'daily_metrics';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reading_count' => 'integer',
            'coverage_pct' => 'float',
            'mean_glucose' => 'float',
            'sd_glucose' => 'float',
            'cv_pct' => 'float',
            'tir_pct' => 'float',
            'tar_level1_pct' => 'float',
            'tar_level2_pct' => 'float',
            'tbr_level1_pct' => 'float',
            'tbr_level2_pct' => 'float',
            'total_insulin_u' => 'float',
            'auto_insulin_u' => 'float',
            'bolus_insulin_u' => 'float',
            'total_carbs_g' => 'float',
        ];
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

    /** A soma das cinco faixas — invariante que deve dar 100%. */
    public function rangeSum(): float
    {
        return $this->tbr_level2_pct + $this->tbr_level1_pct + $this->tir_pct
            + $this->tar_level1_pct + $this->tar_level2_pct;
    }
}

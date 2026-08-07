<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Import\Pdf\Value\PdfAggregate as PdfAggregateValue;
use App\Domain\Import\Pdf\Value\PdfMetric;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um agregado de PDF gravado (Spec 007, FR-705).
 *
 * ⚠️ **`source` é sempre `pdf_aggregate`**, e há teste. É o que permite exibir
 * este número ao lado da métrica equivalente de CSV **e dizer qual é qual** (§D7).
 */
class PdfAggregate extends Model
{
    protected $table = 'pdf_aggregates';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metric' => PdfMetric::class,
            'value' => 'float',
        ];
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)->orderByDesc('period_end');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /** ⚠️ Sempre verdadeiro — e o teste que o cobra é o que garante o §D7. */
    public function isFromPdf(): bool
    {
        return $this->source === PdfAggregateValue::SOURCE;
    }

    /** Ver PeriodReport::dateOnly() — mesma armadilha SQLite/MySQL. */
    protected function periodStart(): Attribute
    {
        return $this->dateOnly();
    }

    protected function periodEnd(): Attribute
    {
        return $this->dateOnly();
    }

    private function dateOnly(): Attribute
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
}

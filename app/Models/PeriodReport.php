<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Patterns\PatternEngine;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um relatório de padrões gravado (FR-413).
 *
 * ⚠️ `isStale()` compara as DUAS versões (§D9). Um relatório pode estar
 * desatualizado por dois motivos independentes — as regras mudaram, ou as
 * fórmulas de que ele derivou mudaram — e os dois pedem regeneração.
 */
class PeriodReport extends Model
{
    protected $table = 'period_reports';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'findings' => 'array',
            'rule_failures' => 'array',
            'finding_count' => 'integer',
            'coverage_pct' => 'float',
            'span_days' => 'float',
            'generated_at' => 'datetime',
            'narrative_generated_at' => 'datetime',
        ];
    }

    /**
     * ⚠️ `null` é o estado NORMAL, não a exceção. Relatório sem narrativa é o
     * relatório de hoje, e a tela já sabe renderizá-lo (§D3).
     */
    public function hasNarrative(): bool
    {
        return $this->narrative !== null && trim((string) $this->narrative) !== '';
    }

    /** Ver RecordsEventTime::localDate() — mesma armadilha SQLite/MySQL. */
    protected function periodStart(): Attribute
    {
        return $this->dateOnly();
    }

    protected function periodEnd(): Attribute
    {
        return $this->dateOnly();
    }

    /**
     * ⚠️ O cast `date` do Laravel grava `2026-07-29 00:00:00`. O MySQL trunca ao
     * gravar numa coluna `DATE`; o SQLite **não**. Sem esta normalização, um
     * `whereBetween` funcionaria em produção e falharia em desenvolvimento — ou,
     * pior, o contrário.
     */
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

    /**
     * O relatório foi gerado por versão antiga das regras ou das métricas?
     *
     * ⚠️ **Sinalizar, nunca recalcular em silêncio.** Recalcular escondido
     * misturaria achados de duas versões de regra sem ninguém perceber qual é
     * qual — e o texto continuaria plausível nas duas.
     */
    public function isStale(): bool
    {
        return $this->engine_version !== PatternEngine::VERSION
            || $this->metrics_version !== DailyMetricsWriter::VERSION;
    }

    public function hasRuleFailures(): bool
    {
        return ! empty($this->rule_failures);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comportamento comum das tabelas de evento do importador.
 *
 * ⚠️ Artigo VIII.5 — as duas colunas de tempo NÃO são intercambiáveis:
 *
 *   `recorded_at_local`  hora de parede do aparelho. Use para BUCKETING por
 *                        horário. É o que dá sentido a "seu pior horário".
 *   `recorded_at_utc`    use para ORDENAÇÃO cronológica e comparação entre
 *                        aparelhos, com `device_index` desempatando DST.
 *
 * Trocar as duas desloca todo insight de horário mantendo os números
 * plausíveis — o segundo pior bug possível neste projeto.
 */
trait RecordsEventTime
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /** Filtra por intervalo de DIAS LOCAIS — o recorte que o produto usa. */
    public function scopeBetweenLocalDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('local_date', [$from, $to]);
    }

    /**
     * `local_date` sempre como `Y-m-d`, entrando e saindo.
     *
     * ⚠️ Armadilha de portabilidade, medida: o cast `date` do Laravel grava
     * `2026-07-29 00:00:00` mesmo numa coluna `DATE`. O **MySQL trunca sozinho;
     * o SQLite guarda a string inteira** (tipagem dinâmica). Resultado: um
     * `whereBetween('local_date', ['2026-07-29', '2026-07-29'])` acha a linha em
     * produção e NÃO acha em desenvolvimento.
     *
     * É o pior formato de bug possível — o que funciona só num dos ambientes.
     * Normalizar aqui garante comportamento idêntico nos dois (Artigo IX).
     *
     * Efeito colateral desejado: `local_date` é `string`, não `Carbon`. Ele é
     * chave de agrupamento por dia, não um instante — tratá-lo como data com
     * hora só convida a confusão de fuso.
     */
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

    /** Ordem cronológica correta, com desempate de horário de verão (§A6). */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('recorded_at_utc')->orderBy('device_index');
    }

    /** @return array<string, string> */
    protected function eventTimeCasts(): array
    {
        return [
            'recorded_at_local' => 'datetime',
            'recorded_at_utc' => 'datetime',
            'local_hour' => 'integer',
            'device_index' => 'float',
        ];
    }
}

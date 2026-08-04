<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perfil de razão de carboidrato e sensibilidade, reconstruído das colunas
 * `BWZ *` (FR-008).
 *
 * O CSV não traz o relatório "Definições do dispositivo", mas cada bolus
 * registra a configuração vigente naquele momento — e isso basta para
 * reconstruir o perfil por horário.
 */
class DeviceSettingsSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'carb_ratio_profile' => 'array',
            'isf_values' => 'array',
            'basal_profile' => 'array',
        ];
    }

    /**
     * ⚠️ JSON não distingue `10.0` de `10`.
     *
     * `json_encode(10.0)` produz `10`, e `json_decode` devolve `int`. Sem
     * normalizar, uma razão de carboidrato de 10 g/U volta como inteiro e a de
     * 5,5 volta como float — o tipo passa a depender do valor.
     *
     * Isso é inofensivo em aritmética e venenoso em comparação estrita, que é
     * exatamente o que a regra R6 da Spec 004 vai fazer ao cruzar o perfil
     * configurado com o tempo acima da faixa.
     */
    protected function carbRatioProfile(): Attribute
    {
        return Attribute::make(get: $this->floatValues(...));
    }

    protected function isfValues(): Attribute
    {
        return Attribute::make(get: $this->floatValues(...));
    }

    protected function basalProfile(): Attribute
    {
        return Attribute::make(get: $this->floatValues(...));
    }

    /** @return array<array-key, float>|null */
    private function floatValues(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_map(floatval(...), $decoded) : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /** Hash do conteúdo — um snapshot novo só nasce quando a config muda. */
    public static function makeFingerprint(array $carbRatio, array $isf, ?array $basal): string
    {
        ksort($carbRatio);
        sort($isf);

        return sha1(json_encode([$carbRatio, $isf, $basal], JSON_THROW_ON_ERROR));
    }
}

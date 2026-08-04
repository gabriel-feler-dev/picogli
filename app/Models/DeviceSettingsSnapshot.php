<?php

declare(strict_types=1);

namespace App\Models;

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

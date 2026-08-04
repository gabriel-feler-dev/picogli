<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RecordsEventTime;
use Illuminate\Database\Eloquent\Model;

/**
 * Glicemia capilar (picada no dedo).
 *
 * `used_for_calibration` marca a leitura ENVIADA para calibrar. O valor ACEITO
 * pelo sensor é outro registro, em `device_events` com categoria `calibration`.
 * São 39 de cada no export de referência — para calcular erro do sensor (MARD),
 * o relevante é o aceito.
 */
class BgReading extends Model
{
    use RecordsEventTime;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            ...$this->eventTimeCasts(),
            'glucose_mgdl' => 'integer',
            'used_for_calibration' => 'boolean',
        ];
    }
}

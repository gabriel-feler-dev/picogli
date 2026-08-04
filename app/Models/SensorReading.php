<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RecordsEventTime;
use Illuminate\Database\Eloquent\Model;

/**
 * Leitura de CGM. ~288/dia — a tabela de volume.
 *
 * ⚠️ É a ÚNICA fonte válida para TIR, GMI e CV. Glicemia capilar vive em
 * `bg_readings`, separada de propósito: frequência irregular e propósito
 * diferente distorceriam as métricas se entrassem na mesma série.
 */
class SensorReading extends Model
{
    use RecordsEventTime;

    protected $guarded = [];

    protected function casts(): array
    {
        return [...$this->eventTimeCasts(), 'glucose_mgdl' => 'integer', 'isig' => 'float'];
    }
}

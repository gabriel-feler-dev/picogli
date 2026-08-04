<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RecordsEventTime;
use Illuminate\Database\Eloquent\Model;

/**
 * Alerta, dispensa de alerta, suspensão, troca de reservatório, prime, estado
 * do sensor ou calibração aceita.
 *
 * `category` = `alert` é o alerta DISPARANDO; `alert_cleared` é o usuário
 * DISPENSANDO. Colunas distintas no CSV, semânticas distintas — cruzar as duas
 * dá tempo de resposta a alerta.
 */
class DeviceEvent extends Model
{
    use RecordsEventTime;

    protected $guarded = [];

    protected function casts(): array
    {
        return [...$this->eventTimeCasts(), 'payload' => 'array'];
    }

    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}

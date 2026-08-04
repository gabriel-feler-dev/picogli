<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RecordsEventTime;
use Illuminate\Database\Eloquent\Model;

/** Mudança de taxa basal programada (perfil manual). */
class BasalRate extends Model
{
    use RecordsEventTime;

    protected $guarded = [];

    protected function casts(): array
    {
        return [...$this->eventTimeCasts(), 'rate_uh' => 'float'];
    }
}

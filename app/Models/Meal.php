<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RecordsEventTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Refeição, vinda de uma linha BWZ (calculadora de bolus).
 *
 * `peak_2h`, `delta_2h` e `glucose_4h` NÃO vêm do CSV — são preenchidos no
 * pós-import pelo MealEnricher, consultando as leituras de sensor.
 */
class Meal extends Model
{
    use RecordsEventTime;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            ...$this->eventTimeCasts(),
            'carbs_g' => 'float',
            'carb_ratio' => 'float',
            'insulin_sensitivity' => 'float',
            'target_low' => 'integer',
            'target_high' => 'integer',
            'bg_input' => 'integer',
            'estimate_u' => 'float',
            'correction_u' => 'float',
            'food_u' => 'float',
            'active_insulin_u' => 'float',
            'peak_2h' => 'integer',
            'delta_2h' => 'integer',
            'glucose_4h' => 'integer',
        ];
    }

    public function dose(): HasOne
    {
        return $this->hasOne(InsulinDose::class);
    }

    /** Se o enriquecimento pós-import já rodou para esta refeição. */
    public function hasGlycemicResponse(): bool
    {
        return $this->peak_2h !== null;
    }
}

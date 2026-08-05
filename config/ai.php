<?php

declare(strict_types=1);

/**
 * Camada de IA (Spec 005).
 *
 * ⚠️ **A chave NUNCA tem default no código** (NFR-503). Vem de `.env`, que já é
 * gitignorado junto com `.env.*`. Este repositório é portfólio público.
 */
return [

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'timeout_seconds' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | ALLOWLIST DO PAYLOAD — Artigo VII
    |--------------------------------------------------------------------------
    |
    | ⚠️ **Só o que está aqui sai daqui.** Chave não prevista é descartada e
    | logada.
    |
    | *Por quê allowlist e não denylist:* com denylist, um campo novo num export
    | futuro vira vazamento silencioso. Com allowlist, o comportamento seguro é o
    | default, e a chave nova falha em teste até alguém revisá-la.
    |
    | **Duas barreiras, não uma.** O construtor de `Finding` já valida a chave por
    | `/^[a-z][a-z0-9_]*$/` — e as chaves do CareLink são `Last Name`,
    | `Patient ID`, `BG Reading (mg/dL)`, nenhuma delas passa. Essa é a barreira
    | ESTRUTURAL. A lista abaixo é a barreira EDITORIAL: impede que uma chave nova
    | e legítima saia sem revisão humana.
    |
    | ⚠️ Um teste falha se o motor emitir chave que não esteja aqui. Ao adicionar
    | uma regra, o teste quebra — de propósito.
    |
    */

    'payload_allowlist' => [

        // ── Identificação do achado (valores de enum, não do paciente) ──────
        'rule_id', 'severity', 'rank',

        // ── Denominador do período (Artigo V) ───────────────────────────────
        'period_from', 'period_to', 'coverage_percent', 'span_days', 'validity',

        // ── R1 · deriva por período do dia ─────────────────────────────────
        'worst_daypart', 'worst_percent_above', 'worst_readings',
        'best_daypart', 'best_percent_above', 'best_readings', 'ratio',
        'difference_pp',

        // ⚠️ Geradas por período do dia, uma por `Daypart::value`. Não estão nos
        // blocos `evidence` de `lang/`, e uma allowlist derivada só de lá as
        // perderia em silêncio.
        'dawn_percent_above', 'dawn_readings',
        'morning_percent_above', 'morning_readings',
        'afternoon_percent_above', 'afternoon_readings',
        'evening_percent_above', 'evening_readings',

        // ── R2 · cluster de hipoglicemias ──────────────────────────────────
        'episodes_total', 'episodes_clustered', 'episodes_outside',
        'windows_used', 'window_hours', 'concentration_percent', 'worst_nadir',
        'window1_start', 'window1_end', 'window1_episodes', 'window1_nadir',
        'window2_start', 'window2_end', 'window2_episodes', 'window2_nadir',

        // ── R3 · montanha-russa ────────────────────────────────────────────
        'date', 'nadir', 'nadir_at', 'hypo_duration_minutes', 'meals',
        'carbs_g', 'first_meal_at', 'last_meal_at', 'hyper_start_at',
        'hyper_duration_minutes', 'hyper_duration_hours', 'hyper_peak',
        'minutes_from_nadir_to_hyper',

        // ── R4 · dia outlier ───────────────────────────────────────────────
        'metric', 'dominant_date', 'dominant_readings', 'dominant_minutes',
        'total_readings', 'total_minutes', 'contribution_percent',
        'days_total', 'days_affected', 'clean_days',

        // ── R5 · gap × loop fechado ────────────────────────────────────────
        'gap_minutes', 'gap_hours', 'gap_start', 'gap_end', 'affected_date',
        'gap_minutes_on_date', 'auto_insulin_u', 'period_mean_auto_insulin_u',
        'drop_percent', 'day_automatic_fraction_percent',
        'period_automatic_fraction_percent', 'day_bolus_insulin_u',
        'day_coverage_percent',

        // ── R6 · coerência de CR ───────────────────────────────────────────
        'strongest_daypart', 'strongest_carb_ratio', 'strongest_meals',
        'strongest_percent_above', 'weakest_daypart', 'weakest_carb_ratio',
        'weakest_meals', 'weakest_percent_above', 'ratio_spread_g',
        'percent_above_difference_pp', 'dayparts_compared',

        // ⚠️ Também geradas por período do dia (ver nota em R1).
        'dawn_carb_ratio', 'dawn_meals',
        'morning_carb_ratio', 'morning_meals',
        'afternoon_carb_ratio', 'afternoon_meals',
        'evening_carb_ratio', 'evening_meals',

        // ── R7 · aderência ao sensor ───────────────────────────────────────
        'days_below_threshold', 'threshold_percent', 'worst_date',
        'worst_coverage_percent', 'period_coverage_percent', 'days_below_100',

        // ── R8 · trocas de reservatório ────────────────────────────────────
        'rewinds', 'primes', 'intervals', 'mean_interval_days',
        'shortest_interval_days', 'longest_interval_days',
        'set_change_reminders',

        // ── R9 · carga de calibração ───────────────────────────────────────
        'calibrations', 'days', 'per_day',

        // ── R10 · qualidade do sensor ──────────────────────────────────────
        'pairs', 'window_minutes', 'mean_error_percent', 'median_error_percent',
        'max_error_percent', 'mean_offset_minutes', 'expected_error_percent',
        'pairs_sensor_higher',
    ],

];

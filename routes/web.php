<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Rotas da fase 3. Ainda sem autenticação — ela entra no T201, e as rotas do
 * dashboard passam a exigi-la ali.
 */

Route::get('/', HealthController::class)->name('health');

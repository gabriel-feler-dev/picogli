<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Rotas da fase 3.
 *
 * Sem cadastro público (§D5): o usuário é criado por `LocalUserSeeder`.
 */

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Placeholder até o T204 trazer o dashboard real.
    Route::get('/dashboard', HealthController::class)->name('dashboard');
});

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

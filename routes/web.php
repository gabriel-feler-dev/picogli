<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\ImportController;
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

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Fase 4 — motor de padrões. Lê o relatório gravado por `ComputePatternsJob`.
    Route::get('/avaliacao', EvaluationController::class)->name('evaluation');

    Route::get('/importar', [ImportController::class, 'index'])->name('imports.index');
    Route::post('/importar', [ImportController::class, 'store'])->name('imports.store');
});

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

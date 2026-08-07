<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MealsController;
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

    /*
     * Fase 7 — refeições. ⚠️ O rótulo é o primeiro dado do produto que não vem
     * do aparelho (Spec 007 §D2). Ele ROTULA, agrupa e filtra — nunca entra em
     * fórmula cujo resultado o produto apresenta como medição.
     */
    Route::get('/refeicoes', [MealsController::class, 'index'])->name('meals.index');
    Route::patch('/refeicoes/{meal}/rotulo', [MealsController::class, 'label'])->name('meals.label');

    /*
     * Fase 6 — chat. O modelo consulta por ferramenta; nenhum dado vai no prompt.
     *
     * ⚠️ **Rate limit próprio** (§D12, §11.3), independente do cooldown da
     * `ModelChain`. Os dois protegem coisas diferentes: o cooldown protege a
     * COTA do provedor; este protege o produto. Um laço acidental no front
     * consumiria as 1.500 requisições do dia antes de qualquer cooldown
     * perceber que há algo errado.
     */
    Route::get('/conversar', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/conversar', [ChatController::class, 'store'])->name('chat.store');
    Route::get('/conversar/{conversation}', [ChatController::class, 'show'])->name('chat.show');

    Route::post('/conversar/{conversation}/mensagens', [ChatController::class, 'message'])
        ->middleware('throttle:chat')
        ->name('chat.message');
});

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));

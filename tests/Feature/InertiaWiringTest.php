<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T200.6 — FR-201 (Wiring do Inertia)
 *
 * Prova que a cadeia completa funciona: middleware → controller → props
 * tipados → componente React resolvido.
 */
it('responde com uma página Inertia, não HTML solto', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Health'));
});

it('entrega os props do servidor', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('appName', config('app.name'))
        ->has('phase')
        ->has('importsCount')
        ->has('readingsCount')
    );
});

// ⚠️ NFR-201 — o React recebe números prontos. Se a contagem fosse feita no
// componente, este teste passaria mesmo com o prop ausente, e a divergência com
// a fase 5 só apareceria em produção.
it('as contagens vêm calculadas do servidor', function () {
    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('importsCount', 0)
        ->where('readingsCount', 0)
    );

    $user = User::factory()->create();

    App\Models\Import::create([
        'user_id' => $user->id,
        'original_filename' => 'x.csv',
        'file_hash' => str_repeat('b', 64),
        'timezone' => 'America/Sao_Paulo',
        'glucose_unit' => 'mg/dL',
        'status' => App\Models\Import::STATUS_DONE,
    ]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('importsCount', 1));
});

it('o template raiz carrega os assets compilados', function () {
    $html = $this->get('/')->getContent();

    // @vite aponta para app.tsx (não app.js, que foi removido) e @inertia
    // renderiza o div raiz. Sem os dois, a página é HTML morto.
    expect($html)->toContain('data-page');
    expect($html)->not->toContain('resources/js/app.js');
});

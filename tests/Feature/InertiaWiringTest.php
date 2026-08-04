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
beforeEach(function () {
    // A rota passou a exigir autenticação no T201. O wiring do Inertia é o que
    // está sob teste aqui, não o acesso — esse é assunto do AuthTest.
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('responde com uma página Inertia, não HTML solto', function () {
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Health'));
});

it('entrega os props do servidor', function () {
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
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
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('importsCount', 0)
        ->where('readingsCount', 0)
    );

    App\Models\Import::create([
        'user_id' => $this->user->id,
        'original_filename' => 'x.csv',
        'file_hash' => str_repeat('b', 64),
        'timezone' => 'America/Sao_Paulo',
        'glucose_unit' => 'mg/dL',
        'status' => App\Models\Import::STATUS_DONE,
    ]);

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('importsCount', 1));
});

it('o template raiz carrega os assets compilados', function () {
    $html = $this->get('/dashboard')->getContent();

    // @vite aponta para app.tsx (não app.js, que foi removido) e @inertia
    // renderiza o div raiz. Sem os dois, a página é HTML morto.
    expect($html)->toContain('data-page');
    expect($html)->not->toContain('resources/js/app.js');
});

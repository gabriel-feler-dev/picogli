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
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
});

it('entrega os props do servidor', function () {
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->has('summary')
        ->has('summary.coverage')
        ->has('isEmpty')
    );
});

it('o template raiz carrega os assets compilados', function () {
    $html = $this->get('/dashboard')->getContent();

    // @vite aponta para app.tsx (não app.js, que foi removido) e @inertia
    // renderiza o div raiz. Sem os dois, a página é HTML morto.
    expect($html)->toContain('data-page');
    expect($html)->not->toContain('resources/js/app.js');
});

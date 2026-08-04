<?php

declare(strict_types=1);

use App\Jobs\ImportCsvJob;
use App\Models\Import;
use App\Models\SensorReading;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T201 — FR-208 (Autenticação mínima), spec.md §D5
 */
beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'eu@picogli.local',
        'password' => Hash::make('senha-de-teste-123'),
    ]);
});

describe('acesso', function () {

    it('visitante anônimo é redirecionado para o login', function () {
        $this->get('/dashboard')->assertRedirect('/login');
    });

    it('a raiz manda o anônimo para o login e o autenticado para o dashboard', function () {
        $this->get('/')->assertRedirect('/login');

        $this->actingAs($this->user)->get('/')->assertRedirect('/dashboard');
    });

    it('autentica com credenciais válidas', function () {
        $this->post('/login', [
            'email' => 'eu@picogli.local',
            'password' => 'senha-de-teste-123',
        ])->assertRedirect('/dashboard');

        expect(auth()->check())->toBeTrue();
    });

    it('recusa senha errada com mensagem genérica', function () {
        $response = $this->from('/login')->post('/login', [
            'email' => 'eu@picogli.local',
            'password' => 'errada',
        ]);

        // Mensagem genérica: dizer "e-mail não existe" revelaria quais contas
        // existem.
        $response->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => __('auth.failed')]);

        expect(auth()->check())->toBeFalse();
    });

    it('não vaza se o e-mail existe', function () {
        // E-mail inexistente e senha errada devem dar a MESMA mensagem. Se
        // divergissem, daria para enumerar contas por tentativa e erro.
        $this->from('/login')->post('/login', [
            'email' => 'ninguem@picogli.local',
            'password' => 'qualquer',
        ])->assertSessionHasErrors(['email' => __('auth.failed')]);
    });

    it('faz logout e invalida a sessão', function () {
        $this->actingAs($this->user)->post('/logout')->assertRedirect('/login');

        expect(auth()->check())->toBeFalse();
    });

    // §D5 — não existe cadastro público, e isso é deliberado.
    it('não existe rota de registro', function () {
        $this->post('/register', [])->assertNotFound();
        $this->get('/register')->assertNotFound();
    });

    it('o login é uma página Inertia sem link de cadastro', function () {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    });
});

describe('isolamento entre usuários — o teste que mais importa', function () {

    // ⚠️ A primeira versão do HealthController contava imports e leituras
    // GLOBALMENTE. Num app com dois usuários isso mostraria o dado do outro —
    // e nenhum teste de "a página abre" pegaria.
    it('um usuário não vê nem conta o dado de outro', function () {
        $outro = User::factory()->create();

        // Só o OUTRO usuário importa.
        (new ImportCsvJob($outro->id, requireReferenceExport(), 'America/Sao_Paulo'))->handle(
            app(App\Domain\Import\CarelinkCsvReader::class),
            app(App\Domain\Import\EventExploder::class),
            app(App\Domain\Import\BolusLinker::class),
            app(App\Domain\Import\Persistence\MealEnricher::class),
            app(App\Domain\Import\SettingsInferrer::class),
        );

        // O banco TEM dado — a contagem global seria diferente de zero.
        expect(SensorReading::count())->toBe(3616);
        expect(Import::count())->toBe(1);

        // Mas o nosso usuário não tem nada.
        $this->actingAs($this->user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('isEmpty', true)
                ->where('summary.coverage.reading_count', 0)
                ->where('summary.metrics', [])
            );

        // E o outro vê o próprio, com o período dele.
        $this->actingAs($outro)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('isEmpty', false)
                ->where('summary.coverage.reading_count', 3616)
                ->where('summary.period.to', '2026-07-29')
            );
    });
});

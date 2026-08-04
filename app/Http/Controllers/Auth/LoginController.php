<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Autenticação mínima (FR-208, spec.md §D5).
 *
 * ⚠️ **Não existe cadastro público, e isso é deliberado.** O produto é pessoal
 * (Spec 001 §Para quem). Um formulário de registro aberto num app que guarda
 * dado de saúde é superfície de ataque sem contrapartida — o usuário é criado
 * por seeder.
 *
 * Multi-tenant de verdade (policies, convites, auditoria) não se paga com um
 * usuário e atrasaria a única coisa que importa nesta fase: a tela existir.
 */
class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // Mensagem genérica de propósito: dizer "e-mail não existe" revela
            // quais contas existem. Aqui há um usuário só, mas o hábito é o que
            // importa.
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Presentation\MealsPresenter;
use App\Models\Meal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A tela de refeições e o rótulo (Spec 007, FR-701, FR-702).
 *
 * ⚠️ **O rótulo é o primeiro dado do produto que não vem do aparelho** (§D2), e
 * a distinção que o torna aceitável é: ele é **texto**, e não entra em fórmula.
 *
 * Peso do usuário foi cortado desta fase pelo motivo oposto — seria número, e
 * dividiria a insulina total, produzindo uma métrica que *parece medida e está
 * errada* quando o campo envelhece.
 *
 * **A regra que sai daí, e vale para o futuro:** dado digitado pode rotular,
 * agrupar e filtrar. Não pode entrar em fórmula cujo resultado o produto
 * apresenta como medição.
 */
final class MealsController extends Controller
{
    public function index(Request $request, MealsPresenter $presenter): Response
    {
        return Inertia::render('Meals', $presenter->forLatestPeriod(
            (int) $request->user()->id,
        ));
    }

    /**
     * Grava ou apaga o rótulo de uma refeição.
     *
     * ⚠️ Escopado por `user_id`, e o 404 é deliberado: dizer "essa refeição não é
     * sua" confirmaria que ela existe.
     */
    public function label(Request $request, Meal $meal): RedirectResponse
    {
        abort_unless($meal->user_id === (int) $request->user()->id, 404);

        $dados = $request->validate([
            // ⚠️ Curto de propósito. Rótulo é etiqueta — "pizza", "feijoada",
            // "café da manhã". Campo longo convida a virar diário, e diário
            // pediria uma spec própria.
            'label' => ['nullable', 'string', 'max:60'],
        ]);

        $rotulo = trim((string) ($dados['label'] ?? ''));

        // Apagar é caso de uso, não erro: a pessoa rotulou errado e quer desfazer.
        // ⚠️ E `null` é o estado normal da coluna — não um valor especial.
        $meal->update(['label' => $rotulo === '' ? null : $rotulo]);

        return back();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Presentation\ComparisonPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A tela de comparação entre períodos (Spec 007, FR-704).
 *
 * ⚠️ O recorte padrão é "os últimos 7 dias contra os 7 anteriores", ancorado na
 * última leitura do usuário — não em `now()`. Quem importa um export de duas
 * semanas atrás quer comparar aquele período.
 */
final class ComparisonController extends Controller
{
    public function __invoke(Request $request, ComparisonPresenter $presenter): Response
    {
        $userId = (int) $request->user()->id;

        $dados = $request->validate([
            'a_start' => ['nullable', 'date_format:Y-m-d'],
            'a_end' => ['nullable', 'date_format:Y-m-d'],
            'b_start' => ['nullable', 'date_format:Y-m-d'],
            'b_end' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $completo = count(array_filter($dados, fn ($v): bool => $v !== null)) === 4;

        return Inertia::render('Comparison', $completo
            // ⚠️ A validação de coerência (início ≤ fim, span máximo) é do
            // `ArgumentValidator`, atrás do `ToolRegistry` — o mesmo caminho que
            // o modelo usa. Duplicá-la aqui criaria duas regras de período.
            ? $presenter->compare($userId, $dados['a_start'], $dados['a_end'], $dados['b_start'], $dados['b_end'])
            : $presenter->latestVersusPrevious($userId));
    }
}

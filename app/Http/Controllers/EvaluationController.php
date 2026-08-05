<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Presentation\EvaluationPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A tela de avaliação (FR-414).
 *
 * ⚠️ Lê o relatório gravado. Não roda o motor, não recalcula cobertura — ver
 * `EvaluationPresenter`.
 */
final class EvaluationController extends Controller
{
    public function __invoke(Request $request, EvaluationPresenter $presenter): Response
    {
        return Inertia::render(
            'Evaluation',
            $presenter->forLatestReport($request->user()->id),
        );
    }
}

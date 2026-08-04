<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Presentation\DashboardPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * O dashboard (FR-203, FR-204).
 *
 * ⚠️ O controller não calcula nada e não formata nada. Ele pega o
 * `PeriodSummary` — que já vem com cobertura, validade e cards traduzidos — e
 * entrega. Toda decisão clínica aconteceu antes de chegar aqui.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardPresenter $presenter): Response
    {
        $summary = $presenter->forLatestPeriod($request->user()->id);

        return Inertia::render('Dashboard', [
            'summary' => $summary->toArray(),
            'isEmpty' => $summary->isEmpty(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Import;
use App\Models\SensorReading;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Diagnóstico do wiring (T200.6).
 *
 * Existe para provar que props tipados chegam do servidor ao React. Será
 * substituído pelo dashboard real no T204.
 *
 * ⚠️ As contagens são feitas AQUI, não no componente (NFR-201). Parece exagero
 * numa página de diagnóstico, mas é o hábito que a fase toda depende: assim que
 * um número passa a ser calculado no cliente, ele começa a divergir do que a
 * fase 5 vai narrar.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $userId = $request->user()->id;

        return Inertia::render('Health', [
            'appName' => config('app.name', 'PicoGli'),
            'phase' => 'Fase 3 — dashboard · T201',
            // ⚠️ TODA query escopada por user_id. A primeira versão contava
            // global, o que num app multiusuário vazaria dado de outra
            // pessoa — e o teste de isolamento do T201.4 pega isso.
            'importsCount' => Import::where('user_id', $userId)->count(),
            'readingsCount' => SensorReading::where('user_id', $userId)->count(),
        ]);
    }
}

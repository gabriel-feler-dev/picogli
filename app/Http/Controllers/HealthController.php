<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Import;
use App\Models\SensorReading;
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
    public function __invoke(): Response
    {
        return Inertia::render('Health', [
            'appName' => config('app.name', 'PicoGli'),
            'phase' => 'Fase 3 — dashboard · T200 wiring',
            'importsCount' => Import::count(),
            'readingsCount' => SensorReading::count(),
        ]);
    }
}

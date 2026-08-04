<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Presentation\ImportSummaryPresenter;
use App\Jobs\ImportCsvJob;
use App\Models\Import;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importação de export do CareLink (FR-207).
 */
class ImportController extends Controller
{
    /** Fusos oferecidos no formulário — os relevantes para o projeto. */
    private const TIMEZONES = [
        'America/Sao_Paulo',
        'America/Manaus',
        'America/Belem',
        'America/Fortaleza',
        'Europe/Lisbon',
        'UTC',
    ];

    public function index(Request $request, ImportSummaryPresenter $presenter): Response
    {
        $imports = Import::where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (Import $import): array => $presenter->present($import))
            ->all();

        return Inertia::render('Import', [
            'imports' => $imports,
            'timezones' => self::TIMEZONES,
            // §A5 — o CSV não carrega fuso. O default vem do ambiente, mas o
            // usuário confirma: errar isto desloca todo insight de horário
            // mantendo os números plausíveis.
            'defaultTimezone' => config('picogli.default_timezone'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/plain,text/csv,application/csv', 'max:51200'],
            'timezone' => ['required', 'string', 'timezone'],
        ]);

        // Armazenado fora de `public/`, em disco privado. O Job apaga depois de
        // importar: é o objeto com mais PII do sistema.
        $path = $request->file('file')->store('imports', 'local');

        ImportCsvJob::dispatch(
            userId: $request->user()->id,
            path: storage_path('app/private/'.$path),
            timezone: $validated['timezone'],
            originalFilename: $request->file('file')->getClientOriginalName(),
            deleteAfterImport: true,
        );

        return redirect()->route('imports.index')
            ->with('status', __('imports.queued'));
    }

    /** @return list<string> */
    public static function availableTimezones(): array
    {
        return array_values(array_filter(
            self::TIMEZONES,
            fn (string $tz): bool => in_array($tz, DateTimeZone::listIdentifiers(), true),
        ));
    }
}

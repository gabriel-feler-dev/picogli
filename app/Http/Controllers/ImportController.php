<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Import\Pdf\Persistence\PdfAggregateWriter;
use App\Domain\Presentation\ImportSummaryPresenter;
use App\Jobs\ImportCsvJob;
use App\Models\Import;
use App\Models\PdfAggregate;
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
        $writer = app(PdfAggregateWriter::class);
        $superseded = fn (PdfAggregate $a): bool => $writer->hasCsvFor(
            (int) $request->user()->id,
            (string) $a->period_start,
            (string) $a->period_end,
        );

        $imports = Import::where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (Import $import): array => $presenter->present($import))
            ->all();

        return Inertia::render('Import', [
            'imports' => $imports,
            /*
             * ⚠️ Agregados de PDF (Spec 007, §D7) — em bloco SEPARADO, e nunca
             * misturados com métrica de CSV.
             *
             * É o Artigo V por analogia: aquele artigo proíbe exibir métrica
             * inválida como válida; este bloco existe para não exibir procedência
             * mais fraca como se tivesse a mesma força. Um "TIR 78%" resumido pela
             * Medtronic e um calculado sobre 3.616 leituras não são o mesmo número.
             *
             * ⚠️ Lista VAZIA quando nenhum PDF foi importado — e aí a tela é
             * exatamente a de antes da fase 7 (T607).
             */
            'pdfAggregates' => PdfAggregate::forUser((int) $request->user()->id)
                ->limit(30)
                ->get()
                ->map(fn (PdfAggregate $a): array => [
                    'metric' => $a->metric->value,
                    'label' => __('pdf.metrics.'.$a->metric->value),
                    'value' => $a->value,
                    'unit' => $a->unit,
                    'period_start' => (string) $a->period_start,
                    'period_end' => (string) $a->period_end,
                    // Sempre `pdf_aggregate`. Viaja para que a tela não tenha
                    // como esquecer de marcar.
                    'source' => $a->source,
                    // O período tem CSV? Então este resumo é redundante, e a tela
                    // diz isso em vez de deixar a pessoa comparar dois números.
                    'superseded_by_csv' => $superseded($a),
                ])
                ->all(),
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

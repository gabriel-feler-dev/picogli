<?php

declare(strict_types=1);

use App\Jobs\ImportCsvJob;
use App\Models\Import;
use App\Models\SensorReading;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T206 — FR-207 (tela de importação com resumo transparente)
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('upload', function () {

    it('exige autenticação', function () {
        auth()->logout();

        $this->get('/importar')->assertRedirect('/login');
        $this->post('/importar', [])->assertRedirect('/login');
    });

    it('oferece o fuso padrão do ambiente (§A5)', function () {
        $this->get('/importar')->assertInertia(fn (Assert $page) => $page
            ->component('Import')
            ->where('defaultTimezone', config('picogli.default_timezone'))
            ->has('timezones')
        );
    });

    it('enfileira o job com o fuso escolhido', function () {
        Queue::fake();

        $this->post('/importar', [
            'file' => UploadedFile::fake()->createWithContent('export.csv', "Last Name;First Name\r\n"),
            'timezone' => 'Europe/Lisbon',
        ])->assertRedirect('/importar');

        Queue::assertPushed(ImportCsvJob::class, function (ImportCsvJob $job): bool {
            // ⚠️ `deleteAfterImport` verdadeiro só para arquivo ENVIADO. A
            // fixture de teste vive em storage/carelink/ e um default `true`
            // apagaria o gabarito na primeira execução da suíte.
            return $job->timezone === 'Europe/Lisbon'
                && $job->userId === $this->user->id
                && $job->deleteAfterImport === true;
        });
    });

    it('recusa fuso inválido', function () {
        $this->post('/importar', [
            'file' => UploadedFile::fake()->createWithContent('export.csv', 'x'),
            'timezone' => 'Marte/Olympus',
        ])->assertSessionHasErrors('timezone');
    });

    it('exige arquivo', function () {
        $this->post('/importar', ['timezone' => 'America/Sao_Paulo'])
            ->assertSessionHasErrors('file');
    });

    it('apaga o arquivo enviado depois de importar', function () {
        $temp = storage_path('app/private/imports/copia.csv');
        @mkdir(dirname($temp), 0777, true);
        copy(requireReferenceExport(), $temp);

        (new ImportCsvJob($this->user->id, $temp, 'America/Sao_Paulo', 'copia.csv', deleteAfterImport: true))
            ->handle(
                app(App\Domain\Import\CarelinkCsvReader::class),
                app(App\Domain\Import\EventExploder::class),
                app(App\Domain\Import\BolusLinker::class),
                app(App\Domain\Import\Persistence\MealEnricher::class),
                app(App\Domain\Import\SettingsInferrer::class),
            );

        // O dado normalizado está no banco; o arquivo é o objeto com mais PII
        // do sistema e não precisa mais existir.
        expect(SensorReading::count())->toBe(3616);
        expect(is_file($temp))->toBeFalse();

        // E a fixture original segue intacta.
        expect(is_file(requireReferenceExport()))->toBeTrue();
    });
});

describe('o resumo auditável', function () {

    beforeEach(function () {
        (new ImportCsvJob($this->user->id, requireReferenceExport(), 'America/Sao_Paulo', 'referencia.csv'))
            ->handle(
                app(App\Domain\Import\CarelinkCsvReader::class),
                app(App\Domain\Import\EventExploder::class),
                app(App\Domain\Import\BolusLinker::class),
                app(App\Domain\Import\Persistence\MealEnricher::class),
                app(App\Domain\Import\SettingsInferrer::class),
            );
    });

    // ⚠️ O REQUISITO CENTRAL DA TELA. Sem este desdobramento, uma importação que
    // perdesse 700 leituras diria "Concluída" e o erro apareceria semanas
    // depois, numa métrica errada.
    it('mostra que 3.616 + 77 + 56 = 3.749 no bloco Sensor', function () {
        $summary = collect(
            app(App\Domain\Presentation\ImportSummaryPresenter::class)
                ->present(Import::firstOrFail())['blocks']
        )->keyBy('key');

        $sensor = $summary['sensor'];

        expect($sensor['lines'])->toBe(3749);

        $byLabel = collect($sensor['breakdown'])->keyBy('label');

        expect($byLabel['leituras de glicose']['count'])->toBe(3616);
        expect($byLabel['eventos do aparelho']['count'])->toBe(77);
        expect($byLabel['marcadores de início/fim de dia']['count'])->toBe(56);

        // 3.616 + 77 + 56 = 3.749 — a soma fecha na tela.
        expect(3616 + 77 + 56)->toBe($sensor['lines']);
        expect($sensor['events_and_discards'])->toBe(3749);
        expect($sensor['reconciles'])->toBeTrue();
    });

    it('reconcilia o bloco Pump, onde uma linha pode gerar dois eventos', function () {
        $summary = collect(
            app(App\Domain\Presentation\ImportSummaryPresenter::class)
                ->present(Import::firstOrFail())['blocks']
        )->keyBy('key');

        $pump = $summary['pump'];

        expect($pump['lines'])->toBe(544);
        // §A4 — a soma PASSA do número de linhas, e isso é correto: uma linha
        // com glicemia capilar E alerta gera dois eventos.
        expect($pump['events_and_discards'])->toBeGreaterThan(544);
        expect($pump['reconciles'])->toBeTrue();
    });

    it('mostra o bloco de insulina automática', function () {
        $summary = collect(
            app(App\Domain\Presentation\ImportSummaryPresenter::class)
                ->present(Import::firstOrFail())['blocks']
        )->keyBy('key');

        expect($summary['auto_insulin']['lines'])->toBe(14);
        expect($summary['auto_insulin']['reconciles'])->toBeTrue();
    });

    it('não esconde avisos', function () {
        Import::firstOrFail()->update(['parse_warnings' => ['Linha 42 descartada: unrecognized']]);

        $presented = app(App\Domain\Presentation\ImportSummaryPresenter::class)
            ->present(Import::firstOrFail());

        // Esconder aviso é o mesmo que não ter aviso.
        expect($presented['warnings'])->toBe(['Linha 42 descartada: unrecognized']);
    });

    it('a tela entrega o resumo completo', function () {
        $this->get('/importar')->assertInertia(fn (Assert $page) => $page
            ->component('Import')
            ->has('imports', 1)
            ->where('imports.0.status', 'done')
            ->where('imports.0.device', 'MiniMed 780G MMT-1886')
            ->has('imports.0.blocks', 3)
            ->where('imports.0.warnings', [])
        );
    });

    it('não mostra importação de outro usuário', function () {
        $outro = User::factory()->create();

        $this->actingAs($outro)->get('/importar')
            ->assertInertia(fn (Assert $page) => $page->has('imports', 0));
    });
});

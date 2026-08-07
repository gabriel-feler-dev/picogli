<?php

declare(strict_types=1);

use App\Domain\Import\Pdf\PdfAggregateReader;
use App\Domain\Import\Pdf\Persistence\PdfAggregateWriter;
use App\Domain\Import\Pdf\Value\PdfAggregate as PdfAggregateValue;
use App\Domain\Import\Pdf\Value\PdfMetric;
use App\Jobs\ImportPdfJob;
use App\Models\BasalRate;
use App\Models\BgReading;
use App\Models\DailyAutoInsulin;
use App\Models\DeviceEvent;
use App\Models\InsulinDose;
use App\Models\Meal;
use App\Models\PdfAggregate;
use App\Models\SensorReading;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * T603–T606 — o parser de PDF (Spec 007, item 3, §D5, §D6, §D7).
 *
 * ⚠️⚠️ **Os PADRÕES de `config/pdf.php` não foram verificados contra um PDF real
 * do CareLink** — não havia amostra no projeto (07/08/2026). O que estes testes
 * cobrem é a **mecânica**: achar texto num PDF, casar rótulo com número, recusar
 * valor implausível, isolar a persistência. O teste com fixture opcional, no fim
 * do arquivo, faz `skip` até alguém colocar um PDF de verdade.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * Um PDF mínimo, montado à mão, com texto NÃO comprimido.
 *
 * ⚠️ É PDF de verdade — estrutura de objeto, fluxo de conteúdo e operadores `Tj`.
 * Não é "um arquivo com texto solto": se fosse, o teste passaria sem exercitar a
 * extração.
 */
function pdfSintetico(string $texto): string
{
    $linhas = array_map(
        fn (string $linha): string => 'BT /F1 12 Tf ('.str_replace(['(', ')'], ['\\(', '\\)'], $linha).') Tj ET',
        explode("\n", $texto),
    );

    $conteudo = implode("\n", $linhas);

    $pdf = "%PDF-1.4\n";
    $pdf .= "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n";
    $pdf .= "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n";
    $pdf .= "3 0 obj<</Type/Page/Parent 2 0 R/Contents 4 0 R>>endobj\n";
    $pdf .= '4 0 obj<</Length '.strlen($conteudo).">>>stream\n".$conteudo."\nendstream endobj\n";
    $pdf .= "trailer<</Root 1 0 R>>\n%%EOF";

    $caminho = tempnam(sys_get_temp_dir(), 'picogli').'.pdf';
    file_put_contents($caminho, $pdf);

    return $caminho;
}

function relatorioDeExemplo(): string
{
    return pdfSintetico(implode("\n", [
        'Relatorio de Avaliacao e Progresso',
        'Periodo 2026-07-16 - 2026-07-29',
        'Media de glicose do sensor 142 mg/dL',
        'Tempo no intervalo 83,9 %',
        'Tempo acima do intervalo 14,2 %',
        'Tempo abaixo do intervalo 1,9 %',
        'Variabilidade 28,8 %',
        'Uso do sensor 91 %',
        'Insulina total diaria 52,5 U',
    ]));
}

/*
|--------------------------------------------------------------------------
| T603.3 — ⚠️⚠️ A PROIBIÇÃO. A guarda antes da porta.
|--------------------------------------------------------------------------
*/

/**
 * ⚠️⚠️ **O teste que justifica o T603 vir antes do T604.**
 *
 * `PicoGli.md` §6.3: *"Não tente extrair valores numéricos dos gráficos por visão
 * computacional ou modelo multimodal. Um modelo lendo pixels de uma curva de CGM
 * chuta valores."*
 *
 * É o Artigo I aplicado a uma fonte nova — número lido de pixel não rastreia até
 * nada, e sai plausível com uma casa decimal. Escrita depois do parser, esta
 * proibição deixaria uma janela em que alguém "só testa com uma imagem para ver".
 */
it('nenhum arquivo do parser de PDF toca visão computacional', function () {
    $diretorios = [
        app_path('Domain/Import/Pdf'),
        app_path('Infrastructure/Import'),
    ];

    $varridos = 0;

    foreach ($diretorios as $diretorio) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($diretorio)) as $arquivo) {
            if (! $arquivo->isFile() || $arquivo->getExtension() !== 'php') {
                continue;
            }

            $codigo = (string) file_get_contents($arquivo->getPathname());
            // Comentário sai antes: os docblocks EXPLICAM a proibição, e citam as
            // palavras para isso. Terceira vez que esta armadilha aparece.
            $codigo = (string) preg_replace('#/\*.*?\*/#s', '', $codigo);
            $codigo = (string) preg_replace('#//.*$#m', '', $codigo);

            $varridos++;

            foreach ([
                'ocr', 'tesseract', 'imagick', 'Imagick', 'gd_', 'imagecreate',
                'vision', 'multimodal', 'inline_data', 'image/png', 'image/jpeg',
            ] as $proibido) {
                expect(str_contains($codigo, $proibido))->toBeFalse(
                    "{$arquivo->getFilename()} referencia '{$proibido}' — número de pixel "
                    .'não rastreia até nada (PicoGli.md §6.3)'
                );
            }
        }
    }

    // Sem isto, um diretório renomeado faria o teste varrer o vácuo.
    expect($varridos)->toBeGreaterThan(2);
});

/**
 * ⚠️ **T603.4 — o parser não conhece o `EventExploder`** (§D6).
 *
 * Não existe caminho em que um PDF grave linha em tabela de evento, e a garantia
 * mais forte disso é que o código nem sabe que aquelas classes existem.
 */
it('o parser não conhece o importador de evento', function () {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Domain/Import/Pdf'))) as $arquivo) {
        if (! $arquivo->isFile() || $arquivo->getExtension() !== 'php') {
            continue;
        }

        $codigo = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($arquivo->getPathname()));

        foreach (['EventExploder', 'BolusLinker', 'MealEnricher', 'ImportCsvJob'] as $proibido) {
            expect(str_contains($codigo, $proibido))->toBeFalse(
                "{$arquivo->getFilename()} referencia '{$proibido}' — PDF não produz evento (§D6)"
            );
        }
    }
});

it('nenhuma chamada de rede no parser', function () {
    Http::fake();

    app(PdfAggregateReader::class)->read(relatorioDeExemplo());

    // ⚠️ PDF é lido de arquivo. Um serviço de extração seria dado de paciente
    // saindo daqui (Artigo VII), e por outra porta que não o PayloadSanitizer.
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| T603.2 — o value object
|--------------------------------------------------------------------------
*/

it('a procedência é sempre pdf_aggregate', function () {
    $agregado = new PdfAggregateValue(PdfMetric::MeanGlucose, 142.0, '2026-07-16', '2026-07-29');

    expect($agregado->source)->toBe('pdf_aggregate');
    expect($agregado->toArray()['source'])->toBe('pdf_aggregate');
});

it('recusa qualquer outra procedência', function () {
    expect(fn () => new PdfAggregateValue(
        PdfMetric::MeanGlucose, 142.0, '2026-07-16', '2026-07-29', null, 'csv'
    ))->toThrow(InvalidArgumentException::class, 'pdf_aggregate');
});

/**
 * ⚠️ Extração torta grava número que aparece na tela com a mesma aparência dos
 * corretos. O construtor recusa antes de chegar ao banco.
 */
it('recusa valor implausível para a métrica', function (PdfMetric $metrica, float $valor) {
    expect(fn () => new PdfAggregateValue($metrica, $valor, '2026-07-16', '2026-07-29'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'TIR acima de 100%' => [PdfMetric::TimeInRange, 1483.0],
    'média negativa' => [PdfMetric::MeanGlucose, -5.0],
    'média de 4 dígitos' => [PdfMetric::MeanGlucose, 1420.0],
    'GMI impossível' => [PdfMetric::Gmi, 67.0],
]);

it('recusa período inválido ou invertido', function () {
    expect(fn () => new PdfAggregateValue(PdfMetric::MeanGlucose, 142.0, '16/07/2026', '2026-07-29'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => new PdfAggregateValue(PdfMetric::MeanGlucose, 142.0, '2026-07-29', '2026-07-16'))
        ->toThrow(InvalidArgumentException::class, 'invertido');
});

/*
|--------------------------------------------------------------------------
| T604 — a extração
|--------------------------------------------------------------------------
*/

it('extrai os agregados de um PDF com texto', function () {
    $agregados = app(PdfAggregateReader::class)->read(relatorioDeExemplo());

    expect($agregados)->not->toBeEmpty();

    $porMetrica = collect($agregados)->keyBy(fn (PdfAggregateValue $a): string => $a->metric->value);

    expect($porMetrica['mean_glucose']->value)->toBe(142.0);
    expect($porMetrica['time_in_range_percent']->value)->toBe(83.9);
    expect($porMetrica['cv_percent']->value)->toBe(28.8);

    // ⚠️ Decimal com vírgula, como o CareLink escreve (armadilha A1).
    expect($porMetrica['total_insulin_u']->value)->toBe(52.5);

    // E o período veio do rótulo, não de chute.
    expect($porMetrica['mean_glucose']->periodStart)->toBe('2026-07-16');
    expect($porMetrica['mean_glucose']->periodEnd)->toBe('2026-07-29');
});

it('aceita data em DD/MM/AAAA, como o cabeçalho do CSV', function () {
    $caminho = pdfSintetico("Periodo 16/07/2026 - 29/07/2026\nTempo no intervalo 83,9 %");

    $agregados = app(PdfAggregateReader::class)->read($caminho);

    expect($agregados)->toHaveCount(1);
    expect($agregados[0]->periodStart)->toBe('2026-07-16');
});

/**
 * ⚠️ **T604.3 — arquivo que não é o que se esperava devolve VAZIO, não exceção.**
 * Mesma disciplina do CSV sem bloco: é caso previsto.
 */
it('devolve vazio em vez de explodir', function (string $descricao, string $conteudo) {
    $caminho = tempnam(sys_get_temp_dir(), 'picogli').'.pdf';
    file_put_contents($caminho, $conteudo);

    expect(app(PdfAggregateReader::class)->read($caminho))->toBe([]);
})->with([
    'arquivo vazio' => ['vazio', ''],
    'não é PDF' => ['texto solto', 'Tempo no intervalo 83,9 %'],
    'PDF sem período' => ['sem periodo', "%PDF-1.4\nstream\nBT (Tempo no intervalo 83,9 %) Tj ET\nendstream"],
]);

it('arquivo inexistente devolve vazio', function () {
    expect(app(PdfAggregateReader::class)->read('/nao/existe.pdf'))->toBe([]);
});

/**
 * ⚠️ PDF escaneado devolve vazio, **e está certo** — nele o texto é imagem, e
 * imagem está fora do escopo por decisão, não por limitação.
 */
it('PDF sem operador de texto devolve vazio', function () {
    $caminho = pdfSintetico('');

    expect(app(PdfAggregateReader::class)->read($caminho))->toBe([]);
});

it('descarta agregado implausível em vez de gravar', function () {
    // "Tempo no intervalo 1483" — extração casou o número errado da página.
    $caminho = pdfSintetico("Periodo 2026-07-16 - 2026-07-29\nTempo no intervalo 1483 %\nVariabilidade 28,8 %");

    $agregados = app(PdfAggregateReader::class)->read($caminho);

    $metricas = collect($agregados)->pluck('metric.value')->all();

    expect($metricas)->not->toContain('time_in_range_percent');
    expect($metricas)->toContain('cv_percent');
});

/*
|--------------------------------------------------------------------------
| T605 — ⚠️⚠️ a persistência isolada
|--------------------------------------------------------------------------
*/

it('cria a tabela com tipos portáveis', function () {
    expect(Schema::hasColumns('pdf_aggregates', [
        'user_id', 'import_id', 'metric', 'value', 'unit',
        'period_start', 'period_end', 'source',
    ]))->toBeTrue();

    $migration = file_get_contents(
        base_path('database/migrations/2026_08_07_120000_create_pdf_aggregates_table.php')
    );
    $codigo = (string) preg_replace('#/\*.*?\*/#s', '', $migration);
    $codigo = (string) preg_replace('#//.*$#m', '', $codigo);

    foreach (['jsonb', 'GENERATED ALWAYS', '->enum(', 'interval'] as $proibido) {
        expect(str_contains($codigo, $proibido))->toBeFalse("Artigo IX: usa '{$proibido}'");
    }
});

/**
 * ⚠️⚠️ **O TESTE CENTRAL DO ITEM 3.**
 *
 * Um agregado numa tabela de evento traria número de granularidade e procedência
 * diferentes, e **nenhuma métrica saberia disso** — o `StatisticsCalculator`
 * trataria um "TIR 78%" resumido como se fosse a soma de 3.616 leituras.
 */
it('importar PDF não grava uma linha em nenhuma tabela de evento', function () {
    $antes = [
        'sensor_readings' => SensorReading::count(),
        'bg_readings' => BgReading::count(),
        'insulin_doses' => InsulinDose::count(),
        'meals' => Meal::count(),
        'device_events' => DeviceEvent::count(),
        'basal_rates' => BasalRate::count(),
        'daily_auto_insulin' => DailyAutoInsulin::count(),
    ];

    $gravados = (new ImportPdfJob($this->user->id, relatorioDeExemplo()))->handle(
        app(PdfAggregateReader::class),
        app(PdfAggregateWriter::class),
    );

    expect($gravados)->toBeGreaterThan(0);
    expect(PdfAggregate::count())->toBe($gravados);

    // ⚠️ E nada mais mudou.
    foreach ($antes as $tabela => $contagem) {
        expect(DB::table($tabela)->count())->toBe($contagem, "o PDF gravou em {$tabela}");
    }
});

it('reimportar o mesmo relatório atualiza, não empilha', function () {
    $job = fn (): int => (new ImportPdfJob($this->user->id, relatorioDeExemplo()))->handle(
        app(PdfAggregateReader::class),
        app(PdfAggregateWriter::class),
    );

    $primeira = $job();
    $job();

    expect(PdfAggregate::count())->toBe($primeira);
});

it('todo agregado gravado carrega a procedência', function () {
    (new ImportPdfJob($this->user->id, relatorioDeExemplo()))->handle(
        app(PdfAggregateReader::class),
        app(PdfAggregateWriter::class),
    );

    foreach (PdfAggregate::all() as $agregado) {
        expect($agregado->isFromPdf())->toBeTrue();
        expect($agregado->source)->toBe('pdf_aggregate');
    }
});

it('PDF sem agregado reconhecido não grava nada, e não quebra', function () {
    $caminho = pdfSintetico('Relatorio de outra coisa');

    $gravados = (new ImportPdfJob($this->user->id, $caminho))->handle(
        app(PdfAggregateReader::class),
        app(PdfAggregateWriter::class),
    );

    expect($gravados)->toBe(0);
    expect(PdfAggregate::count())->toBe(0);
});

/**
 * ⚠️ **T605.4 — CSV existente não é substituído.**
 *
 * O CSV traz leitura de 5 em 5 minutos; o PDF traz o que a Medtronic decidiu
 * resumir. Preferir o resumo quando o dado existe é trocar dado por resumo de
 * dado — e a troca seria invisível depois.
 */
it('o writer sabe quando o período já tem CSV', function () {
    $writer = app(PdfAggregateWriter::class);

    expect($writer->hasCsvFor($this->user->id, '2026-07-16', '2026-07-29'))->toBeFalse();

    importAndAnalyse($this->user->id);

    expect($writer->hasCsvFor($this->user->id, '2026-07-16', '2026-07-29'))->toBeTrue();
});

it('o agregado é gravado mesmo com CSV no período, e marcado como redundante', function () {
    importAndAnalyse($this->user->id);

    (new ImportPdfJob($this->user->id, relatorioDeExemplo()))->handle(
        app(PdfAggregateReader::class),
        app(PdfAggregateWriter::class),
    );

    // ⚠️ Gravado, não descartado: descartar apagaria a única prova de que o PDF
    // foi importado.
    expect(PdfAggregate::count())->toBeGreaterThan(0);

    $props = $this->get('/importar')->viewData('page')['props'];

    expect(collect($props['pdfAggregates'])->every(fn (array $a): bool => $a['superseded_by_csv']))
        ->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| T606 — ⚠️ a procedência visível
|--------------------------------------------------------------------------
*/

it('a tela de importação expõe os agregados em bloco separado, marcados', function () {
    (new ImportPdfJob($this->user->id, relatorioDeExemplo()))->handle(
        app(PdfAggregateReader::class),
        app(PdfAggregateWriter::class),
    );

    $props = $this->get('/importar')->viewData('page')['props'];

    expect($props['pdfAggregates'])->not->toBeEmpty();

    foreach ($props['pdfAggregates'] as $agregado) {
        // ⚠️ A marcação viaja com CADA número, não só com o bloco.
        expect($agregado['source'])->toBe('pdf_aggregate');
        expect($agregado)->toHaveKeys(['label', 'value', 'unit', 'superseded_by_csv']);
    }

    // ⚠️ E não se mistura com métrica de CSV: são chaves diferentes do payload.
    expect($props)->toHaveKey('imports');
    expect($props['pdfAggregates'])->not->toBe($props['imports']);
});

/**
 * ⚠️ **Sem PDF importado, a tela é EXATAMENTE a de antes da fase 7** — é o T607
 * antecipado para o caminho mais provável de quebrar.
 */
it('sem PDF importado, o bloco não aparece', function () {
    $props = $this->get('/importar')->viewData('page')['props'];

    expect($props['pdfAggregates'])->toBe([]);
});

it('o componente de PDF não calcula, e sempre marca a procedência', function () {
    $codigo = (string) file_get_contents(resource_path('js/Components/PdfAggregateBlock.tsx'));
    $limpo = (string) preg_replace('#/\*.*?\*/#s', '', $codigo);
    $limpo = (string) preg_replace('#//.*$#m', '', $limpo);

    foreach (['reduce(', 'toFixed(', 'Math.', 'parseFloat('] as $calculo) {
        expect(str_contains($limpo, $calculo))->toBeFalse("usa '{$calculo}'");
    }

    // O selo por número, e a nota de procedência antes deles.
    expect($limpo)->toContain('PDF');
    expect($limpo)->toContain('resumos prontos');
});

it('o agregado de outro usuário não aparece', function () {
    $outro = User::factory()->create();

    (new ImportPdfJob($outro->id, relatorioDeExemplo()))->handle(
        app(PdfAggregateReader::class),
        app(PdfAggregateWriter::class),
    );

    expect($this->get('/importar')->viewData('page')['props']['pdfAggregates'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| ⚠️⚠️ O teste contra realidade — Artigo XI, e ele está PENDENTE
|--------------------------------------------------------------------------
*/

/**
 * ⚠️⚠️ **Os padrões de `config/pdf.php` são HIPÓTESE até este teste rodar.**
 *
 * O Artigo XI manda testar contra realidade, e não havia PDF do CareLink no
 * projeto em 07/08/2026. Tudo o que está acima verifica a **mecânica** com PDF
 * sintético; **nada** verifica se os rótulos de config são os que a Medtronic
 * imprime.
 *
 * Coloque um relatório real em `storage/carelink/reference-report.pdf` e este
 * teste passa a valer. Enquanto ele fizer `skip`, **trate o item 3 como não
 * verificado** — é a mesma disciplina do `requireReferenceExport()` da fase 1.
 */
it('extrai agregados de um relatório REAL do CareLink', function () {
    $caminho = storage_path('app/'.config('pdf.reference_report'));

    if (! is_file($caminho)) {
        $this->markTestSkipped(
            'Relatório PDF de referência ausente. Coloque um PDF do CareLink em '
            .'storage/app/'.config('pdf.reference_report').' — ATÉ ENTÃO, os padrões '
            .'de config/pdf.php são hipótese não verificada (Artigo XI).'
        );
    }

    $agregados = app(PdfAggregateReader::class)->read($caminho);

    expect($agregados)->not->toBeEmpty(
        'o PDF real não casou com nenhum rótulo de config/pdf.php — ajuste os padrões lá'
    );

    foreach ($agregados as $agregado) {
        expect($agregado->source)->toBe('pdf_aggregate');
        expect($agregado->metric->accepts($agregado->value))->toBeTrue();
    }
});

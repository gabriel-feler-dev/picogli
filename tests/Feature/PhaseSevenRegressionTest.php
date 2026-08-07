<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\ChatOrchestrator;
use App\Domain\Ai\Chat\ChatProvider;
use App\Domain\Import\Pdf\PdfAggregateReader;
use App\Domain\Import\Pdf\Persistence\PdfAggregateWriter;
use App\Infrastructure\Ai\GeminiProvider;
use App\Jobs\ImportPdfJob;
use App\Models\ChatConversation;
use App\Models\Meal;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeChatProvider;

/**
 * ⚠️⚠️⚠️ **T607 — o teste de não-regressão da fase 7 (FR-707).**
 *
 * ⚠️ **É o último da fase de propósito.** As telas das fases 3 a 6 são o que esta
 * fase não pode quebrar, e isso só se verifica com tudo montado — como o
 * `ArticleOneTest` foi para a fase 5 e o `ChatArticleTest` para a 6.
 *
 * ## O que a fase 7 acrescentou, e o que ela NÃO pode ter mexido
 *
 * ```
 * acrescentou:  /refeicoes  /comparar  + bloco de PDF em /importar
 * não mexeu:    /dashboard  /avaliacao  /conversar
 * ```
 *
 * ⚠️ **A afirmação forte é a segunda linha, e ela é verificada por igualdade de
 * payload** — não por "parece igual". Rótulo é dado novo em tela nova; se ele
 * mexer no dashboard, algo está acoplado onde não devia.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    importAndAnalyse($this->user->id);
});

/**
 * O payload de uma tela, sem o que o framework injeta.
 *
 * ⚠️ `errors` é um `stdClass` vazio criado **por request** pelo middleware do
 * Inertia. Comparar payloads inteiros por identidade reprova nele — dois objetos
 * vazios diferentes — e o teste acusaria mudança onde não houve nenhuma.
 *
 * Tirá-lo não afrouxa nada: ele nunca carrega dado de tela. A primeira versão
 * deste arquivo não o tirava, e as três comparações falharam por isso.
 */
function payloadDaTelaDe(string $rota): array
{
    $props = test()->get($rota)->viewData('page')['props'];

    unset($props['errors']);

    return $props;
}

/** As telas que existiam ANTES da fase 7 e não podem ter mudado. */
function telasAnteriores(): array
{
    return [
        '/dashboard' => payloadDaTelaDe('/dashboard'),
        '/avaliacao' => payloadDaTelaDe('/avaliacao'),
        '/conversar' => payloadDaTelaDe('/conversar'),
    ];
}

function pdfDeExemplo(): string
{
    $conteudo = implode("\n", array_map(
        fn (string $l): string => "BT /F1 12 Tf ({$l}) Tj ET",
        [
            'Periodo 2026-07-16 - 2026-07-29',
            'Media de glicose do sensor 142 mg/dL',
            'Tempo no intervalo 83,9 %',
        ],
    ));

    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n"
        .'4 0 obj<</Length '.strlen($conteudo).">>>stream\n".$conteudo."\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

    $caminho = tempnam(sys_get_temp_dir(), 'picogli').'.pdf';
    file_put_contents($caminho, $pdf);

    return $caminho;
}

/*
|--------------------------------------------------------------------------
| T607.1 — as seis telas respondem
|--------------------------------------------------------------------------
*/

it('as seis telas respondem', function (string $rota, string $componente) {
    $this->get($rota)->assertOk()->assertInertia(fn ($page) => $page->component($componente));
})->with([
    ['/dashboard', 'Dashboard'],
    ['/avaliacao', 'Evaluation'],
    ['/importar', 'Import'],
    ['/conversar', 'Chat'],
    ['/refeicoes', 'Meals'],
    ['/comparar', 'Comparison'],
]);

it('sem rótulo e sem PDF, as telas novas dizem que não há o que mostrar', function () {
    $refeicoes = payloadDaTelaDe('/refeicoes');
    $importar = payloadDaTelaDe('/importar');

    // Refeições existem no export — o que não existe é rótulo.
    expect($refeicoes['labelled_count'])->toBe(0);
    expect($refeicoes['groups'])->toBe([]);

    // ⚠️ E a tela de importação é EXATAMENTE a de antes da fase 7.
    expect($importar['pdfAggregates'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| T607.2 — ⚠️⚠️ rótulo não mexe em nada do que já existia
|--------------------------------------------------------------------------
*/

/**
 * ⚠️⚠️ **A afirmação central do T607.**
 *
 * Rótulo é dado novo em tela nova. Se ele mudar o dashboard, a avaliação ou o
 * chat, algo está acoplado onde não devia — e a comparação é por **igualdade de
 * payload**, não por inspeção visual.
 */
it('rotular refeições não muda nenhuma tela anterior', function () {
    $antes = telasAnteriores();

    foreach (Meal::where('user_id', $this->user->id)->limit(8)->get() as $refeicao) {
        $this->patch(route('meals.label', $refeicao), ['label' => 'pizza']);
    }

    // O rótulo chegou.
    expect(payloadDaTelaDe('/refeicoes')['labelled_count'])->toBe(8);

    // ⚠️ E TUDO O MAIS É IDÊNTICO.
    foreach (telasAnteriores() as $rota => $depois) {
        expect($depois)->toBe($antes[$rota], "a tela {$rota} mudou por causa de um rótulo");
    }
});

/**
 * ⚠️ Rótulo também não muda a comparação — ele não entra em cálculo (§D2).
 */
it('rotular refeições não muda a comparação entre períodos', function () {
    $antes = payloadDaTelaDe('/comparar');

    foreach (Meal::where('user_id', $this->user->id)->limit(5)->get() as $refeicao) {
        $this->patch(route('meals.label', $refeicao), ['label' => 'feijoada']);
    }

    expect(payloadDaTelaDe('/comparar'))->toBe($antes);
});

/*
|--------------------------------------------------------------------------
| T607.2 — ⚠️⚠️ PDF só acrescenta, e num lugar só
|--------------------------------------------------------------------------
*/

/**
 * ⚠️⚠️ **§D6 verificado do lado da INTERFACE.**
 *
 * O teste do item 3 provou que o PDF não grava em tabela de evento. Este prova a
 * consequência: nenhuma tela que lê evento muda, e a única que muda ganha
 * **exatamente uma chave**.
 *
 * É a mesma forma do `ArticleOneTest` da fase 5 — lá a única diferença permitida
 * era `narrative` virar `null`; aqui é `pdfAggregates` deixar de ser vazio.
 */
it('importar PDF não muda nenhuma tela, exceto o bloco de PDF na importação', function () {
    $antes = telasAnteriores();
    $importarAntes = payloadDaTelaDe('/importar');
    $compararAntes = payloadDaTelaDe('/comparar');
    $refeicoesAntes = payloadDaTelaDe('/refeicoes');

    $gravados = (new ImportPdfJob($this->user->id, pdfDeExemplo()))->handle(
        app(PdfAggregateReader::class),
        app(PdfAggregateWriter::class),
    );

    expect($gravados)->toBeGreaterThan(0);

    // ⚠️ As telas que leem EVENTO não mudaram — nem uma vírgula.
    foreach (telasAnteriores() as $rota => $depois) {
        expect($depois)->toBe($antes[$rota], "a tela {$rota} mudou por causa de um PDF");
    }

    expect(payloadDaTelaDe('/comparar'))->toBe($compararAntes);
    expect(payloadDaTelaDe('/refeicoes'))->toBe($refeicoesAntes);

    // ⚠️ E a de importação mudou em UMA chave só.
    $importarDepois = payloadDaTelaDe('/importar');

    expect($importarDepois['pdfAggregates'])->not->toBe([]);

    foreach (array_keys($importarAntes) as $chave) {
        if ($chave === 'pdfAggregates') {
            continue;
        }

        expect($importarDepois[$chave])->toBe($importarAntes[$chave], "a chave '{$chave}' mudou");
    }

    expect(array_keys($importarDepois))->toBe(array_keys($importarAntes));
});

/**
 * ⚠️ **O agregado de PDF não entra em métrica nenhuma.** É o §D6 pelo lado que
 * mais importaria errar: um "TIR 78%" resumido somado às 3.616 leituras produziria
 * um número plausível e errado, e nenhuma métrica saberia disso.
 */
it('o agregado de PDF não entra na média, no TIR nem nos achados', function () {
    $mediaAntes = $this->get('/dashboard')->viewData('page')['props']['summary']['coverage']['reading_count'];
    $achadosAntes = $this->get('/avaliacao')->viewData('page')['props']['findings'];

    (new ImportPdfJob($this->user->id, pdfDeExemplo()))->handle(
        app(PdfAggregateReader::class),
        app(PdfAggregateWriter::class),
    );

    expect($this->get('/dashboard')->viewData('page')['props']['summary']['coverage']['reading_count'])
        ->toBe($mediaAntes);
    expect($this->get('/avaliacao')->viewData('page')['props']['findings'])->toBe($achadosAntes);
});

/*
|--------------------------------------------------------------------------
| O chat continua íntegro com o dado novo
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ O rótulo entrou no `get_meals` (T600.4). O Artigo III no chat não pode ter
 * afrouxado por causa disso — a guarda continua confrontando a resposta com os
 * `tool_results` do turno.
 */
it('o chat continua recusando número sem procedência, com rótulo em jogo', function () {
    foreach (Meal::where('user_id', $this->user->id)->limit(3)->get() as $refeicao) {
        $this->patch(route('meals.label', $refeicao), ['label' => 'pizza']);
    }

    app()->instance(ChatProvider::class, FakeChatProvider::script([
        FakeChatProvider::wantsTools(['get_meals' => ['start' => '2026-07-16', 'end' => '2026-07-29']]),
        FakeChatProvider::answers('Você registrou 3 refeições de pizza, com subida média de 999 mg/dL.'),
    ]));
    app()->forgetInstance(ChatOrchestrator::class);

    $conversa = ChatConversation::create(['user_id' => $this->user->id]);
    $this->post(route('chat.message', $conversa), ['message' => 'como reagi às pizzas?']);

    $resposta = $conversa->messages()->where('role', 'assistant')->firstOrFail();

    // 999 não veio de ferramenta nenhuma.
    expect($resposta->outcome->value)->toBe('refused');
    expect($resposta->content)->not->toContain('999');
});

/*
|--------------------------------------------------------------------------
| T607.3 — os artigos que a fase 7 encostou
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ **Artigo V — nenhuma média nova sai sem denominador.**
 *
 * A fase 7 acrescentou dois lugares onde uma média aparece: o agrupamento de
 * refeição e o delta da comparação. Os dois carregam a contagem ou a cobertura.
 */
it('toda média nova da fase 7 vem com o denominador', function () {
    foreach (Meal::where('user_id', $this->user->id)->limit(4)->get() as $refeicao) {
        $this->patch(route('meals.label', $refeicao), ['label' => 'pizza']);
    }

    foreach (payloadDaTelaDe('/refeicoes')['groups'] as $grupo) {
        expect($grupo)->toHaveKeys(['meal_count', 'with_response_count']);
    }

    $comparar = payloadDaTelaDe('/comparar');

    foreach (['period_a', 'period_b'] as $lado) {
        expect($comparar[$lado])->toHaveKeys(['days_span', 'coverage_percent', 'reading_count']);
    }
});

/**
 * ⚠️ **Artigo VI, camada 5 — as telas novas trazem o rodapé.**
 */
it('as telas novas trazem o rodapé de fronteira clínica', function (string $arquivo) {
    // ⚠️ Ver a nota do ComparisonTest: o rodapé mudou de lugar na Spec 008,
    // e a corrente cobrada passou a ser tela -> casca -> rodapé.
    expect(file_get_contents(resource_path("js/Pages/{$arquivo}")))->toContain('AppShell');
    expect(file_get_contents(resource_path('js/Layouts/AppShell.tsx')))->toContain('ClinicalFooter');
})->with(['Meals.tsx', 'Comparison.tsx']);

/**
 * ⚠️ **Artigo I — a fase 7 não introduziu dependência de IA.**
 *
 * Rótulo, agrupamento, comparação e agregado de PDF são todos determinísticos.
 * Nenhum deles chama provedor, e as telas novas funcionam com a chave desligada.
 */
it('as telas novas funcionam sem chave de IA', function () {
    app()->instance(ChatProvider::class, new GeminiProvider(
        app(Factory::class), null, 45
    ));
    Http::fake();

    $this->get('/refeicoes')->assertOk();
    $this->get('/comparar')->assertOk();
    $this->get('/importar')->assertOk();

    Http::assertNothingSent();
});

<?php

declare(strict_types=1);

use App\Models\PeriodReport;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T407 — a narrativa na tela (FR-507, §D3).
 *
 * ⚠️ **A narrativa ENRIQUECE, nunca substitui.** É o que torna o Artigo I
 * verdadeiro por construção: sem ela, a tela é exatamente a de ontem.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    importAndAnalyse($this->user->id);

    $this->report = PeriodReport::where('user_id', $this->user->id)->firstOrFail();
});

it('sem narrativa, a tela entrega null e os dez achados', function () {
    // ⚠️ T407.4 — este é o estado NORMAL, e é o de hoje.
    $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page
        ->component('Evaluation')
        ->where('narrative', null)
        ->has('findings', 10)
    );
});

it('com narrativa, entrega o texto e a procedência', function () {
    $this->report->update([
        'narrative' => "Primeiro parágrafo.\nSegundo parágrafo.",
        'narrative_model' => 'gemini-3.6-flash',
        'narrative_generated_at' => now(),
    ]);

    $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page
        ->where('narrative.text', "Primeiro parágrafo.\nSegundo parágrafo.")
        // Procedência: qual modelo escreveu e quando.
        ->where('narrative.model', 'gemini-3.6-flash')
        ->whereNot('narrative.generated_at', null)
        // ⚠️ E os achados CONTINUAM lá, com evidência (T407.3).
        ->has('findings', 10)
    );
});

/**
 * ⚠️⚠️ **T407.4 — A PROPRIEDADE QUE A FASE INTEIRA PROTEGE.**
 *
 * Com e sem narrativa, os dez achados são os MESMOS, com os MESMOS números. A
 * narrativa não é uma view alternativa: ela é uma camada acima.
 */
it('a presença da narrativa não muda nenhum achado', function () {
    $semNarrativa = $this->get('/avaliacao')->viewData('page')['props']['findings'];

    $this->report->update([
        'narrative' => 'Um resumo qualquer.',
        'narrative_model' => 'gemini-3.6-flash',
        'narrative_generated_at' => now(),
    ]);

    $comNarrativa = $this->get('/avaliacao')->viewData('page')['props']['findings'];

    expect($comNarrativa)->toBe($semNarrativa);
});

it('narrativa em branco conta como ausente', function () {
    // Uma string vazia gravada por engano não pode render um bloco vazio na tela.
    $this->report->update(['narrative' => '   ', 'narrative_model' => 'x']);

    $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page->where('narrative', null));
});

it('regerar o relatório faz a narrativa sumir da tela', function () {
    $this->report->update([
        'narrative' => 'Resumo antigo.',
        'narrative_model' => 'gemini-3.6-flash',
        'narrative_generated_at' => now(),
    ]);

    $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page
        ->where('narrative.text', 'Resumo antigo.'));

    // ⚠️ §D8 — texto escrito sobre a versão anterior das regras, ao lado de
    // achados recalculados, é plausível e falso.
    importAndAnalyse($this->user->id);

    $this->get('/avaliacao')->assertInertia(fn (Assert $page) => $page
        ->where('narrative', null)
        ->has('findings', 10)
    );
});

it('sem relatório nenhum, narrative também é null', function () {
    $outro = User::factory()->create();

    $this->actingAs($outro)->get('/avaliacao')->assertInertia(fn (Assert $page) => $page
        ->where('has_report', false)
        ->where('narrative', null)
    );
});

/**
 * ⚠️ NFR-404 — o componente não calcula nada. Dividir parágrafos é formatação;
 * nenhum número é tocado.
 */
it('o componente da narrativa não calcula nem formata número', function () {
    $codigo = file_get_contents(resource_path('js/Components/NarrativeBlock.tsx'));

    $codigo = preg_replace('#\{/\*.*?\*/\}#s', '', $codigo);
    $codigo = preg_replace('#/\*.*?\*/#s', '', (string) $codigo);
    $codigo = preg_replace('#//.*$#m', '', (string) $codigo);

    foreach (['.reduce(', 'toFixed(', 'Math.', 'parseFloat', 'parseInt'] as $proibido) {
        expect(str_contains((string) $codigo, $proibido))->toBeFalse(
            "NarrativeBlock usa '{$proibido}'"
        );
    }
});

/**
 * ⚠️ A marcação de origem não é aviso legal — é a distinção de que o produto
 * depende. Os números vêm de regras; o texto é uma redação deles, e a confiança
 * que o usuário deposita nos dois é legitimamente diferente.
 */
it('o bloco declara que o texto foi escrito por IA a partir dos achados', function () {
    $codigo = file_get_contents(resource_path('js/Components/NarrativeBlock.tsx'));

    expect($codigo)->toContain('Resumo escrito por IA');
    expect($codigo)->toContain('Escrito a partir dos achados abaixo');
    expect($codigo)->toContain('Os números são calculados pelo PicoGli');
});

<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\ChatPromptBuilder;
use App\Domain\Ai\Chat\ToolRegistry;
use App\Domain\Ai\Chat\Value\ChatPayload;

/**
 * T508 — o prompt de chat (FR-606, §D6 da fase 5).
 *
 * ⚠️ **O prompt é o texto que produz o texto.** Varrer só a saída deixaria a
 * causa livre — e cada resposta seria diferente, então o defeito apareceria de
 * forma intermitente.
 */
function chatPrompt(ChatPayload $payload = new ChatPayload([], [])): string
{
    return app(ChatPromptBuilder::class)->build(
        $payload,
        app(ToolRegistry::class)->descriptors(),
    );
}

/*
|--------------------------------------------------------------------------
| T508.2 — ⚠️ fonte única: o arquivo limpo, o renderizado completo
|--------------------------------------------------------------------------
*/

it('o ARQUIVO do prompt não contém as palavras proibidas', function () {
    $arquivo = file_get_contents(resource_path('prompts/chat.pt_BR.md'));

    // ⚠️ É o que permite a varredura do Artigo IV cobrir este arquivo sem
    // acusá-lo — o erro que a fase 3 cometeu ao acusar a documentação da regra.
    foreach (config('tone.forbidden_vocabulary') as $proibido) {
        expect(str_contains(mb_strtolower($arquivo), mb_strtolower($proibido)))->toBeFalse(
            "o arquivo do prompt contém \"{$proibido}\" — deveria interpolar"
        );
    }

    expect($arquivo)->toContain(':vocabulario_proibido');
    expect($arquivo)->toContain(':conduta_proibida');
});

it('o prompt RENDERIZADO carrega todas as palavras que o teste cobra', function () {
    $renderizado = mb_strtolower(chatPrompt());

    // ⚠️ O outro lado da fonte única: com as listas duplicadas, uma palavra
    // acrescentada ao teste não chegaria ao modelo.
    foreach (config('tone.forbidden_vocabulary') as $proibido) {
        expect($renderizado)->toContain(mb_strtolower($proibido));
    }

    foreach (config('tone.forbidden_conduct') as $conduta) {
        expect($renderizado)->toContain(mb_strtolower($conduta));
    }
});

it('não sobra placeholder no prompt renderizado', function () {
    expect(chatPrompt())->toHaveNoUnresolvedPlaceholder();
});

/*
|--------------------------------------------------------------------------
| T508.1 e T508.6 — o catálogo e o contexto
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ **O catálogo é renderizado dos descritores reais.**
 *
 * Escrever a lista à mão no arquivo criaria a divergência mais cara possível: o
 * prompt anunciando uma ferramenta que não existe (o modelo chama e recebe
 * erro), ou omitindo uma que existe (o modelo nunca chama, e ninguém sabe por
 * quê).
 */
it('o prompt anuncia exatamente as dez ferramentas registradas', function () {
    $renderizado = chatPrompt();

    foreach (app(ToolRegistry::class)->names() as $nome) {
        expect($renderizado)->toContain($nome);
    }
});

it('o prompt mostra os argumentos de cada ferramenta', function () {
    $renderizado = chatPrompt();

    expect($renderizado)->toContain('get_episodes(start, end, type)');
    expect($renderizado)->toContain('compare_periods(a_start, a_end, b_start, b_end)');
});

it('o contexto e os resultados entram como JSON', function () {
    $payload = new ChatPayload(
        context: ['mean_glucose' => 142.0, 'period_start' => '2026-07-16'],
        toolResults: [[
            'name' => 'get_period_metrics',
            'arguments' => [],
            'result' => ['time_in_range_percent' => 83.9],
        ]],
    );

    $renderizado = chatPrompt($payload);

    // ⚠️ JSON e não prosa: é sobre esta serialização que o teste anti-vazamento
    // varre o cabeçalho do CSV. Reformatar em frases criaria uma segunda
    // representação, fora do alcance da verificação do Artigo VII.
    expect($renderizado)->toContain('```json');
    expect($renderizado)->toContain('142');
    expect($renderizado)->toContain('83.9');
});

it('turno sem consulta nenhuma diz isso, em vez de mostrar um JSON vazio', function () {
    expect(chatPrompt())->toContain('nada consultado ainda');
});

/*
|--------------------------------------------------------------------------
| As seis regras do §9.4
|--------------------------------------------------------------------------
*/

it('o prompt traz os guardrails do §9.4', function (string $regra) {
    expect(mb_strtolower(chatPrompt()))->toContain($regra);
})->with([
    // 1. escopo
    'não for sobre os dados de glicose e insulina desta pessoa',
    // 2. nunca recomendar dose/basal/CR/ISF
    'sugerir dose de insulina',
    'razão de carboidrato',
    // 3. todo número vem de ferramenta
    'você não tem os dados',
    // 4. portão de validade
    '14 dias e 70% de captura',
    // 5. não diagnosticar
    'diagnosticar',
    // 6. encaminhar ao médico
    'endocrinologista',
]);

/**
 * ⚠️ **T508.5 — dizer que não sabe.**
 *
 * A lacuna preenchida é indistinguível do dado real, e é o modo de falha que
 * este produto inteiro existe para evitar.
 */
it('o prompt manda dizer que falta dado, em vez de preencher', function () {
    $renderizado = mb_strtolower(chatPrompt());

    expect($renderizado)->toContain('diga que falta');
    expect($renderizado)->toContain('preencher a lacuna não é');
});

/**
 * ⚠️ **A regra que organiza todas as outras** (§D1) tem de estar no topo, não
 * no meio de uma lista. O modelo lê o começo com mais peso.
 */
it('a regra de não ter os dados aparece antes do catálogo de ferramentas', function () {
    $renderizado = chatPrompt();

    $regra = mb_strpos($renderizado, 'Você não tem os dados');
    $catalogo = mb_strpos($renderizado, 'get_period_metrics');

    expect($regra)->not->toBeFalse();
    expect($regra)->toBeLessThan($catalogo);
});

it('o prompt mostra o exemplo canônico do Artigo IV', function () {
    // O par ❌/✅ da constituição, que ensina a diferença entre descrever
    // mecanismo e julgar caráter melhor que qualquer instrução abstrata.
    $renderizado = chatPrompt();

    expect($renderizado)->toContain('109 g');
    expect($renderizado)->toContain('reação fisiológica');
});

it('o prompt existe no caminho que a config aponta', function () {
    expect(file_exists(resource_path(config('chat.prompt_path'))))->toBeTrue();
});

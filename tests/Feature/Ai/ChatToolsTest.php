<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\ToolRegistry;
use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolCall;
use App\Domain\Ai\Chat\Value\ToolResult;
use App\Models\Meal;
use App\Models\User;

/**
 * T503–T505 — as dez ferramentas do §9.3 (FR-602, §D1, §D8).
 *
 * ⚠️ **Roda contra o export de referência**, não contra série sintética. É o
 * Artigo XI aplicado à camada nova: os números que as ferramentas devolvem são
 * conferidos com o `gabarito.md`, apurado por análise independente do arquivo.
 * Se o código divergir, presume-se que o código está errado.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    importAndAnalyse($this->user->id);

    $this->scope = new ChatScope($this->user->id, 400);
});

/** O período do export de referência (gabarito §Fase 2). */
const REF_FROM = '2026-07-16';

const REF_TO = '2026-07-29';

function chamar(string $nome, array $args = []): ToolResult
{
    return app(ToolRegistry::class)->run(new ToolCall($nome, $args), test()->scope);
}

function periodo(array $extra = []): array
{
    return array_merge(['start' => REF_FROM, 'end' => REF_TO], $extra);
}

/*
|--------------------------------------------------------------------------
| O catálogo
|--------------------------------------------------------------------------
*/

it('registra as dez ferramentas do §9.3, com estes nomes', function () {
    expect(app(ToolRegistry::class)->names())->toEqualCanonicalizing([
        'get_period_metrics', 'get_hourly_profile', 'get_daily_series',
        'get_episodes', 'get_meals', 'get_insulin_summary', 'get_sensor_gaps',
        'get_device_events', 'compare_periods', 'get_findings',
    ]);
});

/**
 * ⚠️ **T502.4 com as ferramentas REAIS.** O `ToolDescriptor` já recusa na
 * construção; esta varredura é a segunda camada, sobre as dez de produção.
 */
it('nenhum schema das dez declara user_id', function () {
    foreach (app(ToolRegistry::class)->descriptors() as $descritor) {
        foreach (array_keys($descritor->argumentSchema) as $argumento) {
            expect(mb_strtolower((string) $argumento))
                ->not->toContain('user', "{$descritor->name} aceita '{$argumento}'");
        }
    }
});

it('toda ferramenta tem descrição que diz quando usá-la', function () {
    foreach (app(ToolRegistry::class)->descriptors() as $descritor) {
        // É o único texto do projeto escrito para uma máquina ler. Descrição
        // vaga produz ferramenta chamada na hora errada — e o sintoma é uma
        // resposta ruim, sem erro nenhum no log.
        expect(mb_strlen($descritor->description))->toBeGreaterThan(80);
        expect(mb_strtolower($descritor->description))->toContain('use');
    }
});

/*
|--------------------------------------------------------------------------
| T503 — métrica, conferida com o gabarito
|--------------------------------------------------------------------------
*/

it('get_period_metrics bate com o gabarito do export de referência', function () {
    $r = chamar('get_period_metrics', periodo());

    expect($r->succeeded())->toBeTrue($r->error ?? '');

    // gabarito.md §Fase 2 — apurados por análise independente do CSV.
    expect($r->data['reading_count'])->toBe(3616);
    expect($r->data['days_span'])->toBeCloseToValue(13.8, 0.05);
    expect($r->data['coverage_percent'])->toBeCloseToValue(91.1, 0.05);
    expect($r->data['mean_glucose'])->toBeCloseToValue(142.0, 0.5);
    expect($r->data['cv_percent'])->toBeCloseToValue(28.8, 0.05);
    expect($r->data['gmi'])->toBeCloseToValue(6.70, 0.005);
    expect($r->data['time_in_range_percent'])->toBeCloseToValue(83.9, 0.05);
});

/**
 * ⚠️⚠️ **T503.5 — a prova de que chat e dashboard não divergem (§D1).**
 *
 * Não é teste de estilo: duas fontes de verdade para o mesmo número é o defeito
 * mais corrosivo de confiança que este produto poderia ter. Quebra no dia em que
 * alguém duplicar uma fórmula na camada de ferramentas.
 */
it('get_period_metrics devolve os MESMOS números que /dashboard', function () {
    $tela = $this->get('/dashboard')->viewData('page')['props']['summary'];

    $r = chamar('get_period_metrics', [
        'start' => $tela['period']['from'],
        'end' => $tela['period']['to'],
    ]);

    expect($r->data['reading_count'])->toBe($tela['coverage']['reading_count']);
    expect($r->data['coverage_percent'])->toBe($tela['coverage']['percentage']);
    expect($r->data['validity'])->toBe($tela['validity']['status']);
});

it('get_daily_series devolve os mesmos dias que o dashboard, com os mesmos números', function () {
    $tela = $this->get('/dashboard')->viewData('page')['props']['summary'];

    $r = chamar('get_daily_series', [
        'start' => $tela['period']['from'],
        'end' => $tela['period']['to'],
    ]);

    expect($r->data['day_count'])->toBe(count($tela['daily_metrics']));

    $doDia = collect($r->data['rows'])->firstWhere('local_date', '2026-07-25');

    // gabarito.md §Fase 2 — 25/07: n=281, cap 98%, média 159, TIR 68,7%, CV 42,2%
    expect($doDia['reading_count'])->toBe(281);
    expect($doDia['mean_glucose'])->toBeCloseToValue(159.0, 0.5);
    expect($doDia['time_in_range_percent'])->toBeCloseToValue(68.7, 0.05);
    expect($doDia['cv_percent'])->toBeCloseToValue(42.2, 0.05);
});

it('get_hourly_profile devolve 24 horas com o denominador de cada uma', function () {
    $r = chamar('get_hourly_profile', periodo());

    expect($r->data['rows'])->toHaveCount(24);

    // gabarito.md §Fase 2 — 20h é a maior média, com n = 132.
    $vinteHoras = collect($r->data['rows'])->firstWhere('hour', 20);
    expect($vinteHoras['mean_glucose'])->toBeCloseToValue(171.0, 0.5);
    expect($vinteHoras['reading_count'])->toBe(132);

    // ⚠️ Artigo V: nenhuma linha sai sem quantas leituras a sustentam.
    foreach ($r->data['rows'] as $linha) {
        expect($linha)->toHaveKey('reading_count');
    }
});

it('get_insulin_summary calcula a fração automática em PHP, não no modelo', function () {
    $r = chamar('get_insulin_summary', periodo());

    // gabarito.md — automática 31,4 U/dia, bolus 21,1 U/dia, total 52,5 U/dia.
    expect($r->data['total_auto_insulin_u'])->toBeCloseToValue(440.0, 1.0);
    expect($r->data['total_bolus_insulin_u'])->toBeCloseToValue(295.15, 1.0);

    // ⚠️ A fração já vem pronta: o Artigo I proíbe o modelo dividir.
    expect($r->data['automatic_fraction_percent'])->toBeCloseToValue(59.9, 0.5);
});

/**
 * ⚠️⚠️ **T503.6 — o Artigo V dentro do resultado (§D8).**
 *
 * "Avise o usuário quando o período for curto" é uma instrução que o modelo
 * cumpre quase sempre. Um campo `null` **não tem como ser citado**.
 */
it('período curto zera GMI e CV, e diz por quê', function () {
    $r = chamar('get_period_metrics', ['start' => '2026-07-27', 'end' => '2026-07-29']);

    expect($r->data['validity'])->not->toBe('valid');

    // ⚠️ Não há número para citar.
    expect($r->data['gmi'])->toBeNull();
    expect($r->data['cv_percent'])->toBeNull();

    // E o campo ao lado dá ao modelo o texto para repassar.
    expect($r->data['gmi_unavailable'])->toContain('14 dias');
    expect($r->data['cv_unavailable'])->toContain('70%');

    // ⚠️ A média CONTINUA saindo: ela é interpretável em qualquer período. O
    // que exige 14 dias e 70% de captura são GMI e CV.
    expect($r->data['mean_glucose'])->toBeFloat();

    // E o denominador nunca some.
    expect($r->data['days_span'])->not->toBeNull();
    expect($r->data['coverage_percent'])->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| T504 — evento e refeição
|--------------------------------------------------------------------------
*/

it('get_episodes devolve as hipoglicemias do gabarito', function () {
    $r = chamar('get_episodes', periodo(['type' => 'hypo']));

    expect($r->succeeded())->toBeTrue($r->error ?? '');
    expect($r->data['episode_count'])->toBeGreaterThan(0);

    foreach ($r->data['rows'] as $linha) {
        expect($linha)->toHaveKeys([
            'start', 'end', 'duration_minutes', 'extreme_mgdl', 'interrupted_by_gap',
        ]);
        // ⚠️ O nadir do gabarito é 55; nenhuma hipo pode sair acima do limiar.
        expect($linha['extreme_mgdl'])->toBeLessThan(70);
    }

    // As somas vêm prontas — o Artigo I proíbe o modelo somar a lista.
    expect($r->data['total_duration_minutes'])->toBeNumeric();
    expect($r->data['longest_duration_minutes'])->toBeNumeric();
});

it('get_episodes recusa um tipo que não existe', function () {
    expect(chamar('get_episodes', periodo(['type' => 'severa']))->error)
        ->toContain('hyper_l2');
});

it('get_sensor_gaps encontra a lacuna de 22,4 h do gabarito', function () {
    $r = chamar('get_sensor_gaps', periodo());

    // gabarito.md §R5 — a maior lacuna do export são 1.347 min (22,4 h).
    expect($r->data['longest_minutes'])->toBeCloseToValue(1347.0, 2.0);
    expect($r->data['gap_count'])->toBeGreaterThan(0);
});

it('get_device_events agrega sem devolver os 266 eventos', function () {
    $r = chamar('get_device_events', periodo());

    expect($r->data['event_count'])->toBeGreaterThan(0);
    expect($r->data['by_category'])->toBeArray();

    // ⚠️ Dado NUNCA é chave: a agregação sai como lista de linhas nomeadas.
    // Um mapa `{"2026-07-25": 3}` quebraria a allowlist do Artigo VII, que
    // enumera NOMES DE CAMPO — e nenhuma allowlist pode listar todas as datas.
    foreach ($r->data['by_date'] as $linha) {
        expect($linha)->toHaveKeys(['local_date', 'event_count']);
    }

    foreach ($r->data['by_code'] as $linha) {
        expect($linha)->toHaveKeys(['code', 'event_count']);
    }
});

it('get_device_events filtra por categoria', function () {
    $todos = chamar('get_device_events', periodo())->data['event_count'];
    $rewinds = chamar('get_device_events', periodo(['category' => 'rewind']));

    // gabarito.md §R8 — 3 trocas de reservatório no período.
    expect($rewinds->data['event_count'])->toBe(3);
    expect($rewinds->data['event_count'])->toBeLessThan($todos);
});

/**
 * ⚠️⚠️ **`get_meals` LÊ a resposta glicêmica; não a recalcula.**
 *
 * A primeira versão desta ferramenta recalculava `peak_2h` e `delta_2h` a partir
 * da série — e estava errada, não por aritmética, mas por criar a segunda fonte
 * de verdade que o §D1 existe para impedir. Pior: divergia de propósito, porque o
 * `MealEnricher` da fase 1 usa `bg_input` como partida do delta (a glicose que a
 * calculadora da bomba usou, e que a pessoa VIU na tela).
 *
 * Este teste é a rede: o que a ferramenta devolve é **igual ao que está gravado**.
 */
it('get_meals devolve exatamente o que o MealEnricher gravou', function () {
    $r = chamar('get_meals', periodo());

    expect($r->data['meal_count'])->toBeGreaterThan(0);

    $comResposta = collect($r->data['rows'])->firstWhere(
        fn (array $l): bool => $l['peak_2h'] !== null && $l['delta_2h'] !== null
    );

    expect($comResposta)->not->toBeNull();

    $gravada = Meal::where('user_id', $this->user->id)
        ->where('recorded_at_local', 'like', substr($comResposta['at'], 0, 16).'%')
        ->firstOrFail();

    expect($comResposta['peak_2h'])->toBe($gravada->peak_2h);
    expect($comResposta['delta_2h'])->toBe($gravada->delta_2h);
    expect($comResposta['glucose_4h'])->toBe($gravada->glucose_4h);
    expect($comResposta['bg_input'])->toBe($gravada->bg_input);
});

/**
 * ⚠️ O delta usa `bg_input` como partida — decisão da fase 1, e a razão está
 * escrita lá: é o número que a pessoa viu na tela da bomba, e "meu app discorda
 * da minha bomba" é a forma mais rápida de perder confiança.
 */
it('o delta parte da glicose que a calculadora usou, não do sensor', function () {
    $r = chamar('get_meals', periodo());

    $comInput = collect($r->data['rows'])->firstWhere(
        fn (array $l): bool => $l['bg_input'] !== null && $l['delta_2h'] !== null && $l['peak_2h'] !== null
    );

    expect($comInput)->not->toBeNull();
    expect($comInput['delta_2h'])->toBe($comInput['peak_2h'] - $comInput['bg_input']);
});

it('get_meals filtra por carboidrato mínimo', function () {
    $todas = chamar('get_meals', periodo())->data['meal_count'];
    $grandes = chamar('get_meals', periodo(['min_carbs' => 60]));

    expect($grandes->data['meal_count'])->toBeLessThan($todas);

    foreach ($grandes->data['rows'] as $linha) {
        expect($linha['carbs_g'])->toBeGreaterThanOrEqual(60);
    }
});

/*
|--------------------------------------------------------------------------
| T505 — compostas
|--------------------------------------------------------------------------
*/

it('compare_periods calcula o delta em PHP, com os dois lados completos', function () {
    $r = chamar('compare_periods', [
        'a_start' => '2026-07-16', 'a_end' => '2026-07-22',
        'b_start' => '2026-07-23', 'b_end' => '2026-07-29',
    ]);

    expect($r->succeeded())->toBeTrue($r->error ?? '');

    $a = $r->data['period_a'];
    $b = $r->data['period_b'];

    // ⚠️ O delta é dado, não conta que o modelo faz (Artigo I).
    expect($r->data['delta']['mean_glucose_delta'])
        ->toBeCloseToValue($b['mean_glucose'] - $a['mean_glucose'], 0.02);
});

/**
 * ⚠️ **T505.3 — cada lado carrega a própria validade.**
 *
 * Comparar 14 dias com 3 dias e apresentar o delta como fato é exatamente o que
 * o Artigo V existe para impedir — e é a comparação que o usuário pede sem
 * perceber, porque "melhorei em relação à semana passada?" não menciona captura.
 */
it('compare_periods marca a validade de cada lado, e não inventa delta de campo ausente', function () {
    $r = chamar('compare_periods', [
        'a_start' => REF_FROM, 'a_end' => REF_TO,       // 14 dias — válido
        'b_start' => '2026-07-27', 'b_end' => '2026-07-29',  // 3 dias — não
    ]);

    expect($r->data['period_a']['validity'])->toBe('valid');
    expect($r->data['period_b']['validity'])->not->toBe('valid');

    // O CV do lado B veio `null` (§D8) — então o delta de CV também é `null`.
    expect($r->data['period_b']['cv_percent'])->toBeNull();
    expect($r->data['delta']['cv_percent_delta'])->toBeNull();

    // E o que os dois lados têm continua sendo comparado.
    expect($r->data['delta']['mean_glucose_delta'])->toBeNumeric();
});

it('get_findings devolve os dez achados com evidência, e SEM a prosa', function () {
    $r = chamar('get_findings', periodo());

    expect($r->data['finding_count'])->toBe(10);

    foreach ($r->data['rows'] as $linha) {
        expect($linha)->toHaveKeys(['rule_id', 'severity', 'rank', 'evidence']);
        expect($linha['evidence'])->not->toBeEmpty();

        // ⚠️ Com a prosa pronta no contexto, o modelo escreve "conforme o texto
        // acima" ou simplesmente a copia — e deixa de responder a pergunta.
        expect($linha)->not->toHaveKey('fallback_prose');
        expect($linha)->not->toHaveKey('prose');
    }

    // ⚠️ O período do RELATÓRIO viaja junto: ele pode não ser o perguntado, e
    // sem isso o modelo citaria os dez achados como se fossem do recorte pedido.
    expect($r->data['report_period_start'])->not->toBeNull();
    expect($r->data['engine_version'])->not->toBeNull();
});

it('get_findings devolve vazio quando não há relatório no período', function () {
    $r = chamar('get_findings', ['start' => '2020-01-01', 'end' => '2020-01-10']);

    expect($r->succeeded())->toBeTrue();
    expect($r->data['finding_count'])->toBe(0);
    expect($r->data['rows'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| ⚠️ O que NENHUMA das dez pode fazer
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ **T504.5 — nenhuma devolve série bruta.**
 *
 * Seria o contexto cheio entrando pela porta dos fundos (§D1): 3.616 leituras
 * num resultado de ferramenta são as mesmas 3.616 leituras que o §9.2 recusa
 * colocar no prompt.
 */
it('nenhuma ferramenta devolve a série de leituras', function () {
    $chamadas = [
        ['get_period_metrics', periodo()],
        ['get_hourly_profile', periodo()],
        ['get_daily_series', periodo()],
        ['get_insulin_summary', periodo()],
        ['get_episodes', periodo(['type' => 'hypo'])],
        ['get_sensor_gaps', periodo()],
        ['get_device_events', periodo()],
        ['get_meals', periodo()],
        ['get_findings', periodo()],
    ];

    foreach ($chamadas as [$nome, $args]) {
        $r = chamar($nome, $args);

        expect($r->succeeded())->toBeTrue("{$nome}: {$r->error}");

        // Nada com 3.616 elementos, e nada com chave de leitura crua.
        $json = json_encode($r->data, JSON_THROW_ON_ERROR);
        expect(substr_count($json, 'glucose_mgdl'))->toBe(0, "{$nome} expõe leitura crua");
        expect(mb_strlen($json))->toBeLessThan(60000, "{$nome} devolveu payload grande demais");
    }
});

/**
 * ⚠️ **T506 antecipado pelo registry:** a saída real de cada ferramenta é
 * conferida contra `emittedKeys` a cada execução. Este teste prova que as dez
 * passam com dados de verdade — declaração que ninguém confere é allowlist que
 * não protege.
 */
it('a saída real das dez respeita as chaves declaradas', function () {
    $chamadas = [
        ['get_period_metrics', periodo()],
        ['get_hourly_profile', periodo()],
        ['get_daily_series', periodo()],
        ['get_insulin_summary', periodo()],
        ['get_episodes', periodo(['type' => 'hypo'])],
        ['get_episodes', periodo(['type' => 'hyper_l2'])],
        ['get_sensor_gaps', periodo()],
        ['get_device_events', periodo()],
        ['get_device_events', periodo(['category' => 'alert'])],
        ['get_meals', periodo()],
        ['get_meals', periodo(['min_carbs' => 30])],
        ['get_findings', periodo()],
        ['compare_periods', [
            'a_start' => '2026-07-16', 'a_end' => '2026-07-22',
            'b_start' => '2026-07-23', 'b_end' => '2026-07-29',
        ]],
    ];

    foreach ($chamadas as [$nome, $args]) {
        $r = chamar($nome, $args);

        // O registry transforma chave não declarada em erro — se passou, a
        // declaração cobre a saída real.
        expect($r->succeeded())->toBeTrue("{$nome} emitiu chave não declarada: {$r->error}");
    }
});

/**
 * ⚠️ **§D2 com dados reais.** Duas contas, e a ferramenta lendo a da sessão.
 */
it('a ferramenta lê os dados do usuário do escopo, e de nenhum outro', function () {
    $outro = User::factory()->create();

    $meu = chamar('get_period_metrics', periodo());

    $doOutro = app(ToolRegistry::class)->run(
        new ToolCall('get_period_metrics', periodo()),
        new ChatScope($outro->id, 400),
    );

    expect($meu->data['reading_count'])->toBe(3616);
    // O outro usuário não importou nada — e não vê uma leitura sequer.
    expect($doOutro->data['reading_count'])->toBe(0);
});

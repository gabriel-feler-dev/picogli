<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\ToolRegistry;
use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolCall;
use App\Domain\Presentation\MealsPresenter;
use App\Domain\Presentation\Value\MealGroup;
use App\Models\Meal;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T600 e T601 — o rótulo de refeição (Spec 007, FR-701–703, §D1, §D2, §D3).
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    importAndAnalyse($this->user->id);
});

function primeirasRefeicoes(int $quantas): array
{
    return Meal::where('user_id', test()->user->id)
        ->whereNotNull('delta_2h')
        ->orderBy('recorded_at_local')
        ->limit($quantas)
        ->get()
        ->all();
}

function rotular(Meal $meal, string $rotulo): void
{
    $meal->forceFill(['label' => $rotulo])->saveQuietly();
}

/*
|--------------------------------------------------------------------------
| T600.1 — ⚠️ nenhuma migration
|--------------------------------------------------------------------------
*/

/**
 * ⭐ **`meals.label` foi criada na PRIMEIRA migration do projeto**, com o
 * comentário `// Input do usuário (Spec 007)` — seis fases antes de esta spec
 * existir.
 *
 * Este teste registra isso: se alguém escrever uma migration para adicionar a
 * coluna, ela já está lá, e a segunda tentativa quebraria o `migrate:fresh`.
 */
it('a coluna label existe desde a fase 1, sem migration nova', function () {
    expect(Schema::hasColumn('meals', 'label'))->toBeTrue();

    $migrationDaFase1 = file_get_contents(
        base_path('database/migrations/2026_08_04_120100_create_event_tables.php')
    );

    expect($migrationDaFase1)->toContain("string('label')");
    expect($migrationDaFase1)->toContain('Spec 007');

    // E nenhuma migration da fase 7 tocou nela.
    foreach (glob(base_path('database/migrations/2026_08_0[7-9]*.php')) as $arquivo) {
        expect(file_get_contents($arquivo))->not->toContain("'label'");
    }
});

/*
|--------------------------------------------------------------------------
| T600.2 — gravar, apagar, escopar
|--------------------------------------------------------------------------
*/

it('grava o rótulo de uma refeição', function () {
    $refeicao = primeirasRefeicoes(1)[0];

    $this->patch(route('meals.label', $refeicao), ['label' => 'pizza'])
        ->assertRedirect();

    expect($refeicao->fresh()->label)->toBe('pizza');
});

it('rótulo em branco apaga, e null é o estado normal', function () {
    $refeicao = primeirasRefeicoes(1)[0];
    rotular($refeicao, 'pizza');

    // Apagar é caso de uso, não erro: a pessoa rotulou errado e quer desfazer.
    $this->patch(route('meals.label', $refeicao), ['label' => '   ']);

    expect($refeicao->fresh()->label)->toBeNull();
});

it('espaço em volta do rótulo é removido', function () {
    $refeicao = primeirasRefeicoes(1)[0];

    $this->patch(route('meals.label', $refeicao), ['label' => '  feijoada  ']);

    expect($refeicao->fresh()->label)->toBe('feijoada');
});

it('rótulo longo demais é recusado', function () {
    $refeicao = primeirasRefeicoes(1)[0];

    // ⚠️ 60 caracteres é etiqueta. Campo longo convidaria a virar diário de
    // refeição, e diário pediria uma spec própria.
    $this->patch(route('meals.label', $refeicao), ['label' => str_repeat('a', 61)])
        ->assertSessionHasErrors('label');

    expect($refeicao->fresh()->label)->toBeNull();
});

/**
 * ⚠️ O 404 é deliberado: dizer "essa refeição não é sua" confirmaria que ela
 * existe.
 */
it('a refeição de outro usuário não é rotulável', function () {
    $outro = User::factory()->create();
    $refeicao = primeirasRefeicoes(1)[0];

    $this->actingAs($outro)
        ->patch(route('meals.label', $refeicao), ['label' => 'pizza'])
        ->assertNotFound();

    expect($refeicao->fresh()->label)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| T600.3 — ⚠️ o rótulo NÃO entra em cálculo (§D2)
|--------------------------------------------------------------------------
*/

/**
 * ⚠️⚠️ **A distinção que fez o rótulo entrar nesta fase e o peso do usuário sair.**
 *
 * Rótulo é TEXTO e agrupa; peso seria NÚMERO e dividiria a insulina total,
 * produzindo métrica que *parece medida e está errada* quando o campo envelhece.
 *
 * **A regra:** dado digitado pode rotular, agrupar e filtrar. Não pode entrar em
 * fórmula cujo resultado o produto apresenta como medição.
 */
it('rotular não muda nenhum número da refeição', function () {
    $refeicao = primeirasRefeicoes(1)[0];

    $antes = $refeicao->only(['carbs_g', 'bg_input', 'peak_2h', 'delta_2h', 'glucose_4h']);

    $this->patch(route('meals.label', $refeicao), ['label' => 'pizza']);

    expect($refeicao->fresh()->only(array_keys($antes)))->toBe($antes);
});

/**
 * ⚠️ E não muda nenhuma métrica do produto. Rótulo é dado novo em tela nova; se
 * ele mexer no dashboard ou na avaliação, algo está acoplado onde não devia.
 */
it('rotular não muda o dashboard nem a avaliação', function () {
    $dashboardAntes = $this->get('/dashboard')->viewData('page')['props']['summary'];
    $avaliacaoAntes = $this->get('/avaliacao')->viewData('page')['props']['findings'];

    foreach (primeirasRefeicoes(3) as $refeicao) {
        $this->patch(route('meals.label', $refeicao), ['label' => 'pizza']);
    }

    expect($this->get('/dashboard')->viewData('page')['props']['summary'])->toBe($dashboardAntes);
    expect($this->get('/avaliacao')->viewData('page')['props']['findings'])->toBe($avaliacaoAntes);
});

/*
|--------------------------------------------------------------------------
| T600.4 e T600.5 — o rótulo no chat
|--------------------------------------------------------------------------
*/

it('get_meals emite o rótulo, e a allowlist derivada o aceita sozinha', function () {
    rotular(primeirasRefeicoes(1)[0], 'pizza');

    $registry = app(ToolRegistry::class);

    // ⚠️ A allowlist do Artigo VII é DERIVADA dos descritores (§D7 da fase 6) —
    // a chave passa a ser permitida sem editar config.
    expect($registry->allowedKeys())->toContain('label');

    $r = $registry->run(
        new ToolCall('get_meals', ['start' => '2026-07-16', 'end' => '2026-07-29']),
        new ChatScope($this->user->id, 400),
    );

    expect($r->succeeded())->toBeTrue($r->error ?? '');
    expect(collect($r->data['rows'])->pluck('label'))->toContain('pizza');
});

/**
 * ⚠️ Rótulo é texto livre — a pessoa pode escrever o próprio nome ali. Isso não é
 * vazamento (ela mandou o dado por vontade dela), mas é superfície nova, e o
 * teste anti-vazamento continua valendo para o que vem do CABEÇALHO do CSV.
 */
it('o anti-vazamento continua valendo com rótulos gravados', function () {
    rotular(primeirasRefeicoes(1)[0], 'almoço de domingo');

    $sanitizer = app('ai.chat.sanitizer');

    $resultado = app(ToolRegistry::class)->run(
        new ToolCall('get_meals', ['start' => '2026-07-16', 'end' => '2026-07-29']),
        new ChatScope($this->user->id, 400),
    );

    $json = $sanitizer->sanitizeChat([$resultado->toArray()])->toJson();

    // O rótulo passa (é dado do usuário, deliberado)...
    expect($json)->toContain('almoço de domingo');

    // ...e o que vem do cabeçalho do CSV continua fora.
    foreach (['Feler', 'NG3670115H'] as $pii) {
        expect(str_contains($json, $pii))->toBeFalse();
    }
});

/*
|--------------------------------------------------------------------------
| T601 — a tela e o agrupamento
|--------------------------------------------------------------------------
*/

it('exige autenticação', function () {
    auth()->logout();

    $this->get('/refeicoes')->assertRedirect('/login');
});

it('renderiza a tela com as refeições do período', function () {
    $this->get('/refeicoes')->assertInertia(fn (Assert $page) => $page
        ->component('Meals')
        ->where('meal_count', fn (int $n): bool => $n > 0)
        ->where('labelled_count', 0)
        ->has('period')
    );
});

/**
 * ⚠️⚠️ **T601.2 — os números vêm das COLUNAS** (§D1).
 *
 * A fase 6 tropeçou exatamente aqui: a `MealsTool` nasceu recalculando, e o
 * resultado divergia de propósito, porque o `MealEnricher` parte de `bg_input`.
 * Se este presenter calculasse, a tela e o chat discordariam sobre a mesma
 * refeição.
 */
it('o presenter devolve exatamente o que está gravado em meals', function () {
    $dados = app(MealsPresenter::class)->forPeriod($this->user->id, '2026-07-16', '2026-07-29');

    $naTela = collect($dados['meals'])->firstWhere('has_response', true);
    expect($naTela)->not->toBeNull();

    $gravada = Meal::findOrFail($naTela['id']);

    expect($naTela['peak_2h'])->toBe($gravada->peak_2h);
    expect($naTela['delta_2h'])->toBe($gravada->delta_2h);
    expect($naTela['glucose_4h'])->toBe($gravada->glucose_4h);
    expect($naTela['bg_input'])->toBe($gravada->bg_input);
});

it('agrupa por rótulo, ignorando maiúscula', function () {
    [$a, $b, $c] = primeirasRefeicoes(3);
    rotular($a, 'Pizza');
    rotular($b, 'pizza');
    rotular($c, 'feijoada');

    $grupos = app(MealsPresenter::class)->forPeriod($this->user->id, '2026-07-16', '2026-07-29')['groups'];

    // "Pizza" e "pizza" são a mesma comida.
    expect($grupos)->toHaveCount(2);
    expect(collect($grupos)->pluck('meal_count')->sort()->values()->all())->toBe([1, 2]);
});

/**
 * ⚠️ **A grafia exibida é a da refeição MAIS RECENTE.**
 *
 * Se a pessoa passou a escrever "Pizza", é assim que ela quer ver. A alternativa
 * — a mais antiga — congelaria um typo do primeiro registro para sempre.
 */
it('o grupo mostra a grafia mais recente do rótulo', function () {
    // `primeirasRefeicoes` vem em ordem crescente: $a é a mais antiga.
    [$a, $b] = primeirasRefeicoes(2);
    rotular($a, 'piza');       // como foi digitado primeiro
    rotular($b, 'pizza');      // como passou a ser digitado

    $grupos = app(MealsPresenter::class)->forPeriod($this->user->id, '2026-07-16', '2026-07-29')['groups'];

    // Grafias diferentes são grupos diferentes — só a caixa é ignorada.
    expect(collect($grupos)->pluck('label')->all())->toContain('pizza', 'piza');
});

/**
 * ⚠️⚠️ **T601.4 — o denominador nunca sai de vista** (Artigo V).
 *
 * "Pizza sobe 87 mg/dL em média" sobre duas refeições é ruído com cara de
 * conclusão.
 */
it('todo grupo carrega a contagem ao lado da média', function () {
    foreach (primeirasRefeicoes(2) as $refeicao) {
        rotular($refeicao, 'pizza');
    }

    $grupo = app(MealsPresenter::class)
        ->forPeriod($this->user->id, '2026-07-16', '2026-07-29')['groups'][0];

    expect($grupo)->toHaveKeys(['meal_count', 'with_response_count', 'mean_delta_2h']);
    expect($grupo['meal_count'])->toBe(2);

    // ⚠️ E duas refeições NÃO são amostra suficiente — a tela avisa.
    expect($grupo['has_enough_sample'])->toBeFalse();
});

it('a média de subida usa só as refeições com resposta apurada', function () {
    $comResposta = primeirasRefeicoes(2);
    rotular($comResposta[0], 'pizza');
    rotular($comResposta[1], 'pizza');

    // Uma sem resposta glicêmica, no mesmo grupo.
    $semResposta = Meal::where('user_id', $this->user->id)
        ->whereNull('delta_2h')
        ->first();

    if ($semResposta !== null) {
        rotular($semResposta, 'pizza');
    }

    $grupo = app(MealsPresenter::class)
        ->forPeriod($this->user->id, '2026-07-16', '2026-07-29')['groups'][0];

    // ⚠️ Dividir por todas trataria `null` como zero, e a média sairia menor —
    // número errado com aparência de medição.
    $esperada = round(($comResposta[0]->delta_2h + $comResposta[1]->delta_2h) / 2, 1);

    expect($grupo['mean_delta_2h'])->toBe($esperada);
    expect($grupo['with_response_count'])->toBe(2);

    if ($semResposta !== null) {
        expect($grupo['meal_count'])->toBe(3);
    }
});

it('refeição sem rótulo não vira grupo', function () {
    $dados = app(MealsPresenter::class)->forPeriod($this->user->id, '2026-07-16', '2026-07-29');

    // ⚠️ Um grupo "sem rótulo" com 38 refeições diria menos que a lista já diz.
    expect($dados['groups'])->toBe([]);
    expect($dados['meals'])->not->toBe([]);
});

it('os grupos vêm ordenados pela maior subida média', function () {
    $refeicoes = Meal::where('user_id', $this->user->id)
        ->whereNotNull('delta_2h')
        ->orderByDesc('delta_2h')
        ->limit(4)
        ->get();

    rotular($refeicoes[0], 'subiu muito');
    rotular($refeicoes[3], 'subiu pouco');

    $grupos = app(MealsPresenter::class)
        ->forPeriod($this->user->id, '2026-07-16', '2026-07-29')['groups'];

    expect($grupos[0]['label'])->toBe('subiu muito');
});

/*
|--------------------------------------------------------------------------
| T601.5 — ⚠️ o agrupamento não conclui (§D3)
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ "Pizza é pior que arroz" seria a R11 — regra determinística nova, com
 * limiar, severidade, prosa de fallback e gabarito apurado. As dez estão
 * fechadas, e o limiar precisaria de amostra que esta fase apenas começa a
 * coletar.
 */
it('o payload da tela não traz veredito, severidade nem recomendação', function () {
    foreach (primeirasRefeicoes(3) as $refeicao) {
        rotular($refeicao, 'pizza');
    }

    $json = json_encode(
        $this->get('/refeicoes')->viewData('page')['props'],
        JSON_THROW_ON_ERROR
    );

    foreach (['severity', 'severidade', 'veredito', 'recomend', 'evite', 'prefira', 'pior'] as $conclusao) {
        expect(str_contains(mb_strtolower($json), $conclusao))->toBeFalse(
            "o payload traz '{$conclusao}' — o agrupamento agrupa, não conclui (§D3)"
        );
    }
});

/*
|--------------------------------------------------------------------------
| O MealGroup em si
|--------------------------------------------------------------------------
*/

it('MealGroup recusa grupo sem rótulo', function () {
    expect(fn () => new MealGroup('  ', 1, 40.0, 50.0, 1))
        ->toThrow(InvalidArgumentException::class);
});

it('MealGroup recusa contagem inválida', function () {
    expect(fn () => new MealGroup('pizza', 0, null, null, 0))
        ->toThrow(InvalidArgumentException::class, 'denominador');
});

it('MealGroup recusa mais respostas que refeições', function () {
    expect(fn () => new MealGroup('pizza', 2, 40.0, 50.0, 3))
        ->toThrow(InvalidArgumentException::class);
});

it('MealGroup decide amostra suficiente pelas refeições COM resposta', function () {
    // Dez refeições, duas com leitura: a média é sustentada por duas.
    expect((new MealGroup('pizza', 10, 40.0, 50.0, 2))->hasEnoughSample())->toBeFalse();
    expect((new MealGroup('pizza', 3, 40.0, 50.0, 3))->hasEnoughSample())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| T601.6 e T601.7 — a tela
|--------------------------------------------------------------------------
*/

it('os componentes de refeição não fazem conta', function (string $arquivo) {
    $codigo = (string) file_get_contents(resource_path("js/{$arquivo}"));
    $codigo = (string) preg_replace('#/\*.*?\*/#s', '', $codigo);
    $codigo = (string) preg_replace('#//.*$#m', '', $codigo);

    // ⚠️ `delta_2h` chega pronto do servidor, que o lê da coluna. Subtrair aqui
    // criaria a TERCEIRA versão do mesmo número — e a segunda já divergiu.
    foreach (['reduce(', 'toFixed(', 'Math.', 'parseFloat(', 'parseInt('] as $calculo) {
        expect(str_contains($codigo, $calculo))->toBeFalse(
            "{$arquivo} usa '{$calculo}' — o servidor calcula, a tela apresenta"
        );
    }
})->with(['Pages/Meals.tsx', 'Components/MealRow.tsx']);

it('a tela traz o rodapé de fronteira clínica', function () {
    // ⚠️ O rodapé passou a morar na casca (Spec 008 §D6). A garantia do
    // Artigo VI, camada 5, ficou MAIS forte: antes, apagar a linha de uma tela
    // tirava o rodapé só dela; agora ele chega por construção a todas.
    //
    // O que se cobra aqui é a corrente inteira: a tela usa a casca, E a casca
    // renderiza o rodapé. Verificar só um dos elos deixaria o outro livre.
    expect(file_get_contents(resource_path('js/Pages/Meals.tsx')))->toContain('AppShell');
    expect(file_get_contents(resource_path('js/Layouts/AppShell.tsx')))->toContain('ClinicalFooter');
});

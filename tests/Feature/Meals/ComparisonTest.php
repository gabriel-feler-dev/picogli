<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\Persistence\ComparePeriodsTool;
use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Presentation\ComparisonPresenter;
use App\Domain\Presentation\Value\ComparedMetric;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * T602 — a tela de comparação (Spec 007, FR-704, §D1, §D4).
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    importAndAnalyse($this->user->id);
});

function comparar(string $aStart, string $aEnd, string $bStart, string $bEnd): array
{
    return app(ComparisonPresenter::class)
        ->compare(test()->user->id, $aStart, $aEnd, $bStart, $bEnd);
}

/*
|--------------------------------------------------------------------------
| T602.1 — ⚠️ reusa a ferramenta, não recalcula (§D1)
|--------------------------------------------------------------------------
*/

/**
 * ⚠️⚠️ **A prova de que a tela e o chat não divergem.**
 *
 * Se as duas calculassem separado, elas poderiam discordar sobre a mesma semana —
 * o §D1 da fase 6 acontecendo entre camadas, e o defeito mais corrosivo de
 * confiança que este produto poderia ter.
 */
it('a tela e o chat devolvem os mesmos números para o mesmo par de períodos', function () {
    $daTela = comparar('2026-07-16', '2026-07-22', '2026-07-23', '2026-07-29');

    $doChat = app(ComparePeriodsTool::class)->run([
        'a_start' => '2026-07-16', 'a_end' => '2026-07-22',
        'b_start' => '2026-07-23', 'b_end' => '2026-07-29',
    ], new ChatScope($this->user->id, 400));

    expect($daTela['period_a']['reading_count'])->toBe($doChat->data['period_a']['reading_count']);
    expect($daTela['period_b']['coverage_percent'])->toBe($doChat->data['period_b']['coverage_percent']);

    $media = collect($daTela['metrics'])->firstWhere('key', 'mean_glucose');
    expect($media['delta'])->toBe($doChat->data['delta']['mean_glucose_delta']);
});

it('o presenter não implementa fórmula de métrica', function () {
    $codigo = (string) file_get_contents(app_path('Domain/Presentation/ComparisonPresenter.php'));
    $codigo = (string) preg_replace('#/\*.*?\*/#s', '', $codigo);
    $codigo = (string) preg_replace('#//.*$#m', '', $codigo);

    // ⚠️ Ele orquestra e apresenta. Qualquer aritmética de métrica aqui seria a
    // segunda fonte de verdade.
    foreach (['array_sum(', 'sqrt(', 'pow(', 'count($leituras', 'glucose_mgdl'] as $calculo) {
        expect(str_contains($codigo, $calculo))->toBeFalse(
            "o presenter usa '{$calculo}' — o cálculo é do ComparePeriodsTool"
        );
    }
});

/*
|--------------------------------------------------------------------------
| T602.2 — ⚠️ a validade de cada lado é visível
|--------------------------------------------------------------------------
*/

it('cada lado carrega o próprio denominador', function () {
    $dados = comparar('2026-07-16', '2026-07-22', '2026-07-23', '2026-07-29');

    foreach (['period_a', 'period_b'] as $lado) {
        expect($dados[$lado])->toHaveKeys([
            'from', 'to', 'days_span', 'coverage_percent', 'reading_count', 'validity', 'is_valid',
        ]);
    }
});

/**
 * ⚠️⚠️ **O caso que o §D4 existe para cobrir.**
 *
 * Comparar 14 dias com 3 e apresentar o delta como fato é o que o Artigo V
 * proíbe — e é a comparação que o usuário pede sem perceber, porque "melhorei em
 * relação à semana passada?" não menciona cobertura.
 */
it('período curto de um lado torna a comparação não conclusiva, com o motivo', function () {
    $dados = comparar('2026-07-16', '2026-07-29', '2026-07-27', '2026-07-29');

    expect($dados['period_a']['is_valid'])->toBeTrue();
    expect($dados['period_b']['is_valid'])->toBeFalse();

    $media = collect($dados['metrics'])->firstWhere('key', 'mean_glucose');

    expect($media['conclusive'])->toBeFalse();
    // ⚠️ O motivo nomeia o lado E os números — "não é conclusiva" sem o
    // denominador é tão opaco quanto não avisar.
    expect($media['inconclusive_reason'])->toContain('atual');
    expect($media['inconclusive_reason'])->toContain('captura');
    expect($media['inconclusive_reason'])->toContain('não é conclusiva');
});

/**
 * ⚠️ `conclusive: false` **não esconde o número.** Esconder deixaria a tela vazia
 * sem explicar por quê.
 */
it('comparação não conclusiva ainda mostra os números', function () {
    $media = collect(comparar('2026-07-16', '2026-07-29', '2026-07-27', '2026-07-29')['metrics'])
        ->firstWhere('key', 'mean_glucose');

    expect($media['conclusive'])->toBeFalse();
    expect($media['value_a'])->toBeFloat();
    expect($media['value_b'])->toBeFloat();
    expect($media['delta'])->toBeFloat();
});

it('os dois lados válidos tornam a comparação conclusiva', function () {
    $dados = comparar('2026-07-16', '2026-07-29', '2026-07-16', '2026-07-29');

    $media = collect($dados['metrics'])->firstWhere('key', 'mean_glucose');

    expect($media['conclusive'])->toBeTrue();
    expect($media['inconclusive_reason'])->toBeNull();
    // Comparar um período consigo mesmo dá delta zero — e é o teste mais simples
    // de que o delta não está inventando nada.
    expect($media['delta'])->toBe(0.0);
});

/*
|--------------------------------------------------------------------------
| T602.3 — ⚠️ delta ausente quando um lado não tem o número
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ Diferença calculada contra `null` é número inventado — e do pior tipo,
 * porque sai plausível. O portão de validade zera o CV de um período curto, e o
 * delta de CV tem de desaparecer junto.
 */
it('delta de CV desaparece quando um lado não tem CV', function () {
    $dados = comparar('2026-07-16', '2026-07-29', '2026-07-27', '2026-07-29');

    $cv = collect($dados['metrics'])->firstWhere('key', 'cv_percent');

    expect($cv['value_b'])->toBeNull();
    expect($cv['delta'])->toBeNull();

    // E a média, que os dois lados têm, continua comparada.
    expect(collect($dados['metrics'])->firstWhere('key', 'mean_glucose')['delta'])->toBeFloat();
});

/*
|--------------------------------------------------------------------------
| T602.5 — a decisão é do servidor
|--------------------------------------------------------------------------
*/

it('a tela recebe conclusive decidido, não os ingredientes para decidir', function () {
    $props = $this->get('/comparar')->viewData('page')['props'];

    foreach ($props['metrics'] as $metrica) {
        expect($metrica)->toHaveKey('conclusive');
        expect($metrica['conclusive'])->toBeBool();
    }
});

it('o componente não decide se é conclusivo', function () {
    $codigo = (string) file_get_contents(resource_path('js/Components/DeltaCard.tsx'));
    $codigo = (string) preg_replace('#/\*.*?\*/#s', '', $codigo);
    $codigo = (string) preg_replace('#//.*$#m', '', $codigo);

    // ⚠️ Se o componente comparasse cobertura com limiar, a regra viveria em
    // TypeScript, fora do alcance da suíte.
    //
    // ⚠️ As agulhas são COMPARAÇÕES, não números soltos. A primeira versão deste
    // teste procurava `70` e reprovou a classe Tailwind `border-amber-300/70` —
    // varredura sobre arquivo de componente tem de casar com a construção que
    // importa, não com o dígito.
    foreach ([
        'coverage_percent <', 'coverage_percent >',
        'days_span <', 'days_span >',
        'validity ===', 'Math.',
    ] as $regra) {
        expect(str_contains($codigo, $regra))->toBeFalse(
            "DeltaCard decide '{$regra}' — a decisão é do servidor"
        );
    }

    // E consome a decisão já tomada.
    expect($codigo)->toContain('metric.conclusive');
});

/*
|--------------------------------------------------------------------------
| A tela
|--------------------------------------------------------------------------
*/

it('exige autenticação', function () {
    auth()->logout();

    $this->get('/comparar')->assertRedirect('/login');
});

it('renderiza a comparação padrão: 7 dias contra os 7 anteriores', function () {
    $this->get('/comparar')->assertInertia(fn (Assert $page) => $page
        ->component('Comparison')
        ->where('has_data', true)
        ->has('period_a')
        ->has('period_b')
        ->has('metrics', 5)
    );

    $props = $this->get('/comparar')->viewData('page')['props'];

    // Ancorado na última leitura, não em `now()` — o export é de julho de 2026.
    expect($props['period_b']['to'])->toBe('2026-07-29');
    expect($props['period_a']['to'])->toBe('2026-07-22');
});

it('aceita um par de períodos pela URL', function () {
    $props = $this->get('/comparar?a_start=2026-07-16&a_end=2026-07-22&b_start=2026-07-23&b_end=2026-07-29')
        ->viewData('page')['props'];

    expect($props['period_a']['from'])->toBe('2026-07-16');
    expect($props['period_b']['to'])->toBe('2026-07-29');
});

/**
 * ⚠️ A validação de coerência é do `ArgumentValidator`, atrás do `ToolRegistry` —
 * o mesmo caminho que o modelo usa. Duplicá-la no controller criaria duas regras
 * de período.
 */
it('período invertido devolve o erro acionável do validador, sem exceção', function () {
    $props = $this->get('/comparar?a_start=2026-07-22&a_end=2026-07-16&b_start=2026-07-23&b_end=2026-07-29')
        ->viewData('page')['props'];

    expect($props['error'])->toContain('posterior');
    expect($props)->not->toHaveKey('period_a');
});

it('sem dados, a tela diz isso em vez de comparar vazio', function () {
    $novo = User::factory()->create();

    $props = $this->actingAs($novo)->get('/comparar')->viewData('page')['props'];

    expect($props['has_data'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| O ComparedMetric em si
|--------------------------------------------------------------------------
*/

/**
 * ⚠️ **`direction()` não diz "melhorou".** Para o tempo na faixa, subir é bom;
 * para o tempo acima, subir é o contrário. Quem conhece o sentido clínico de cada
 * métrica é o `MetricTranslator` e as metas de `config/clinical.php`.
 */
it('a direção não julga a mudança', function () {
    $subiu = new ComparedMetric('x', 'X', 10.0, 12.0, 2.0, '%', true);
    $caiu = new ComparedMetric('x', 'X', 12.0, 10.0, -2.0, '%', true);
    $igual = new ComparedMetric('x', 'X', 10.0, 10.0, 0.0, '%', true);
    $semDado = new ComparedMetric('x', 'X', 10.0, null, null, '%', false, 'motivo');

    expect($subiu->direction())->toBe('up');
    expect($caiu->direction())->toBe('down');
    expect($igual->direction())->toBe('flat');
    expect($semDado->direction())->toBe('unknown');

    // E nenhum deles diz "melhorou" ou "piorou".
    foreach ([$subiu, $caiu] as $metrica) {
        expect($metrica->direction())->not->toContain('melhor');
        expect($metrica->direction())->not->toContain('pior');
    }
});

it('conclusive e o motivo viajam juntos', function () {
    $payload = (new ComparedMetric('x', 'X', 10.0, null, null, '%', false, 'período curto'))->toArray();

    // ⚠️ Um `conclusive: false` sem motivo deixaria a tela dizendo "não é
    // conclusivo" sem dizer por quê.
    expect($payload)->toHaveKeys(['conclusive', 'inconclusive_reason']);
    expect($payload['conclusive'])->toBeFalse();
    expect($payload['inconclusive_reason'])->toBe('período curto');
});

it('a tela de comparação não calcula', function (string $arquivo) {
    $codigo = (string) file_get_contents(resource_path("js/{$arquivo}"));
    $codigo = (string) preg_replace('#/\*.*?\*/#s', '', $codigo);
    $codigo = (string) preg_replace('#//.*$#m', '', $codigo);

    foreach (['reduce(', 'toFixed(', 'Math.', 'parseFloat(', 'parseInt('] as $calculo) {
        expect(str_contains($codigo, $calculo))->toBeFalse("{$arquivo} usa '{$calculo}'");
    }
})->with(['Pages/Comparison.tsx', 'Components/DeltaCard.tsx']);

it('a tela traz o rodapé de fronteira clínica', function () {
    expect(file_get_contents(resource_path('js/Pages/Comparison.tsx')))->toContain('ClinicalFooter');
});

<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\ArgumentValidator;
use App\Domain\Ai\Chat\ChatTool;
use App\Domain\Ai\Chat\ToolRegistry;
use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolCall;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;

/**
 * T502 — o contrato de ferramenta (FR-603, §D2, §D7).
 *
 * ⚠️ Teste de unidade: sem container e sem banco. A ferramenta falsa abaixo é o
 * que permite testar o contrato **sem** as dez implementações reais — e é o que
 * vai continuar valendo quando elas mudarem.
 */
function fakeTool(
    string $nome = 'get_period_metrics',
    array $schema = ['start' => ['type' => 'date', 'required' => true], 'end' => ['type' => 'date', 'required' => true]],
    array $emite = ['mean_glucose', 'days_span'],
    ?callable $executa = null,
): ChatTool {
    return new class($nome, $schema, $emite, $executa) implements ChatTool
    {
        public function __construct(
            private string $nome,
            private array $schema,
            private array $emite,
            private $executa,
        ) {}

        public function describe(): ToolDescriptor
        {
            return new ToolDescriptor($this->nome, 'Métricas do período.', $this->schema, $this->emite);
        }

        public function run(array $args, ChatScope $scope): ToolResult
        {
            if ($this->executa !== null) {
                return ($this->executa)($args, $scope);
            }

            return ToolResult::ok($this->nome, $args, ['mean_glucose' => 154.2, 'days_span' => 14]);
        }
    };
}

function registry(array $tools): ToolRegistry
{
    return new ToolRegistry($tools, new ArgumentValidator);
}

function scope(int $maxSpan = 90): ChatScope
{
    return new ChatScope(1, $maxSpan);
}

/*
|--------------------------------------------------------------------------
| T502.4 — ⚠️ `user_id` não existe no schema
|--------------------------------------------------------------------------
*/

it('recusa na CONSTRUÇÃO um schema que declare user_id', function (string $campo) {
    expect(fn () => new ToolDescriptor(
        'get_period_metrics',
        'Métricas.',
        [$campo => ['type' => 'int']],
        ['mean_glucose'],
    ))->toThrow(InvalidArgumentException::class);
})->with(['user_id', 'userid', 'user', 'account_id', 'owner_id']);

/**
 * ⚠️ **O caso que o §D2 existe para cobrir.** O modelo manda `user_id` junto,
 * de propósito ou por confusão. Como o schema não tem o campo, ele cai na regra
 * de argumento desconhecido — e o erro **volta ao modelo**, deixando rastro, em
 * vez de ser descartado em silêncio.
 */
it('argumento user_id vindo do modelo é recusado, não ignorado', function () {
    $resultado = registry([fakeTool()])->run(
        new ToolCall('get_period_metrics', [
            'start' => '2026-07-16',
            'end' => '2026-07-29',
            'user_id' => 999,
        ]),
        scope(),
    );

    expect($resultado->succeeded())->toBeFalse();
    expect($resultado->error)->toContain('user_id');
    expect($resultado->error)->toContain('desconhecido');
});

it('o escopo vem do ChatScope, e a ferramenta o recebe separado dos argumentos', function () {
    $vistos = null;

    $tool = fakeTool(executa: function (array $args, ChatScope $scope) use (&$vistos): ToolResult {
        $vistos = ['args' => $args, 'user' => $scope->userId];

        return ToolResult::ok('get_period_metrics', $args, ['mean_glucose' => 1.0]);
    });

    registry([$tool])->run(
        new ToolCall('get_period_metrics', ['start' => '2026-07-16', 'end' => '2026-07-29']),
        new ChatScope(42, 90),
    );

    expect($vistos['user'])->toBe(42);
    expect($vistos['args'])->not->toHaveKey('user_id');
});

/*
|--------------------------------------------------------------------------
| T502.5 — validação antes da query
|--------------------------------------------------------------------------
*/

it('recusa argumento inválido antes de executar a ferramenta', function (array $args, string $esperado) {
    $executou = false;

    $tool = fakeTool(
        schema: [
            'start' => ['type' => 'date', 'required' => true],
            'end' => ['type' => 'date', 'required' => true],
            'type' => ['type' => 'enum', 'values' => ['hypo', 'hyper_l2']],
            'min_carbs' => ['type' => 'int', 'min' => 0, 'max' => 300],
        ],
        executa: function (array $args, ChatScope $scope) use (&$executou): ToolResult {
            $executou = true;

            return ToolResult::ok('get_period_metrics', $args, ['mean_glucose' => 1.0]);
        },
    );

    $resultado = registry([$tool])->run(new ToolCall('get_period_metrics', $args), scope());

    expect($resultado->succeeded())->toBeFalse();
    expect($resultado->error)->toContain($esperado);

    // ⚠️ A ferramenta NÃO chegou a rodar — a query nunca foi montada.
    expect($executou)->toBeFalse();
})->with([
    'data fora do formato' => [['start' => '16/07/2026', 'end' => '2026-07-29'], 'YYYY-MM-DD'],
    'data que não existe' => [['start' => '2026-02-30', 'end' => '2026-03-01'], 'calendário'],
    'obrigatório ausente' => [['start' => '2026-07-16'], 'obrigatório'],
    'enum fora da lista' => [['start' => '2026-07-16', 'end' => '2026-07-29', 'type' => 'severa'], 'hypo'],
    'número acima do teto' => [['start' => '2026-07-16', 'end' => '2026-07-29', 'min_carbs' => 900], '<= 300'],
    'número não numérico' => [['start' => '2026-07-16', 'end' => '2026-07-29', 'min_carbs' => 'muito'], 'numérico'],
]);

it('recusa período invertido', function () {
    $resultado = registry([fakeTool()])->run(
        new ToolCall('get_period_metrics', ['start' => '2026-07-29', 'end' => '2026-07-16']),
        scope(),
    );

    expect($resultado->error)->toContain('posterior');
});

/**
 * ⚠️ **O teto de span mora no `ChatScope`, não na ferramenta.** "Como foi meu
 * último ano?" vira varredura de 105 mil leituras — e a resposta certa é "peça
 * um recorte menor", não um timeout.
 */
it('recusa período maior que o teto do escopo', function () {
    $resultado = registry([fakeTool()])->run(
        new ToolCall('get_period_metrics', ['start' => '2025-01-01', 'end' => '2026-01-01']),
        scope(maxSpan: 90),
    );

    expect($resultado->error)->toContain('maior que o máximo');
    expect($resultado->error)->toContain('recorte menor');
});

it('o período é fechado nos dois extremos: 16 a 29 são 14 dias', function () {
    // Com teto de 14, o período de 16 a 29 cabe...
    expect(registry([fakeTool()])->run(
        new ToolCall('get_period_metrics', ['start' => '2026-07-16', 'end' => '2026-07-29']),
        scope(maxSpan: 14),
    )->succeeded())->toBeTrue();

    // ...e um dia a mais não.
    expect(registry([fakeTool()])->run(
        new ToolCall('get_period_metrics', ['start' => '2026-07-16', 'end' => '2026-07-30']),
        scope(maxSpan: 14),
    )->succeeded())->toBeFalse();
});

/**
 * ⚠️ `compare_periods` tem DOIS pares de data. O pareamento por convenção de
 * nome (`a_start`/`a_end`) é o que evita cada ferramenta declarar os próprios —
 * e é a ferramenta de dois pares que esqueceria um.
 */
it('valida os dois pares de data de uma comparação', function () {
    $tool = fakeTool('compare_periods', [
        'a_start' => ['type' => 'date', 'required' => true],
        'a_end' => ['type' => 'date', 'required' => true],
        'b_start' => ['type' => 'date', 'required' => true],
        'b_end' => ['type' => 'date', 'required' => true],
    ]);

    // O primeiro par é válido; o segundo está invertido.
    $resultado = registry([$tool])->run(new ToolCall('compare_periods', [
        'a_start' => '2026-07-16', 'a_end' => '2026-07-22',
        'b_start' => '2026-07-29', 'b_end' => '2026-07-23',
    ]), scope());

    expect($resultado->error)->toContain('b_start');
});

it('argumento opcional ausente não é erro', function () {
    $resultado = registry([fakeTool(schema: [
        'start' => ['type' => 'date', 'required' => true],
        'end' => ['type' => 'date', 'required' => true],
        'min_carbs' => ['type' => 'int', 'required' => false],
    ])])->run(
        new ToolCall('get_period_metrics', ['start' => '2026-07-16', 'end' => '2026-07-29']),
        scope(),
    );

    expect($resultado->succeeded())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| T502.2 e T502.3 — declaração, catálogo e a saída conferida
|--------------------------------------------------------------------------
*/

it('a ferramenta desconhecida vira erro que volta ao modelo, não exceção', function () {
    $resultado = registry([fakeTool()])->run(new ToolCall('get_tudo'), scope());

    expect($resultado->succeeded())->toBeFalse();
    expect($resultado->error)->toContain('desconhecida');
    // ⚠️ E lista as que existem — o modelo corrige sozinho na volta seguinte.
    expect($resultado->error)->toContain('get_period_metrics');
});

/**
 * ⚠️ **§D7 — declaração que ninguém confere é allowlist que não protege.**
 */
it('chave não declarada não vaza: o resultado inteiro vira erro', function () {
    $tool = fakeTool(
        emite: ['mean_glucose'],
        executa: fn (array $args): ToolResult => ToolResult::ok('get_period_metrics', $args, [
            'mean_glucose' => 154.2,
            'patient_name' => 'Feler',      // ⚠️ o que o Artigo VII existe para barrar
        ]),
    );

    $resultado = registry([$tool])->run(
        new ToolCall('get_period_metrics', ['start' => '2026-07-16', 'end' => '2026-07-29']),
        scope(),
    );

    expect($resultado->succeeded())->toBeFalse();
    expect($resultado->error)->toContain('patient_name');
    expect($resultado->data)->toBe([]);
});

it('a conferência de chaves alcança qualquer profundidade', function () {
    $tool = fakeTool(
        emite: ['rows', 'local_date'],
        executa: fn (array $args): ToolResult => ToolResult::ok('get_period_metrics', $args, [
            'rows' => [
                ['local_date' => '2026-07-25', 'device_serial' => 'NG3670115H'],
            ],
        ]),
    );

    $resultado = registry([$tool])->run(
        new ToolCall('get_period_metrics', ['start' => '2026-07-16', 'end' => '2026-07-29']),
        scope(),
    );

    expect($resultado->error)->toContain('device_serial');
});

it('lista de resultados não confunde índice com chave', function () {
    $tool = fakeTool(
        emite: ['rows', 'hour'],
        executa: fn (array $args): ToolResult => ToolResult::ok('get_period_metrics', $args, [
            'rows' => [['hour' => 0], ['hour' => 1], ['hour' => 2]],
        ]),
    );

    expect(registry([$tool])->run(
        new ToolCall('get_period_metrics', ['start' => '2026-07-16', 'end' => '2026-07-29']),
        scope(),
    )->succeeded())->toBeTrue();
});

it('o catálogo expõe nome, descrição e schema de cada ferramenta', function () {
    $catalogo = registry([fakeTool('get_period_metrics'), fakeTool('get_episodes')])->descriptors();

    expect($catalogo)->toHaveCount(2);
    expect($catalogo[0]->name)->toBe('get_period_metrics');
    expect($catalogo[0]->description)->not->toBe('');
});

it('recusa duas ferramentas com o mesmo nome', function () {
    expect(fn () => registry([fakeTool('get_episodes'), fakeTool('get_episodes')]))
        ->toThrow(InvalidArgumentException::class, 'duplicada');
});

/*
|--------------------------------------------------------------------------
| A declaração em si
|--------------------------------------------------------------------------
*/

it('recusa descritor sem descrição — é o texto que o modelo lê', function () {
    expect(fn () => new ToolDescriptor('get_x', '  ', [], ['a']))
        ->toThrow(InvalidArgumentException::class, 'descrição');
});

it('recusa descritor sem chaves emitidas', function () {
    expect(fn () => new ToolDescriptor('get_x', 'desc', [], []))
        ->toThrow(InvalidArgumentException::class, 'allowlist');
});

it('recusa chave emitida fora do padrão snake_case', function (string $chave) {
    expect(fn () => new ToolDescriptor('get_x', 'desc', [], [$chave]))
        ->toThrow(InvalidArgumentException::class);
})->with(['Last Name', 'Patient ID', 'BG Reading (mg/dL)', 'meanGlucose']);

it('o ToolResult sabe listar as chaves que emitiu', function () {
    $resultado = ToolResult::ok('get_x', [], [
        'total' => 3,
        'rows' => [['local_date' => '2026-07-25', 'mean' => 154.2]],
    ]);

    expect($resultado->keys())->toEqualCanonicalizing(['total', 'rows', 'local_date', 'mean']);
});

it('o erro é gravável junto do que foi pedido', function () {
    $gravado = ToolResult::failed('get_x', ['start' => '2026-13-01'], 'data inválida')->toArray();

    expect($gravado['name'])->toBe('get_x');
    expect($gravado['arguments']['start'])->toBe('2026-13-01');
    expect($gravado['error'])->toBe('data inválida');
    expect($gravado)->not->toHaveKey('result');
});

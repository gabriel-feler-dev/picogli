<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\ToolRegistry;
use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolCall;
use App\Domain\Ai\PayloadSanitizer;
use App\Domain\Import\CarelinkCsvReader;
use App\Models\User;

/**
 * T506 — a porta do Artigo VII, estendida ao chat (FR-603, §D7).
 *
 * ⚠️ **Mesma classe, outra lista.** O `PayloadSanitizer` continua sendo a única
 * porta; o que muda é a allowlist, derivada dos `emittedKeys` das dez
 * ferramentas em vez de escrita à mão em `config/`.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    importAndAnalyse($this->user->id);

    $this->scope = new ChatScope($this->user->id, 400);
    $this->registry = app(ToolRegistry::class);
    $this->sanitizer = app('ai.chat.sanitizer');
});

/** Um turno realista: quatro ferramentas, como o §9.3 descreve o fluxo. */
function turnoCompleto(): array
{
    $periodo = ['start' => '2026-07-16', 'end' => '2026-07-29'];

    return array_map(
        fn (ToolCall $call): array => test()->registry->run($call, test()->scope)->toArray(),
        [
            new ToolCall('get_period_metrics', $periodo),
            new ToolCall('get_daily_series', $periodo),
            new ToolCall('get_episodes', array_merge($periodo, ['type' => 'hypo'])),
            new ToolCall('get_findings', $periodo),
            new ToolCall('get_meals', $periodo),
            new ToolCall('get_device_events', $periodo),
        ],
    );
}

/*
|--------------------------------------------------------------------------
| T506.1 — a allowlist derivada
|--------------------------------------------------------------------------
*/

it('a allowlist do chat é a união dos emittedKeys das dez ferramentas', function () {
    $allowlist = $this->registry->allowedKeys();

    expect($allowlist)->not->toBeEmpty();

    foreach ($this->registry->descriptors() as $descritor) {
        // `toContain` de array trata cada argumento como mais um item procurado
        // — a mensagem viraria uma agulha. Daí o `in_array` explícito.
        foreach ($descritor->emittedKeys as $chave) {
            expect(in_array($chave, $allowlist, true))->toBeTrue(
                "{$descritor->name} declara '{$chave}' fora da união"
            );
        }
    }
});

/**
 * ⚠️ Derivada, e não mantida à mão. Uma lista paralela às ferramentas diverge no
 * primeiro dia corrido — e a divergência é silenciosa: a ferramenta emite, a
 * lista não permite, o campo some do payload e ninguém percebe.
 */
it('a allowlist do chat não está escrita em config', function () {
    $config = file_get_contents(base_path('config/ai.php'));

    foreach (['emitted_keys', 'chat_allowlist', 'tool_allowlist'] as $inventada) {
        expect(str_contains($config, $inventada))->toBeFalse(
            "config/ai.php tem '{$inventada}' — a allowlist do chat é derivada das ferramentas"
        );
    }
});

/**
 * ⚠️ **Instância separada, e não uma lista somada.** Uma allowlist maior protege
 * MENOS: a narrativa passaria a poder emitir `peak_2h` sem ninguém revisar.
 */
it('a allowlist do chat e a da narrativa são listas distintas', function () {
    $chat = $this->registry->allowedKeys();
    $narrativa = config('ai.payload_allowlist');

    // Chave que só o chat tem.
    expect($chat)->toContain('peak_2h');
    expect($narrativa)->not->toContain('peak_2h');

    // E o sanitizador da narrativa continua com a lista dele.
    expect(app(PayloadSanitizer::class))->not->toBe($this->sanitizer);
});

/*
|--------------------------------------------------------------------------
| T506.3 — ⚠️ anti-vazamento sobre TODA saída de ferramenta
|--------------------------------------------------------------------------
*/

/**
 * ⭐ **Camada 1: varredura DINÂMICA**, como no T400.4.
 *
 * Lê o cabeçalho do export em tempo de execução e varre o payload do chat por
 * cada valor. Se um export futuro trouxer `Caregiver Email`, ela acusa sozinha —
 * a lista fixa não acusaria, porque ninguém a teria atualizado.
 */
it('nenhum valor do cabeçalho do CSV aparece no payload do chat', function () {
    $header = app(CarelinkCsvReader::class)->readHeader(requireReferenceExport());

    $json = $this->sanitizer->sanitizeChat(turnoCompleto())->toJson();
    $verificados = 0;

    foreach ($header->patient as $campo => $valor) {
        $valor = trim((string) $valor);

        if (mb_strlen($valor) < 3) {
            continue;
        }

        $verificados++;

        expect(str_contains($json, $valor))->toBeFalse(
            "o payload do chat contém '{$campo}' do cabeçalho: {$valor}"
        );
    }

    // Sem isto, um cabeçalho vazio faria o teste varrer o vácuo.
    expect($verificados)->toBeGreaterThan(0);
});

/** Camada 2: literais — menos poderosa, mas documenta o que se protege. */
it('o payload do chat não contém o sobrenome nem o número de série', function () {
    $json = $this->sanitizer->sanitizeChat(turnoCompleto())->toJson();

    foreach (['Feler', 'NG3670115H'] as $pii) {
        expect(str_contains($json, $pii))->toBeFalse("o payload do chat contém '{$pii}'");
    }
});

/**
 * ⚠️ **A varredura vale para CADA ferramenta isolada**, não só para o turno
 * montado. Uma delas poderia vazar num caminho que o turno de exemplo não
 * exercita — e o teste de conjunto passaria.
 */
it('nenhuma das dez ferramentas emite valor do cabeçalho', function () {
    $header = app(CarelinkCsvReader::class)->readHeader(requireReferenceExport());
    $periodo = ['start' => '2026-07-16', 'end' => '2026-07-29'];

    $chamadas = [
        new ToolCall('get_period_metrics', $periodo),
        new ToolCall('get_hourly_profile', $periodo),
        new ToolCall('get_daily_series', $periodo),
        new ToolCall('get_insulin_summary', $periodo),
        new ToolCall('get_episodes', array_merge($periodo, ['type' => 'hypo'])),
        new ToolCall('get_sensor_gaps', $periodo),
        new ToolCall('get_device_events', $periodo),
        new ToolCall('get_meals', $periodo),
        new ToolCall('get_findings', $periodo),
        new ToolCall('compare_periods', [
            'a_start' => '2026-07-16', 'a_end' => '2026-07-22',
            'b_start' => '2026-07-23', 'b_end' => '2026-07-29',
        ]),
    ];

    foreach ($chamadas as $call) {
        $json = json_encode(
            $this->registry->run($call, $this->scope)->toArray(),
            JSON_THROW_ON_ERROR
        );

        foreach ($header->patient as $campo => $valor) {
            $valor = trim((string) $valor);

            if (mb_strlen($valor) < 3) {
                continue;
            }

            expect(str_contains($json, $valor))->toBeFalse(
                "{$call->name} emite '{$campo}': {$valor}"
            );
        }
    }
});

/*
|--------------------------------------------------------------------------
| O filtro em si
|--------------------------------------------------------------------------
*/

it('descarta chave não prevista em qualquer profundidade, e registra o descarte', function () {
    $payload = $this->sanitizer->sanitizeChat([[
        'name' => 'get_daily_series',
        'arguments' => ['start' => '2026-07-16', 'end' => '2026-07-29'],
        'result' => [
            'day_count' => 14,
            'rows' => [
                ['local_date' => '2026-07-25', 'device_serial' => 'NG3670115H'],
            ],
        ],
    ]]);

    $json = $payload->toJson();

    expect(str_contains($json, 'NG3670115H'))->toBeFalse();
    expect($payload->droppedKeys)->toContain('device_serial');

    // E o que era legítimo continua lá.
    expect($json)->toContain('2026-07-25');
    expect($json)->toContain('day_count');
});

/**
 * ⚠️ **Índice de lista não é chave.** É a outra metade da regra que a agregação
 * de eventos ensinou: dado nunca é chave, e posição também não. Sem isso, o
 * filtro descartaria toda linha de toda ferramenta.
 */
it('preserva as linhas de uma lista sem tratá-las como chave desconhecida', function () {
    $payload = $this->sanitizer->sanitizeChat([[
        'name' => 'get_hourly_profile',
        'arguments' => [],
        'result' => ['rows' => [
            ['hour' => 0, 'mean_glucose' => 132.4],
            ['hour' => 1, 'mean_glucose' => 128.1],
            ['hour' => 2, 'mean_glucose' => 121.7],
        ]],
    ]]);

    expect($payload->toolResults[0]['result']['rows'])->toHaveCount(3);
    expect($payload->droppedKeys)->toBe([]);
});

/**
 * O envelope da chamada é preservado: `arguments` veio do próprio modelo e
 * `error` é texto nosso. O que precisa de allowlist é `result`, o único bloco
 * que carrega dado do banco.
 */
it('mantém nome, argumentos e erro da chamada', function () {
    $payload = $this->sanitizer->sanitizeChat([[
        'name' => 'get_episodes',
        'arguments' => ['start' => '2026-07-29', 'end' => '2026-07-16'],
        'error' => "período inválido: 'start' é posterior a 'end'",
    ]]);

    expect($payload->toolResults[0]['name'])->toBe('get_episodes');
    expect($payload->toolResults[0]['error'])->toContain('posterior');
});

it('o contexto pré-carregado passa pela mesma allowlist', function () {
    $payload = $this->sanitizer->sanitizeChat([], [
        'mean_glucose' => 142.0,
        'patient_name' => 'Feler',
    ]);

    expect($payload->context)->toHaveKey('mean_glucose');
    expect($payload->context)->not->toHaveKey('patient_name');
    expect($payload->droppedKeys)->toContain('patient_name');
});

/**
 * ⚠️ **T506.4 — o Artigo VII continua com uma resposta só.** A varredura da fase
 * 5 já cobre `app/`; este teste registra que a camada nova não abriu um segundo
 * caminho de saída.
 */
it('nenhuma classe do chat monta payload para provedor por conta própria', function () {
    $arquivos = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Domain/Ai/Chat'))
    );

    foreach ($arquivos as $arquivo) {
        if (! $arquivo->isFile() || $arquivo->getExtension() !== 'php') {
            continue;
        }

        $codigo = (string) file_get_contents($arquivo->getPathname());
        $codigo = (string) preg_replace('#/\*.*?\*/#s', '', $codigo);
        $codigo = (string) preg_replace('#//.*$#m', '', $codigo);

        foreach (['generativelanguage', 'googleapis', 'Http::', 'curl_'] as $marca) {
            expect(str_contains($codigo, $marca))->toBeFalse(
                "{$arquivo->getFilename()} fala com a rede — só GeminiProvider pode"
            );
        }
    }
});

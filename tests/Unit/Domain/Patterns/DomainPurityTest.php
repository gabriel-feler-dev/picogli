<?php

declare(strict_types=1);

/**
 * T302.5 — NFR-401. `app/Domain/Patterns/` é PHP puro.
 *
 * ⚠️ Este teste é o que sustenta a fase inteira. Sem ele, "as regras não tocam o
 * banco" seria disciplina, e disciplina não sobrevive à décima regra às onze da
 * noite.
 *
 * Quatro proibições, cada uma com um motivo diferente:
 *
 * | Proibido | Por quê |
 * |---|---|
 * | Eloquent / model | dez regras consultando a mesma série = N+1 invisível; e o teste da regra passaria a exigir fixture |
 * | facade / helper | tira a classe do PHP puro e obriga o container no teste de unidade |
 * | `config()` | limiar tem de ser injetado (§D4), senão o teste não sabe o que está testando |
 * | `now()` / `time()` | regra que consulta o relógio não é determinística — e determinismo é o Artigo II |
 *
 * `Persistence/` é isento: é a borda, e existe justamente para concentrar o
 * acesso ao banco num lugar auditável.
 */
function patternsDomainFiles(): array
{
    $root = dirname(__DIR__, 4).'/app/Domain/Patterns';
    $files = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        // A borda tem licença para tocar banco — é o que ela é.
        if (str_contains($path, '/Patterns/Persistence/')) {
            continue;
        }

        $files[$path] = file_get_contents($file->getPathname());
    }

    return $files;
}

/** @return list<string> nomes das proibições encontradas no código */
function impurities(string $source): array
{
    // Comentário e docblock são removidos ANTES da varredura: um teste que
    // acusasse a própria documentação da regra seria inútil — foi o erro que a
    // fase 3 cometeu no detector de vocabulário proibido.
    $code = preg_replace('#/\*.*?\*/#s', '', $source);
    $code = preg_replace('#//.*$#m', '', (string) $code);
    $code = (string) $code;

    $forbidden = [
        'model Eloquent' => '/\bApp\\\\Models\\\\/',
        'Eloquent' => '/\bIlluminate\\\\Database\\\\/',
        'facade DB' => '/\bDB::/',
        'facade Cache/Log/Auth' => '/\b(Cache|Log|Auth|Session|Request|Response|Schema)::/',
        'config()' => '/(?<![>$\w])config\s*\(/',
        'now()' => '/(?<![>$\w])now\s*\(/',
        'time()' => '/(?<![>$\w])(time|microtime|date)\s*\(/',
        'app()' => '/(?<![>$\w])app\s*\(/',
        'trans()/__()' => '/(?<![>$\w])(trans|__)\s*\(/',
    ];

    $found = [];

    foreach ($forbidden as $name => $pattern) {
        if (preg_match($pattern, $code) === 1) {
            $found[] = $name;
        }
    }

    return $found;
}

it('nenhuma classe de Patterns/ usa Eloquent, facade, config(), now() ou tradução', function () {
    $violations = [];

    foreach (patternsDomainFiles() as $path => $source) {
        $found = impurities($source);

        if ($found !== []) {
            $violations[] = basename($path).': '.implode(', ', $found);
        }
    }

    expect($violations)->toBe([]);
});

it('encontrou arquivos para varrer', function () {
    // Sem isto, um caminho errado faria o teste acima passar varrendo o vácuo —
    // a falha mais silenciosa que um guarda pode ter.
    expect(count(patternsDomainFiles()))->toBeGreaterThanOrEqual(8);
});

/**
 * ⚠️ **Autoteste da detecção.** Um guarda que nunca acusaria nada é pior que
 * nenhum guarda, porque dá a sensação de proteção. Cada linha abaixo é uma
 * violação real que o detector precisa pegar.
 */
it('a detecção realmente pega cada tipo de violação', function (string $codigo, string $esperado) {
    expect(impurities("<?php\n".$codigo))->toContain($esperado);
})->with([
    ['use App\Models\SensorReading;', 'model Eloquent'],
    ['use Illuminate\Database\Eloquent\Model;', 'Eloquent'],
    ['DB::table("x")->get();', 'facade DB'],
    ['Log::info("x");', 'facade Cache/Log/Auth'],
    ['$limiar = config("patterns.rules.r1");', 'config()'],
    ['$hoje = now();', 'now()'],
    ['$agora = time();', 'time()'],
    ['$service = app(Foo::class);', 'app()'],
    ['$texto = __("patterns.r1.prose");', 'trans()/__()'],
]);

/**
 * O outro lado do autoteste: o detector não pode acusar código legítimo. Um
 * guarda hipersensível é desligado na primeira semana, e aí não protege nada.
 */
it('a detecção não acusa código legítimo', function (string $codigo) {
    expect(impurities("<?php\n".$codigo))->toBe([]);
})->with([
    ['use App\Domain\Metrics\Value\GlucoseSeries;'],
    ['$this->config->ranges["target"]["min"];'],
    ['// config() aqui seria violação do §D2'],
    ['/** Não use now() nesta classe. */'],
    ['$reading->at->format("Y-m-d");'],
    ['$dataset->daypart(Daypart::Afternoon);'],
    ['$this->configKey();'],
    ['$x = $foo->time();'],
]);

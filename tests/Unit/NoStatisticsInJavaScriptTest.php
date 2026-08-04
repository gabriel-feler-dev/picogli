<?php

declare(strict_types=1);

/**
 * T205.8 — NFR-201, verificado por busca
 *
 * O React recebe números prontos. Não calcula média, percentual, percentil, nem
 * classifica faixa.
 *
 * ⚠️ **Por que isto é teste e não convenção:** duas implementações da mesma
 * estatística divergem. Sempre. Se o dashboard calculasse TIR em JS e a fase 5
 * narrasse o TIR calculado em PHP, os dois números apareceriam na mesma tela
 * com valores diferentes — e o usuário não teria como saber qual acreditar.
 */
uses(Tests\TestCase::class);

/** @return list<array{file: string, line: int, text: string}> */
function javaScriptLines(): array
{
    $base = base_path('resources/js');
    $lines = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

        foreach (file($file->getPathname()) as $index => $text) {
            $lines[] = ['file' => $relative, 'line' => $index + 1, 'text' => $text];
        }
    }

    return $lines;
}

/**
 * Reconhece linha de comentário, para não acusar o texto que EXPLICA a regra.
 *
 * ⚠️ Inclui o comentário JSX (`{/* … *\/}`), que a primeira versão esquecia — e
 * por isso o teste acusava o próprio comentário do AgpChart que documenta por
 * que `connectNulls` fica desligado.
 */
function isComment(string $text): bool
{
    $trimmed = ltrim($text);

    foreach (['//', '*', '/*', '{/*'] as $marker) {
        if (str_starts_with($trimmed, $marker)) {
            return true;
        }
    }

    return false;
}

it('há arquivos de JavaScript para varrer', function () {
    // Sem esta guarda o teste passaria vazio se a pasta mudasse de lugar,
    // dando conformidade fictícia.
    expect(javaScriptLines())->not->toBeEmpty();
});

// ⚠️ O limiar clínico existe num ÚNICO lugar: config/clinical.php. Ele chega ao
// componente via `ranges` no payload. Um `> 180` em JS seria uma segunda fonte
// de verdade para a mesma decisão.
it('nenhum componente compara glicose com limiar clínico', function () {
    $violations = [];

    foreach (javaScriptLines() as $entry) {
        if (isComment($entry['text'])) {
            continue;
        }

        if (preg_match('/[<>]=?\s*\b(54|70|180|250)\b/', $entry['text']) === 1) {
            $violations[] = "{$entry['file']}:{$entry['line']} → ".trim($entry['text']);
        }
    }

    expect($violations)->toBe(
        [],
        "Limiar clínico comparado em JS:\n".implode("\n", $violations)
        ."\n\nOs limites vêm do servidor em `ranges`. Ver NFR-201."
    );
});

it('nenhum componente agrega série com reduce', function () {
    $violations = [];

    foreach (javaScriptLines() as $entry) {
        if (isComment($entry['text'])) {
            continue;
        }

        if (str_contains($entry['text'], '.reduce(')) {
            $violations[] = "{$entry['file']}:{$entry['line']} → ".trim($entry['text']);
        }
    }

    expect($violations)->toBe(
        [],
        "Agregação em JS:\n".implode("\n", $violations)
        ."\n\nMédia, soma e contagem vêm calculadas do servidor."
    );
});

it('a busca pega uma violação de fato — o teste não é decorativo', function () {
    // Prova que a detecção funciona. Um teste que nunca acusaria nada seria
    // pior que nenhum, porque daria confiança falsa.
    $samples = [
        'const isHigh = reading.glucose > 180;',
        'const mean = values.reduce((a, b) => a + b, 0) / values.length;',
    ];

    expect(preg_match('/[<>]=?\s*\b(54|70|180|250)\b/', $samples[0]))->toBe(1);
    expect(str_contains($samples[1], '.reduce('))->toBeTrue();
});

it('o gráfico AGP desenha a banda-alvo a partir do payload, não de constante', function () {
    $agp = file_get_contents(base_path('resources/js/Components/AgpChart.tsx'));

    // A banda-alvo vem de `ranges.target`, que o servidor manda.
    expect($agp)->toContain('ranges.target');
    expect($agp)->toContain('y1={target.min');
    expect($agp)->toContain('y2={target.max');
});

it('a lacuna do sensor NÃO é ligada por interpolação (D4)', function () {
    // ⚠️ Precisa ignorar comentário: o próprio docblock do AgpChart EXPLICA a
    // regra citando `connectNulls`, e a primeira versão deste teste acusava o
    // texto que documenta a regra em vez de uma violação dela.
    $code = collect(javaScriptLines())
        ->reject(fn (array $entry): bool => isComment($entry['text']))
        ->filter(fn (array $entry): bool => str_contains($entry['file'], 'AgpChart'))
        ->pluck('text')
        ->implode('');

    // `connectNulls` do Recharts é falso por padrão. Se alguém ativar, a linha
    // atravessa a lacuna e o gráfico afirma medição que não houve.
    expect($code)->not->toContain('connectNulls');
});

it('o eixo Y do AGP começa em zero (D4)', function () {
    $agp = file_get_contents(base_path('resources/js/Components/AgpChart.tsx'));

    // Eixo truncado exagera a variação e assusta sem motivo.
    expect($agp)->toContain("domain={[0,");
});

it('a faixa de cada hora vem classificada do servidor', function () {
    $bar = file_get_contents(base_path('resources/js/Components/HourlyBar.tsx'));

    expect($bar)->toContain('dominant_range');
    // Se classificasse aqui, o dashboard poderia discordar da fase 4 sobre o
    // que é "hora alta".
    expect($bar)->not->toContain('percent_above >');
});

it('cor nunca é o único sinal (NFR-203)', function () {
    foreach (['HourlyBar', 'DayGrid', 'MetricCard'] as $component) {
        $source = file_get_contents(base_path("resources/js/Components/{$component}.tsx"));

        // Daltonismo para vermelho/verde é comum, e vermelho/verde é exatamente
        // a codificação natural para glicemia.
        //
        // `toContain` não aceita mensagem no segundo parâmetro — ele espera
        // outra agulha. Daí o `str_contains` explícito, que permite dizer QUAL
        // componente falhou.
        expect(str_contains($source, 'aria-label'))
            ->toBeTrue("{$component} não tem aria-label");
    }
});

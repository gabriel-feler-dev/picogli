<?php

declare(strict_types=1);
use Tests\TestCase;

/**
 * T203.5 — Artigo IV, imposto por teste (spec.md §D2)
 *
 * O Artigo IV diz que tom não acusatório é **requisito técnico**, não cortesia.
 * Sem este teste, "requisito técnico" seria uma frase bonita no documento — e a
 * primeira string escrita às pressas o violaria.
 *
 * ⚠️ A varredura percorre o DIRETÓRIO `lang/`, não uma lista de arquivos. Um
 * arquivo de idioma novo entra na proteção automaticamente. É a diferença entre
 * uma salvaguarda que envelhece e uma que continua valendo.
 */

// `__()` e `base_path()` precisam do container. O binding de `Feature` daria
// isso, mas este teste não é de feature — ele varre arquivos. Declarar aqui o
// que precisa é mais honesto que movê-lo de pasta.
uses(TestCase::class);

/**
 * Vocabulário que julga a pessoa em vez de descrever o mecanismo.
 *
 * Não é lista de palavrão: são as construções que transformam um dado em
 * acusação. "Você comeu 109 g de carboidrato" e "quedas de glicose disparam
 * fome intensa — é reação do corpo" dizem o MESMO número.
 */
function forbiddenVocabulary(): array
{
    // ⚠️ Fonte ÚNICA desde a fase 5 (`config/tone.php`). A lista tem três
    // consumidores agora: esta varredura, a varredura anti-conduta de R6, e o
    // PROMPT de narrativa — que precisa citar as palavras para instruir o
    // modelo a não usá-las. Duplicada, uma palavra acrescentada aqui não
    // chegaria ao modelo, e ele continuaria autorizado a usá-la.
    return config('tone.forbidden_vocabulary');
}

/**
 * Os arquivos de prompt, em texto cru.
 *
 * ⚠️ O prompt é o texto que PRODUZ o texto. Se ele disser "explique o que a
 * pessoa fez de errado", nenhuma varredura sobre a saída conserta isso com
 * confiança — e a violação sairia com a fluência de um modelo de linguagem.
 *
 * @return list<array{file: string, text: string}>
 */
function allPromptFiles(): array
{
    $path = resource_path('prompts');

    if (! is_dir($path)) {
        return [];
    }

    $prompts = [];

    foreach (new DirectoryIterator($path) as $file) {
        if ($file->isDot() || ! $file->isFile()) {
            continue;
        }

        $prompts[] = [
            'file' => 'resources/prompts/'.$file->getFilename(),
            'text' => (string) file_get_contents($file->getPathname()),
        ];
    }

    return $prompts;
}

/** Todas as strings de todos os arquivos de idioma, achatadas. */
function allLanguageStrings(): array
{
    $langPath = base_path('lang');

    if (! is_dir($langPath)) {
        return [];
    }

    $strings = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($langPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $flatten = function (array $items, string $prefix) use (&$flatten, &$strings, $file): void {
            foreach ($items as $key => $value) {
                $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

                if (is_array($value)) {
                    $flatten($value, $path);

                    continue;
                }

                if (is_string($value)) {
                    $strings[] = [
                        'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                        'key' => $path,
                        'text' => $value,
                    ];
                }
            }
        };

        $contents = require $file->getPathname();

        if (is_array($contents)) {
            $flatten($contents, '');
        }
    }

    return $strings;
}

it('existe pelo menos um arquivo de idioma para varrer', function () {
    // Sem esta guarda, o teste passaria vazio se `lang/` desaparecesse — dando
    // falsa sensação de conformidade.
    expect(allLanguageStrings())->not->toBeEmpty();
});

it('nenhum texto voltado ao usuário usa vocabulário que julga a pessoa', function () {
    $violations = [];

    foreach (allLanguageStrings() as $entry) {
        $haystack = mb_strtolower($entry['text']);

        foreach (forbiddenVocabulary() as $forbidden) {
            if (str_contains($haystack, mb_strtolower($forbidden))) {
                $violations[] = sprintf(
                    '%s → %s: "%s" contém "%s"',
                    $entry['file'],
                    $entry['key'],
                    $entry['text'],
                    $forbidden,
                );
            }
        }
    }

    expect($violations)->toBe(
        [],
        "Artigo IV violado:\n".implode("\n", $violations)
        ."\n\nO texto deve descrever MECANISMO e CONSEQUÊNCIA, nunca caráter."
    );
});

it('existe pelo menos um prompt para varrer', function () {
    // Mesma guarda do teste de idioma: sem ela, um diretório que desaparecesse
    // daria falsa sensação de conformidade.
    expect(allPromptFiles())->not->toBeEmpty();
});

/**
 * ⚠️⚠️ **T404.2 — o prompt entra na MESMA varredura desde a fase 5.**
 *
 * O prompt é o texto que produz o texto. Varrer só a saída deixaria a causa
 * livre — e cada geração seria diferente, então o defeito apareceria de forma
 * intermitente.
 *
 * As palavras proibidas NÃO estão escritas no arquivo: elas são interpoladas de
 * `config/tone.php` na renderização. É o que permite esta varredura existir sem
 * acusar o próprio prompt — o erro que a fase 3 cometeu ao acusar a documentação
 * da regra que ela explicava.
 */
it('nenhum prompt usa vocabulário que julga a pessoa', function () {
    $violations = [];

    foreach (allPromptFiles() as $prompt) {
        $haystack = mb_strtolower($prompt['text']);

        foreach (forbiddenVocabulary() as $forbidden) {
            if (str_contains($haystack, mb_strtolower($forbidden))) {
                $violations[] = sprintf('%s contém "%s"', $prompt['file'], $forbidden);
            }
        }
    }

    expect($violations)->toBe(
        [],
        "Artigo IV violado no prompt:\n".implode("\n", $violations)
        ."\n\nO prompt é o texto que produz o texto — varrer só a saída não basta."
    );
});

/**
 * ⚠️ **T404.3 — a varredura anti-conduta (criada para R6 na fase 4) cobre o
 * prompt.** A fronteira do Artigo VI não é sobre palavras isoladas, é sobre modo
 * verbal: descrever no indicativo é permitido, prescrever no imperativo não.
 */
it('nenhum prompt escreve conduta médica em modo imperativo', function () {
    $violations = [];

    foreach (allPromptFiles() as $prompt) {
        $haystack = mb_strtolower($prompt['text']);

        foreach (config('tone.forbidden_conduct') as $conduct) {
            if (str_contains($haystack, mb_strtolower($conduct))) {
                $violations[] = sprintf('%s contém "%s"', $prompt['file'], $conduct);
            }
        }
    }

    expect($violations)->toBe([], "Artigo VI violado no prompt:\n".implode("\n", $violations));
});

it('a varredura pega uma violação de fato — o teste não é decorativo', function () {
    // Prova que a detecção funciona. Um teste de vocabulário que nunca acusaria
    // nada seria pior que nenhum, porque daria confiança falsa.
    $sample = 'Você deveria ter mais cuidado com a alimentação.';
    $caught = false;

    foreach (forbiddenVocabulary() as $forbidden) {
        if (str_contains(mb_strtolower($sample), mb_strtolower($forbidden))) {
            $caught = true;
            break;
        }
    }

    expect($caught)->toBeTrue();
});

it('os textos das métricas estão em português e não vazios', function () {
    $keys = [
        'metrics.time_in_range.label',
        'metrics.time_in_range.explanation',
        'metrics.variability.explanation',
        'metrics.gmi.explanation',
        'metrics.time_below_range.explanation_none_severe',
    ];

    foreach ($keys as $key) {
        // `__()` devolve a própria chave quando a tradução falta — é como uma
        // chave ausente se disfarça de texto.
        expect(__($key))->not->toBe($key, "tradução ausente: {$key}");
        expect(__($key))->not->toBeEmpty();
    }
});

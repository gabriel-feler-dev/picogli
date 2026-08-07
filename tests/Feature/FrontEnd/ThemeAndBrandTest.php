<?php

declare(strict_types=1);

/**
 * T700 — tokens, tema e a rede (Spec 008 §D2, §D5, §D11).
 *
 * ⚠️⚠️ **A guarda antes da porta, pela quarta vez no projeto** (T400 sanitizer,
 * T500 classificador de emergência, T603 proibição de OCR, agora). Estas
 * varreduras existem ANTES de qualquer tela ser redesenhada — escritas depois,
 * deixariam uma janela em que "só um botão menta para ver como fica".
 *
 * ⚠️ Sem `uses(TestCase::class)`: o `tests/Pest.php` já o aplica a `Feature/`,
 * e repetir aqui derruba o Pest com "already uses the test case". O
 * `ForbiddenVocabularyTest` precisa declarar porque vive em `Unit/`.
 */

/**
 * Arquivos de front, sem comentário.
 *
 * ⚠️⚠️ **REMOVER COMENTÁRIO É OBRIGATÓRIO, e este projeto já tropeçou QUATRO
 * vezes nisso** (fase 3, T501, T512, T603). Os `.tsx` daqui são densos em
 * comentário explicando exatamente as regras que estas varreduras impõem — sem
 * remover, o teste nasce vermelho acusando a si mesmo.
 *
 * @return list<array{file: string, code: string}>
 */
function frontEndSources(string $extension = 'tsx'): array
{
    $root = resource_path('js');

    if (! is_dir($root)) {
        return [];
    }

    $sources = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== $extension) {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());

        // Blocos /* ... */ — cobre também os comentários JSX {/* ... */}.
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);

        // Linhas que são só comentário. Deliberadamente NÃO removemos `//` no
        // meio da linha: isso apagaria o miolo de qualquer URL `https://`.
        $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

        $sources[] = [
            'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
            'code' => $code,
        ];
    }

    return $sources;
}

it('há arquivos de front para varrer', function () {
    // ⚠️ Mesma guarda do T603: sem contar os arquivos, um diretório renomeado
    // faria as varreduras abaixo passarem varrendo o vácuo.
    expect(count(frontEndSources()))->toBeGreaterThan(5);
});

/*
|--------------------------------------------------------------------------
| §D5 — a troca de tema precisa do mecanismo, não só do botão
|--------------------------------------------------------------------------
*/

it('o dark: do Tailwind está ligado ao atributo, não à media query', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // ⚠️ Sem esta linha o Tailwind 4 resolve `dark:` por `prefers-color-scheme`
    // e a escolha da pessoa não tem efeito nenhum — o botão parece quebrado.
    // Foi o estado real do produto até 07/08/2026: o blade cravava uma classe
    // que o CSS ignorava.
    expect($css)->toContain('@custom-variant dark');
    expect($css)->toContain("data-theme='dark'");
});

it('o tema é decidido antes da primeira pintura, e antes do Vite', function () {
    $blade = (string) file_get_contents(resource_path('views/app.blade.php'));

    expect($blade)->toContain('dataset.theme');

    $script = mb_strpos($blade, 'dataset.theme');
    $vite = mb_strpos($blade, '@vite');

    expect($script)->toBeLessThan(
        $vite,
        'O script de tema tem de vir ANTES do @vite: depois dele, a tela pisca '
        .'branco antes de escurecer.'
    );
});

it('a preferência guardada é a escolha, não o resultado dela', function () {
    $blade = (string) file_get_contents(resource_path('views/app.blade.php'));

    // "Seguir o sistema" tem de continuar seguindo. Gravar 'dark' porque o
    // sistema estava escuro congelaria a escolha da pessoa.
    expect($blade)->toContain("'system'");
    expect($blade)->toContain('prefers-color-scheme: dark');
});

/*
|--------------------------------------------------------------------------
| §D2 — a menta fica na marca; o roxo é a interação
|--------------------------------------------------------------------------
*/

it('a menta da marca não é usada como cor de interface', function () {
    $violations = [];
    $logo = 'Components'.DIRECTORY_SEPARATOR.'Brand.tsx';
    $vistoNoLogo = false;

    foreach (frontEndSources() as $source) {
        // ⚠️ O logo é a exceção DECLARADA do §D2 — ali a menta é assinatura,
        // não estado. Em qualquer outro arquivo ela vira vocabulário clínico.
        if (str_contains($source['file'], $logo)) {
            $vistoNoLogo = str_contains(mb_strtolower($source['code']), '5dcaa5');

            continue;
        }

        $haystack = mb_strtolower($source['code']);

        foreach (['5dcaa5', 'brand-mint'] as $needle) {
            if (str_contains($haystack, $needle)) {
                $violations[] = sprintf('%s usa "%s"', $source['file'], $needle);
            }
        }
    }

    expect($violations)->toBe(
        [],
        "§D2 violado:\n".implode("\n", $violations)
        ."\n\n`rangePalette.target` é #10b981 e significa \"na faixa\" — significado "
        ."CLÍNICO. A menta #5DCAA5 é vizinha dela. Menta em botão ensina dois "
        ."significados para o mesmo verde, e um deles decide se a pessoa acha que "
        .'está bem. A cor de interação é o roxo #26215C.'
    );

    // ⚠️ O outro lado: se o logo perdesse a menta, esta varredura passaria a
    // proibir uma cor que não existe mais em lugar nenhum — verde por vácuo.
    expect($vistoNoLogo)->toBeTrue('A menta sumiu do próprio logo.');
});

it('a paleta clínica não foi tocada por esta fase', function () {
    $palette = (string) file_get_contents(resource_path('js/Components/rangePalette.ts'));

    // ⚠️ A marca se afasta do vocabulário clínico, não o contrário. Se algum dia
    // for mais fácil mudar o verde da faixa do que o da marca, o §D2 foi lido
    // ao contrário.
    expect($palette)->toContain('#10b981');
    expect($palette)->toContain('very_low');
    expect($palette)->toContain('label');
});

/*
|--------------------------------------------------------------------------
| §D11 — a varredura do Artigo IV passa a cobrir resources/js
|--------------------------------------------------------------------------
*/

it('nenhum texto do front usa vocabulário que julga a pessoa', function () {
    $violations = [];

    foreach (frontEndSources() as $source) {
        $haystack = mb_strtolower($source['code']);

        foreach (config('tone.forbidden_vocabulary') as $forbidden) {
            if (str_contains($haystack, mb_strtolower($forbidden))) {
                $violations[] = sprintf('%s contém "%s"', $source['file'], $forbidden);
            }
        }
    }

    expect($violations)->toBe(
        [],
        "Artigo IV violado no front:\n".implode("\n", $violations)
        ."\n\nAté 07/08/2026 esta varredura não existia: ela cobria lang/ e "
        .'resources/prompts/, e todo texto escrito no componente escapava (§D11).'
    );
});

it('a varredura do front pega uma violação de fato', function () {
    // ⚠️ Autoteste dos DOIS lados. Uma varredura que nunca acusaria nada é pior
    // que nenhuma, porque dá confiança falsa — foi assim que a fase 5 encontrou
    // o defeito do NumberGuard e a fase 6 o do classificador de emergência.
    $amostra = '<p>Você deveria ter mais cuidado.</p>';
    $pegou = false;

    foreach (config('tone.forbidden_vocabulary') as $forbidden) {
        if (str_contains(mb_strtolower($amostra), mb_strtolower($forbidden))) {
            $pegou = true;
            break;
        }
    }

    expect($pegou)->toBeTrue();
});

it('a remoção de comentário não engole código de verdade', function () {
    // O outro lado do autoteste: se a limpeza fosse agressiva demais, a
    // varredura passaria por não ter mais o que ler.
    $sources = frontEndSources();

    $comCodigo = array_filter(
        $sources,
        fn (array $source): bool => str_contains($source['code'], 'export')
    );

    expect(count($comCodigo))->toBeGreaterThan(5);

    // E uma URL com `//` sobrevive: a limpeza só remove linha que É comentário.
    $limpo = (string) preg_replace('#^\s*//.*$#m', '', "const a = 'https://exemplo';");
    expect($limpo)->toContain('https://exemplo');
});

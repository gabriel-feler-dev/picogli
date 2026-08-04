<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Bindings de caso de teste
|--------------------------------------------------------------------------
|
| `Feature` recebe o TestCase do Laravel + RefreshDatabase: são os testes que
| tocam banco, fila e HTTP.
|
| `Unit` fica DELIBERADAMENTE sem binding, usando o TestCase do PHPUnit puro.
| Os testes de `app/Domain/` não sobem o framework — rodam em milissegundos.
|
| Essa separação é o que torna a convenção do AGENTS.md ("app/Domain é PHP
| puro, sem Eloquent nem facades") verificável em vez de aspiracional: se
| alguém usar um helper do Laravel numa classe de domínio, o teste unitário
| quebra na hora, porque não existe container ali.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Fixture do export de referência
|--------------------------------------------------------------------------
|
| O CSV real fica em storage/carelink/ e NÃO é versionado (contém nome,
| número de série da bomba e histórico de glicemia).
|
| Consequência: quem clonar o projeto não tem o arquivo. Em vez de deixar a
| suíte falhar com "file not found", os testes que dependem dele fazem skip
| com mensagem explícita apontando para a spec.
|
| Ver plan.md §Configuração de ambiente.
|
*/

function referenceExportPath(): string
{
    return dirname(__DIR__).'/storage/carelink/reference-export.csv';
}

function requireReferenceExport(): string
{
    $path = referenceExportPath();

    if (! is_file($path)) {
        test()->markTestSkipped(
            'Export de referência ausente. Copie um CSV do CareLink para '
            .'storage/carelink/reference-export.csv (ver plan.md). '
            .'Valores esperados em specs/001-fundacao-de-dados/gabarito.md.'
        );
    }

    return $path;
}

/*
|--------------------------------------------------------------------------
| Expectativas customizadas
|--------------------------------------------------------------------------
*/

/**
 * Compara float com tolerância explícita.
 *
 * Usada nos asserts do gabarito: somas de insulina em ponto flutuante não
 * batem exatamente. `toBe(295.15)` falharia por 1e-13 e mandaria alguém
 * caçar um bug que não existe.
 */
expect()->extend('toBeCloseToValue', function (float $expected, float $tolerance = 0.005) {
    $actual = (float) $this->value;

    expect(abs($actual - $expected))->toBeLessThanOrEqual(
        $tolerance,
        sprintf('Esperado %.5f (±%.5f), obtido %.5f', $expected, $tolerance, $actual)
    );

    return $this;
});

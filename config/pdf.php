<?php

declare(strict_types=1);

/**
 * Extração de agregados de PDF (Spec 007, item 3).
 *
 * ⚠️⚠️ **ESTES PADRÕES NÃO FORAM VERIFICADOS CONTRA UM PDF REAL DO CARELINK.**
 *
 * Não havia PDF de amostra no projeto quando este arquivo foi escrito (07/08/2026)
 * — `storage/carelink/` tem só o CSV de referência. O que está testado é a
 * **mecânica** da extração (achar texto num PDF, casar rótulo com número,
 * recusar valor implausível); o que **não** está é se estes rótulos são os que a
 * Medtronic imprime.
 *
 * O Artigo XI manda testar contra realidade, e aqui não há gabarito. A
 * consequência prática: **trate o item 3 como não verificado até rodar contra um
 * PDF de verdade.** Um teste com fixture opcional já existe e faz `skip` quando o
 * arquivo não está lá — coloque um PDF em
 * `storage/carelink/reference-report.pdf` e ele passa a valer.
 *
 * ⚠️ Ajustar padrão aqui é editar config, não código — de propósito. Quando o PDF
 * real aparecer, a correção é neste arquivo.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Onde o PDF de referência é procurado, em teste
    |--------------------------------------------------------------------------
    */

    'reference_report' => 'carelink/reference-report.pdf',

    /*
    |--------------------------------------------------------------------------
    | Rótulo → métrica
    |--------------------------------------------------------------------------
    |
    | Cada entrada é uma lista de expressões que podem preceder o número, na
    | ordem em que são tentadas. O primeiro acerto ganha.
    |
    | ⚠️ **Português E inglês:** o CareLink exporta no idioma da conta, e um
    | relatório em inglês não é caso exótico — é o default de muitas contas.
    |
    | ⚠️ **`sensor` antes de `média`** em `mean_glucose` não é acidente: se o
    | padrão genérico vier primeiro, ele casa com "Média de carboidrato" numa
    | página que também tem glicose. Ordem é semântica aqui.
    |
    */

    'labels' => [
        'mean_glucose' => [
            'média de glicose do sensor',
            'glicose média do sensor',
            'sensor glucose average',
            'average sensor glucose',
            'glicose média',
            'average glucose',
        ],
        'time_in_range_percent' => [
            'tempo no intervalo',
            'tempo na faixa',
            'time in range',
        ],
        'time_above_180_percent' => [
            'tempo acima do intervalo',
            'tempo acima',
            'time above range',
        ],
        'time_above_250_percent' => [
            'tempo muito acima',
            'time very high',
        ],
        'time_below_70_percent' => [
            'tempo abaixo do intervalo',
            'tempo abaixo',
            'time below range',
        ],
        'time_below_54_percent' => [
            'tempo muito abaixo',
            'time very low',
        ],
        'cv_percent' => [
            'variabilidade',
            'coeficiente de variação',
            'coefficient of variation',
        ],
        'gmi' => [
            'indicador de manejo da glicose',
            'glucose management indicator',
            'gmi',
        ],
        'coverage_percent' => [
            'uso do sensor',
            'sensor wear',
            'sensor usage',
        ],
        'total_insulin_u' => [
            'insulina total diária',
            'total daily insulin',
            'insulina total',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | O número, depois do rótulo
    |--------------------------------------------------------------------------
    |
    | ⚠️ Janela curta de propósito. Rótulo e valor ficam próximos num relatório;
    | uma janela larga casaria o rótulo de um bloco com o número do bloco
    | seguinte, e o resultado sairia plausível.
    |
    */

    'value_window_chars' => 40,

    /*
    |--------------------------------------------------------------------------
    | Período
    |--------------------------------------------------------------------------
    |
    | Rótulos que precedem o intervalo de datas do relatório. Sem período, o
    | agregado não é gravável: ele não teria a que se referir.
    |
    */

    'period_labels' => [
        'período',
        'periodo',
        'date range',
        'report period',
    ],

];

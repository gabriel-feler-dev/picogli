<?php

declare(strict_types=1);

/**
 * As listas dos Artigos IV e VI, em UM lugar só.
 *
 * ## Por que este arquivo existe
 *
 * Até a fase 5 estas listas viviam dentro dos testes que as usavam — o
 * vocabulário no `ForbiddenVocabularyTest`, a conduta num array local do
 * `R6GabaritoTest`. Funcionava, porque cada uma tinha um consumidor.
 *
 * ⚠️ **A fase 5 trouxe um terceiro consumidor, e ele é diferente dos outros: o
 * PROMPT.** Ele precisa *citar* as palavras para instruir o modelo a não usá-las
 * — e isso cria uma tensão real:
 *
 *   - se o prompt trouxesse as palavras escritas, a varredura de vocabulário
 *     acusaria o próprio prompt (o erro que a fase 3 cometeu ao acusar a
 *     documentação da regra);
 *   - se as listas ficassem duplicadas, uma palavra acrescentada ao teste não
 *     chegaria ao modelo — e o modelo continuaria autorizado a usá-la.
 *
 * A saída é fonte única: o prompt **interpola** estas listas em tempo de
 * renderização. O arquivo do prompt fica limpo para a varredura, e a instrução
 * que chega ao modelo é exatamente a mesma que o teste cobra.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Artigo IV — vocabulário que julga a pessoa
    |--------------------------------------------------------------------------
    |
    | Não é lista de palavrão: são as construções que transformam um dado em
    | acusação. "Você comeu 109 g de carboidrato" e "quedas de glicose disparam
    | fome intensa — é reação do corpo" dizem o MESMO número.
    |
    | Alguns termos estão truncados de propósito (`negligên`, `preguiç`) para
    | pegar as variações de gênero e número numa entrada só.
    |
    */

    'forbidden_vocabulary' => [
        'você deveria',
        'vocês deveriam',
        'falta de',
        'descuido',
        'descontrole',
        'descontrolado',
        'errado',
        'ruim',
        'culpa',
        'falhou',
        'negligên',
        'preguiç',
        'irresponsáv',
    ],

    /*
    |--------------------------------------------------------------------------
    | Artigo VI — construções que viram conduta médica
    |--------------------------------------------------------------------------
    |
    | ⚠️ A fronteira do Artigo VI não é sobre palavras isoladas, é sobre o **modo
    | verbal**: descrever no indicativo é permitido, prescrever no imperativo não.
    | "Sua bomba dá 1 U para cada 8 g" descreve; "ajuste para 6 g" prescreve.
    |
    | Esta lista é usada pela varredura da prosa de R6 (fase 4) e pelo prompt de
    | narrativa (fase 5).
    |
    */

    'forbidden_conduct' => [
        'ajuste para',
        'ajustar para',
        'mude para',
        'mudar para',
        'troque para',
        'reduza',
        'reduzir para',
        'aumente',
        'aumentar para',
        'deveria ser',
        'deveria dar',
        'o ideal seria',
        'o correto seria',
        'recomendo',
        'recomenda-se',
        'sugerimos',
        'experimente',
    ],

];

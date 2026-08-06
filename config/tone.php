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

    /*
    |--------------------------------------------------------------------------
    | Artigo VI, camada 4 — o classificador de emergência (Spec 006, §D4)
    |--------------------------------------------------------------------------
    |
    | ⚠️ Esta é a única camada da fronteira clínica que roda ANTES do modelo.
    | As outras quatro são instrução, prosa ou interface; esta é um `if`.
    |
    |     "Não dependa do modelo para segurança." — PicoGli.md §9.4
    |
    | ## O desenho: termo de risco + marca de presente
    |
    | ⚠️ Disparar com o TERMO SOZINHO é o erro fácil, e ele destrói o produto:
    | metade das perguntas legítimas deste app menciona hipoglicemia. Um chat que
    | responde "procure um serviço médico" para "minhas hipos estão diminuindo?"
    | é desligado na primeira semana — e uma camada 4 desligada não protege
    | ninguém. Por isso o disparo exige DUAS marcas.
    |
    |   "estou com 40 agora"            → presente + valor crítico  → dispara
    |   "tive um 40 no dia 25"          → sem marca de presente     → não
    |   "o que é cetoacidose?"          → sem marca de presente     → não
    |   "minhas hipos estão diminuindo?"→ sem marca de presente     → não
    |
    | ## Por que os termos aparecem com e sem acento
    |
    | A varredura NÃO remove acento do texto do usuário, de propósito: sem acento,
    | o prefixo `hipo` passaria a casar com `hipotese`. Como as pessoas escrevem
    | dos dois jeitos, os termos em que isso importa estão listados nas duas
    | formas. É verbosidade comprada em troca de precisão.
    |
    */

    'emergency' => [

        /*
        | Sintomas e quadros. Casam por PREFIXO em início de palavra: `desmai`
        | pega "desmaio", "desmaiando" e "desmaiei" numa entrada só.
        |
        | ⚠️ O que NÃO está aqui é tão deliberado quanto o que está. `confus`
        | saiu porque "estou confuso com esse gráfico" é uma frase normal deste
        | produto; "muito alta" saiu porque "minha variabilidade está muito alta"
        | também é. Ficaram as formas compostas, que não têm outro sentido.
        */
        'risk_terms' => [
            'hipo',
            'hipoglicemi',
            'cetoacidose',
            'cetona',
            'cetose',
            'desmai',
            'convuls',
            'vômit',
            'vomit',
            'passando mal',
            'passar mal',
            'tremendo',
            'tremor',
            'suando frio',
            'suor frio',
            'visão embaçada',
            'visao embaçada',
            'glicose muito baixa',
            'glicemia muito baixa',
            'glicose muito alta',
            'glicemia muito alta',
            'açúcar muito baixo',
            'acucar muito baixo',
        ],

        /*
        | A segunda marca: o que situa o quadro em AGORA.
        |
        | ⚠️ `está` e `estão` ficaram DE FORA. "minha média está muito alta" é
        | observação sobre dado histórico, não relato de sintoma — e incluí-las
        | faria a metade das perguntas do produto virar emergência.
        |
        | ⚠️⚠️ **`agora` sozinho também ficou de fora, e o autoteste é que
        | mostrou.** Em português ele significa duas coisas: "neste instante" e
        | "a esta altura". Com `agora` na lista, *"quantas hipos eu tenho agora
        | no total?"* virava emergência — termo de risco + marca de presente,
        | exatamente como a regra manda, e completamente errado.
        |
        | O caso terso legítimo ("40 agora") não se perdeu: um valor crítico
        | COLADO em `agora` carrega a própria marca de presente, e essa exceção
        | é estreita porque exige o número adjacente. Ver `EmergencyClassifier`.
        */
        'presence_markers' => [
            'estou',
            'to com',
            'tô com',
            'tô ',
            'neste momento',
            'nesse momento',
            'acabei de',
            'não consigo',
            'nao consigo',
            'me sinto',
            'sentindo',
            'o que faço',
            'o que eu faço',
            'me ajuda',
            'preciso de ajuda',
        ],

        /*
        | Dispensam a segunda marca: já carregam a urgência na própria palavra.
        | Lista curta de propósito — cada entrada aqui é uma exceção à regra das
        | duas marcas, e exceção que cresce vira a regra.
        */
        'standalone_terms' => [
            'socorro',
            'ambulância',
            'ambulancia',
            'samu',
            'pronto-socorro',
            'pronto socorro',
        ],

        /*
        | Valor de glicose relatado no presente.
        |
        | ⚠️ NÃO reusa `clinical.ranges` de propósito: fora da faixa não é
        | emergência. 251 mg/dL é TAR nível 2 e merece uma conversa, não uma
        | orientação de urgência. Emergência é o extremo — abaixo de 54 (TBR
        | nível 2) ou 300 para cima, território de cetoacidose.
        */
        'critical_low' => 54,
        'critical_high' => 300,
    ],

];

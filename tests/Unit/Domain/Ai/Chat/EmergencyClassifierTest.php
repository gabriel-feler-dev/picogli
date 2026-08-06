<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\EmergencyClassifier;

/**
 * T500 — a camada 4 do Artigo VI (Spec 006, §D4, FR-604).
 *
 * ⚠️⚠️ **Este arquivo é uma tabela de frases, e é assim de propósito.** A guarda
 * não tem lógica interessante; ela tem uma FRONTEIRA. E fronteira só se
 * documenta com exemplos dos dois lados.
 *
 * ## Os dois lados, e por que o segundo é o difícil
 *
 * | Lado | O que prova | O que custa errar |
 * |---|---|---|
 * | dispara | a camada 4 existe de fato | o caso que ela existe para cobrir |
 * | não acusa | o chat continua utilizável | o usuário desliga, e aí ela não protege ninguém |
 *
 * A lição vem do `NumberGuard` da fase 5: o defeito de desenho dele (tolerância
 * relativa aplicada sobre número extraído de data) só apareceu porque havia
 * teste do lado "não pode acusar". Aqui o risco é o mesmo, invertido — um
 * classificador nervoso demais é indistinguível de um chat quebrado.
 *
 * ⚠️ Teste de unidade: sem container, sem `config()`. As listas entram literais,
 * o que também prova que o domínio é puro.
 */

/** As listas reais de produção, copiadas de `config/tone.emergency`. */
function emergencyClassifier(): EmergencyClassifier
{
    return new EmergencyClassifier(
        riskTerms: [
            'hipo', 'hipoglicemi', 'cetoacidose', 'cetona', 'cetose',
            'desmai', 'convuls', 'vômit', 'vomit',
            'passando mal', 'passar mal', 'tremendo', 'tremor',
            'suando frio', 'suor frio', 'visão embaçada', 'visao embaçada',
            'glicose muito baixa', 'glicemia muito baixa',
            'glicose muito alta', 'glicemia muito alta',
            'açúcar muito baixo', 'acucar muito baixo',
        ],
        // ⚠️ `agora` NÃO está aqui — ver o teste da ambiguidade, no fim.
        presenceMarkers: [
            'estou', 'to com', 'tô com', 'tô ',
            'neste momento', 'nesse momento', 'acabei de',
            'não consigo', 'nao consigo',
            'me sinto', 'sentindo', 'o que faço', 'o que eu faço',
            'me ajuda', 'preciso de ajuda',
        ],
        standaloneTerms: [
            'socorro', 'ambulância', 'ambulancia', 'samu',
            'pronto-socorro', 'pronto socorro',
        ],
        criticalLow: 54,
        criticalHigh: 300,
        guidance: 'Procure atendimento médico imediatamente: ligue 192 (SAMU).',
    );
}

/*
|--------------------------------------------------------------------------
| T500.4 — o lado que TEM de pegar
|--------------------------------------------------------------------------
*/

it('dispara em situação de risco no presente', function (string $mensagem) {
    expect(emergencyClassifier()->isEmergency($mensagem))->toBeTrue(
        "deveria disparar: \"{$mensagem}\""
    );
})->with([
    // Valor crítico + presente. O `40` não é termo de risco nenhum — é a régua
    // numérica que o pega.
    'estou com 40 agora',
    'tô com 38 e me sinto mal',
    'minha glicose está 45 neste momento',
    'estou com 480 mg/dl',
    'o sensor marcou 512 agora, o que faço?',

    // ⚠️ Relato terso: sem verbo, sem sintoma nomeado. O valor crítico colado
    // em "agora" é a única marca — e basta.
    '40 agora',
    'glicose 38 agora',

    // Termo de risco + presente.
    'acho que estou entrando em cetoacidose',
    'estou passando mal, tremendo e suando frio',
    'estou com hipoglicemia e não consigo comer nada',
    'não consigo parar de vomitar',
    'estou com a visão embaçada agora',
    'estou sentindo tremor e suor frio',
    'estou com a glicose muito baixa',

    // Urgência isolada — dispensa a segunda marca.
    'socorro',
    'preciso chamar o samu?',
    'devo ir para o pronto-socorro?',
]);

/*
|--------------------------------------------------------------------------
| T500.5 — ⚠️ o lado que NÃO PODE acusar
|--------------------------------------------------------------------------
|
| Este bloco é o que define o desenho. Se ele passasse a falhar, a saída certa
| NÃO é relaxar o teste: é que o classificador ficou nervoso demais e o chat
| deixou de ser utilizável.
*/

it('não acusa pergunta legítima sobre o histórico', function (string $mensagem) {
    expect(emergencyClassifier()->isEmergency($mensagem))->toBeFalse(
        "não deveria disparar: \"{$mensagem}\""
    );
})->with([
    // ⚠️ Passado. É o par exato do primeiro caso do bloco de cima — mesmo
    // número, mesma faixa crítica, sem marca de presente.
    'tive um 40 no dia 25',
    'qual foi minha pior hipoglicemia no período?',
    'tive uma hipo grave na madrugada de 25/07',
    'meu pior episódio foi em julho',

    // Perguntas educativas: o termo aparece, o quadro não.
    'o que é cetoacidose?',
    'o que significa hipoglicemia nível 2?',
    'por que hipoglicemia dá tremor?',

    // ⚠️ Perguntas COM marca de presente e sem quadro nenhum. São a metade do
    // uso normal deste produto, e o portão precisa deixar todas passarem.
    'minhas hipos estão diminuindo?',
    'estou vendo uma média de 40 no gráfico, isso está certo?',
    'quantas hipos eu tenho agora no total?',
    'estou querendo entender o dia 25',
    'e agora, o dia 25 continua sendo o pior?',
    'estou confuso com esse gráfico',
    'minha variabilidade está muito alta?',
    'sou diabético há 40 anos e estou querendo entender esses números',
    'estou comparando 25/07 com 26/07',
    'comi 40 g de carboidrato e estou vendo o efeito disso',

    // Vazio e ruído.
    '',
    '   ',
    'oi',
]);

/*
|--------------------------------------------------------------------------
| A mecânica que sustenta os dois lados
|--------------------------------------------------------------------------
*/

it('a marca de presente é o portão: o mesmo termo muda de lado com ela', function () {
    $guarda = emergencyClassifier();

    // ⚠️ O par que resume a classe inteira.
    expect($guarda->isEmergency('estou com hipoglicemia'))->toBeTrue();
    expect($guarda->isEmergency('tive hipoglicemia'))->toBeFalse();
});

it('valor crítico sem marca de presente não dispara', function () {
    $guarda = emergencyClassifier();

    expect($guarda->isEmergency('estou com 40'))->toBeTrue();
    expect($guarda->isEmergency('o menor valor foi 40'))->toBeFalse();
});

it('valor dentro da faixa não dispara nem com marca de presente', function () {
    $guarda = emergencyClassifier();

    // 54 é o limiar: dentro, não dispara. Abaixo, dispara.
    expect($guarda->isEmergency('estou com 54'))->toBeFalse();
    expect($guarda->isEmergency('estou com 53'))->toBeTrue();

    // 299 é TAR nível 2, e TAR nível 2 não é urgência.
    expect($guarda->isEmergency('estou com 299'))->toBeFalse();
    expect($guarda->isEmergency('estou com 300'))->toBeTrue();
});

it('número sem pista de glicose não é lido como glicose', function () {
    $guarda = emergencyClassifier();

    // A diferença é a palavra ANTES do número.
    expect($guarda->isEmergency('estou com 40'))->toBeTrue();
    expect($guarda->isEmergency('estou vendo uma média de 40'))->toBeFalse();
    expect($guarda->isEmergency('estou olhando o dia 40'))->toBeFalse();
    expect($guarda->isEmergency('estou há 40 anos com diabetes'))->toBeFalse();
});

it('data e hora não são valores de glicose', function () {
    $guarda = emergencyClassifier();

    expect($guarda->isEmergency('estou olhando 25/07 agora'))->toBeFalse();
    expect($guarda->isEmergency('estou vendo o que houve às 18:06'))->toBeFalse();
});

/**
 * ⚠️⚠️ **O caso que reprovou o primeiro desenho, e mudou a lista.**
 *
 * A primeira versão trazia `agora` como marca de presente. A regra das duas
 * marcas era cumprida ao pé da letra — "hipos" + "agora" — e o resultado estava
 * errado: em português, `agora` significa "neste instante" **e** "a esta
 * altura".
 *
 * A saída não foi relaxar o teste. Foi tirar `agora` da lista e devolvê-lo por
 * uma porta estreita: **valor crítico adjacente**. Uma pergunta de contagem não
 * tem número crítico colado ao advérbio; um relato de crise tem.
 */
it('agora sozinho não é marca de presente, mas colado a um valor crítico é', function () {
    $guarda = emergencyClassifier();

    // Análise — o "agora" é "a esta altura".
    expect($guarda->isEmergency('quantas hipos eu tenho agora no total?'))->toBeFalse();
    expect($guarda->isEmergency('e agora, o dia 25 continua sendo o pior?'))->toBeFalse();

    // Relato — o "agora" é "neste instante", e o número prova.
    expect($guarda->isEmergency('40 agora'))->toBeTrue();

    // ⚠️ A porta é estreita: o número precisa estar COLADO ao advérbio.
    expect($guarda->isEmergency('40 no dia 25, e agora?'))->toBeFalse();
});

/**
 * ⚠️ **Nem todo número no presente é glicose** — e os dois que mais aparecem
 * neste produto são carboidrato e insulina.
 *
 * "tomei 40 agora" seriam 40 unidades, o que é assunto sério e **não** é uma
 * emergência glicêmica. Se a guarda lesse isso como "glicose 40", ela estaria
 * disparando pelo motivo errado — e o motivo errado é o que faz uma guarda
 * perder credibilidade.
 */
it('carboidrato e insulina não são lidos como glicose', function () {
    $guarda = emergencyClassifier();

    expect($guarda->isEmergency('comi 40 agora'))->toBeFalse();
    expect($guarda->isEmergency('tomei 40 agora'))->toBeFalse();
    expect($guarda->isEmergency('apliquei 12 agora'))->toBeFalse();
    expect($guarda->isEmergency('estou com 40 g de carboidrato no wizard'))->toBeFalse();

    // E a leitura de glicose continua passando.
    expect($guarda->isEmergency('glicose 40 agora'))->toBeTrue();
});

it('acento preservado impede que hipo case com hipótese', function () {
    $guarda = emergencyClassifier();

    // ⚠️ Se o classificador removesse acento para "normalizar", `hipotese`
    // começaria com `hipo` e esta frase viraria emergência.
    expect($guarda->isEmergency('minha hipótese é que estou pior à tarde'))->toBeFalse();
    expect($guarda->isEmergency('estou com hipo'))->toBeTrue();
});

it('o termo casa em início de palavra, não em qualquer posição', function () {
    $guarda = emergencyClassifier();

    // `desmai` pega as três flexões...
    expect($guarda->isEmergency('estou quase desmaiando'))->toBeTrue();
    expect($guarda->isEmergency('estou achando que vou desmaiar'))->toBeTrue();

    // ...e o passado continua fora.
    expect($guarda->isEmergency('desmaiei em junho do ano passado'))->toBeFalse();
});

it('maiúscula e pontuação não escapam da guarda', function () {
    $guarda = emergencyClassifier();

    expect($guarda->isEmergency('SOCORRO!!!'))->toBeTrue();
    expect($guarda->isEmergency('Estou com 40, o que faço?'))->toBeTrue();
    expect($guarda->isEmergency('  estou    com   38  '))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| T500.1 e T500.6 — construção e orientação
|--------------------------------------------------------------------------
*/

it('devolve a orientação fixa', function () {
    expect(emergencyClassifier()->guidance())->toContain('192');
});

it('lista vazia é recusada na construção', function (string $vazia) {
    $listas = [
        'risk' => ['hipoglicemi'],
        'presence' => ['estou'],
        'standalone' => ['socorro'],
    ];
    $listas[$vazia] = [];

    expect(fn () => new EmergencyClassifier(
        $listas['risk'], $listas['presence'], $listas['standalone'], 54, 300, 'texto',
    ))->toThrow(InvalidArgumentException::class);
})->with(['risk', 'presence', 'standalone']);

it('faixa crítica invertida é recusada na construção', function () {
    expect(fn () => new EmergencyClassifier(
        ['hipoglicemi'], ['estou'], ['socorro'], 300, 54, 'texto',
    ))->toThrow(InvalidArgumentException::class, 'invertida');
});

it('orientação vazia é recusada na construção', function () {
    // ⚠️ Sem texto, o disparo devolveria silêncio — o pior resultado possível
    // desta guarda, porque ela teria funcionado e não pareceria.
    expect(fn () => new EmergencyClassifier(
        ['hipoglicemi'], ['estou'], ['socorro'], 54, 300, '   ',
    ))->toThrow(InvalidArgumentException::class);
});

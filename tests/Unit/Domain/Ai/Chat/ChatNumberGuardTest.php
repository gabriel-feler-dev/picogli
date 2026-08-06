<?php

declare(strict_types=1);

use App\Domain\Ai\NumberGuard;
use App\Domain\Ai\Value\AiPayload;

/**
 * T507 — a guarda de número sobre resultados de ferramenta (§D3, §D6, FR-607).
 *
 * ⚠️⚠️ **No chat, a guarda é MAIS crítica que na fase 5.** Lá havia fallback: a
 * narrativa era descartada e a tela voltava ao estado de ontem. Aqui não existe
 * template estático que responda "por que o dia 25 foi diferente?" — se a
 * resposta cai, o usuário fica sem resposta.
 *
 * ⚠️ E a fonte de procedência muda: não é mais a `evidence` dos achados, é a
 * **união dos `tool_results` do turno**. A consequência é o Artigo III por
 * construção — um número que o modelo escreva sem ter chamado a ferramenta
 * correspondente não tem correspondência aqui.
 */
function chatGuard(): NumberGuard
{
    // Mesmos valores de `config/ai.number_guard`.
    return new NumberGuard(0.06, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 12, 24, 100]);
}

/** Um turno realista, como o `ChatOrchestrator` vai montar. */
function toolResults(): array
{
    return [
        [
            'name' => 'get_period_metrics',
            'arguments' => ['start' => '2026-07-16', 'end' => '2026-07-29'],
            'result' => [
                'period_start' => '2026-07-16',
                'period_end' => '2026-07-29',
                'reading_count' => 3616,
                'mean_glucose' => 142.0,
                'time_in_range_percent' => 83.9,
                'cv_percent' => 28.8,
            ],
        ],
        [
            'name' => 'get_daily_series',
            'arguments' => ['start' => '2026-07-16', 'end' => '2026-07-29'],
            'result' => [
                'day_count' => 14,
                'rows' => [
                    ['local_date' => '2026-07-25', 'mean_glucose' => 159.0, 'cv_percent' => 42.2],
                    ['local_date' => '2026-07-26', 'mean_glucose' => 131.4, 'cv_percent' => 27.1],
                ],
            ],
        ],
    ];
}

it('aprova resposta que só cita número consultado', function () {
    $resposta = 'No período de 2026-07-16 a 2026-07-29 sua média foi 142 mg/dL, com '
        .'83,9% do tempo na faixa. O dia 25 destoou: média de 159 e variabilidade de 42,2%.';

    expect(chatGuard()->orphansIn($resposta, toolResults()))->toBe([]);
});

/**
 * ⚠️ **O caso que a fase inteira existe para impedir.** O número é plausível,
 * está no formato certo, e nenhuma ferramenta o devolveu.
 *
 * ⚠️ O valor precisa estar **fora da lista de isenção**: números de linguagem
 * (`1`–`10`, `12`, `24`, `100`) são dispensados de procedência de propósito,
 * senão "uma vez" e "as 24 horas do dia" derrubariam toda resposta.
 */
it('pega número que nenhuma ferramenta devolveu', function () {
    $resposta = 'Sua média foi 142 mg/dL e você teve 23 episódios de hipoglicemia.';

    $orfaos = chatGuard()->orphansIn($resposta, toolResults());

    expect($orfaos)->toContain('23');
    // E não acusa os que vieram das ferramentas.
    expect($orfaos)->not->toContain('142');
});

/**
 * ⚠️ **A varredura precisa alcançar dentro das linhas.** O payload do chat tem
 * linha dentro de resultado dentro de ferramenta — parar no primeiro nível
 * descartaria a procedência de toda métrica diária, e a guarda reprovaria
 * respostas corretas.
 */
it('encontra procedência dentro das linhas de uma tabela', function () {
    $resposta = 'No dia 26 sua média foi 131,4 mg/dL.';

    expect(chatGuard()->orphansIn($resposta, toolResults()))->toBe([]);
});

/**
 * ⚠️ A distinção `measured`/`literal` da fase 5 continua valendo aqui — e é o
 * que impede o `25` de uma data de autorizar um `26` inventado.
 */
it('data continua sendo literal: o dia não ganha tolerância de medição', function () {
    // 26 aparece como dia (literal, casamento exato) — então "26" passa.
    expect(chatGuard()->orphansIn('o dia 26 foi melhor', toolResults()))->toBe([]);

    // ⚠️ Mas 16,7 NÃO. O `16` de `'2026-07-16'` é literal e não admite margem;
    // se admitisse, o dia do mês autorizaria qualquer número a 6% dele — que é
    // exatamente o defeito de desenho que a fase 5 encontrou.
    //
    // A escolha do valor importa: a primeira versão deste teste usou 25,9, que
    // está a 4,4% de 27,1 — uma MEDIÇÃO real do turno. Tinha procedência
    // legítima, e o teste é que estava errado.
    expect(chatGuard()->orphansIn('foram 16,7 horas', toolResults()))->toContain('16,7');
});

it('arredondamento razoável de uma medição é aceito', function () {
    // 83,9% escrito como "quase 84%".
    expect(chatGuard()->orphansIn('quase 84% do tempo na faixa', toolResults()))->toBe([]);
});

it('resultado de ferramenta com erro não vira procedência', function () {
    $comErro = [[
        'name' => 'get_episodes',
        'arguments' => ['start' => '2026-07-29', 'end' => '2026-07-16'],
        'error' => "período inválido: 'start' é posterior a 'end'",
    ]];

    // ⚠️ Só o que veio nos ARGUMENTOS e no texto do erro tem procedência. Um
    // número de resposta não pode nascer de uma consulta que falhou.
    expect(chatGuard()->orphansIn('você teve 19 hipoglicemias', $comErro))->toContain('19');
});

it('turno sem nenhuma ferramenta chamada reprova qualquer número', function () {
    // ⚠️ É o caso do modelo respondendo "de cabeça". Sem ferramenta, sem
    // procedência — e a resposta inteira cai (FR-607).
    $orfaos = chatGuard()->orphansIn('sua média foi 142 mg/dL', []);

    expect($orfaos)->toContain('142');
});

/**
 * ⚠️ **T507.3 registrado em teste:** a assinatura antiga continua existindo e
 * continua funcionando. A fase 5 não sabe que esta refatoração aconteceu.
 */
it('a assinatura da fase 5 continua valendo', function () {
    $payload = new AiPayload(
        period: ['mean_glucose' => 142.0],
        findings: [[
            'rule_id' => 'daypart_drift',
            'severity' => 'priority',
            'rank' => 4,
            'evidence' => ['afternoon_above_pct' => 24.1],
        ]],
    );

    expect(chatGuard()->approves('a tarde concentra 24,1%, com média de 142', $payload))->toBeTrue();
    expect(chatGuard()->approves('a tarde concentra 31,7%', $payload))->toBeFalse();
});

/**
 * ⚠️ **`rank` continua LITERAL depois da refatoração.**
 *
 * A varredura virou recursiva no T507, e sem cuidado o `rank` entraria como
 * medição — a tolerância relativa em cima de um rank 10 passaria a autorizar
 * "9,5" de graça. Frouxidão pequena, mas frouxidão introduzida por refatoração é
 * a pior espécie: ninguém a decidiu.
 */
it('rank não vira medição com tolerância', function () {
    $payload = new AiPayload(
        period: [],
        findings: [[
            'rule_id' => 'sensor_quality',
            'severity' => 'info',
            'rank' => 10,
            'evidence' => ['mean_error_pct' => 10.7],
        ]],
    );

    // 9,5 está a 5% de 10 — dentro da tolerância, se `rank` fosse medição.
    expect(chatGuard()->orphansIn('foram 9,5 horas', $payload->toArray()))->toContain('9,5');
});

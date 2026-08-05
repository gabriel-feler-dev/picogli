<?php

declare(strict_types=1);

use App\Domain\Ai\NumberGuard;
use App\Domain\Ai\Value\AiPayload;

/**
 * T403 — a guarda de número inventado (FR-505, §D5).
 *
 * ⚠️ **O autoteste é metade do arquivo, e é o que dá valor ao resto.** Uma guarda
 * que nunca acusa nada dá a sensação de proteção sem proteger; uma que acusa tudo
 * é desligada na primeira semana. Só os dois lados juntos provam que ela
 * discrimina — mesmo princípio dos guardas das fases 3 e 4.
 */
function guard(?float $tolerance = null, ?array $exempt = null): NumberGuard
{
    return new NumberGuard(
        $tolerance ?? 0.06,
        $exempt ?? [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 12, 24, 70, 180, 250, 54, 100],
    );
}

/** Payload com a evidência real de R1 e R3 do export de referência. */
function guardPayload(): AiPayload
{
    return new AiPayload(
        period: [
            'period_from' => '2026-07-16',
            'period_to' => '2026-07-29',
            'coverage_percent' => 91.1,
            'span_days' => 13.8,
        ],
        findings: [
            [
                'rule_id' => 'R1_DAYPART_DRIFT',
                'severity' => 'priority',
                'rank' => 4,
                'evidence' => [
                    'worst_daypart' => 'afternoon',
                    'worst_percent_above' => 24.10,
                    'worst_readings' => 917,
                    'best_percent_above' => 4.17,
                    'ratio' => 5.78,
                ],
            ],
            [
                'rule_id' => 'R3_ROLLERCOASTER',
                'severity' => 'attention',
                'rank' => 3,
                'evidence' => [
                    'date' => '2026-07-25',
                    'nadir' => 55,
                    'nadir_at' => '18:06',
                    'carbs_g' => 109.0,
                    'hyper_duration_hours' => 4.6,
                    'hyper_peak' => 324,
                ],
            ],
        ],
    );
}

describe('números com procedência passam', function () {

    it('aceita o valor exato da evidência', function () {
        expect(guard()->orphans('A razão foi de 5,78 vezes.', guardPayload()))->toBe([]);
        expect(guard()->orphans('Foram 917 leituras e 109 g.', guardPayload()))->toBe([]);
    });

    /**
     * ⚠️ **O caso que a tolerância relativa sozinha reprovaria.** 4,6 h escrito
     * como "cerca de 5 horas" está a 8,7% do valor — acima de qualquer tolerância
     * defensável — e ainda assim é arredondamento correto para a casa inteira.
     */
    it('aceita arredondamento para a casa inteira', function () {
        expect(guard()->orphans('ficou alta por cerca de 5 horas', guardPayload()))->toBe([]);
    });

    it('aceita arredondamento para uma casa', function () {
        // 5,78 → "5,8" ; 24,10 → "24,1" ; 91,1 → "91"
        expect(guard()->orphans('5,8 vezes mais tempo', guardPayload()))->toBe([]);
        expect(guard()->orphans('24,1% do tempo', guardPayload()))->toBe([]);
        expect(guard()->orphans('91% de captura', guardPayload()))->toBe([]);
    });

    /**
     * ⚠️ Sem isto, TODA narrativa que citasse uma data seria descartada. A
     * evidência guarda `'2026-07-25'` como string porque §D1 exige escalar plano.
     */
    it('aceita números dentro de valores de texto — datas e horários', function () {
        expect(guard()->orphans('Em 2026-07-25 sua glicose caiu.', guardPayload()))->toBe([]);
        expect(guard()->orphans('chegou ao mínimo às 18:06', guardPayload()))->toBe([]);
        expect(guard()->orphans('o período de 2026-07-16 a 2026-07-29', guardPayload()))->toBe([]);
    });

    /**
     * ⚠️ A lista de isenção existe para a guarda não virar ruído — e guarda
     * ruidosa é guarda desligada, que é o pior desfecho possível.
     */
    it('aceita números de linguagem', function () {
        $prose = 'Isso aconteceu uma vez, e nas 24 horas do dia a faixa de 70 a 180 é a meta.';

        expect(guard()->orphans($prose, guardPayload()))->toBe([]);
    });

    it('aceita prosa sem número nenhum', function () {
        expect(guard()->orphans('Suas quedas se concentram em dois horários.', guardPayload()))
            ->toBe([]);
        expect(guard()->approves('Texto sem números.', guardPayload()))->toBeTrue();
    });

    it('aceita milhar com ponto', function () {
        $payload = new AiPayload(
            period: [],
            findings: [[
                'rule_id' => 'R5_SENSOR_GAP_LOOP_IMPACT',
                'severity' => 'attention',
                'rank' => 5,
                'evidence' => ['gap_minutes' => 1347],
            ]],
        );

        expect(guard()->orphans('ficou 1.347 minutos sem registrar', $payload))->toBe([]);
    });
});

/**
 * ⚠️⚠️ **O AUTOTESTE: a guarda tem de PEGAR o número inventado.**
 *
 * Cada caso abaixo é uma alucinação plausível — o tipo de coisa que um modelo
 * escreve com a mesma fluência com que escreve a verdade.
 */
describe('números inventados são pegos', function () {

    it('pega valor que não existe em nenhuma evidência', function (string $prose, string $esperado) {
        expect(guard()->orphans($prose, guardPayload()))->toContain($esperado);
    })->with([
        ['A razão foi de 7,3 vezes.', '7,3'],
        ['Foram 1.200 leituras no período.', '1.200'],
        ['Sua média foi de 142 mg/dL.', '142'],
        ['Você passou 38% do tempo acima da faixa.', '38'],
        ['O pico chegou a 401.', '401'],
        ['Foram 15 episódios de hipoglicemia.', '15'],
    ]);

    it('pega o número inventado mesmo cercado de números corretos', function () {
        // ⚠️ É o caso realista: nove números certos e um inventado. É por isso
        // que a narrativa INTEIRA é descartada — o usuário não tem como saber
        // qual é qual.
        $prose = 'Das 917 leituras da tarde, 24,1% ficaram acima, contra 4,17% da '
            .'madrugada — 5,78 vezes mais. A glicose chegou a 324 depois de 109 g, '
            .'e o episódio durou 4,6 horas com média de 187 mg/dL.';

        $orphans = guard()->orphans($prose, guardPayload());

        expect($orphans)->toBe(['187']);
        expect(guard()->approves($prose, guardPayload()))->toBeFalse();
    });

    it('pega número fora da tolerância de arredondamento', function () {
        // 24,10 arredonda para 24 ou 24,1 — nunca para 26.
        expect(guard()->orphans('26% do tempo acima', guardPayload()))->toContain('26');
    });

    it('devolve cada órfão uma vez, mesmo repetido', function () {
        $orphans = guard()->orphans('Foram 142 e depois 142 de novo.', guardPayload());

        expect($orphans)->toBe(['142']);
    });
});

/**
 * O outro lado do autoteste: a guarda não pode acusar por excesso de zelo.
 */
describe('a guarda não é ruidosa', function () {

    it('não acusa a prosa de fallback da fase 4, que é toda derivada da evidência', function () {
        // Se a guarda reprovasse os próprios fallbacks, ela estaria calibrada
        // errado — eles são construídos a partir da mesma evidência.
        $prose = 'No período da madrugada sua glicose fica acima da faixa em 4,2% '
            .'do tempo. Da tarde, em 24,1%. É 5,8 vezes mais tempo com glicose alta.';

        expect(guard()->orphans($prose, guardPayload()))->toBe([]);
    });

    it('sem lista de isenção, a guarda acusa números de linguagem', function () {
        // Prova que a lista de isenção FAZ diferença — e portanto que ela é
        // necessária, não decorativa.
        //
        // ⚠️ Usa 70 e 180 de propósito. O primeiro rascunho deste teste usou
        // "as 24 horas do dia" e falhou: `24` TEM procedência neste payload,
        // porque `worst_percent_above = 24,10` arredonda para 24. Os limites da
        // faixa-alvo não são deriváveis de nenhuma evidência daqui.
        $prose = 'A meta é ficar entre 70 e 180.';

        $semIsencao = guard(exempt: [])->orphans($prose, guardPayload());

        expect($semIsencao)->toContain('70');
        expect($semIsencao)->toContain('180');
        expect(guard()->orphans($prose, guardPayload()))->toBe([]);
    });

    it('tolerância mais frouxa aceita mais, e isso é visível', function () {
        // 26 contra 24,10 é 7,9% de erro.
        expect(guard(tolerance: 0.02)->orphans('26%', guardPayload()))->toContain('26');
        expect(guard(tolerance: 0.10)->orphans('26%', guardPayload()))->toBe([]);
    });
});

it('o container entrega a guarda com a config real', function () {
    expect(app(NumberGuard::class))->toBeInstanceOf(NumberGuard::class);
})->skip('precisa do container — coberto em tests/Feature/Ai');

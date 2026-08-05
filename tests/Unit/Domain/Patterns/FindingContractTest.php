<?php

declare(strict_types=1);

use App\Domain\Patterns\Rule;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;

/**
 * T301 — FR-401, D1. O contrato do achado.
 *
 * Estes testes protegem quatro invariantes que só quebram na fase 5, quando já
 * for caro consertar:
 *
 *   - `evidence` plano e escalar ...... allowlist do PayloadSanitizer (Artigo VII)
 *   - chave em snake_case ............. impede cabeçalho do CSV virar chave
 *   - fallback não vazio .............. produto funciona com a IA desligada (I)
 *   - R6 sem encaminhamento não existe  fronteira clínica (Artigo VI, camada 3)
 */
function makeFinding(
    RuleId $rule = RuleId::DaypartDrift,
    Severity $severity = Severity::Attention,
    ?array $evidence = null,
    string $prose = 'Prosa de fallback com número: 5,78x.',
    bool $handoff = false,
): Finding {
    return new Finding(
        ruleId: $rule,
        severity: $severity,
        evidence: $evidence ?? ['ratio' => 5.78],
        fallbackProse: $prose,
        requiresClinicalHandoff: $handoff,
    );
}

describe('evidence — o contrato com a camada de IA (D1)', function () {

    it('aceita escalar e null', function () {
        $finding = makeFinding(evidence: [
            'worst_daypart' => 'tarde',
            'worst_pct' => 24.10,
            'worst_n' => 917,
            'fired' => true,
            'previous_period_pct' => null,
        ]);

        expect($finding->evidence)->toHaveCount(5);
        expect($finding->evidenceValue('worst_n'))->toBe(917);
        expect($finding->evidenceValue('previous_period_pct'))->toBeNull();
    });

    // ⚠️ O teste que prepara o Artigo VII. Se `evidence` puder aninhar, a
    // allowlist da fase 5 não tem formato conhecido para operar.
    it('recusa array aninhado', function () {
        expect(fn () => makeFinding(evidence: ['dayparts' => ['tarde' => 24.1]]))
            ->toThrow(InvalidArgumentException::class, 'Só escalar ou null');
    });

    it('recusa objeto', function () {
        expect(fn () => makeFinding(evidence: ['when' => new DateTimeImmutable]))
            ->toThrow(InvalidArgumentException::class, 'DateTimeImmutable');
    });

    // ⚠️ A defesa mais direta do Artigo VII: as chaves do CSV do CareLink são
    // "Last Name", "Patient ID", "BG Reading (mg/dL)". Nenhuma passa daqui.
    it('recusa chave que não é snake_case', function (string $key) {
        expect(fn () => makeFinding(evidence: [$key => 1]))
            ->toThrow(InvalidArgumentException::class, 'snake_case');
    })->with([
        'Last Name',
        'Patient ID',
        'BG Reading (mg/dL)',
        'worstDaypart',
        'Worst_Daypart',
        '_leading',
        '9leading',
        '',
    ]);

    it('recusa evidência vazia', function () {
        // Artigo III: achado sem número é prosa sem procedência.
        expect(fn () => makeFinding(evidence: []))
            ->toThrow(InvalidArgumentException::class, 'sem evidência');
    });

    it('lança ao pedir evidência que não existe', function () {
        expect(fn () => makeFinding()->evidenceValue('inexistente'))
            ->toThrow(InvalidArgumentException::class, 'inexistente');
    });
});

describe('prosa de fallback (D3, Artigo I)', function () {

    it('recusa prosa vazia', function () {
        expect(fn () => makeFinding(prose: '   '))
            ->toThrow(InvalidArgumentException::class, 'Artigo I');
    });
});

describe('encaminhamento clínico (Artigo VI, camada 3)', function () {

    // ⚠️ O teste que impede a violação mais fácil de cometer no projeto.
    it('R6 não consegue emitir achado sem encaminhamento', function () {
        expect(fn () => makeFinding(
            rule: RuleId::CarbRatioCoherence,
            evidence: ['morning_carb_ratio' => 5.0],
            handoff: false,
        ))->toThrow(InvalidArgumentException::class, 'Artigo VI');
    });

    it('R6 com encaminhamento constrói', function () {
        $finding = makeFinding(
            rule: RuleId::CarbRatioCoherence,
            evidence: ['morning_carb_ratio' => 5.0, 'evening_carb_ratio' => 8.0],
            handoff: true,
        );

        expect($finding->requiresClinicalHandoff)->toBeTrue();
    });

    it('só R6 exige encaminhamento', function () {
        $exigem = array_values(array_filter(
            RuleId::cases(),
            fn (RuleId $rule): bool => $rule->requiresClinicalHandoff(),
        ));

        expect($exigem)->toBe([RuleId::CarbRatioCoherence]);
    });

    it('outra regra pode marcar encaminhamento por iniciativa própria', function () {
        expect(makeFinding(handoff: true)->requiresClinicalHandoff)->toBeTrue();
    });
});

describe('teto de severidade', function () {

    // FR-411 — "2,8 picadas por dia" não é cobrança: o Guardian 3 exige
    // calibração. Uma regra que só conta linhas ainda pode violar o Artigo IV.
    it('R9 não passa de Info', function () {
        expect(RuleId::CalibrationBurden->maxSeverity())->toBe(Severity::Info);

        expect(fn () => makeFinding(
            rule: RuleId::CalibrationBurden,
            severity: Severity::Attention,
            evidence: ['calibrations' => 39],
        ))->toThrow(InvalidArgumentException::class, 'teto de severidade');
    });

    it('R9 com Info constrói', function () {
        $finding = makeFinding(
            rule: RuleId::CalibrationBurden,
            severity: Severity::Info,
            evidence: ['calibrations' => 39, 'per_day' => 2.8],
        );

        expect($finding->severity)->toBe(Severity::Info);
    });

    it('cappedAt nunca sobe a severidade', function () {
        expect(Severity::Priority->cappedAt(Severity::Info))->toBe(Severity::Info);
        expect(Severity::Info->cappedAt(Severity::Priority))->toBe(Severity::Info);
        expect(Severity::Attention->cappedAt(Severity::Attention))->toBe(Severity::Attention);
    });

    it('o peso ordena, e não depende da ordem de declaração', function () {
        expect(Severity::Priority->weight())->toBeGreaterThan(Severity::Attention->weight());
        expect(Severity::Attention->weight())->toBeGreaterThan(Severity::Info->weight());
    });
});

describe('rank', function () {

    // ⚠️ Não é parâmetro do construtor: duas fontes para o mesmo número é a
    // classe de divergência que este projeto passou três fases evitando.
    it('vem do RuleId, não do construtor', function () {
        expect(makeFinding(rule: RuleId::HypoCluster)->rank())
            ->toBe(RuleId::HypoCluster->rank());

        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(Finding::class, '__construct'))->getParameters(),
        );

        expect($params)->not->toContain('rank');
    });

    it('as dez regras têm rank único e contíguo de 1 a 10', function () {
        $ranks = array_map(fn (RuleId $rule): int => $rule->rank(), RuleId::cases());
        sort($ranks);

        expect($ranks)->toBe(range(1, 10));
    });

    // A ordem é decisão de produto documentada no enum, e a razão é o Artigo IV.
    it('hipoglicemia vem primeiro e o reenquadramento vem antes do detalhe', function () {
        expect(RuleId::HypoCluster->rank())->toBe(1);

        // R4 ("veio de um único dia") antes de R3 ("o que foi aquele dia"):
        // a pessoa lê a perspectiva antes do episódio ruim.
        expect(RuleId::OutlierDay->rank())->toBeLessThan(RuleId::Rollercoaster->rank());

        // Informativo no fim.
        expect(RuleId::CalibrationBurden->rank())->toBe(10);
    });
});

describe('identidade e serialização', function () {

    it('o valor do enum é o ruleId persistido', function () {
        expect(RuleId::Rollercoaster->value)->toBe('R3_ROLLERCOASTER');
    });

    it('toArray tem a forma que period_reports grava', function () {
        $array = makeFinding()->toArray();

        expect($array)->toHaveKeys([
            'rule_id', 'severity', 'rank', 'evidence',
            'fallback_prose', 'requires_clinical_handoff',
        ]);
        expect($array['rule_id'])->toBe('R1_DAYPART_DRIFT');
        expect($array['rank'])->toBe(RuleId::DaypartDrift->rank());
    });

    it('sobrevive ao round-trip de JSON sem perder tipo', function () {
        // Lição da fase 1: JSON não distingue float de int, e `10.0` volta como
        // `int 10`. Aqui isso importa porque `evidence` vai ao prompt na fase 5.
        $finding = makeFinding(evidence: ['ratio' => 5.78, 'n' => 917, 'label' => 'tarde']);

        $decoded = json_decode(json_encode($finding->toArray(), JSON_THROW_ON_ERROR), true);

        expect($decoded['evidence']['ratio'])->toBe(5.78);
        expect($decoded['evidence']['n'])->toBe(917);
        expect($decoded['evidence']['label'])->toBe('tarde');
    });
});

describe('a interface Rule', function () {

    it('declara id() e evaluate(PatternDataset)', function () {
        $reflection = new ReflectionClass(Rule::class);

        expect($reflection->isInterface())->toBeTrue();

        $evaluate = $reflection->getMethod('evaluate');
        $parameter = $evaluate->getParameters()[0];

        // getName() sobre o tipo não autocarrega a classe — PatternDataset
        // chega em T302.
        expect($parameter->getType()?->getName())
            ->toBe(PatternDataset::class);
        expect($reflection->hasMethod('id'))->toBeTrue();
    });
});

<?php

declare(strict_types=1);

use App\Domain\Patterns\PatternEngine;
use App\Domain\Patterns\Rule;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\FindingSet;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;

/**
 * T308 — o motor: ordenação, isolamento de falha e conjunto vazio.
 */

/** Regra falsa que devolve os achados que você mandar. */
function fakeRule(RuleId $id, array $findings = [], ?Throwable $throws = null): Rule
{
    return new class($id, $findings, $throws) implements Rule
    {
        public function __construct(
            private RuleId $id,
            private array $findings,
            private ?Throwable $throws,
        ) {}

        public function id(): RuleId
        {
            return $this->id;
        }

        public function evaluate(PatternDataset $dataset): array
        {
            if ($this->throws !== null) {
                throw $this->throws;
            }

            return $this->findings;
        }
    };
}

function fakeFinding(RuleId $id, Severity $severity = Severity::Attention): Finding
{
    return new Finding(
        ruleId: $id,
        severity: $severity,
        evidence: ['valor' => 1],
        fallbackProse: 'prosa de '.$id->value,
        requiresClinicalHandoff: $id->requiresClinicalHandoff(),
    );
}

describe('a ordenação', function () {

    it('ordena por severidade e depois por rank', function () {
        $engine = new PatternEngine([
            fakeRule(RuleId::CalibrationBurden, [fakeFinding(RuleId::CalibrationBurden, Severity::Info)]),
            fakeRule(RuleId::DaypartDrift, [fakeFinding(RuleId::DaypartDrift, Severity::Priority)]),
            fakeRule(RuleId::HypoCluster, [fakeFinding(RuleId::HypoCluster, Severity::Attention)]),
            fakeRule(RuleId::OutlierDay, [fakeFinding(RuleId::OutlierDay, Severity::Attention)]),
        ]);

        $set = $engine->run(makePatternDataset());

        $ordem = array_map(fn (Finding $f): string => $f->ruleId->value, $set->findings);

        // Priority primeiro. Depois os dois Attention, entre si por rank
        // (HypoCluster = 1, OutlierDay = 2). Info por último.
        expect($ordem)->toBe([
            'R1_DAYPART_DRIFT',
            'R2_HYPO_CLUSTER',
            'R4_OUTLIER_DAY',
            'R9_CALIBRATION_BURDEN',
        ]);
    });

    // ⚠️ Se dependesse, reordenar o array do service provider por estética
    // mudaria o que o usuário lê primeiro — acoplamento invisível entre
    // configuração e produto.
    it('a saída NÃO depende da ordem de registro', function () {
        $regras = [
            fakeRule(RuleId::SensorQuality, [fakeFinding(RuleId::SensorQuality, Severity::Info)]),
            fakeRule(RuleId::HypoCluster, [fakeFinding(RuleId::HypoCluster, Severity::Priority)]),
            fakeRule(RuleId::SensorAdherence, [fakeFinding(RuleId::SensorAdherence, Severity::Attention)]),
        ];

        $direta = (new PatternEngine($regras))->run(makePatternDataset());
        $invertida = (new PatternEngine(array_reverse($regras)))->run(makePatternDataset());

        expect(array_map(fn (Finding $f): string => $f->ruleId->value, $direta->findings))
            ->toBe(array_map(fn (Finding $f): string => $f->ruleId->value, $invertida->findings));
    });

    // R4 pode emitir dois achados com o mesmo ruleId e o mesmo rank. A ordenação
    // do PHP 8 é estável, então eles mantêm a ordem em que a regra os produziu.
    it('mantém a ordem entre achados da mesma regra', function () {
        $primeiro = new Finding(RuleId::OutlierDay, Severity::Attention, ['metric' => 'above_250'], 'a');
        $segundo = new Finding(RuleId::OutlierDay, Severity::Attention, ['metric' => 'below_70'], 'b');

        $set = (new PatternEngine([fakeRule(RuleId::OutlierDay, [$primeiro, $segundo])]))
            ->run(makePatternDataset());

        expect($set->findings[0]->evidence['metric'])->toBe('above_250');
        expect($set->findings[1]->evidence['metric'])->toBe('below_70');
    });
});

describe('o isolamento de falha', function () {

    // ⚠️ A diferença entre "o motor está fora do ar" e "nove dos dez achados
    // apareceram". Uma exceção em R10, que é informativa, não pode esconder o
    // cluster de hipoglicemias de R2.
    it('uma regra que lança não derruba as outras', function () {
        $set = (new PatternEngine([
            fakeRule(RuleId::SensorQuality, throws: new RuntimeException('divisão por zero')),
            fakeRule(RuleId::HypoCluster, [fakeFinding(RuleId::HypoCluster, Severity::Priority)]),
            fakeRule(RuleId::OutlierDay, [fakeFinding(RuleId::OutlierDay)]),
        ]))->run(makePatternDataset());

        expect($set->findings)->toHaveCount(2);
        expect($set->hasFailures())->toBeTrue();
        expect($set->failures)->toHaveCount(1);
        expect($set->failures[0]['rule_id'])->toBe('R10_SENSOR_QUALITY');
        expect($set->failures[0]['message'])->toBe('divisão por zero');
    });

    it('registra a falha em vez de engoli-la', function () {
        // Sem o registro, a regra sumiria em silêncio e o relatório pareceria
        // completo com nove achados.
        $set = (new PatternEngine([
            fakeRule(RuleId::Rollercoaster, throws: new LogicException('sem nadir')),
        ]))->run(makePatternDataset());

        expect($set->isEmpty())->toBeTrue();
        expect($set->hasFailures())->toBeTrue();
    });

    it('todas as regras falhando devolve conjunto vazio com as falhas', function () {
        $set = (new PatternEngine([
            fakeRule(RuleId::DaypartDrift, throws: new RuntimeException('a')),
            fakeRule(RuleId::HypoCluster, throws: new RuntimeException('b')),
        ]))->run(makePatternDataset());

        expect($set->isEmpty())->toBeTrue();
        expect($set->failures)->toHaveCount(2);
    });
});

describe('o conjunto vazio (§D10)', function () {

    // ⚠️ Zero achado é RESULTADO, não erro. Período sem nenhum padrão é boa
    // notícia, e a tela precisa poder dizer isso sem inventar achado de
    // enchimento.
    it('nenhuma regra disparando devolve conjunto vazio, sem erro', function () {
        $set = (new PatternEngine([
            fakeRule(RuleId::DaypartDrift),
            fakeRule(RuleId::HypoCluster),
        ]))->run(makePatternDataset());

        expect($set)->toBeInstanceOf(FindingSet::class);
        expect($set->isEmpty())->toBeTrue();
        expect($set->count())->toBe(0);
        expect($set->hasFailures())->toBeFalse();
    });

    it('motor sem regra nenhuma também devolve vazio', function () {
        expect((new PatternEngine([]))->run(makePatternDataset())->isEmpty())->toBeTrue();
    });
});

describe('o FindingSet', function () {

    it('filtra por regra e por severidade', function () {
        $set = (new PatternEngine([
            fakeRule(RuleId::HypoCluster, [fakeFinding(RuleId::HypoCluster, Severity::Priority)]),
            fakeRule(RuleId::CalibrationBurden, [fakeFinding(RuleId::CalibrationBurden, Severity::Info)]),
        ]))->run(makePatternDataset());

        expect($set->ofRule(RuleId::HypoCluster))->toHaveCount(1);
        expect($set->ofRule(RuleId::Rollercoaster))->toBe([]);
        expect($set->ofSeverity(Severity::Priority))->toHaveCount(1);
        expect($set->ofSeverity(Severity::Attention))->toBe([]);
    });

    it('destaca o achado que exige encaminhamento clínico', function () {
        $set = (new PatternEngine([
            fakeRule(RuleId::CarbRatioCoherence, [fakeFinding(RuleId::CarbRatioCoherence)]),
            fakeRule(RuleId::HypoCluster, [fakeFinding(RuleId::HypoCluster)]),
        ]))->run(makePatternDataset());

        $handoff = $set->requiringClinicalHandoff();

        expect($handoff)->toHaveCount(1);
        expect($handoff[0]->ruleId)->toBe(RuleId::CarbRatioCoherence);
    });

    it('toArray tem a forma que period_reports grava', function () {
        $set = (new PatternEngine([
            fakeRule(RuleId::HypoCluster, [fakeFinding(RuleId::HypoCluster)]),
            fakeRule(RuleId::SensorQuality, throws: new RuntimeException('x')),
        ]))->run(makePatternDataset());

        $array = $set->toArray();

        expect($array)->toHaveKeys(['findings', 'rule_failures', 'finding_count']);
        expect($array['finding_count'])->toBe(1);
        expect($array['rule_failures'])->toHaveCount(1);
        expect($array['findings'][0]['rule_id'])->toBe('R2_HYPO_CLUSTER');
    });
});

it('a versão do motor segue a convenção AAAA.MM.N', function () {
    expect(PatternEngine::VERSION)->toMatch('/^\d{4}\.\d{2}\.\d+$/');
});

<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Value;

/**
 * As dez regras do motor de padrões, e tudo que se sabe sobre elas sem olhar
 * seus dados.
 *
 * ⚠️ **Este enum é a fonte única de rank, teto de severidade, encaminhamento
 * clínico e chaves de configuração exigidas.** Cada um desses quatro é decisão
 * de produto, não detalhe de implementação — e distribuí-los como constante
 * dentro de dez classes garantiria que um dia divergiriam.
 *
 * O valor de cada case é o `ruleId` que aparece no JSON persistido e, na fase 5,
 * no payload da narrativa. **Renomear um valor daqui é mudança de versão do
 * motor** (`PatternEngine::VERSION`), porque relatórios já gravados o carregam.
 */
enum RuleId: string
{
    case DaypartDrift = 'R1_DAYPART_DRIFT';
    case HypoCluster = 'R2_HYPO_CLUSTER';
    case Rollercoaster = 'R3_ROLLERCOASTER';
    case OutlierDay = 'R4_OUTLIER_DAY';
    case SensorGapLoopImpact = 'R5_SENSOR_GAP_LOOP_IMPACT';
    case CarbRatioCoherence = 'R6_CARB_RATIO_COHERENCE';
    case SensorAdherence = 'R7_SENSOR_ADHERENCE';
    case ReservoirChanges = 'R8_RESERVOIR_CHANGES';
    case CalibrationBurden = 'R9_CALIBRATION_BURDEN';
    case SensorQuality = 'R10_SENSOR_QUALITY';

    /**
     * Ordem de exibição dentro da mesma severidade. Menor aparece primeiro.
     *
     * A ordem é uma decisão de produto, e a razão dela é o Artigo IV:
     *
     *   1. **Hipoglicemia primeiro** — é o risco agudo, e é o único achado em que
     *      atrasar a leitura tem custo real.
     *   2. **O reenquadramento antes do detalhe.** R4 diz "veio de um único dia";
     *      R3 conta o que foi aquele dia. Nessa ordem, a pessoa lê a perspectiva
     *      antes do episódio ruim. Invertido, ela lê a acusação e só depois o
     *      alívio — e a maioria não chega ao alívio.
     *   3. **Padrão recorrente antes de evento isolado**, porque é o que se
     *      repete que compensa levar ao médico.
     *   4. **Equipamento e informativo no fim.** Não porque não importem, mas
     *      porque não mudam a leitura que a pessoa faz de si mesma.
     */
    public function rank(): int
    {
        return match ($this) {
            self::HypoCluster => 1,
            self::OutlierDay => 2,
            self::Rollercoaster => 3,
            self::DaypartDrift => 4,
            self::SensorGapLoopImpact => 5,
            self::CarbRatioCoherence => 6,
            self::SensorAdherence => 7,
            self::ReservoirChanges => 8,
            self::SensorQuality => 9,
            self::CalibrationBurden => 10,
        };
    }

    /**
     * Teto de severidade da regra.
     *
     * ⚠️ R9 tem teto em `Info` **por requisito** (FR-411). "2,8 picadas de dedo
     * por dia" lido por quem não conhece o equipamento soa como cobrança — e o
     * Guardian Sensor 3 **exige** calibração. O número é característica do
     * aparelho, não escolha da pessoa. Uma regra que só conta linhas ainda pode
     * violar o Artigo IV, e o teto é o que impede.
     */
    public function maxSeverity(): Severity
    {
        return match ($this) {
            self::CalibrationBurden => Severity::Info,
            default => Severity::Priority,
        };
    }

    /**
     * A regra termina devolvendo a pergunta ao médico?
     *
     * ⚠️ R6 cruza configuração da bomba com resultado glicêmico — é a regra que
     * mais se aproxima de conduta médica. Ela **descreve a observação e devolve
     * a pergunta**; nunca propõe valor novo de CR, basal ou ISF.
     *
     * Isto é o Artigo VI, camada 3, imposto por construção: `Finding` recusa um
     * achado de R6 sem o encaminhamento. Convenção de texto se perde num
     * refactor; validação de construtor não.
     */
    public function requiresClinicalHandoff(): bool
    {
        return $this === self::CarbRatioCoherence;
    }

    /** Chave desta regra em `config/patterns.php`. */
    public function configKey(): string
    {
        return match ($this) {
            self::DaypartDrift => 'r1',
            self::HypoCluster => 'r2',
            self::Rollercoaster => 'r3',
            self::OutlierDay => 'r4',
            self::SensorGapLoopImpact => 'r5',
            self::CarbRatioCoherence => 'r6',
            self::SensorAdherence => 'r7',
            self::ReservoirChanges => 'r8',
            self::CalibrationBurden => 'r9',
            self::SensorQuality => 'r10',
        };
    }

    /**
     * Limiares que a regra exige em configuração.
     *
     * `PatternsConfig` valida esta lista **na construção**, para todas as dez
     * regras de uma vez. Config incompleta explode ao inicializar o container,
     * não no meio de um cálculo devolvendo `null` como se fosse zero.
     *
     * @return list<string>
     */
    public function requiredConfigKeys(): array
    {
        return match ($this) {
            self::DaypartDrift => ['min_readings_per_daypart', 'ratio_threshold', 'priority_ratio'],
            self::HypoCluster => ['window_hours', 'min_episodes', 'max_windows', 'concentration_threshold'],
            self::Rollercoaster => ['window_hours', 'carbs_threshold_g'],
            self::OutlierDay => ['pareto_threshold', 'min_total_readings'],
            self::SensorGapLoopImpact => ['min_gap_minutes', 'auto_insulin_drop_ratio'],
            self::CarbRatioCoherence => ['min_boluses_per_daypart', 'min_ratio_spread_g'],
            self::SensorAdherence => ['coverage_threshold'],
            self::ReservoirChanges => ['min_rewinds'],
            self::CalibrationBurden => ['min_calibrations'],
            self::SensorQuality => ['pairing_minutes', 'min_pairs'],
        };
    }

    /** Chave da prosa em `lang/pt_BR/patterns.php`. */
    public function langKey(): string
    {
        return 'patterns.'.$this->configKey();
    }
}

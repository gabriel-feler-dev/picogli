<?php

declare(strict_types=1);

use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\Value\RuleId;

/**
 * T301 — a borda: `config/patterns.php` real, resolvido pelo container.
 *
 * Separado do teste de unidade porque **este** precisa do Laravel. A validação
 * do value object roda sem container (`tests/Unit/Domain/Patterns/`), e é essa
 * separação que mantém a pureza do domínio verificável em vez de aspiracional.
 */
it('o arquivo de configuração é válido e completo', function () {
    // Se este teste falha, o container não sobe — que é exatamente o ponto:
    // config incompleta explode cedo, com o nome da chave que falta.
    expect(PatternsConfig::fromArray(config('patterns'))->rules)->toHaveCount(10);
});

it('o container entrega a config já validada', function () {
    expect(app(PatternsConfig::class))->toBeInstanceOf(PatternsConfig::class);
    expect(app(PatternsConfig::class))->toBe(app(PatternsConfig::class)); // singleton
});

/**
 * ⚠️ O teste que impede o limiar mal calibrado.
 *
 * Um limiar acima do valor real do export faria a regra **não disparar** sobre o
 * arquivo de referência — e a suíte inteira de T303–T307 ficaria verde testando
 * apenas o caminho negativo. Isto confere, contra o gabarito §Fase 4, que cada
 * regra tem espaço para disparar antes de existir uma linha dela.
 */
it('os limiares deixam as dez regras dispararem no export de referência', function () {
    $config = PatternsConfig::fromArray(config('patterns'));

    // R1 — 5,78x apurado em T300 (tarde 24,10% ÷ madrugada 4,17%).
    expect($config->threshold(RuleId::DaypartDrift, 'ratio_threshold'))->toBeLessThan(5.78);
    expect($config->threshold(RuleId::DaypartDrift, 'priority_ratio'))->toBeLessThan(5.78);
    // Os períodos do export têm entre 831 e 936 leituras.
    expect($config->threshold(RuleId::DaypartDrift, 'min_readings_per_daypart'))->toBeLessThan(831);

    // R2 — 5 episódios de hipo, 100% em 2 janelas.
    expect($config->threshold(RuleId::HypoCluster, 'min_episodes'))->toBeLessThanOrEqual(5);
    expect($config->threshold(RuleId::HypoCluster, 'concentration_threshold'))->toBeLessThanOrEqual(1.0);
    expect($config->threshold(RuleId::HypoCluster, 'max_windows'))->toBeGreaterThanOrEqual(2);

    // R3 — 109 g em 3 refeições, dentro de ~3,5 h do nadir das 17:56.
    expect($config->threshold(RuleId::Rollercoaster, 'carbs_threshold_g'))->toBeLessThan(109.0);
    expect($config->threshold(RuleId::Rollercoaster, 'window_hours'))->toBeGreaterThanOrEqual(4);

    // R4 — 25/07 com 71,4% de 70 leituras >250 (§D7).
    expect($config->threshold(RuleId::OutlierDay, 'pareto_threshold'))->toBeLessThan(0.714);
    expect($config->threshold(RuleId::OutlierDay, 'min_total_readings'))->toBeLessThanOrEqual(70);

    // R5 — lacuna de 1.347 min; automática de 9,0 U contra média de 31,4 U (queda de 71%).
    expect($config->threshold(RuleId::SensorGapLoopImpact, 'min_gap_minutes'))->toBeLessThan(1347);
    expect($config->threshold(RuleId::SensorGapLoopImpact, 'auto_insulin_drop_ratio'))
        ->toBeLessThan(1.0 - (9.0 / 31.4));

    // R6 — CR de 5,0 a 8,0 g/U = espalhamento de 3,0; períodos com ≥3 bolus.
    expect($config->threshold(RuleId::CarbRatioCoherence, 'min_ratio_spread_g'))->toBeLessThan(3.0);
    expect($config->threshold(RuleId::CarbRatioCoherence, 'min_boluses_per_daypart'))
        ->toBeLessThanOrEqual(5);

    // R7 — 22/07 com 34% abaixo do limiar, 21/07 com 73% acima. O limiar não é
    // aproximado: se fosse 0,75, o 21/07 entraria e a regra acusaria dois dias.
    expect(0.34)->toBeLessThan($config->threshold(RuleId::SensorAdherence, 'coverage_threshold'));
    expect(0.73)->toBeGreaterThan($config->threshold(RuleId::SensorAdherence, 'coverage_threshold'));

    // R8 — 3 rewinds, 2 intervalos observados (§D8).
    expect($config->threshold(RuleId::ReservoirChanges, 'min_rewinds'))->toBeLessThanOrEqual(3);

    // R9 — 39 calibrações em 14 dias.
    expect($config->threshold(RuleId::CalibrationBurden, 'min_calibrations'))->toBeLessThanOrEqual(39);

    // R10 — 39 pares, janela de ±10 min. A janela É evidência: sem ela o erro
    // médio de 10,7% não é reproduzível.
    expect($config->threshold(RuleId::SensorQuality, 'min_pairs'))->toBeLessThanOrEqual(39);
    expect($config->threshold(RuleId::SensorQuality, 'pairing_minutes'))->toBe(10);
});

/**
 * Se os períodos do dia morassem em `patterns.php`, o dashboard e o motor
 * poderiam discordar sobre o que é "tarde". São definição clínica, não limiar.
 */
it('os períodos do dia vivem em clinical.php, não em patterns.php', function () {
    expect(config('clinical.dayparts'))->toHaveCount(4);
    expect(config('patterns.dayparts'))->toBeNull();
});

it('os quatro períodos cobrem as 24 horas sem sobreposição (D6)', function () {
    $horas = [];

    foreach (config('clinical.dayparts') as $daypart) {
        for ($hora = $daypart['from']; $hora <= $daypart['to']; $hora++) {
            expect($horas)->not->toContain($hora);
            $horas[] = $hora;
        }
    }

    sort($horas);
    expect($horas)->toBe(range(0, 23));
});

/**
 * FR-416 / Artigo X — nenhuma linha de IA nesta fase.
 *
 * Vale conferir já em T301: `PatternsConfig` é o primeiro arquivo do motor, e é
 * onde uma chave de API apareceria "só para deixar preparado".
 */
it('a configuração do motor não menciona provedor de IA', function () {
    $conteudo = file_get_contents(config_path('patterns.php'));

    foreach (['gemini', 'openai', 'anthropic', 'api_key', 'GEMINI'] as $proibido) {
        expect(str_contains(strtolower($conteudo), strtolower($proibido)))->toBeFalse(
            "config/patterns.php menciona '{$proibido}' — Artigo X proíbe IA antes da fase 5."
        );
    }
});

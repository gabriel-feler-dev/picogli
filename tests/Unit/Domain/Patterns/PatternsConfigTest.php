<?php

declare(strict_types=1);

use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\Value\RuleId;

/**
 * T301 — FR-401, D4. Limiar é configuração, não literal.
 *
 * ⚠️ O modo de falha que estes testes previnem é **silencioso**. Um limiar
 * ausente devolve `null`, `null >= 2.0` é `false`, e a regra simplesmente deixa
 * de disparar. Nada quebra: as telas funcionam e o relatório fica vazio,
 * parecendo boa notícia.
 *
 * Este arquivo roda **sem container** (ver `tests/Pest.php`). A conferência do
 * `config/patterns.php` de verdade vive em
 * `tests/Feature/Patterns/PatternsConfigWiringTest.php` — é ela que precisa do
 * Laravel, e a separação é o que mantém a pureza do domínio verificável.
 */
function fullRulesConfig(): array
{
    $rules = [];

    foreach (RuleId::cases() as $rule) {
        $rules[$rule->configKey()] = array_fill_keys($rule->requiredConfigKeys(), 1.0);
    }

    return $rules;
}

describe('validação exaustiva contra o enum', function () {

    it('constrói com as dez regras completas', function () {
        expect((new PatternsConfig(fullRulesConfig()))->rules)->toHaveCount(10);
    });

    it('lança se falta uma regra, dizendo qual', function () {
        $rules = fullRulesConfig();
        unset($rules['r5']);

        expect(fn () => new PatternsConfig($rules))
            ->toThrow(InvalidArgumentException::class, "'r5'");
    });

    it('lança se falta um limiar, dizendo qual', function () {
        $rules = fullRulesConfig();
        unset($rules['r2']['concentration_threshold']);

        expect(fn () => new PatternsConfig($rules))
            ->toThrow(InvalidArgumentException::class, 'concentration_threshold');
    });

    it('lança se um limiar não é numérico', function () {
        $rules = fullRulesConfig();
        $rules['r4']['pareto_threshold'] = '40%';

        expect(fn () => new PatternsConfig($rules))
            ->toThrow(InvalidArgumentException::class, 'numérico');
    });

    it('lança se a entrada da regra não é array', function () {
        $rules = fullRulesConfig();
        $rules['r7'] = 0.70;

        expect(fn () => new PatternsConfig($rules))
            ->toThrow(InvalidArgumentException::class, 'array de limiares');
    });

    // Autoteste da validação: sem isto, um `requiredConfigKeys()` que devolvesse
    // sempre `[]` faria todos os testes acima passarem sem validar nada.
    it('toda regra declara pelo menos um limiar exigido', function () {
        foreach (RuleId::cases() as $rule) {
            expect(count($rule->requiredConfigKeys()))->toBeGreaterThan(0);
        }
    });

    it('as dez chaves de configuração são distintas', function () {
        $keys = array_map(fn (RuleId $rule): string => $rule->configKey(), RuleId::cases());

        expect(array_unique($keys))->toHaveCount(10);
    });
});

describe('acesso aos limiares', function () {

    it('for() devolve os limiares da regra', function () {
        expect((new PatternsConfig(fullRulesConfig()))->for(RuleId::HypoCluster))
            ->toHaveKeys(['window_hours', 'min_episodes', 'max_windows', 'concentration_threshold']);
    });

    it('threshold() devolve o valor', function () {
        $rules = fullRulesConfig();
        $rules['r1']['ratio_threshold'] = 2.0;

        expect((new PatternsConfig($rules))->threshold(RuleId::DaypartDrift, 'ratio_threshold'))
            ->toBe(2.0);
    });

    // ⚠️ Pega a regra que USA um limiar mas esqueceu de declará-lo: a declaração
    // incompleta passa pelo construtor e reapareceria aqui como null.
    it('threshold() lança para limiar não declarado', function () {
        expect(fn () => (new PatternsConfig(fullRulesConfig()))
            ->threshold(RuleId::SensorAdherence, 'limiar_fantasma'))
            ->toThrow(InvalidArgumentException::class, 'requiredConfigKeys');
    });
});

describe('pureza do domínio', function () {

    // A razão de PatternsConfig existir: o domínio não chama config().
    it('constrói sem container, a partir de array literal', function () {
        $config = PatternsConfig::fromArray(['rules' => fullRulesConfig()]);

        expect($config->rules)->toHaveCount(10);
    });

    it('fromArray sem a chave rules lança em vez de aceitar vazio', function () {
        expect(fn () => PatternsConfig::fromArray([]))
            ->toThrow(InvalidArgumentException::class);
    });
});

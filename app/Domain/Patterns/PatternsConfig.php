<?php

declare(strict_types=1);

namespace App\Domain\Patterns;

use App\Domain\Patterns\Value\RuleId;
use InvalidArgumentException;

/**
 * Limiares de disparo das dez regras, injetados no domínio.
 *
 * ⚠️ O domínio NÃO chama `config()`. Quem lê `config/patterns.php` é a borda
 * (service provider, Job); aqui chega um array já resolvido. Mesma decisão de
 * `MetricsConfig`, e pelo mesmo motivo: é o que mantém a suíte de unidade rápida
 * e a separação verificável.
 *
 * ## Por que os limiares não são literais dentro das regras
 *
 * `≥2×` de R1, `≥60% em ≤2 janelas` de R2, `≥40%` de R4 são **decisões de
 * produto**, e vão ser ajustadas quando o motor rodar sobre um segundo export.
 * Literal enterrado numa regra transforma ajuste em caça ao código — e faz o
 * teste afirmar "dispara com razão 5,78×" sem deixar claro se está testando a
 * regra ou o limiar.
 *
 * ## Por que a validação é exaustiva contra o enum
 *
 * O construtor confere que **as dez regras** têm entrada e que cada uma tem as
 * chaves que ela mesma declara em `RuleId::requiredConfigKeys()`. Uma regra nova
 * sem configuração quebra ao inicializar o container, com o nome da chave que
 * falta — não devolve `null` no meio de uma comparação, onde `null >= 2.0` é
 * `false` e a regra simplesmente para de disparar em silêncio.
 *
 * **É esse silêncio o modo de falha que importa aqui.** Um motor de padrões que
 * deixa de detectar não quebra nada: as telas continuam funcionando e o
 * relatório fica vazio, parecendo boa notícia.
 */
final readonly class PatternsConfig
{
    /**
     * @param  array<string, array<string, int|float>>  $rules  limiar por regra, indexado por `RuleId::configKey()`
     */
    public function __construct(
        // Sem default de propósito — ver o bloco acima.
        public array $rules,
    ) {
        foreach (RuleId::cases() as $rule) {
            $key = $rule->configKey();

            if (! array_key_exists($key, $this->rules)) {
                throw new InvalidArgumentException(
                    "config/patterns.php não tem a chave '{$key}' exigida por "
                    ."{$rule->value}."
                );
            }

            if (! is_array($this->rules[$key])) {
                throw new InvalidArgumentException(
                    "config/patterns.php: '{$key}' deve ser array de limiares."
                );
            }

            foreach ($rule->requiredConfigKeys() as $required) {
                if (! array_key_exists($required, $this->rules[$key])) {
                    throw new InvalidArgumentException(
                        "config/patterns.php: '{$key}' não tem o limiar "
                        ."'{$required}' exigido por {$rule->value}."
                    );
                }

                if (! is_int($this->rules[$key][$required]) && ! is_float($this->rules[$key][$required])) {
                    throw new InvalidArgumentException(
                        "config/patterns.php: '{$key}.{$required}' deve ser "
                        .'numérico, e é '.get_debug_type($this->rules[$key][$required]).'.'
                    );
                }
            }
        }
    }

    /**
     * Limiares de uma regra.
     *
     * @return array<string, int|float>
     */
    public function for(RuleId $rule): array
    {
        return $this->rules[$rule->configKey()];
    }

    /**
     * Um limiar específico.
     *
     * Lança se a chave não existir — inclusive para chave que a regra usa mas
     * esqueceu de declarar em `requiredConfigKeys()`. Sem isso, a declaração
     * incompleta passaria pela validação do construtor e reapareceria aqui como
     * `null`.
     */
    public function threshold(RuleId $rule, string $key): int|float
    {
        $thresholds = $this->for($rule);

        if (! array_key_exists($key, $thresholds)) {
            throw new InvalidArgumentException(
                "{$rule->value} pediu o limiar '{$key}', que não está em "
                ."config/patterns.php ('{$rule->configKey()}'). Se a regra usa "
                .'este limiar, declare-o em RuleId::requiredConfigKeys().'
            );
        }

        return $thresholds[$key];
    }

    /** @param array<string, mixed> $patterns conteúdo de config/patterns.php */
    public static function fromArray(array $patterns): self
    {
        return new self(rules: $patterns['rules'] ?? []);
    }
}

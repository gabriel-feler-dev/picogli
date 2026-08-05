<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use InvalidArgumentException;

/**
 * A cadeia de modelos, com cooldown por tipo de erro (FR-502, §D4).
 *
 * ## Sempre do topo, nunca "onde parou"
 *
 * ⚠️ Cada tentativa **percorre a cadeia desde o melhor modelo**, saltando os que
 * estão de castigo. Não existe estado de "em que modelo eu estava".
 *
 * *Por quê:* é o que faz o requisito "antes de descer, veja se um anterior já
 * voltou" acontecer **por construção**. Um cursor que descesse e ficasse embaixo
 * continuaria usando o modelo mais fraco depois de o melhor ter voltado — e
 * ninguém notaria, porque o texto continuaria saindo.
 *
 * ## Cooldown por tipo de erro
 *
 * A API devolve 429 tanto para limite por minuto (volta em ~1 min) quanto para
 * cota diária (volta na virada do dia). O castigo vem de
 * `cooldownSeconds[$failure->value]` — tratar os dois igual faria o sistema bater
 * no modelo esgotado a cada requisição, o dia inteiro.
 *
 * ## Nunca lança para cima
 *
 * ⚠️ Todos os modelos indisponíveis → devolve `null`. **Exceção de IA não chega à
 * tela** (Artigo I): quem chama cai para o `fallbackProse`, que já é publicável.
 */
final class ModelChain
{
    /**
     * @param  list<string>  $models  ordenados por QUALIDADE, melhor primeiro
     * @param  array<string, int>  $cooldownSeconds  indexado por `ProviderFailure::value`
     */
    public function __construct(
        private readonly array $models,
        private readonly array $cooldownSeconds,
        private readonly CooldownStore $store,
    ) {
        if ($this->models === []) {
            throw new InvalidArgumentException(
                'A cadeia de modelos está vazia. Confira config/ai.model_chain.'
            );
        }
    }

    /**
     * Tenta cada modelo disponível, do melhor para o mais fraco.
     *
     * @template T
     *
     * @param  callable(string): T  $attempt  recebe o nome do modelo
     * @return T|null `null` quando nenhum modelo atendeu
     */
    public function attempt(callable $attempt): mixed
    {
        foreach ($this->available() as $model) {
            try {
                return $attempt($model);
            } catch (ProviderUnavailable $exception) {
                $this->penalise($model, $exception->failure);

                // ⚠️ Chave inválida é a mesma para todos os modelos: descer na
                // cadeia só gastaria tempo para receber a mesma recusa.
                if (! $exception->failure->allowsFallbackToNextModel()) {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Os modelos que não estão de castigo, na ordem de qualidade.
     *
     * @return list<string>
     */
    public function available(): array
    {
        return array_values(array_filter(
            $this->models,
            fn (string $model): bool => ! $this->store->isCoolingDown($model),
        ));
    }

    public function penalise(string $model, ProviderFailure $failure): void
    {
        if (! $failure->deservesCooldown()) {
            // `BadResponse` não põe o modelo de castigo: esconderia um problema
            // de parsing atrás de um cooldown de horas, e o sintoma reportado
            // seria "a IA parou de funcionar".
            return;
        }

        $seconds = $this->cooldownSeconds[$failure->value] ?? null;

        if ($seconds === null || $seconds <= 0) {
            throw new InvalidArgumentException(
                "Falta cooldown para '{$failure->value}' em config/ai.cooldown_seconds. "
                .'Sem ele o modelo seria retentado imediatamente, em laço.'
            );
        }

        $this->store->penalise($model, $seconds);
    }

    /** Toda a cadeia está de castigo? */
    public function isExhausted(): bool
    {
        return $this->available() === [];
    }

    /**
     * Estado da cadeia, para log e diagnóstico.
     *
     * @return array<string, int|null> modelo → segundos restantes (`null` = livre)
     */
    public function status(): array
    {
        $status = [];

        foreach ($this->models as $model) {
            $status[$model] = $this->store->remainingSeconds($model);
        }

        return $status;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * Por que uma chamada ao provedor falhou.
 *
 * ⚠️ **Esta classificação é o motivo de a cadeia de modelos funcionar.** Dois
 * erros que a API devolve com o MESMO código HTTP 429 têm escalas de tempo
 * completamente diferentes:
 *
 * | Falha | Volta em |
 * |---|---|
 * | `RateLimitPerMinute` | ~1 minuto |
 * | `QuotaExhausted` | virada do dia, no fuso do provedor |
 *
 * Tratar os dois igual faria o sistema bater no modelo esgotado a cada
 * requisição, o dia inteiro — gastando latência para receber a mesma recusa.
 *
 * `Unauthorized` é o caso em que descer na cadeia **não ajuda**: a chave é a
 * mesma para todos os modelos. A cadeia precisa saber disso para não gastar três
 * tentativas confirmando o óbvio.
 */
enum ProviderFailure: string
{
    case RateLimitPerMinute = 'rate_limit_per_minute';
    case QuotaExhausted = 'quota_exhausted';
    case Timeout = 'timeout';
    case BadResponse = 'bad_response';
    case Unauthorized = 'unauthorized';
    case Unknown = 'unknown';

    /**
     * Vale tentar o próximo modelo da cadeia?
     *
     * ⚠️ `Unauthorized` devolve `false`: a chave é a mesma para todos os modelos,
     * então descer na cadeia só gastaria tempo para receber a mesma recusa três
     * vezes. É o único caso em que a cadeia inteira está fora, não um modelo.
     */
    public function allowsFallbackToNextModel(): bool
    {
        return $this !== self::Unauthorized;
    }

    /** O modelo deve entrar em cooldown, ou a falha foi pontual? */
    public function deservesCooldown(): bool
    {
        return match ($this) {
            self::RateLimitPerMinute, self::QuotaExhausted, self::Timeout => true,
            // `BadResponse` e `Unknown` podem ser transitórios e não indicam
            // limite atingido — penalizar o modelo por eles esconderia um
            // problema de parsing atrás de um cooldown.
            self::BadResponse, self::Unknown, self::Unauthorized => false,
        };
    }
}

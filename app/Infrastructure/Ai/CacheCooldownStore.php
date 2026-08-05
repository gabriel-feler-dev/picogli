<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Domain\Ai\CooldownStore;
use Illuminate\Contracts\Cache\Repository;

/**
 * Cooldown persistido em cache, com TTL fazendo o trabalho de expiração.
 *
 * ⚠️ **Borda**, como `Persistence/` no domínio de padrões: é aqui que o relógio e
 * o armazenamento entram. `ModelChain` fica determinística e testável sem
 * congelar o tempo (NFR-401).
 *
 * ## Por que cache e não tabela
 *
 * O dado é **efêmero por natureza** — existe para expirar. Uma tabela exigiria
 * limpeza, índice por data e uma consulta a cada tentativa; o TTL do cache é
 * literalmente o cooldown, e a expiração é grátis.
 *
 * ⚠️ **Mas depende do driver.** Em produção a hospedagem compartilhada usa o
 * driver `database` (não há Redis — ADR-5), e é isso que dá a persistência entre
 * execuções do cron. Com driver `array`, o cooldown some entre requisições e a
 * cadeia perde a memória — que é exatamente o que §D4 existe para evitar.
 */
final class CacheCooldownStore implements CooldownStore
{
    private const PREFIX = 'ai:cooldown:';

    public function __construct(private readonly Repository $cache) {}

    public function isCoolingDown(string $model): bool
    {
        return $this->cache->has($this->key($model));
    }

    public function penalise(string $model, int $seconds): void
    {
        // Grava o instante em que o castigo termina, e não um booleano: é o que
        // permite `remainingSeconds()` dizer QUANTO falta, para log e para a
        // tela poder explicar por que não há narrativa.
        $this->cache->put($this->key($model), time() + $seconds, $seconds);
    }

    public function remainingSeconds(string $model): ?int
    {
        $until = $this->cache->get($this->key($model));

        if ($until === null) {
            return null;
        }

        $remaining = (int) $until - time();

        return $remaining > 0 ? $remaining : null;
    }

    public function release(string $model): void
    {
        $this->cache->forget($this->key($model));
    }

    private function key(string $model): string
    {
        return self::PREFIX.$model;
    }
}

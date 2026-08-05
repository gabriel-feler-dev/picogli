<?php

declare(strict_types=1);

namespace App\Domain\Ai\Value;

/**
 * O que — e apenas o que — sai em direção a um provedor de IA (Artigo VII).
 *
 * ⚠️ Se um dado não está aqui, ele **não sai**. Este objeto é a resposta à
 * pergunta "o que o provedor recebe?", e ela precisa caber num teste.
 *
 * `$droppedKeys` existe para que o descarte seja **visível**. O domínio é PHP
 * puro e não pode chamar `Log::` (NFR-401); então ele devolve o que descartou, e
 * a borda registra. Descarte silencioso seria pior que nenhuma allowlist, porque
 * pareceria que nada foi filtrado.
 */
final readonly class AiPayload
{
    /**
     * @param  array<string, int|float|string|bool|null>  $period
     * @param  list<array{rule_id: string, severity: string, rank: int, evidence: array<string, int|float|string|bool|null>}>  $findings
     * @param  list<string>  $droppedKeys  chaves recusadas pela allowlist
     */
    public function __construct(
        public array $period,
        public array $findings,
        public array $droppedKeys = [],
    ) {}

    public function hasDroppedKeys(): bool
    {
        return $this->droppedKeys !== [];
    }

    /** @return array<string, mixed> a forma que vai ao provedor */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'findings' => $this->findings,
        ];
    }

    /**
     * O payload serializado — é sobre ESTA string que o teste anti-vazamento
     * varre o cabeçalho do CSV.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}

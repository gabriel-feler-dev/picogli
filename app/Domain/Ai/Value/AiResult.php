<?php

declare(strict_types=1);

namespace App\Domain\Ai\Value;

use DateTimeImmutable;

/**
 * O texto que voltou do provedor, com procedência.
 *
 * ⚠️ `$model` e `$generatedAt` não são enfeite. Eles vão para
 * `period_reports.narrative_model` e `narrative_generated_at`, e respondem duas
 * perguntas que aparecem quando um texto sai estranho: **qual modelo escreveu** e
 * **quando**. Sem isso, investigar uma narrativa ruim começa por adivinhar.
 *
 * ⚠️ `$generatedAt` é **injetado**, não capturado com `now()`. O domínio é puro
 * (NFR-401), e um objeto que lê o relógio no construtor não é testável sem
 * congelar o tempo.
 */
final readonly class AiResult
{
    public function __construct(
        public string $text,
        public string $model,
        public DateTimeImmutable $generatedAt,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    public function wordCount(): int
    {
        return count(preg_split('/\s+/u', trim($this->text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}

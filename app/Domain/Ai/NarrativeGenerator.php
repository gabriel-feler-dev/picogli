<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Value\AiResult;
use App\Domain\Ai\Value\DiscardReason;
use App\Domain\Ai\Value\NarrativeAttempt;

/**
 * Orquestra a geração da narrativa (FR-504, FR-505).
 *
 * ```
 * achados -> PayloadSanitizer -> PromptBuilder -> ModelChain -> NumberGuard
 *                (Artigo VII)                       (§D4)        (§D5)
 * ```
 *
 * ## Uma chamada por relatório, não uma por achado (§D7)
 *
 * ⚠️ Dez chamadas custariam dez vezes mais e produziriam dez parágrafos
 * desconexos. Um texto que conecta "a tarde é o pior período" com "e o 25/07
 * responde por 71% do tempo muito alto" **só é possível vendo os dois juntos** — e
 * é exatamente essa conexão que justifica ter IA aqui, já que os cartões da tela
 * mostram cada achado isolado.
 *
 * ## Nunca lança, nunca deixa erro chegar à tela
 *
 * ⚠️ Todo caminho de falha — cadeia esgotada, resposta vazia, número inventado,
 * saída em fuga — devolve um `NarrativeAttempt` **descartado**, com a razão. A
 * tela cai para o `fallbackProse` e o usuário vê o que veria ontem (Artigo I).
 *
 * *Isso só é aceitável porque o fallback é publicável.* Se fosse `"R3 disparou"`,
 * descartar deixaria a tela pior que antes e cada uma destas guardas viraria
 * negociável.
 */
final class NarrativeGenerator
{
    public function __construct(
        private readonly PayloadSanitizer $sanitizer,
        private readonly PromptBuilder $prompt,
        private readonly ModelChain $chain,
        private readonly Provider $provider,
        private readonly NumberGuard $guard,
        private readonly int $maxWords,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $findings  entradas de `period_reports.findings`
     * @param  array<string, mixed>  $period
     */
    public function generate(array $findings, array $period): NarrativeAttempt
    {
        // ⚠️ Período sem padrão nenhum não vira narrativa: não há o que conectar,
        // e a tela já diz isso melhor (§D10). Gerar texto aqui seria produzir
        // enchimento — o oposto do que a fase 4 decidiu.
        if ($findings === []) {
            return NarrativeAttempt::discarded(DiscardReason::NothingToNarrate);
        }

        // A porta do Artigo VII, sempre primeiro.
        $payload = $this->sanitizer->sanitize($findings, $period);
        $prompt = $this->prompt->build($payload);

        /** @var AiResult|null $result */
        $result = $this->chain->attempt(
            fn (string $model): AiResult => $this->provider->generate($model, $prompt, $payload),
        );

        if ($result === null) {
            // A cadeia já penalizou o que devia; ela nunca lança para cima.
            return NarrativeAttempt::discarded(DiscardReason::NoModelAvailable);
        }

        if ($result->isEmpty()) {
            return NarrativeAttempt::discarded(DiscardReason::EmptyResponse);
        }

        // ⚠️ Margem de 50% sobre o teto: não é policiamento de estilo, é proteção
        // contra saída em fuga. Um texto de mil palavras quebra o layout e quase
        // certamente significa que o modelo ignorou o prompt.
        if ($result->wordCount() > $this->maxWords * 1.5) {
            return NarrativeAttempt::discarded(DiscardReason::TooLong);
        }

        // ⚠️ §D5 — a guarda de número. Um órfão descarta a narrativa INTEIRA, não
        // a frase: uma prosa com um número inventado e nove corretos é pior que
        // nenhuma prosa, porque quem lê não sabe qual é qual (Artigo III).
        $orphans = $this->guard->orphans($result->text, $payload);

        if ($orphans !== []) {
            return NarrativeAttempt::discarded(DiscardReason::OrphanNumbers, $orphans);
        }

        return NarrativeAttempt::published($result);
    }
}

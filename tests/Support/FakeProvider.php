<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ai\Provider;
use App\Domain\Ai\ProviderFailure;
use App\Domain\Ai\ProviderUnavailable;
use App\Domain\Ai\Value\AiPayload;
use App\Domain\Ai\Value\AiResult;
use DateTimeImmutable;

/**
 * Provedor de mentira, para teste (NFR-501).
 *
 * ⚠️ **Vive em `tests/`, não em `app/`.** Um dublê de teste em `app/` viaja para
 * produção e, pior, dá a alguém a opção de "usar o fake por enquanto" num
 * caminho real.
 *
 * ⚠️ **Nenhum teste desta fase toca a rede.** Um teste que depende da API falha
 * quando a cota acaba — e gasta cota para rodar, que é o recurso que a cadeia de
 * modelos existe para economizar.
 *
 * ## Como usar
 *
 * ```php
 * $provider = FakeProvider::replying('texto qualquer');
 * $provider = FakeProvider::failing(ProviderFailure::QuotaExhausted);
 * $provider = FakeProvider::failingOnce(ProviderFailure::RateLimitPerMinute, 'depois vai');
 * ```
 */
final class FakeProvider implements Provider
{
    /** @var list<array{model: string, prompt: string, payload: AiPayload}> */
    public array $calls = [];

    /**
     * @param  list<ProviderFailure>  $failuresInOrder  uma por chamada; esgotada, responde texto
     */
    private function __construct(
        private readonly string $reply,
        private array $failuresInOrder = [],
        private readonly ?ProviderFailure $alwaysFails = null,
    ) {}

    public static function replying(string $reply = 'Narrativa de teste.'): self
    {
        return new self($reply);
    }

    public static function failing(ProviderFailure $failure): self
    {
        return new self('', alwaysFails: $failure);
    }

    /** Falha nas primeiras chamadas e depois responde — o caso que a cadeia trata. */
    public static function failingOnce(ProviderFailure $failure, string $reply = 'Narrativa de teste.'): self
    {
        return new self($reply, failuresInOrder: [$failure]);
    }

    /** @param  list<ProviderFailure>  $failures */
    public static function failingInOrder(array $failures, string $reply = 'Narrativa de teste.'): self
    {
        return new self($reply, failuresInOrder: $failures);
    }

    public function generate(string $model, string $prompt, AiPayload $payload): AiResult
    {
        // ⚠️ Registra a chamada ANTES de falhar. É o que permite ao teste
        // verificar QUAIS modelos a cadeia tentou, e em que ordem — sem isso, um
        // teste de cooldown não distingue "não tentou" de "tentou e falhou".
        $this->calls[] = ['model' => $model, 'prompt' => $prompt, 'payload' => $payload];

        if ($this->alwaysFails !== null) {
            throw new ProviderUnavailable($this->alwaysFails, $model, 'fake: sempre falha');
        }

        if ($this->failuresInOrder !== []) {
            $failure = array_shift($this->failuresInOrder);

            throw new ProviderUnavailable($failure, $model, 'fake: falha programada');
        }

        return new AiResult(
            text: $this->reply,
            model: $model,
            generatedAt: new DateTimeImmutable('2026-08-05 12:00:00'),
        );
    }

    public function name(): string
    {
        return 'fake';
    }

    public function callCount(): int
    {
        return count($this->calls);
    }

    /** @return list<string> os modelos tentados, na ordem */
    public function modelsTried(): array
    {
        return array_map(fn (array $call): string => $call['model'], $this->calls);
    }

    public function lastPayload(): ?AiPayload
    {
        return $this->calls === [] ? null : end($this->calls)['payload'];
    }
}

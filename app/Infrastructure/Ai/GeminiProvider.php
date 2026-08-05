<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Domain\Ai\Provider;
use App\Domain\Ai\ProviderFailure;
use App\Domain\Ai\ProviderUnavailable;
use App\Domain\Ai\Value\AiPayload;
use App\Domain\Ai\Value\AiResult;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * O único lugar do projeto que fala com um provedor de IA (ADR-6).
 *
 * ⚠️ **Borda.** Aqui entram HTTP, relógio e `config()` resolvido pelo provider —
 * tudo o que `app/Domain/Ai/` não pode ter (NFR-401). Existe teste varrendo
 * `app/` por marcas de endpoint fora de `Domain/Ai/`; este arquivo é a exceção
 * declarada.
 *
 * ⚠️ **O payload chega JÁ SANITIZADO.** Esta classe não decide o que enviar — ela
 * recebe um `AiPayload`, que só existe passando pelo `PayloadSanitizer`. É o que
 * mantém o Artigo VII com uma porta só.
 *
 * ## A classificação do erro é o trabalho mais importante aqui
 *
 * A API devolve **429 para dois casos com escalas de tempo completamente
 * diferentes**: limite por minuto (volta em ~1 min) e cota diária (volta na virada
 * do dia). Só quem lê o corpo da resposta sabe distinguir — e é essa distinção que
 * faz a `ModelChain` funcionar em vez de bater no modelo esgotado o dia inteiro.
 */
final class GeminiProvider implements Provider
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?string $apiKey,
        private readonly int $timeoutSeconds,
    ) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function generate(string $model, string $prompt, AiPayload $payload): AiResult
    {
        // ⚠️ Chave ausente é `Unauthorized`, não exceção genérica: a `ModelChain`
        // usa essa classificação para NÃO tentar os outros modelos — a chave é a
        // mesma para todos, e descer só gastaria tempo.
        if ($this->apiKey === null || trim($this->apiKey) === '') {
            throw new ProviderUnavailable(
                ProviderFailure::Unauthorized,
                $model,
                'GEMINI_API_KEY ausente. Preencha o .env — o produto segue funcionando sem ela (Artigo I).',
            );
        }

        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->asJson()
                ->post(self::ENDPOINT.$model.':generateContent', [
                    'contents' => [[
                        'parts' => [['text' => $prompt]],
                    ]],
                ]);
        } catch (ConnectionException $exception) {
            throw new ProviderUnavailable(
                $this->classifyConnectionError($exception->getMessage()),
                $model,
                $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            throw new ProviderUnavailable(ProviderFailure::Unknown, $model, $exception->getMessage());
        }

        if ($response->failed()) {
            throw new ProviderUnavailable(
                $this->classify($response),
                $model,
                'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300),
            );
        }

        return new AiResult(
            text: $this->textFrom($response, $model),
            model: $model,
            generatedAt: new DateTimeImmutable,
        );
    }

    /**
     * ⚠️ **`ConnectionException` NÃO é sinônimo de timeout.** Ela cobre também
     * falha de TLS, DNS que não resolve e conexão recusada — e essas são
     * problemas de **ambiente ou configuração nossa**, não do provedor.
     *
     * A distinção importa por causa do cooldown. Este projeto tropeçou nela ao
     * vivo em 05/08/2026: um PHP sem bundle de certificados (`curl.cainfo`
     * vazio) devolvia `cURL error 60`, a classe classificava como `Timeout`, e a
     * cadeia punha os três modelos de castigo por cinco minutos. O sintoma virou
     * "a IA está lenta" quando o defeito era **uma linha faltando no php.ini**.
     *
     * Erro de rede que não é timeout vira `Unknown`, que **não gera cooldown** —
     * então ele falha rápido, nos três modelos, e aparece como o que é.
     */
    private function classifyConnectionError(string $message): ProviderFailure
    {
        $message = mb_strtolower($message);

        $isTimeout = str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'error 28');

        return $isTimeout ? ProviderFailure::Timeout : ProviderFailure::Unknown;
    }

    /**
     * Traduz a resposta de erro em `ProviderFailure`.
     *
     * ⚠️ **A distinção dentro do 429 é heurística, e assumida como tal.** O corpo
     * do erro de cota do Google traz o identificador da métrica esgotada, e o de
     * cota diária menciona o dia (`PerDay`, `per day`). Não há campo dedicado.
     *
     * *Errar para qual lado:* na dúvida, classificamos como limite por MINUTO — o
     * cooldown curto. Errar por otimismo custa uma tentativa perdida em um minuto;
     * errar por pessimismo custaria seis horas de narrativa por um erro que já
     * tinha passado.
     */
    private function classify(Response $response): ProviderFailure
    {
        $status = $response->status();
        $body = mb_strtolower($response->body());

        if ($status === 429) {
            $daily = str_contains($body, 'perday')
                || str_contains($body, 'per day')
                || str_contains($body, 'perdayperproject')
                || str_contains($body, 'daily');

            return $daily ? ProviderFailure::QuotaExhausted : ProviderFailure::RateLimitPerMinute;
        }

        return match (true) {
            $status === 401, $status === 403 => ProviderFailure::Unauthorized,
            // 404 é nome de modelo inválido — problema NOSSO, de configuração.
            // Classificar como limite poria o modelo de castigo por seis horas e
            // esconderia o defeito atrás de "a IA parou de funcionar".
            $status === 404 => ProviderFailure::BadResponse,
            $status === 408, $status === 504 => ProviderFailure::Timeout,
            default => ProviderFailure::Unknown,
        };
    }

    /**
     * Extrai o texto da resposta.
     *
     * ⚠️ Resposta bem-formada sem texto (bloqueio de segurança do provedor, por
     * exemplo) é `BadResponse` — não gera cooldown, porque o modelo não está
     * esgotado. Penalizá-lo esconderia o motivo real atrás de horas de silêncio.
     */
    private function textFrom(Response $response, string $model): string
    {
        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new ProviderUnavailable(
                ProviderFailure::BadResponse,
                $model,
                'resposta sem texto: '.mb_substr($response->body(), 0, 300),
            );
        }

        return trim($text);
    }
}

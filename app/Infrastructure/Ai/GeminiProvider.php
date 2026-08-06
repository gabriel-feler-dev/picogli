<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Domain\Ai\Chat\ChatProvider;
use App\Domain\Ai\Chat\Value\ChatResponse;
use App\Domain\Ai\Chat\Value\ToolCall;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
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
final class GeminiProvider implements ChatProvider, Provider
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
        $this->requireKey($model);

        $response = $this->post($model, ':generateContent', [
            'contents' => [[
                'parts' => [['text' => $prompt]],
            ]],
        ]);

        return new AiResult(
            text: $this->textFrom($response, $model),
            model: $model,
            generatedAt: new DateTimeImmutable,
        );
    }

    /**
     * Um passo do laço de tool calling (Spec 006, FR-605).
     *
     * ⚠️ **A MESMA classe, e isso é o Artigo VII se mantendo.** Um `ChatProvider`
     * separado significaria um segundo arquivo conhecendo o endpoint — e a
     * pergunta "quem fala com a rede?" passaria a ter duas respostas.
     *
     * ⚠️ **Nenhum dado do usuário entra por aqui.** O `systemPrompt` já vem
     * montado pelo `FileChatPromptBuilder` a partir de um `ChatPayload`, que só
     * existe passando pelo `PayloadSanitizer`. Esta classe não decide o que sai.
     */
    public function chat(string $model, string $systemPrompt, array $tools, array $history): ChatResponse
    {
        $this->requireKey($model);

        $body = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $this->contentsFrom($history),
        ];

        if ($tools !== []) {
            $body['tools'] = [['function_declarations' => array_map(
                fn (ToolDescriptor $t): array => $this->declare($t),
                $tools,
            )]];
        }

        $response = $this->post($model, ':generateContent', $body);

        return $this->chatResponseFrom($response, $model);
    }

    /**
     * O schema de uma ferramenta, no formato que o provedor entende.
     *
     * ⚠️ **Traduzido aqui, na borda.** O `ToolDescriptor` é nosso e não conhece
     * o vocabulário de nenhum provedor — é o que permite trocar de provedor sem
     * tocar nas dez ferramentas (ADR-6).
     *
     * @return array<string, mixed>
     */
    private function declare(ToolDescriptor $tool): array
    {
        $properties = [];
        $required = [];

        foreach ($tool->argumentSchema as $nome => $regra) {
            $tipo = match ($regra['type'] ?? 'string') {
                'int' => 'integer',
                'float' => 'number',
                default => 'string',
            };

            $campo = ['type' => $tipo];

            // Data vira `string` com formato descrito: o provedor não tem tipo
            // de data, e sem a dica o modelo manda `16/07/2026`.
            if (($regra['type'] ?? null) === 'date') {
                $campo['description'] = 'Data no formato YYYY-MM-DD.';
            }

            if (($regra['type'] ?? null) === 'enum') {
                $campo['enum'] = array_values($regra['values'] ?? []);
            }

            $properties[$nome] = $campo;

            if (($regra['required'] ?? false) === true) {
                $required[] = $nome;
            }
        }

        return [
            'name' => $tool->name,
            'description' => $tool->description,
            'parameters' => [
                'type' => 'object',
                'properties' => (object) $properties,
                'required' => $required,
            ],
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return list<array<string, mixed>>
     */
    private function contentsFrom(array $history): array
    {
        $contents = [];

        foreach ($history as $mensagem) {
            // O provedor chama o assistente de `model`; a conversa é gravada com
            // o vocabulário do §5.3. A tradução mora aqui, na borda.
            $papel = ($mensagem['role'] ?? 'user') === 'assistant' ? 'model' : 'user';

            $contents[] = [
                'role' => $papel,
                'parts' => [['text' => (string) ($mensagem['content'] ?? '')]],
            ];
        }

        return $contents;
    }

    /**
     * Texto ou pedido de ferramenta — nunca os dois (`ChatResponse`).
     *
     * ⚠️ **Resposta sem texto E sem ferramenta é `BadResponse`.** Devolver um
     * `ChatResponse` vazio faria o orquestrador encerrar o turno com silêncio, e
     * o usuário veria a tela travada sem nada no log.
     */
    private function chatResponseFrom(Response $response, string $model): ChatResponse
    {
        $parts = $response->json('candidates.0.content.parts') ?? [];
        $toolCalls = [];
        $texto = '';

        foreach (is_array($parts) ? $parts : [] as $part) {
            if (isset($part['functionCall']['name'])) {
                $toolCalls[] = new ToolCall(
                    (string) $part['functionCall']['name'],
                    is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : [],
                );

                continue;
            }

            if (isset($part['text']) && is_string($part['text'])) {
                $texto .= $part['text'];
            }
        }

        if ($toolCalls === [] && trim($texto) === '') {
            throw new ProviderUnavailable(
                ProviderFailure::BadResponse,
                $model,
                'resposta sem texto e sem ferramenta: '.mb_substr($response->body(), 0, 300),
            );
        }

        return new ChatResponse(
            model: $model,
            // ⚠️ Havendo pedido de ferramenta, o texto do MESMO passo é
            // descartado: ele teria sido escrito sem o resultado da consulta.
            text: $toolCalls === [] ? trim($texto) : null,
            toolCalls: $toolCalls,
            inputTokens: $response->json('usageMetadata.promptTokenCount'),
            outputTokens: $response->json('usageMetadata.candidatesTokenCount'),
        );
    }

    /**
     * ⚠️ Chave ausente é `Unauthorized`, não exceção genérica: a `ModelChain` usa
     * essa classificação para NÃO tentar os outros modelos — a chave é a mesma
     * para todos, e descer só gastaria tempo.
     */
    private function requireKey(string $model): void
    {
        if ($this->apiKey === null || trim($this->apiKey) === '') {
            throw new ProviderUnavailable(
                ProviderFailure::Unauthorized,
                $model,
                'GEMINI_API_KEY ausente. Preencha o .env — o produto segue funcionando sem ela (Artigo I).',
            );
        }
    }

    /**
     * A chamada HTTP, com a classificação de falha que faz o cooldown funcionar.
     *
     * @param  array<string, mixed>  $body
     */
    private function post(string $model, string $verb, array $body): Response
    {
        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->asJson()
                ->post(self::ENDPOINT.$model.$verb, $body);
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

        return $response;
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

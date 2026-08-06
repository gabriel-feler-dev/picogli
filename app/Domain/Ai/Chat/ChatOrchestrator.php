<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ChatTurn;
use App\Domain\Ai\Chat\Value\ToolCall;
use App\Domain\Ai\Chat\Value\TurnOutcome;
use App\Domain\Ai\ModelChain;
use App\Domain\Ai\NumberGuard;
use App\Domain\Ai\PayloadSanitizer;

/**
 * O laço (Spec 006, FR-605, §D5, §D9). O coração da fase 6.
 *
 * ```
 * 1. classificador de emergência      → dispara? FIM, sem tocar a rede
 * 2. LOOP (no máximo N voltas):
 *      sanitiza o que já foi consultado  (Artigo VII)
 *      monta o prompt                     (§D6)
 *      percorre a cadeia de modelos       (fase 5)
 *      pediu ferramenta? executa e volta
 *      escreveu texto?  sai do laço
 * 3. guarda de número contra os tool_results do turno  (Artigo III)
 * 4. devolve um ChatTurn — sempre
 * ```
 *
 * ## As três coisas que esta classe nunca faz
 *
 * ⚠️ **Nunca lança.** Provedor fora, guarda reprovando, teto estourado: tudo
 * vira `TurnOutcome`. Uma exceção aqui chegaria como página de erro no meio de
 * uma conversa.
 *
 * ⚠️ **Nunca loga.** Domínio puro (NFR-401). Devolve `orphanNumbers` e
 * `iterations`; quem registra é a borda.
 *
 * ⚠️ **Nunca decide de quem são os dados.** O `ChatScope` chega pronto do
 * controller, a partir da sessão (§D2).
 *
 * ## Por que os resultados voltam pelo PROMPT, e não pelo protocolo
 *
 * O provedor tem um formato próprio para "resposta de função". Aqui os
 * resultados acumulados são reinjetados no **prompt de sistema**, através do
 * `ChatPayload` — e isso é deliberado:
 *
 *   - o payload continua sendo **um objeto sanitizado só**, que o teste
 *     anti-vazamento inspeciona por inteiro (Artigo VII);
 *   - trocar de provedor não mexe no laço, só na tradução da borda (ADR-6);
 *   - o modelo vê o histórico de consultas do turno inteiro a cada volta, em vez
 *     de uma pilha de mensagens de função.
 *
 * O custo é reenviar os resultados a cada volta. Com teto de 5 e resultados
 * pequenos (as ferramentas devolvem recorte, não série), é barato.
 */
final class ChatOrchestrator
{
    public function __construct(
        private readonly EmergencyClassifier $emergency,
        private readonly ToolRegistry $registry,
        private readonly ChatPromptBuilder $prompts,
        private readonly PayloadSanitizer $sanitizer,
        private readonly ModelChain $chain,
        private readonly ChatProvider $provider,
        private readonly NumberGuard $guard,
        private readonly int $maxIterations,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history  a conversa até aqui
     * @param  array<string, mixed>  $context  o pré-carregado de ~500 tokens (§9.3)
     */
    public function handle(string $message, ChatScope $scope, array $history = [], array $context = []): ChatTurn
    {
        // ⚠️⚠️ ANTES DE TUDO. Artigo VI, camada 4: segurança não depende do
        // modelo, e não depende nem de a rede estar de pé.
        if ($this->emergency->isEmergency($message)) {
            return new ChatTurn(
                content: $this->emergency->guidance(),
                outcome: TurnOutcome::Emergency,
            );
        }

        $conversa = [...$history, ['role' => 'user', 'content' => $message]];
        $chamadas = [];
        $resultados = [];
        $voltas = 0;

        while ($voltas < $this->maxIterations) {
            $voltas++;

            $payload = $this->sanitizer->sanitizeChat($resultados, $context);
            $prompt = $this->prompts->build($payload, $this->registry->descriptors());

            $resposta = $this->chain->attempt(
                fn (string $model) => $this->provider->chat(
                    $model,
                    $prompt,
                    $this->registry->descriptors(),
                    $conversa,
                ),
            );

            // Cadeia esgotada: cota, chave ou rede. Não é defeito nosso.
            if ($resposta === null) {
                return $this->unavailable($chamadas, $resultados, $voltas);
            }

            if ($resposta->wantsTools()) {
                foreach ($resposta->toolCalls as $chamada) {
                    $chamadas[] = $chamada->toArray();
                    $resultados[] = $this->execute($chamada, $scope);
                }

                continue;
            }

            return $this->finish($resposta->text ?? '', $resposta, $chamadas, $resultados, $voltas);
        }

        // ⚠️ Teto estourado (§D5). O turno encerra com o que foi consultado, e a
        // borda loga: se acontece com frequência, uma ferramenta está mal
        // descrita ou falta uma.
        return $this->unavailable($chamadas, $resultados, $voltas);
    }

    /** @return array<string, mixed> */
    private function execute(ToolCall $chamada, ChatScope $scope): array
    {
        return $this->registry->run($chamada, $scope)->toArray();
    }

    /**
     * A guarda de número, e a decisão de publicar (§D3, FR-607).
     *
     * @param  list<array<string, mixed>>  $chamadas
     * @param  list<array<string, mixed>>  $resultados
     */
    private function finish(
        string $texto,
        Value\ChatResponse $resposta,
        array $chamadas,
        array $resultados,
        int $voltas,
    ): ChatTurn {
        // ⚠️ A procedência é a união dos RESULTADOS do turno — não o histórico,
        // não o contexto de outra conversa. Um número que o modelo escreva sem
        // ter chamado a ferramenta correspondente não tem correspondência aqui.
        $orfaos = $this->guard->orphansIn($texto, $resultados);

        if ($orfaos !== []) {
            return new ChatTurn(
                content: '',
                outcome: TurnOutcome::Refused,
                toolCalls: $chamadas,
                toolResults: $resultados,
                model: $resposta->model,
                inputTokens: $resposta->inputTokens,
                outputTokens: $resposta->outputTokens,
                orphanNumbers: $orfaos,
                iterations: $voltas,
            );
        }

        return new ChatTurn(
            content: $texto,
            outcome: TurnOutcome::Published,
            toolCalls: $chamadas,
            toolResults: $resultados,
            model: $resposta->model,
            inputTokens: $resposta->inputTokens,
            outputTokens: $resposta->outputTokens,
            iterations: $voltas,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $chamadas
     * @param  list<array<string, mixed>>  $resultados
     */
    private function unavailable(array $chamadas, array $resultados, int $voltas): ChatTurn
    {
        return new ChatTurn(
            content: '',
            outcome: TurnOutcome::Unavailable,
            toolCalls: $chamadas,
            toolResults: $resultados,
            iterations: $voltas,
        );
    }
}

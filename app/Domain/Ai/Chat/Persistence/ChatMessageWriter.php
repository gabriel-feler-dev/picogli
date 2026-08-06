<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\Value\ChatRole;
use App\Domain\Ai\Chat\Value\ChatTurn;
use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Patterns\PatternEngine;
use App\Models\ChatConversation;
use App\Models\ChatMessage;

/**
 * Grava o turno (Spec 006, §D10, FR-605).
 *
 * ⚠️ **Não existe método que altere mensagem gravada, e é a decisão.** A
 * narrativa da fase 5 é vista derivada e o `PeriodReportWriter` a invalida
 * quando os achados mudam. A conversa é registro do que foi dito e do que foi
 * consultado — reescrever apagaria a auditabilidade que o Artigo III existe para
 * dar.
 *
 * ⚠️ As versões de motor e de métricas são carimbadas **no instante da
 * resposta**. Não servem para invalidar; servem para explicar, dois meses
 * depois, por que um número citado aqui difere do número da tela de hoje.
 */
final class ChatMessageWriter
{
    /** A pergunta, gravada antes de qualquer chamada ao provedor. */
    public function recordQuestion(ChatConversation $conversation, string $message): ChatMessage
    {
        // ⚠️ Gravada ANTES: se o provedor cair no meio, a pergunta não some da
        // conversa. Perder o que a pessoa escreveu é pior que não responder.
        return ChatMessage::create([
            'chat_conversation_id' => $conversation->id,
            'role' => ChatRole::User,
            'content' => $message,
        ]);
    }

    public function recordAnswer(ChatConversation $conversation, ChatTurn $turn, string $fallbackText): ChatMessage
    {
        $atributos = $turn->toAttributes();

        // ⚠️ §D9 — no chat, silêncio é tela travada. Quando a guarda reprova ou
        // a cadeia não atende, o usuário recebe uma frase explícita. Ela não
        // expõe cota, chave nem modelo (NFR-502 da fase 5).
        if (trim((string) $atributos['content']) === '') {
            $atributos['content'] = $fallbackText;
        }

        $mensagem = ChatMessage::create(array_merge($atributos, [
            'chat_conversation_id' => $conversation->id,
            'role' => ChatRole::Assistant,
            'engine_version' => PatternEngine::VERSION,
            'metrics_version' => DailyMetricsWriter::VERSION,
        ]));

        // A listagem é "minhas conversas, mais recente primeiro" — e a conversa
        // que acabou de receber resposta é a mais recente.
        $conversation->touch();

        return $mensagem;
    }

    /**
     * Título a partir da primeira pergunta.
     *
     * ⚠️ Cortado em palavra inteira, sem IA. Uma chamada de modelo para
     * intitular a conversa custaria uma requisição da cota por conversa — e o
     * título não é informação, é etiqueta.
     */
    public function titleFrom(ChatConversation $conversation, string $message): void
    {
        if ($conversation->title !== null) {
            return;
        }

        $limpo = trim(preg_replace('/\s+/u', ' ', $message) ?? '');

        if ($limpo === '') {
            return;
        }

        $titulo = mb_strlen($limpo) <= 60
            ? $limpo
            : rtrim(mb_substr($limpo, 0, mb_strrpos(mb_substr($limpo, 0, 61), ' ') ?: 60)).'…';

        $conversation->update(['title' => $titulo]);
    }
}

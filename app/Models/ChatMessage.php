<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ai\Chat\Value\ChatRole;
use App\Domain\Ai\Chat\Value\TurnOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma mensagem gravada (Spec 006, FR-601, §D10).
 *
 * ⚠️⚠️ **Esta classe não tem um único método que altere uma mensagem existente,
 * e isso é a decisão, não um esquecimento** (§D10).
 *
 * A narrativa da fase 5 é vista derivada: quando os achados mudam, o texto sobre
 * eles está errado, e o `PeriodReportWriter` o invalida. A conversa é o oposto —
 * é o registro do que foi dito e do que foi consultado. Reescrever o passado
 * apagaria exatamente a auditabilidade que o Artigo III existe para dar.
 *
 * `engine_version` e `metrics_version` estão aqui pelo mesmo motivo: não servem
 * para decidir regerar. Servem para explicar, depois, por que um número citado
 * numa conversa de julho difere do número da tela de hoje.
 */
class ChatMessage extends Model
{
    protected $table = 'chat_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'role' => ChatRole::class,
            'outcome' => TurnOutcome::class,
            'tool_calls' => 'array',
            'tool_results' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'chat_conversation_id');
    }

    /**
     * ⚠️ **A procedência do Artigo III, como foi gravada.**
     *
     * É daqui que o rodapé "dados consultados" é lido (FR-608) — **nunca**
     * remontado executando as ferramentas de novo. Remontar mostraria o
     * resultado de agora; o que torna o número auditável é ver o que foi
     * consultado naquele turno.
     *
     * @return list<array<string, mixed>>
     */
    public function consultedData(): array
    {
        return $this->tool_results ?? [];
    }

    public function hasProvenance(): bool
    {
        return $this->consultedData() !== [];
    }

    /** A rede foi tocada para produzir esta mensagem? */
    public function reachedProvider(): bool
    {
        return $this->outcome?->reachedProvider() ?? false;
    }

    /** Só as que viram bolha na tela. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('role', [ChatRole::User->value, ChatRole::Assistant->value]);
    }
}

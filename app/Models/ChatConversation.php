<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ai\Chat\Value\ChatScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma conversa (Spec 006, FR-601).
 *
 * ⚠️ **Toda leitura passa por `forUser()`** (§11.3). Não é convenção: é o único
 * caminho que o resto do código usa, e há teste provando que a conversa de um
 * usuário não aparece para outro.
 *
 * *Por quê não um global scope:* a v1 é single-user e o global scope precisaria
 * de `auth()` dentro do model — que é exatamente o tipo de acoplamento que faz o
 * teste de domínio precisar de sessão. Um escopo nomeado e explícito é mais
 * verboso e mais auditável: dá para procurar por `forUser` e achar todos.
 */
class ChatConversation extends Model
{
    protected $table = 'chat_conversations';

    protected $guarded = [];

    /** Conversas de um usuário, mais recente primeiro. */
    public function scopeForUser(Builder $query, int|ChatScope $user): Builder
    {
        $userId = $user instanceof ChatScope ? $user->userId : $user;

        return $query->where('user_id', $userId)->latest('updated_at');
    }

    public function messages(): HasMany
    {
        // ⚠️ Ordem por `id`, não por `created_at`: duas mensagens do mesmo turno
        // podem cair no mesmo segundo, e aí a conversa apareceria embaralhada.
        return $this->hasMany(ChatMessage::class)->orderBy('id');
    }

    /** As mensagens que viram bolha na tela — o passo de ferramenta não vira. */
    public function visibleMessages(): HasMany
    {
        return $this->messages()->whereIn('role', ['user', 'assistant']);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ver PeriodReport::dateOnly() — mesma armadilha SQLite/MySQL. */
    protected function contextStart(): Attribute
    {
        return $this->dateOnly();
    }

    protected function contextEnd(): Attribute
    {
        return $this->dateOnly();
    }

    /**
     * ⚠️ O cast `date` do Laravel grava `2026-07-29 00:00:00`. O MySQL trunca ao
     * gravar numa coluna `DATE`; o SQLite **não**. Sem esta normalização, um
     * `whereBetween` funcionaria em produção e falharia em desenvolvimento.
     */
    private function dateOnly(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => $value === null ? null : substr($value, 0, 10),
            set: fn (mixed $value): ?string => match (true) {
                $value === null => null,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
                default => substr((string) $value, 0, 10),
            },
        );
    }
}

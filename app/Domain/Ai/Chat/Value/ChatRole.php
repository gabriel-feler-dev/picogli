<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Value;

/**
 * Quem escreveu a mensagem (`PicoGli.md` §5.3).
 *
 * ⚠️ **String no banco, enum no PHP** (Artigo IX). Coluna `enum` é sintaxe de
 * dialeto, e acrescentar um papel viraria migration; aqui é uma linha.
 */
enum ChatRole: string
{
    case User = 'user';
    case Assistant = 'assistant';

    /**
     * Passo intermediário do turno: o resultado que voltou de uma ferramenta.
     *
     * ⚠️ **Não é o que a tela mostra.** A resposta final do assistente carrega
     * `tool_calls` e `tool_results` do turno inteiro, e é dela que o rodapé
     * "dados consultados" é lido (FR-608). Este papel existe para quando o
     * transcrito precisar ser reenviado ao provedor com os passos intermediários
     * separados — decisão que é do T510, não desta tarefa.
     */
    case Tool = 'tool';

    /** Aparece na conversa como uma bolha? */
    public function isVisible(): bool
    {
        return $this !== self::Tool;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Value;

/**
 * O que — e apenas o que — sai do chat em direção a um provedor (Artigo VII).
 *
 * ⚠️ **O chat manda MENOS que a narrativa da fase 5.** Lá saíam dez achados com
 * a evidência inteira; aqui sai só o que as ferramentas devolveram no turno, e
 * as ferramentas devolvem só o recorte que o modelo pediu.
 *
 * ## Três blocos, e o que cada um é
 *
 * | Bloco | O que é | De onde vem |
 * |---|---|---|
 * | `context` | ~500 tokens sempre presentes: período, métricas globais, validade | o código |
 * | `toolResults` | o que as ferramentas devolveram neste turno | as dez ferramentas |
 * | `droppedKeys` | o que a allowlist recusou | auditoria |
 *
 * ⚠️ **A pergunta do usuário NÃO está aqui**, e é deliberado: ela é texto livre,
 * não passa por allowlist nenhuma, e vai ao provedor pelo caminho da mensagem.
 * Este objeto responde por "que DADOS saem daqui" — a distinção importa, porque
 * é sobre dados que o Artigo VII fala.
 */
final readonly class ChatPayload
{
    /**
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>  $toolResults
     * @param  list<string>  $droppedKeys  chaves recusadas pela allowlist
     */
    public function __construct(
        public array $context,
        public array $toolResults,
        public array $droppedKeys = [],
    ) {}

    public function hasDroppedKeys(): bool
    {
        return $this->droppedKeys !== [];
    }

    /** @return array<string, mixed> a forma que vai ao provedor */
    public function toArray(): array
    {
        return [
            'context' => $this->context,
            'tool_results' => $this->toolResults,
        ];
    }

    /**
     * O payload serializado — é sobre ESTA string que o teste anti-vazamento
     * varre o cabeçalho do CSV.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}

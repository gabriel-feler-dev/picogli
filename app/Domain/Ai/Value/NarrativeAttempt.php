<?php

declare(strict_types=1);

namespace App\Domain\Ai\Value;

/**
 * O resultado de tentar gerar uma narrativa — com ou sem texto.
 *
 * ⚠️ **Devolve o motivo do descarte em vez de logar.** O domínio é PHP puro e não
 * chama `Log::` (NFR-401); quem registra é a borda, que recebe este objeto.
 *
 * Foi a decisão que fechou a T403.5: a guarda de número devolve os órfãos, o
 * gerador decide descartar, e o Job loga. Cada camada faz o que lhe cabe, e o
 * teste do gerador não precisa de logger falso.
 */
final readonly class NarrativeAttempt
{
    /** @param list<string> $orphanNumbers */
    private function __construct(
        public ?AiResult $result,
        public ?DiscardReason $discardReason,
        public array $orphanNumbers = [],
    ) {}

    public static function published(AiResult $result): self
    {
        return new self($result, null);
    }

    /** @param list<string> $orphans */
    public static function discarded(DiscardReason $reason, array $orphans = []): self
    {
        return new self(null, $reason, $orphans);
    }

    public function wasPublished(): bool
    {
        return $this->result !== null;
    }

    /**
     * Mensagem para o log — específica o suficiente para ser investigável.
     *
     * "Não houve narrativa" é inútil; "descartada: citou 187, 42 sem procedência"
     * diz onde olhar.
     */
    public function logMessage(): string
    {
        if ($this->wasPublished()) {
            return sprintf(
                'Narrativa gerada por %s (%d palavras).',
                $this->result->model,
                $this->result->wordCount(),
            );
        }

        $message = 'Narrativa descartada: '.$this->discardReason->value;

        if ($this->orphanNumbers !== []) {
            $message .= ' — números sem procedência: '.implode(', ', $this->orphanNumbers);
        }

        return $message;
    }
}

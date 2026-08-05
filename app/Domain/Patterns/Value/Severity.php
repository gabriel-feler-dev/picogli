<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Value;

/**
 * Quanto um achado pede atenção.
 *
 * ⚠️ Três níveis, não cinco. Escala fina obriga a decidir entre "moderado" e
 * "considerável" — distinção que nenhuma regra consegue defender com evidência
 * numérica, e que o usuário não usa para nada.
 *
 * `Priority` **não** significa urgência clínica. Significa "leia este primeiro".
 * A fronteira do Artigo VI continua valendo: o produto descreve, não prescreve,
 * e nenhuma severidade daqui manda alguém procurar médico — isso é papel do
 * classificador de emergência da fase 6, e ele não passa por aqui.
 */
enum Severity: string
{
    case Info = 'info';
    case Attention = 'attention';
    case Priority = 'priority';

    /**
     * Peso para ordenação decrescente.
     *
     * Existe porque comparar enum em PHP compara identidade, não ordem. Sem um
     * peso explícito, a ordenação dependeria da ordem de declaração dos cases —
     * que é exatamente o tipo de acoplamento invisível que quebra quando alguém
     * reordena o arquivo por estética.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Priority => 3,
            self::Attention => 2,
            self::Info => 1,
        };
    }

    /** Nunca sobe além do teto declarado pela regra (`RuleId::maxSeverity()`). */
    public function cappedAt(self $ceiling): self
    {
        return $this->weight() > $ceiling->weight() ? $ceiling : $this;
    }
}

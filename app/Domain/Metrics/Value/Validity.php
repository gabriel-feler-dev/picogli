<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

/**
 * Resultado do portão de validade (Artigo V).
 *
 * O motivo da reprovação é distinguível de propósito: "faltam dias" e "o sensor
 * ficou fora do ar" pedem ações diferentes do usuário, e uma mensagem genérica
 * de "dados insuficientes" não ajuda ninguém.
 */
enum Validity: string
{
    case Valid = 'valid';
    case InsufficientDays = 'insufficient_days';
    case InsufficientCoverage = 'insufficient_coverage';

    public function isValid(): bool
    {
        return $this === self::Valid;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Metrics\Value\Coverage;
use App\Domain\Metrics\Value\Validity;

/**
 * Artigo V — métrica inválida não é exibida como válida.
 *
 * GMI e CV só são interpretáveis com ≥14 dias e ≥70% de captura (consenso
 * ATTD/ADA). Mostrar "GMI 6,7%" calculado sobre 3 dias com 40% de captura é
 * pior que não mostrar nada: parece confiável, e alguém decide algo com base
 * nisso.
 *
 * A ordem de checagem importa: dias primeiro. Um período de 3 dias com 100% de
 * captura é reprovado por DIAS, e dizer "captura insuficiente" ali seria
 * mentira — o sensor funcionou.
 */
final class ValidityGate
{
    public function __construct(private readonly MetricsConfig $config) {}

    public function evaluate(Coverage $coverage): Validity
    {
        $days = $coverage->spanInDays;

        // Span de 13,8 dias arredonda para 14. Decisão de produto aceitável
        // SOMENTE porque a UI sempre mostra o span real ao lado — nunca
        // esconder o denominador.
        if ($days >= $this->config->validity['min_days_rounding_floor']) {
            $days = (float) $this->config->validity['min_days'];
        }

        if ($days < $this->config->validity['min_days']) {
            return Validity::InsufficientDays;
        }

        if ($coverage->percentage < $this->config->validity['min_coverage'] * 100) {
            return Validity::InsufficientCoverage;
        }

        return Validity::Valid;
    }
}

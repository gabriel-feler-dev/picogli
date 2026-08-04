<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

/**
 * Parâmetros clínicos, injetados no domínio.
 *
 * ⚠️ O domínio NÃO chama `config()`. Se chamasse, deixaria de ser PHP puro e os
 * testes precisariam do container do Laravel — perdendo a propriedade que torna
 * a suíte de unidade rápida e a separação verificável.
 *
 * Quem lê `config/clinical.php` é a borda (Job, service provider). Aqui chega
 * um array já resolvido.
 */
final readonly class MetricsConfig
{
    /**
     * @param  array<string, array{min?: int, max?: int}>  $ranges
     * @param  array{intercept: float, slope: float}  $gmi
     * @param  array{min_days: int, min_coverage: float, min_days_rounding_floor: float}  $validity
     * @param  array{readings_per_day: int, interval_minutes: int, gap_threshold_minutes: int}  $sensor
     */
    public function __construct(
        public array $ranges,
        public array $gmi,
        public array $validity,
        public array $sensor,
    ) {}

    /** @param array<string, mixed> $clinical conteúdo de config/clinical.php */
    public static function fromArray(array $clinical): self
    {
        return new self(
            ranges: $clinical['ranges'],
            gmi: $clinical['gmi'],
            validity: $clinical['validity'],
            sensor: $clinical['sensor'],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Presentation\Value;

use App\Domain\Metrics\Value\Coverage;
use App\Domain\Metrics\Value\Validity;

/**
 * Tudo que o dashboard precisa de um período (FR-204).
 *
 * ## O Artigo V imposto por TIPO, não por disciplina
 *
 * ⚠️ `$coverage` e `$validity` são parâmetros **obrigatórios**, sem valor
 * padrão. Não existe forma de construir este objeto sem eles — logo não existe
 * caminho no código que entregue métrica sem o denominador.
 *
 * A alternativa seria confiar em quem chama lembrar de passar a cobertura. Isso
 * funciona até a primeira pressa. Um teste estrutural verifica que os dois
 * seguem sem default.
 */
final readonly class PeriodSummary
{
    /**
     * @param  list<TranslatedMetric>  $metrics
     * @param  array<int, mixed>  $hourlyProfile
     * @param  array<int, mixed>  $hourlyPercentiles
     * @param  list<array<string, mixed>>  $dailyMetrics
     * @param  list<array<string, mixed>>  $gaps
     * @param  array<string, array{min: int|null, max: int|null}>  $ranges
     */
    public function __construct(
        public string $from,
        public string $to,
        public Coverage $coverage,
        public Validity $validity,
        public array $ranges,
        public array $metrics,
        public array $hourlyProfile,
        public array $hourlyPercentiles,
        public array $dailyMetrics,
        public array $gaps,
        public bool $hasStaleMetrics,
    ) {}

    public function isEmpty(): bool
    {
        return $this->coverage->readingCount === 0;
    }

    /**
     * Payload para o React.
     *
     * ⚠️ `coverage` vem sempre, e com o SPAN REAL (13,8 dias) além do
     * arredondado. Artigo V: nunca esconder o denominador — e "14 dias" quando
     * o span é 13,8 é esconder um pedaço dele.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period' => ['from' => $this->from, 'to' => $this->to],

            'coverage' => [
                'reading_count' => $this->coverage->readingCount,
                'expected_count' => $this->coverage->expectedCount,
                'span_in_days' => round($this->coverage->spanInDays, 1),
                'percentage' => round($this->coverage->percentage, 1),
                'summary' => __('metrics.coverage.summary', [
                    'days' => (string) (int) ceil($this->coverage->spanInDays),
                    'percent' => number_format($this->coverage->percentage, 0).'%',
                ]),
                'span_note' => __('metrics.coverage.span_note', [
                    'span' => number_format($this->coverage->spanInDays, 1, ',', '.'),
                ]),
                'readings_note' => __('metrics.coverage.readings', [
                    'count' => (string) $this->coverage->readingCount,
                    'expected' => (string) $this->coverage->expectedCount,
                ]),
            ],

            'validity' => [
                'status' => $this->validity->value,
                'is_valid' => $this->validity->isValid(),
                'message' => $this->validityMessage(),
            ],

            // ⚠️ As faixas vêm do servidor para o gráfico DESENHAR a banda-alvo
            // a partir de dado, não de constante em JS. Assim `70` e `180`
            // existem num único lugar: config/clinical.php.
            'ranges' => $this->ranges,

            'metrics' => array_map(
                fn (TranslatedMetric $metric): array => $metric->toArray(),
                $this->metrics,
            ),

            'hourly_profile' => $this->hourlyProfile,
            'hourly_percentiles' => $this->hourlyPercentiles,
            'daily_metrics' => $this->dailyMetrics,
            'gaps' => $this->gaps,
            'has_stale_metrics' => $this->hasStaleMetrics,
            'stale_message' => $this->hasStaleMetrics ? __('metrics.validity.stale_metrics') : null,
        ];
    }

    /**
     * Mensagem do portão de validade, com o MOTIVO distinguível.
     *
     * "Dados insuficientes" não ajuda ninguém: faltar dias e o sensor ter ficado
     * fora do ar pedem ações diferentes do usuário.
     */
    private function validityMessage(): ?string
    {
        return match ($this->validity) {
            Validity::Valid => null,
            Validity::InsufficientDays => __('metrics.validity.insufficient_days', [
                'days' => (string) config('clinical.validity.min_days'),
                'actual' => number_format($this->coverage->spanInDays, 1, ',', '.'),
            ]),
            Validity::InsufficientCoverage => __('metrics.validity.insufficient_coverage', [
                'percent' => number_format($this->coverage->percentage, 0).'%',
                'required' => number_format(config('clinical.validity.min_coverage') * 100, 0).'%',
            ]),
        };
    }
}

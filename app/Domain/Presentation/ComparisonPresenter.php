<?php

declare(strict_types=1);

namespace App\Domain\Presentation;

use App\Domain\Ai\Chat\Persistence\ComparePeriodsTool;
use App\Domain\Ai\Chat\ToolRegistry;
use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolCall;
use App\Domain\Presentation\Value\ComparedMetric;
use App\Models\SensorReading;
use DateTimeImmutable;

/**
 * A tela de comparação entre períodos (Spec 007, FR-704, §D4).
 *
 * ## ⚠️ Reusa o `ComparePeriodsTool`, e não recalcula nada (§D1)
 *
 * *Por quê uma classe de `Ai/Chat/` sendo usada por uma tela:* o
 * `ComparePeriodsTool` é uma **consulta**, não uma coisa de IA. Ele mora ali
 * porque o chat foi quem precisou dele primeiro — e ele já resolve o caso difícil
 * que esta tela precisa acertar: validade por lado e `null` propagado para o
 * delta quando um dos lados não tem o número.
 *
 * Reimplementar aqui criaria a situação que o §D1 existe para impedir: o chat e a
 * tela discordando sobre a mesma semana. A tensão de namespace é o preço, e é
 * mais barato que duas fontes de verdade.
 *
 * ⚠️ **A execução passa pelo `ToolRegistry`**, não direto na ferramenta. É o único
 * caminho de execução (fase 6, T502), e é ele que valida argumento e confere a
 * saída contra `emittedKeys`.
 */
final class ComparisonPresenter
{
    /** As métricas que a tela compara, com rótulo e unidade. */
    private const METRICS = [
        'mean_glucose' => ['Média de glicose', 'mg/dL'],
        'time_in_range_percent' => ['Tempo na faixa', '%'],
        'time_above_180_percent' => ['Tempo acima de 180', '%'],
        'time_below_70_percent' => ['Tempo abaixo de 70', '%'],
        'cv_percent' => ['Variabilidade', '%'],
    ];

    public function __construct(private readonly ToolRegistry $tools) {}

    /**
     * Os últimos N dias contra os N anteriores — o recorte padrão da tela.
     *
     * ⚠️ Ancorado na **última leitura**, não em `now()`. Mesma decisão do
     * dashboard, do motor de padrões e da tela de refeições.
     *
     * @return array<string, mixed>
     */
    public function latestVersusPrevious(int $userId, int $days = 7): array
    {
        $ultima = SensorReading::where('user_id', $userId)->max('local_date');

        if ($ultima === null) {
            return ['has_data' => false];
        }

        $bEnd = new DateTimeImmutable(substr((string) $ultima, 0, 10));
        $bStart = $bEnd->modify('-'.($days - 1).' days');
        $aEnd = $bStart->modify('-1 day');
        $aStart = $aEnd->modify('-'.($days - 1).' days');

        return $this->compare(
            $userId,
            $aStart->format('Y-m-d'),
            $aEnd->format('Y-m-d'),
            $bStart->format('Y-m-d'),
            $bEnd->format('Y-m-d'),
        );
    }

    /** @return array<string, mixed> */
    public function compare(int $userId, string $aStart, string $aEnd, string $bStart, string $bEnd): array
    {
        $resultado = $this->tools->run(
            new ToolCall('compare_periods', [
                'a_start' => $aStart, 'a_end' => $aEnd,
                'b_start' => $bStart, 'b_end' => $bEnd,
            ]),
            new ChatScope($userId, (int) config('chat.max_span_days')),
        );

        if (! $resultado->succeeded()) {
            // ⚠️ O erro do validador é texto acionável ("período de 730 dias é
            // maior que o máximo"). Vira mensagem, não exceção.
            return ['has_data' => true, 'error' => $resultado->error];
        }

        $a = $resultado->data['period_a'];
        $b = $resultado->data['period_b'];
        $delta = $resultado->data['delta'];

        return [
            'has_data' => true,
            'period_a' => $this->side($a),
            'period_b' => $this->side($b),
            'metrics' => array_map(
                fn (ComparedMetric $m): array => $m->toArray(),
                $this->metricsFrom($a, $b, $delta),
            ),
        ];
    }

    /**
     * Um lado da comparação, com o denominador.
     *
     * ⚠️ **Artigo V:** `days_span`, `coverage_percent` e `validity` sempre
     * acompanham. A tela precisa deles para decidir a apresentação, e o usuário
     * precisa deles para decidir se acredita.
     *
     * @param  array<string, mixed>  $lado
     * @return array<string, mixed>
     */
    private function side(array $lado): array
    {
        return [
            'from' => $lado['period_start'],
            'to' => $lado['period_end'],
            'days_span' => $lado['days_span'],
            'coverage_percent' => $lado['coverage_percent'],
            'reading_count' => $lado['reading_count'],
            'validity' => $lado['validity'],
            'is_valid' => $lado['validity'] === 'valid',
        ];
    }

    /**
     * ⚠️ **Aqui mora a decisão do §D4**, e ela é do servidor.
     *
     * "Melhorei 12% em relação ao mês passado" é a frase mais convincente que este
     * produto pode escrever, e a mais perigosa. Se um dos lados tem 6 dias e 61%
     * de captura, o 12% é ruído com aparência de conclusão — e ninguém pergunta
     * pela cobertura antes de acreditar.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @param  array<string, mixed>  $delta
     * @return list<ComparedMetric>
     */
    private function metricsFrom(array $a, array $b, array $delta): array
    {
        $motivo = $this->inconclusiveReason($a, $b);
        $metricas = [];

        foreach (self::METRICS as $chave => [$rotulo, $unidade]) {
            $diferenca = $delta[$chave.'_delta'] ?? null;

            $metricas[] = new ComparedMetric(
                key: $chave,
                label: $rotulo,
                valueA: $this->numberOrNull($a[$chave] ?? null),
                valueB: $this->numberOrNull($b[$chave] ?? null),
                // ⚠️ Preserva a ausência. O `ComparePeriodsTool` já devolve `null`
                // quando um lado não tem o número (§D8 da fase 6); preencher com
                // zero aqui seria inventar a diferença.
                delta: $this->numberOrNull($diferenca),
                unit: $unidade,
                // Sem os dois lados, não há tendência a ler — mesmo que o delta
                // exista para outras métricas.
                conclusive: $motivo === null && $diferenca !== null,
                inconclusiveReason: $diferenca === null && $motivo === null
                    ? 'Um dos períodos não tem esse número apurado.'
                    : $motivo,
            );
        }

        return $metricas;
    }

    /**
     * Por que a comparação não é conclusiva — em texto, para a tela.
     *
     * ⚠️ Devolve `null` quando ela **é** conclusiva. O texto nomeia qual lado tem
     * o problema e com que números, porque "a comparação não é conclusiva" sem o
     * denominador é tão opaco quanto não avisar.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function inconclusiveReason(array $a, array $b): ?string
    {
        $fracos = [];

        foreach ([['anterior', $a], ['atual', $b]] as [$nome, $lado]) {
            if (($lado['validity'] ?? null) !== 'valid') {
                $fracos[] = sprintf(
                    'o período %s tem %s%% de captura em %s dias',
                    $nome,
                    $this->format($lado['coverage_percent'] ?? null),
                    $this->format($lado['days_span'] ?? null),
                );
            }
        }

        if ($fracos === []) {
            return null;
        }

        return ucfirst(implode(' e ', $fracos)).'. A diferença não é conclusiva.';
    }

    private function numberOrNull(mixed $valor): ?float
    {
        return is_numeric($valor) ? (float) $valor : null;
    }

    private function format(mixed $valor): string
    {
        return is_numeric($valor) ? (string) round((float) $valor, 1) : '?';
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Presentation;

use App\Models\Import;

/**
 * Resumo auditável de uma importação (FR-207).
 *
 * ⚠️ **Por que esta tela existe.** O pior cenário da fase 1 é importação
 * silenciosamente parcial: o arquivo tem 3.749 leituras, o banco fica com 3.000,
 * e ninguém percebe até uma métrica sair errada semanas depois.
 *
 * Então o resumo não mostra só "importado com sucesso". Ele mostra, bloco por
 * bloco, o que cada linha virou — e se a soma fecha. Quem olha consegue
 * conferir, não só confiar.
 */
final class ImportSummaryPresenter
{
    /** Rótulos legíveis para os tipos de evento e motivos de descarte. */
    private const LABELS = [
        'SensorReadingEvent' => 'leituras de glicose',
        'BgReadingEvent' => 'glicemias capilares',
        'BolusRequestEvent' => 'pedidos de bolus',
        'BolusDeliveryEvent' => 'entregas de bolus',
        'MealEvent' => 'refeições',
        'BasalRateEvent' => 'mudanças de basal',
        'DailyAutoInsulinEvent' => 'totais diários automáticos',
        'DeviceEvent' => 'eventos do aparelho',
        'ignorada:day_marker' => 'marcadores de início/fim de dia',
        'ignorada:empty_row' => 'linhas sem conteúdo',
        'ignorada:wizard_detail' => 'detalhes da calculadora',
        'ignorada:unrecognized' => 'linhas não reconhecidas',
        'ignorada:invalid_timestamp' => 'linhas com data inválida',
    ];

    private const BLOCK_LABELS = [
        'pump' => 'Bomba',
        'auto_insulin' => 'Insulina automática',
        'sensor' => 'Sensor',
    ];

    /** @return array<string, mixed> */
    public function present(Import $import): array
    {
        $counts = $import->block_row_counts ?? [];
        $reconciliation = $counts['reconciliation'] ?? [];

        return [
            'id' => $import->id,
            'filename' => $import->original_filename,
            'status' => $import->status,
            'device' => $import->device_model,
            'firmware' => $import->firmware_version,
            'cgm' => $import->cgm_model,
            'timezone' => $import->timezone,
            'glucose_unit' => $import->glucose_unit,
            'period' => [
                'from' => $import->period_start?->format('Y-m-d'),
                'to' => $import->period_end?->format('Y-m-d'),
            ],
            'imported_at' => $import->created_at?->format('Y-m-d H:i'),
            'blocks' => $this->blocks($counts, $reconciliation),
            'written' => $counts['written'] ?? [],
            // ⚠️ Avisos aparecem. Esconder aviso é o mesmo que não ter aviso.
            'warnings' => $import->parse_warnings ?? [],
        ];
    }

    /**
     * Um bloco por linha, com o desdobramento e a conferência da soma.
     *
     * @param  array<string, mixed>  $counts
     * @param  array<string, array<string, int>>  $reconciliation
     * @return list<array<string, mixed>>
     */
    private function blocks(array $counts, array $reconciliation): array
    {
        $blocks = [];

        foreach (self::BLOCK_LABELS as $key => $label) {
            $rows = $reconciliation[$key] ?? [];
            $lines = $counts[$key] ?? 0;

            // Uma linha pode gerar MAIS de um evento (§A4), então a soma dos
            // eventos pode passar do número de linhas. A conferência que importa
            // é: toda linha foi classificada?
            $classified = array_sum($rows);

            $blocks[] = [
                'key' => $key,
                'label' => $label,
                'lines' => $lines,
                'breakdown' => $this->breakdown($rows),
                'events_and_discards' => $classified,
                // Verdadeiro quando nenhuma linha ficou sem classificação.
                // Como uma linha pode virar 2 eventos, `>=` é o correto.
                'reconciles' => $lines === 0 || $classified >= $lines,
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<string, int>  $rows
     * @return list<array{label: string, count: int, discarded: bool}>
     */
    private function breakdown(array $rows): array
    {
        arsort($rows);

        $breakdown = [];

        foreach ($rows as $key => $count) {
            $breakdown[] = [
                'label' => self::LABELS[$key] ?? $key,
                'count' => $count,
                'discarded' => str_starts_with($key, 'ignorada:'),
            ];
        }

        return $breakdown;
    }
}

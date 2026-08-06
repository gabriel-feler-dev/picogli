<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;
use App\Models\DeviceEvent;

/**
 * `get_device_events` — alertas, trocas e calibrações (FR-602).
 *
 * ⚠️ **Devolve CONTAGENS, não a lista de 266 eventos.** A pergunta real é
 * "quantos alertas eu tive?", e despejar cada linha seria o contexto cheio
 * entrando pela porta dos fundos (§D1).
 *
 * ⚠️ **Uma consulta, três agregações em PHP.** `GROUP BY` separado por
 * categoria, código e data custaria três idas ao banco e encostaria em sintaxe
 * de dialeto — a mesma decisão do `PatternDatasetBuilder` (Artigo IX).
 *
 * ## ⚠️ Por que agregação vira LISTA e não mapa
 *
 * O caminho natural seria `{"2026-07-25": 3}`. Isso quebra a allowlist do Artigo
 * VII, e a quebra é séria: `emittedKeys` enumera **nomes de campo**, e ali a
 * chave é um **dado**. Uma allowlist que precisasse listar todas as datas
 * possíveis não é uma allowlist.
 *
 * A forma correta é `[{"local_date": "2026-07-25", "event_count": 3}]` — o dado
 * vira valor, e o nome do campo continua sendo o que a lista permite. Vale como
 * regra para qualquer ferramenta futura: **dado nunca é chave**.
 */
final class DeviceEventsTool extends PeriodTool
{
    /** As categorias que o importador reconhece (`DeviceEventCategory`). */
    private const CATEGORIES = [
        'alert', 'alert_cleared', 'suspend', 'rewind', 'prime',
        'sensor_state', 'calibration',
    ];

    public function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            name: 'get_device_events',
            description: 'Eventos do aparelho num período, agregados: alertas, retomadas, '
                .'suspensões, trocas de reservatório (rewind), primes, estados do sensor e '
                .'calibrações. Devolve contagens por categoria, por código e por dia. '
                .'Use para "quantos alertas", "quando troquei o reservatório", "quantas calibrações".',
            argumentSchema: array_merge(self::PERIOD_SCHEMA, [
                'category' => [
                    'type' => 'enum',
                    'required' => false,
                    'values' => self::CATEGORIES,
                ],
            ]),
            emittedKeys: array_merge(self::PERIOD_KEYS, [
                'category', 'event_count',
                'by_category', 'by_code', 'by_date',
                'code', 'local_date',
            ]),
        );
    }

    public function run(array $args, ChatScope $scope): ToolResult
    {
        [$from, $to] = $this->window($args);
        $categoria = isset($args['category']) ? (string) $args['category'] : null;

        $query = DeviceEvent::where('user_id', $scope->userId)
            ->whereBetween('local_date', [$from, $to]);

        if ($categoria !== null) {
            $query->where('category', $categoria);
        }

        $porCategoria = [];
        $porCodigo = [];
        $porData = [];
        $total = 0;

        foreach ($query->orderBy('recorded_at_local')->get(['category', 'code', 'local_date']) as $evento) {
            $total++;
            $porCategoria[$evento->category] = ($porCategoria[$evento->category] ?? 0) + 1;
            $porCodigo[$evento->code] = ($porCodigo[$evento->code] ?? 0) + 1;

            $data = (string) $evento->local_date;
            $porData[$data] = ($porData[$data] ?? 0) + 1;
        }

        ksort($porData);
        arsort($porCodigo);

        return ToolResult::ok('get_device_events', $args, $this->envelope($from, $to, [
            'category' => $categoria,
            'event_count' => $total,
            'by_category' => $this->rows($porCategoria, 'category'),
            'by_code' => $this->rows($porCodigo, 'code'),
            'by_date' => $this->rows($porData, 'local_date'),
        ]));
    }

    /**
     * Mapa `valor => contagem` vira lista de linhas nomeadas — ver o bloco da
     * classe: **dado nunca é chave**.
     *
     * @param  array<string, int>  $counts
     * @return list<array<string, mixed>>
     */
    private function rows(array $counts, string $label): array
    {
        $rows = [];

        foreach ($counts as $valor => $contagem) {
            $rows[] = [$label => (string) $valor, 'event_count' => $contagem];
        }

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;
use App\Domain\Metrics\EpisodeDetector;
use App\Domain\Metrics\Value\Episode;
use App\Domain\Metrics\Value\EpisodeType;

/**
 * `get_episodes` — hipoglicemias e hiperglicemias nível 2 (FR-602).
 *
 * ⚠️ **Episódio é detectado por regra determinística** (`EpisodeDetector`, fase
 * 2), com a regra de término da §7.3. O modelo recebe episódios prontos; ele não
 * varre a série procurando "quedas" — isso seria o Artigo II do avesso.
 *
 * ⚠️ `interrupted_by_gap` viaja junto de propósito. Um episódio que "terminou"
 * porque o sensor parou de medir não terminou — e sem o campo, o modelo diria
 * "durou 45 minutos" com a mesma confiança dos outros.
 */
final class EpisodesTool extends PeriodTool
{
    public function __construct(private readonly EpisodeDetector $episodes) {}

    public function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            name: 'get_episodes',
            description: 'Episódios de hipoglicemia (`hypo`) ou de hiperglicemia nível 2 '
                .'(`hyper_l2`) num período, com início, duração em minutos, valor extremo '
                .'(o mais baixo numa hipo, o mais alto numa hiper) e se o episódio foi '
                .'interrompido por falha do sensor. Use para "quantas hipos eu tive", '
                .'"quando foi minha pior queda", "quanto tempo durou".',
            argumentSchema: array_merge(self::PERIOD_SCHEMA, [
                'type' => [
                    'type' => 'enum',
                    'required' => true,
                    // ⚠️ Lista fechada: o modelo não inventa um terceiro tipo.
                    'values' => ['hypo', 'hyper_l2'],
                ],
            ]),
            emittedKeys: array_merge(self::PERIOD_KEYS, [
                'type', 'episode_count', 'rows',
                'start', 'end', 'duration_minutes', 'extreme_mgdl',
                'reading_count', 'interrupted_by_gap',
                'total_duration_minutes', 'longest_duration_minutes',
            ]),
        );
    }

    public function run(array $args, ChatScope $scope): ToolResult
    {
        [$from, $to] = $this->window($args);

        $tipo = (string) $args['type'] === 'hypo'
            ? EpisodeType::Hypoglycemia
            : EpisodeType::HyperglycemiaLevel2;

        $episodios = $this->episodes->detect($this->series($scope, $from, $to), $tipo);

        $rows = array_map(fn (Episode $e): array => [
            'start' => $e->start->format('Y-m-d H:i'),
            'end' => $e->end->format('Y-m-d H:i'),
            'duration_minutes' => $this->round($e->durationMinutes, 0),
            'extreme_mgdl' => $e->extreme,
            'reading_count' => $e->readingCount,
            'interrupted_by_gap' => $e->interruptedByGap,
        ], $episodios);

        $duracoes = array_map(fn (Episode $e): float => $e->durationMinutes, $episodios);

        return ToolResult::ok('get_episodes', $args, $this->envelope($from, $to, [
            'type' => (string) $args['type'],
            'episode_count' => count($rows),
            // ⚠️ Somas calculadas aqui: o Artigo I proíbe o modelo somar a lista.
            'total_duration_minutes' => $this->round(array_sum($duracoes), 0),
            'longest_duration_minutes' => $duracoes === [] ? null : $this->round(max($duracoes), 0),
            'rows' => $rows,
        ]));
    }
}

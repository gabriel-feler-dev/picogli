<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat\Persistence;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use App\Domain\Ai\Chat\Value\ToolResult;
use App\Models\PeriodReport;

/**
 * `get_findings` — os achados que o motor determinístico já detectou (FR-602).
 *
 * ⚠️⚠️ **Esta é a ferramenta que impede o Artigo II de ser burlado.** Sem ela, a
 * pergunta "que padrões você vê nos meus dados?" empurraria o modelo a varrer
 * séries procurando padrão — e um modelo instruído a achar padrão acha padrão
 * inexistente com a mesma fluência com que acha o real. Com ela, a resposta é:
 * padrão quem detecta são as dez regras, e aqui está o que elas detectaram.
 *
 * ## O que NÃO sai daqui
 *
 * ⚠️ **`fallback_prose` fica de fora**, e é a mesma decisão do `PayloadSanitizer`
 * na fase 5: com a prosa pronta no contexto, o modelo escreve "conforme o texto
 * acima" ou simplesmente a copia — e a resposta deixa de ser uma resposta à
 * pergunta feita.
 *
 * ⚠️ **A allowlist da evidência é a MESMA da fase 5** (`ai.payload_allowlist`).
 * Não é reaproveitamento por economia: uma segunda lista, paralela, divergiria da
 * primeira no primeiro achado novo — e o Artigo VII passaria a ter duas
 * respostas para "o que pode sair daqui?".
 */
final class FindingsTool extends PeriodTool
{
    /** @param list<string> $evidenceAllowlist de `config('ai.payload_allowlist')` */
    public function __construct(private readonly array $evidenceAllowlist) {}

    public function describe(): ToolDescriptor
    {
        return new ToolDescriptor(
            name: 'get_findings',
            description: 'Os padrões que o motor determinístico do PicoGli detectou no período, '
                .'com severidade, ordem de prioridade e a evidência numérica de cada um. '
                .'Use SEMPRE que a pergunta for sobre padrões, tendências ou "o que está '
                .'acontecendo com meus dados" — os padrões são detectados por regra, nunca '
                .'procurados no texto.',
            argumentSchema: self::PERIOD_SCHEMA,
            emittedKeys: array_merge(
                self::PERIOD_KEYS,
                [
                    'finding_count', 'rows', 'rule_id', 'severity', 'rank', 'evidence',
                    'report_period_start', 'report_period_end', 'engine_version',
                    'coverage_percent', 'span_days', 'validity',
                ],
                // ⚠️ Fonte única com a fase 5 — ver o bloco da classe.
                $this->evidenceAllowlist,
            ),
        );
    }

    public function run(array $args, ChatScope $scope): ToolResult
    {
        [$from, $to] = $this->window($args);

        // O relatório que cobre o período pedido. Sobreposição parcial conta:
        // quem pergunta sobre o dia 25 quer o relatório que contém o dia 25.
        $report = PeriodReport::where('user_id', $scope->userId)
            ->where('period_start', '<=', $to)
            ->where('period_end', '>=', $from)
            ->orderByDesc('generated_at')
            ->first();

        if ($report === null) {
            return ToolResult::ok('get_findings', $args, $this->envelope($from, $to, [
                'finding_count' => 0,
                'rows' => [],
            ]));
        }

        $rows = array_map(
            fn (array $achado): array => [
                'rule_id' => $achado['rule_id'] ?? null,
                'severity' => $achado['severity'] ?? null,
                'rank' => $achado['rank'] ?? null,
                // ⚠️ Filtrada pela allowlist: uma chave nova numa regra futura
                // não sai sem revisão editorial.
                'evidence' => $this->filterEvidence($achado['evidence'] ?? []),
            ],
            $report->findings ?? [],
        );

        return ToolResult::ok('get_findings', $args, $this->envelope($from, $to, [
            // ⚠️ O período do RELATÓRIO, que pode não ser o pedido. Sem isso o
            // modelo citaria dez achados como se fossem do recorte perguntado.
            'report_period_start' => (string) $report->period_start,
            'report_period_end' => (string) $report->period_end,
            'engine_version' => $report->engine_version,
            'coverage_percent' => $this->round((float) $report->coverage_pct),
            'span_days' => $this->round((float) $report->span_days, 2),
            'validity' => $report->validity,
            'finding_count' => count($rows),
            'rows' => $rows,
        ]));
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function filterEvidence(array $evidence): array
    {
        return array_intersect_key($evidence, array_flip($this->evidenceAllowlist));
    }
}

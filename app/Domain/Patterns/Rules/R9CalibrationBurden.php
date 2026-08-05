<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Rules;

use App\Domain\Patterns\PatternsConfig;
use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Rule;
use App\Domain\Patterns\Value\Finding;
use App\Domain\Patterns\Value\PatternDataset;
use App\Domain\Patterns\Value\RuleId;
use App\Domain\Patterns\Value\Severity;

/**
 * R9 — Carga de calibração (FR-411).
 *
 * *No export de referência:* 39 calibrações em 14 dias = **2,8 por dia**.
 *
 * ## A regra mais simples do conjunto, e a que mais depende do texto
 *
 * Ela conta linhas de uma categoria. Não há limiar clínico, não há correlação,
 * não há janela temporal. E ainda assim é a que corre mais risco de violar o
 * Artigo IV — porque **"2,8 picadas de dedo por dia" lido sem contexto soa como
 * cobrança.**
 *
 * O Guardian Sensor 3 **exige** calibração por glicemia capilar para operar. O
 * número é característica do equipamento, não escolha de quem o usa. Sem essa
 * frase, a regra apresenta um fato verdadeiro de um jeito que faz a pessoa se
 * sentir mal por usar o aparelho como ele foi feito para ser usado.
 *
 * ⚠️ Teto de severidade em `Info`, imposto por `RuleId::maxSeverity()` — o
 * construtor de `Finding` recusa qualquer coisa acima. **Ela informa; não cobra.**
 */
final class R9CalibrationBurden implements Rule
{
    public function __construct(
        private readonly PatternsConfig $config,
        private readonly ProseRenderer $prose,
    ) {}

    public function id(): RuleId
    {
        return RuleId::CalibrationBurden;
    }

    public function evaluate(PatternDataset $dataset): array
    {
        $calibrations = $dataset->deviceCategoryCount('calibration');
        $days = count($dataset->daily);

        if ($calibrations < $this->config->threshold($this->id(), 'min_calibrations') || $days === 0) {
            return [];
        }

        $evidence = [
            'calibrations' => $calibrations,
            // Dias COM leitura, não dias do calendário: é o denominador que o
            // gabarito usa ("39 em 14 dias"), e o único que a pessoa reconhece.
            'days' => $days,
            'per_day' => round($calibrations / $days, 1),
        ];

        return [new Finding(
            ruleId: $this->id(),
            severity: Severity::Info,
            evidence: $evidence,
            fallbackProse: $this->prose->render($this->id(), 'prose', $evidence),
        )];
    }
}

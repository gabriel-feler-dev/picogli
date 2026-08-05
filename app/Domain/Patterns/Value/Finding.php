<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Value;

use InvalidArgumentException;

/**
 * Um padrão detectado, com a evidência numérica que o sustenta.
 *
 * ## `evidence` é o contrato com a camada de IA (Artigo II)
 *
 * "Apenas o que está em `evidence` pode aparecer na prosa." Para que essa frase
 * seja **verificável** e não uma boa intenção, o formato é restrito aqui:
 *
 *   - mapa **plano**, só escalares ou `null`;
 *   - chave em `snake_case`;
 *   - nunca vazio.
 *
 * ⚠️ **As três restrições existem por causa do Artigo VII, que só entra em cena
 * na fase 5.** O `PayloadSanitizer` vai operar por allowlist de chaves. Se
 * `evidence` fosse estrutura arbitrária, essa allowlist teria de ser inventada
 * depois, sobre um formato já espalhado por dez regras.
 *
 * A regra de `snake_case` é a mais direta das três: as chaves do CSV do CareLink
 * são `Last Name`, `Patient ID`, `BG Reading (mg/dL)`. Nenhuma delas passa por
 * este filtro. Um campo de identificação **não consegue** virar chave de
 * evidência por acidente.
 *
 * Evidência vazia é recusada porque um achado sem número é prosa sem
 * procedência, e o Artigo III não abre exceção para texto bonito.
 *
 * ## Por que a validação é no construtor
 *
 * O momento de falhar é o momento em que a regra erra — não três camadas
 * depois, na serialização para JSON. Erro que aparece longe da causa custa dez
 * vezes mais para achar, e este projeto já pagou essa conta no `strftime` do
 * `MealEnricher`.
 */
final readonly class Finding
{
    /** Chave de evidência: minúscula, começa por letra, só letra/dígito/underscore. */
    private const EVIDENCE_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param  array<string, int|float|string|bool|null>  $evidence
     */
    public function __construct(
        public RuleId $ruleId,
        public Severity $severity,
        public array $evidence,
        public string $fallbackProse,
        public bool $requiresClinicalHandoff = false,
    ) {
        $this->assertEvidenceIsFlatAndScalar($evidence, $ruleId);

        if (trim($fallbackProse) === '') {
            // Artigo I: sem IA o produto perde a redação, não o achado. Um
            // fallback vazio faria o achado desaparecer quando a API cair.
            throw new InvalidArgumentException(
                "Achado {$ruleId->value} sem prosa de fallback. O Artigo I exige que "
                .'o produto funcione com a IA desligada.'
            );
        }

        if ($ruleId->requiresClinicalHandoff() && ! $requiresClinicalHandoff) {
            // Artigo VI, camada 3. R6 não consegue existir sem o encaminhamento.
            throw new InvalidArgumentException(
                "A regra {$ruleId->value} exige encaminhamento clínico e não pode "
                .'emitir achado com requiresClinicalHandoff = false (Artigo VI).'
            );
        }

        if ($severity->weight() > $ruleId->maxSeverity()->weight()) {
            throw new InvalidArgumentException(
                "A regra {$ruleId->value} tem teto de severidade "
                ."{$ruleId->maxSeverity()->value} e recebeu {$severity->value}."
            );
        }
    }

    /**
     * Ordem de exibição, derivada de `RuleId`.
     *
     * ⚠️ **Não é parâmetro do construtor de propósito.** `PicoGli.md` §8.1
     * esboçava `rank` como campo, mas duas fontes para o mesmo número é
     * precisamente a classe de divergência que este projeto passou três fases
     * evitando: bastaria uma regra passar o rank errado para a ordenação da tela
     * discordar da decisão de produto registrada no enum.
     */
    public function rank(): int
    {
        return $this->ruleId->rank();
    }

    /** @return int|float|string|bool|null */
    public function evidenceValue(string $key): mixed
    {
        if (! array_key_exists($key, $this->evidence)) {
            throw new InvalidArgumentException(
                "O achado {$this->ruleId->value} não tem evidência '{$key}'."
            );
        }

        return $this->evidence[$key];
    }

    /** @return array<string, mixed> forma persistida em `period_reports.findings` */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId->value,
            'severity' => $this->severity->value,
            'rank' => $this->rank(),
            'evidence' => $this->evidence,
            'fallback_prose' => $this->fallbackProse,
            'requires_clinical_handoff' => $this->requiresClinicalHandoff,
        ];
    }

    /** @param array<mixed> $evidence */
    private function assertEvidenceIsFlatAndScalar(array $evidence, RuleId $ruleId): void
    {
        if ($evidence === []) {
            throw new InvalidArgumentException(
                "Achado {$ruleId->value} sem evidência. O Artigo III não admite "
                .'afirmação sem procedência.'
            );
        }

        foreach ($evidence as $key => $value) {
            if (! is_string($key) || preg_match(self::EVIDENCE_KEY_PATTERN, $key) !== 1) {
                throw new InvalidArgumentException(
                    "Chave de evidência inválida em {$ruleId->value}: '{$key}'. "
                    .'Use snake_case — é o que impede um cabeçalho do CSV '
                    .'("Last Name", "Patient ID") de virar chave (Artigo VII).'
                );
            }

            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException(
                    "Evidência '{$key}' de {$ruleId->value} é ".get_debug_type($value)
                    .'. Só escalar ou null: `evidence` é a allowlist da fase 5, '
                    .'e allowlist de estrutura aninhada não se verifica.'
                );
            }
        }
    }
}

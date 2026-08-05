<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Value\AiPayload;
use InvalidArgumentException;

/**
 * A ÚNICA porta de saída em direção a um provedor de IA (Artigo VII, FR-501).
 *
 * ## Por que allowlist, e não denylist
 *
 * Com denylist, um campo novo num export futuro vira **vazamento silencioso** —
 * ninguém o proibiu porque ninguém sabia que ele existia. Com allowlist, o
 * comportamento seguro é o default: a chave desconhecida é descartada, e o teste
 * quebra até alguém revisá-la.
 *
 * ## Duas barreiras, não uma
 *
 * | Barreira | Onde | O que impede |
 * |---|---|---|
 * | **Estrutural** | construtor de `Finding` (fase 4) | chave suja EXISTIR |
 * | **Editorial** | a allowlist desta classe | chave nova SAIR sem revisão |
 *
 * A estrutural já derruba tudo que vem do CareLink: as chaves de lá são
 * `Last Name`, `Patient ID`, `BG Reading (mg/dL)`, e nenhuma passa pelo regex
 * `/^[a-z][a-z0-9_]*$/` que `Finding` aplica. A editorial existe para o caso em
 * que **eu mesmo** adiciono uma chave legítima e ninguém olha.
 *
 * ## O que NUNCA entra
 *
 * - a série de leituras (o modelo não calcula — Artigo I)
 * - qualquer campo de `FileHeader::$patient`
 * - `original_filename` (costuma trazer o nome da pessoa)
 * - `device_serial`
 * - `fallback_prose` (o modelo escreveria "conforme o texto acima")
 *
 * ⚠️ Nada disso depende de disciplina: o que não está na allowlist não passa,
 * e a lista não contém nenhuma dessas chaves.
 */
final class PayloadSanitizer
{
    /** @param list<string> $allowedKeys `config('ai.payload_allowlist')` */
    public function __construct(private readonly array $allowedKeys)
    {
        if ($this->allowedKeys === []) {
            // Allowlist vazia deixaria passar nada — mas, mais provavelmente,
            // significa config mal carregada. Explodir aqui é melhor que
            // devolver payload vazio e alguém debugar o prompt.
            throw new InvalidArgumentException(
                'A allowlist do payload está vazia. Confira config/ai.php.'
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $findings  entradas de `period_reports.findings`
     * @param  array<string, mixed>  $period
     */
    public function sanitize(array $findings, array $period): AiPayload
    {
        $dropped = [];
        $clean = $this->filter($period, $dropped);

        // ⚠️ `foreach` e não `array_map`. Arrow function em PHP captura **por
        // valor**, e não existe forma de capturar `$dropped` por referência
        // dentro de um `fn()` — o acumulador seria uma cópia, e o descarte
        // sumiria em silêncio. Foi exatamente o que aconteceu na primeira
        // versão desta classe, e só os testes de `droppedKeys` pegaram.
        $findingsLimpos = [];

        foreach ($findings as $finding) {
            $findingsLimpos[] = $this->sanitizeFinding($finding, $dropped);
        }

        return new AiPayload(
            period: $clean,
            findings: $findingsLimpos,
            droppedKeys: array_values(array_unique($dropped)),
        );
    }

    /**
     * @param  array<string, mixed>  $finding
     * @param  list<string>  $dropped
     * @return array{rule_id: string, severity: string, rank: int, evidence: array<string, int|float|string|bool|null>}
     */
    private function sanitizeFinding(array $finding, array &$dropped): array
    {
        // ⚠️ `fallback_prose` fica de fora DE PROPÓSITO. Mandá-la faria o modelo
        // parafrasear um texto em vez de escrever a partir dos números — e a
        // prosa gerada passaria a depender da prosa estática, em vez de ambas
        // dependerem da mesma evidência.
        return [
            'rule_id' => (string) ($finding['rule_id'] ?? ''),
            'severity' => (string) ($finding['severity'] ?? ''),
            'rank' => (int) ($finding['rank'] ?? 0),
            'evidence' => $this->filter($finding['evidence'] ?? [], $dropped),
        ];
    }

    /**
     * Mantém só chave conhecida e valor escalar.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $dropped
     * @return array<string, int|float|string|bool|null>
     */
    private function filter(array $values, array &$dropped): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! in_array($key, $this->allowedKeys, true)) {
                $dropped[] = is_string($key) ? $key : (string) $key;

                continue;
            }

            // Cinto e suspensório: a fase 4 já garante escalar em `evidence`,
            // mas este objeto pode receber array vindo do JSON persistido — e um
            // relatório antigo pode ter formato que a versão atual não conhece.
            if ($value !== null && ! is_scalar($value)) {
                $dropped[] = $key;

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}

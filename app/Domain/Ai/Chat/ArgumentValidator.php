<?php

declare(strict_types=1);

namespace App\Domain\Ai\Chat;

use App\Domain\Ai\Chat\Value\ChatScope;
use App\Domain\Ai\Chat\Value\ToolDescriptor;
use DateTimeImmutable;

/**
 * Valida o que o modelo pediu, antes de virar query (Spec 006, §D2, FR-603).
 *
 * ⚠️⚠️ **Argumento de ferramenta é a única entrada deste sistema em que o texto
 * do usuário influencia uma consulta.** Tratar como entrada não confiável não é
 * paranoia: é a diferença entre uma ferramenta e uma injeção.
 *
 * ## Quatro regras, e a terceira é a menos óbvia
 *
 * **1. Argumento desconhecido é ERRO, não é ignorado.**
 * ⚠️ Aceitar e descartar em silêncio esconderia justamente a tentativa que
 * interessa ver — um `user_id` chegando junto. Recusar devolve ao modelo uma
 * mensagem que ele entende, e deixa rastro.
 *
 * **2. Data é `YYYY-MM-DD` e existe no calendário.** `2026-02-30` casa com o
 * regex e não é um dia.
 *
 * **3. ⚠️ Períodos são pareados por CONVENÇÃO de nome.** `start`/`end`,
 * `a_start`/`a_end`, `b_start`/`b_end` — qualquer sufixo `_start` procura o
 * `_end` do mesmo prefixo. Sem isso, cada ferramenta declararia os próprios
 * pares, e `compare_periods` (que tem dois) seria o primeiro a esquecer um.
 *
 * **4. O teto de span vem do `ChatScope`**, não da ferramenta. Dez ferramentas
 * com o próprio limite são dez chances de esquecer um — e "como foi meu último
 * ano?" vira varredura de 105 mil leituras.
 *
 * Devolve `null` quando está tudo certo. Erro é `string`, e vai **de volta ao
 * modelo**: ele costuma corrigir sozinho na iteração seguinte.
 */
final class ArgumentValidator
{
    /**
     * @param  array<string, mixed>  $args
     * @return string|null a mensagem de erro, ou `null` se válido
     */
    public function validate(ToolDescriptor $tool, array $args, ChatScope $scope): ?string
    {
        foreach (array_keys($args) as $nome) {
            if ($tool->rule((string) $nome) === null) {
                // ⚠️ Regra 1. Um `user_id` que chegasse aqui morre nesta linha.
                return "argumento desconhecido: '{$nome}'. Aceitos: "
                    .implode(', ', array_keys($tool->argumentSchema));
            }
        }

        foreach ($tool->argumentSchema as $nome => $regra) {
            $ausente = ! array_key_exists($nome, $args) || $args[$nome] === null;

            if ($ausente) {
                if ($tool->requires($nome)) {
                    return "argumento obrigatório ausente: '{$nome}'";
                }

                continue;
            }

            $erro = $this->checkValue((string) $nome, $args[$nome], $regra);

            if ($erro !== null) {
                return $erro;
            }
        }

        return $this->checkPeriods($args, $scope);
    }

    /** @param array<string, mixed> $regra */
    private function checkValue(string $nome, mixed $valor, array $regra): ?string
    {
        return match ($regra['type'] ?? 'string') {
            'date' => $this->checkDate($nome, $valor),
            'enum' => $this->checkEnum($nome, $valor, $regra['values'] ?? []),
            'int' => $this->checkNumber($nome, $valor, $regra, inteiro: true),
            'float' => $this->checkNumber($nome, $valor, $regra, inteiro: false),
            default => is_scalar($valor)
                ? null
                : "argumento '{$nome}' deve ser um valor simples",
        };
    }

    private function checkDate(string $nome, mixed $valor): ?string
    {
        if (! is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return "argumento '{$nome}' deve ser uma data no formato YYYY-MM-DD";
        }

        [$ano, $mes, $dia] = array_map('intval', explode('-', $valor));

        // ⚠️ Regra 2. `2026-02-30` passa no regex e não existe.
        if (! checkdate($mes, $dia, $ano)) {
            return "argumento '{$nome}': '{$valor}' não é uma data do calendário";
        }

        return null;
    }

    /** @param list<string> $permitidos */
    private function checkEnum(string $nome, mixed $valor, array $permitidos): ?string
    {
        if (! is_string($valor) || ! in_array($valor, $permitidos, true)) {
            return "argumento '{$nome}' deve ser um de: ".implode(', ', $permitidos);
        }

        return null;
    }

    /** @param array<string, mixed> $regra */
    private function checkNumber(string $nome, mixed $valor, array $regra, bool $inteiro): ?string
    {
        if (! is_numeric($valor)) {
            return "argumento '{$nome}' deve ser numérico";
        }

        if ($inteiro && (float) $valor !== floor((float) $valor)) {
            return "argumento '{$nome}' deve ser um número inteiro";
        }

        $numero = (float) $valor;

        if (isset($regra['min']) && $numero < (float) $regra['min']) {
            return "argumento '{$nome}' deve ser >= {$regra['min']}";
        }

        if (isset($regra['max']) && $numero > (float) $regra['max']) {
            return "argumento '{$nome}' deve ser <= {$regra['max']}";
        }

        return null;
    }

    /**
     * ⚠️ Regras 3 e 4 — coerência e teto de todo par de datas encontrado.
     *
     * @param  array<string, mixed>  $args
     */
    private function checkPeriods(array $args, ChatScope $scope): ?string
    {
        foreach ($this->periodPairs($args) as [$chaveInicio, $chaveFim]) {
            $inicio = new DateTimeImmutable((string) $args[$chaveInicio]);
            $fim = new DateTimeImmutable((string) $args[$chaveFim]);

            if ($inicio > $fim) {
                return "período inválido: '{$chaveInicio}' é posterior a '{$chaveFim}'";
            }

            // +1 porque o período é fechado nos dois extremos: de 16 a 29 são
            // 14 dias, não 13. O mesmo critério do `CoverageCalculator`.
            $dias = (int) $inicio->diff($fim)->days + 1;

            if (! $scope->allowsSpan($dias)) {
                return "período de {$dias} dias é maior que o máximo de "
                    ."{$scope->maxSpanDays} dias por consulta. Peça um recorte menor.";
            }
        }

        return null;
    }

    /**
     * Pares de data, por convenção de nome.
     *
     * `start`/`end` e qualquer `<prefixo>_start`/`<prefixo>_end`.
     *
     * @param  array<string, mixed>  $args
     * @return list<array{0: string, 1: string}>
     */
    private function periodPairs(array $args): array
    {
        $pares = [];

        foreach (array_keys($args) as $chave) {
            $chave = (string) $chave;

            if ($chave === 'start' && isset($args['end'])) {
                $pares[] = ['start', 'end'];

                continue;
            }

            if (str_ends_with($chave, '_start')) {
                $fim = substr($chave, 0, -6).'_end';

                if (isset($args[$fim])) {
                    $pares[] = [$chave, $fim];
                }
            }
        }

        return $pares;
    }
}

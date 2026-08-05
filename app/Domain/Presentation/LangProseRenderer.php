<?php

declare(strict_types=1);

namespace App\Domain\Presentation;

use App\Domain\Patterns\ProseRenderer;
use App\Domain\Patterns\Value\RuleId;
use InvalidArgumentException;

/**
 * Monta a prosa de fallback a partir de `lang/pt_BR/patterns.php` (§D3).
 *
 * ⚠️ Vive em `Presentation/` e não em `Patterns/` porque chama `__()`. É a borda
 * que o `ProseRenderer` existe para isolar — as regras recebem a interface e
 * continuam PHP puro (NFR-401).
 *
 * ## O que esta classe garante
 *
 * **Todo `:placeholder` é substituído, ou a construção do achado falha.** Um
 * `:average` esquecido no texto é visível na tela — mas só depois de publicado.
 * Falhar aqui transforma um bug de aparência num erro de teste.
 *
 * **Todo placeholder rastreia até a evidência** (Artigo II). As duas únicas
 * fontes de substituição são:
 *
 *   - `:chave` — valor de `evidence['chave']`, formatado em pt-BR;
 *   - `:chave_label` / `:chave_range` — tradução de um valor de evidência que é
 *     identificador de período do dia (`afternoon` → "tarde").
 *
 * O segundo caso não introduz informação nova: a palavra "tarde" é o rótulo de um
 * valor que está na evidência. A identidade fica na evidência, a palavra no
 * arquivo de idioma — que é o que mantém o Artigo IV verificável por varredura.
 */
final class LangProseRenderer implements ProseRenderer
{
    /** Placeholders no formato `:chave_com_underscore`. */
    private const PLACEHOLDER_PATTERN = '/:([a-z][a-z0-9_]*)/';

    public function render(RuleId $rule, string $key, array $evidence): string
    {
        $template = __($rule->langKey().'.'.$key);

        if (! is_string($template) || $template === $rule->langKey().'.'.$key) {
            throw new InvalidArgumentException(
                "Falta a prosa '{$key}' de {$rule->value} em lang/pt_BR/patterns.php."
            );
        }

        $replacements = $this->replacementsFor($evidence);

        // Ordena por comprimento decrescente: sem isso, `:ratio` substituiria o
        // começo de `:ratio_threshold` e deixaria "_threshold" solto no texto.
        uksort($replacements, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $prose = $template;

        foreach ($replacements as $placeholder => $value) {
            $prose = str_replace(':'.$placeholder, $value, $prose);
        }

        $this->assertNothingLeftUnreplaced($prose, $rule, $key, $evidence);

        return $prose;
    }

    /** @return array<string, string> */
    private function replacementsFor(array $evidence): array
    {
        $replacements = [];
        $daypartLabels = __('patterns.dayparts');
        $daypartRanges = __('patterns.dayparts_range');

        foreach ($evidence as $key => $value) {
            $replacements[$key] = $this->format($value);

            // Valor que é identificador de período ganha rótulo e faixa horária.
            if (is_string($value) && is_array($daypartLabels) && isset($daypartLabels[$value])) {
                $replacements[$key.'_label'] = (string) $daypartLabels[$value];

                if (is_array($daypartRanges) && isset($daypartRanges[$value])) {
                    $replacements[$key.'_range'] = (string) $daypartRanges[$value];
                }
            }
        }

        return $replacements;
    }

    /**
     * Formata um valor de evidência para leitura em pt-BR.
     *
     * ⚠️ Uma casa decimal, e `,0` é descartado. `5,78` vira "5,8" e `100,0` vira
     * "100".
     *
     * *Por quê uma casa:* o valor **exato** continua na evidência, que é o que a
     * fase 5 vai receber e o que a tela mostra ao expandir. O que se formata aqui
     * é só a leitura — e "você passa 5,78 vezes mais tempo" tem uma precisão que
     * a frase não sustenta. Formatar não pode virar arredondar o dado: é a lição
     * dos 295,16 U da fase 1, e por isso a formatação mora AQUI e não na regra.
     */
    private function format(int|float|string|bool|null $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'sim' : 'não',
            is_int($value) => number_format($value, 0, ',', '.'),
            is_float($value) => $this->formatFloat($value),
            default => $value,
        };
    }

    private function formatFloat(float $value): string
    {
        $formatted = number_format($value, 1, ',', '.');

        return str_ends_with($formatted, ',0') ? substr($formatted, 0, -2) : $formatted;
    }

    private function assertNothingLeftUnreplaced(
        string $prose,
        RuleId $rule,
        string $key,
        array $evidence,
    ): void {
        if (preg_match_all(self::PLACEHOLDER_PATTERN, $prose, $matches) < 1) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            "A prosa '%s' de %s tem placeholder sem evidência: %s. Chaves "
            .'disponíveis: %s.',
            $key,
            $rule->value,
            implode(', ', array_map(fn (string $m): string => ':'.$m, $matches[1])),
            implode(', ', array_keys($evidence)) ?: '(nenhuma)',
        ));
    }
}

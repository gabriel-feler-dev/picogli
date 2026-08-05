<?php

declare(strict_types=1);

namespace App\Domain\Ai;

use App\Domain\Ai\Value\AiPayload;

/**
 * Confronta cada número da prosa gerada com a evidência (FR-505, §D5).
 *
 * ## O que ela protege
 *
 * O Artigo III diz que toda afirmação numérica rastreia até uma linha do banco.
 * Um modelo de linguagem escreve "aproximadamente 4 horas" com a mesma fluência
 * com que escreve "aproximadamente 7 horas", e **o usuário não tem como
 * distinguir** — todos os outros números da tela são verdadeiros.
 *
 * ⚠️ **Número órfão descarta a narrativa INTEIRA**, não a frase. Uma prosa com um
 * número inventado e nove corretos é pior que nenhuma prosa: quem lê não sabe
 * qual é qual.
 *
 * ⚠️ **E descartar só é aceitável por causa da fase 4.** O `fallbackProse` de cada
 * achado já é publicável, então o descarte devolve a tela ao estado de ontem — não
 * a um estado pior. Se o fallback fosse `"R3 disparou"`, esta guarda seria
 * impossível de aplicar e viraria negociável.
 *
 * ## De onde vem a procedência
 *
 * Um número é legítimo se corresponde a **qualquer** um destes:
 *
 * | Fonte | Exemplo |
 * |---|---|
 * | valor numérico da evidência | `ratio = 5.78` → "5,78" |
 * | o mesmo valor arredondado a 0, 1 ou 2 casas | `4.6` → "cerca de 5 horas" |
 * | número dentro de um valor de texto | `dominant_date = '2026-07-25'` → "2026", "25" |
 * | lista de isenção | "um padrão", "as 24 horas do dia" |
 *
 * ⚠️ A terceira linha não é frouxidão — é necessidade. A prosa cita datas e
 * horários (`'18:06'`, `'2026-07-25'`), que a evidência guarda como **string**
 * porque §D1 exige escalar plano. Sem extrair números de dentro delas, toda
 * narrativa que cite uma data seria descartada.
 *
 * ⚠️ A quarta existe para a guarda não virar ruído: "um padrão", "faixa de 70 a
 * 180", "as 24 horas". **Guarda ruidosa é guarda desligada**, e aí não protege
 * nada.
 */
final class NumberGuard
{
    /**
     * Numeral em pt-BR: milhar com ponto, decimal com vírgula.
     *
     * `1.347` · `5,78` · `24` · `91,1`
     */
    private const NUMBER_PATTERN = '/\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:,\d+)?/u';

    /** @param list<int|float> $exemptNumbers */
    public function __construct(
        private readonly float $tolerance,
        private readonly array $exemptNumbers,
    ) {}

    /**
     * Os números da prosa que não têm procedência.
     *
     * Vazio = a narrativa pode ser publicada.
     *
     * @return list<string> como apareceram no texto, para o log ser investigável
     */
    public function orphans(string $prose, AiPayload $payload): array
    {
        [$measured, $literal] = $this->sourcesFrom($payload);
        $orphans = [];

        foreach ($this->numbersIn($prose) as $written => $value) {
            if (! $this->hasProvenance($value, $measured, $literal)) {
                $orphans[] = (string) $written;
            }
        }

        return array_values(array_unique($orphans));
    }

    /** Atalho de leitura: a narrativa pode ser publicada? */
    public function approves(string $prose, AiPayload $payload): bool
    {
        return $this->orphans($prose, $payload) === [];
    }

    /**
     * Numerais do texto, indexados pela forma escrita.
     *
     * @return array<string, float>
     */
    private function numbersIn(string $prose): array
    {
        preg_match_all(self::NUMBER_PATTERN, $prose, $matches);

        $numbers = [];

        foreach ($matches[0] as $written) {
            $numbers[$written] = $this->toFloat($written);
        }

        return $numbers;
    }

    /**
     * As duas famílias de fonte — e a distinção entre elas é o coração da guarda.
     *
     * ⚠️ **`measured`** são valores numéricos da evidência: *medições*. Sobre eles
     * a tolerância relativa faz sentido, porque "quase 25%" para 24,10% é uma
     * forma legítima de relatar uma medida.
     *
     * ⚠️ **`literal`** são números que não medem nada: o `25` de `'2026-07-25'`, o
     * `18` de `'18:06'`, e os da lista de isenção. Sobre eles **a tolerância não
     * se aplica** — apenas correspondência exata ou arredondada.
     *
     * *Por quê separar:* sem isso, o dia do mês de uma data autoriza qualquer
     * número a 6% dele. Foi o que aconteceu na primeira versão desta classe: o
     * `07` de `'2026-07-16'` fez a guarda **aceitar "7,3 vezes"** — uma alucinação
     * — porque 7,3 está a 4% de 7. Quatro testes pegaram, todos pela mesma causa.
     *
     * @return array{0: list<float>, 1: list<float>} `[measured, literal]`
     */
    private function sourcesFrom(AiPayload $payload): array
    {
        $measured = [];
        // A lista de isenção é literal: "as 24 horas do dia" não é medição, e
        // tolerar 6% em cima dela autorizaria 22,6 e 25,4 de graça.
        $literal = array_map(fn ($n): float => (float) $n, $this->exemptNumbers);

        $collect = function (array $values) use (&$measured, &$literal): void {
            foreach ($values as $value) {
                if (is_int($value) || is_float($value)) {
                    $measured[] = (float) $value;

                    continue;
                }

                // ⚠️ Número DENTRO de texto. `dominant_date` é `'2026-07-25'`
                // porque §D1 exige escalar plano; sem isto, toda narrativa que
                // cite uma data seria descartada. Mas entra como LITERAL: o dia
                // do mês não é uma medida com margem.
                if (is_string($value)) {
                    foreach ($this->numbersIn($value) as $embedded) {
                        $literal[] = $embedded;
                    }
                }
            }
        };

        $collect($payload->period);

        foreach ($payload->findings as $finding) {
            $collect($finding['evidence']);
            // `rank` é número e aparece no payload; citá-lo na prosa seria
            // estranho, mas não é invenção. Literal: rank não é medida.
            $literal[] = (float) $finding['rank'];
        }

        return [$measured, $literal];
    }

    /**
     * @param  list<float>  $measured
     * @param  list<float>  $literal
     */
    private function hasProvenance(float $value, array $measured, array $literal): bool
    {
        foreach ($literal as $source) {
            if ($this->matchesExactlyOrRounded($value, $source)) {
                return true;
            }
        }

        foreach ($measured as $source) {
            if ($this->matchesExactlyOrRounded($value, $source) || $this->withinTolerance($value, $source)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ⚠️ **Arredondamento DECLARADO, e não só tolerância.** Tolerância relativa
     * sozinha reprovaria o caso legítimo mais comum: 4,6 h escrito como "cerca de
     * 5 horas" está a 8,7% do valor — acima de qualquer tolerância defensável — e
     * ainda assim é arredondamento correto para a casa inteira.
     *
     * Aceitar o valor arredondado a 0, 1 ou 2 casas resolve isso com uma regra
     * explicável, em vez de afrouxar a tolerância até caber.
     */
    private function matchesExactlyOrRounded(float $written, float $source): bool
    {
        foreach ([0, 1, 2] as $decimals) {
            if (abs($written - round($source, $decimals)) < 0.0000001) {
                return true;
            }
        }

        return false;
    }

    /** Só para medições — ver `sourcesFrom()`. */
    private function withinTolerance(float $written, float $source): bool
    {
        if ($source === 0.0) {
            return abs($written) < 0.0000001;
        }

        return abs($written - $source) / abs($source) <= $this->tolerance;
    }

    private function toFloat(string $written): float
    {
        return (float) str_replace(',', '.', str_replace('.', '', $written));
    }
}

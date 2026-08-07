<?php

declare(strict_types=1);

namespace App\Domain\Import\Pdf\Value;

use InvalidArgumentException;

/**
 * Um número que veio de um PDF (Spec 007, FR-705, §D6, §D7).
 *
 * ⚠️⚠️ **`source` é sempre `pdf_aggregate`, validado no construtor — e essa
 * constante é o coração do item 3.**
 *
 * Ela existe para que o número **não possa** ser confundido com dado de CSV em
 * nenhum ponto do caminho: nem na tabela, nem no payload, nem na tela. Um
 * agregado de PDF e uma métrica calculada sobre 3.616 leituras têm procedências
 * muito diferentes, e o Artigo V — por analogia — proíbe exibir a mais fraca como
 * se tivesse a mesma força.
 *
 * ## O que este objeto NÃO é
 *
 * ⚠️ **Não é evento.** Não existe caminho em que ele grave linha em
 * `sensor_readings`, `insulin_doses` ou qualquer tabela de evento (§D6). As nove
 * tabelas são a fundação determinística de três fases; um agregado ali traria
 * granularidade diferente, e **nenhuma métrica saberia disso** — o
 * `StatisticsCalculator` trataria um "TIR 78%" resumido como se fosse a soma das
 * leituras.
 *
 * ⚠️ **Não vem de pixel.** `PicoGli.md` §6.3: número lido de gráfico por OCR ou
 * modelo multimodal é chute, e o chute sai plausível com uma casa decimal. Só
 * texto e célula de tabela.
 */
final readonly class PdfAggregate
{
    /** A única procedência possível. Ver o bloco da classe. */
    public const SOURCE = 'pdf_aggregate';

    public function __construct(
        public PdfMetric $metric,
        public float $value,
        public string $periodStart,
        public string $periodEnd,
        /** O trecho de texto de onde o número saiu — para auditoria. */
        public ?string $excerpt = null,
        public string $source = self::SOURCE,
    ) {
        if ($this->source !== self::SOURCE) {
            throw new InvalidArgumentException(
                "Agregado com source '{$this->source}'. Só existe uma procedência aqui: "
                .self::SOURCE.'. Um agregado que se passasse por dado de CSV seria '
                .'exibido com a mesma força de uma métrica sobre 3.616 leituras.'
            );
        }

        if (! $this->metric->accepts($this->value)) {
            throw new InvalidArgumentException(sprintf(
                "Valor %s fora do plausível para '%s'. Extração torta grava número que "
                .'aparece na tela com a mesma aparência dos corretos.',
                $this->value,
                $this->metric->value,
            ));
        }

        foreach (['periodStart' => $this->periodStart, 'periodEnd' => $this->periodEnd] as $nome => $data) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) !== 1) {
                throw new InvalidArgumentException("{$nome} inválido: '{$data}'. Use YYYY-MM-DD.");
            }
        }

        if ($this->periodStart > $this->periodEnd) {
            throw new InvalidArgumentException(
                "Período invertido: {$this->periodStart} depois de {$this->periodEnd}."
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value,
            'value' => $this->value,
            'unit' => $this->metric->unit(),
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            // ⚠️ Viaja em TODA serialização. Um payload sem ele deixaria a tela
            // sem como saber que aquele número é resumo.
            'source' => $this->source,
        ];
    }
}

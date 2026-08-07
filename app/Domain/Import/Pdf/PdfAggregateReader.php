<?php

declare(strict_types=1);

namespace App\Domain\Import\Pdf;

use App\Domain\Import\Pdf\Value\PdfAggregate;

/**
 * Extrai agregados de um relatório em PDF (Spec 007, FR-705, §D5, §6.3).
 *
 * ⚠️⚠️ **A lei deste contrato está no `PicoGli.md` §6.3:**
 *
 * > **Não** tente extrair valores numéricos dos gráficos por visão computacional
 * > ou modelo multimodal. Um modelo lendo pixels de uma curva de CGM chuta
 * > valores; serve para descrever *forma*, não para alimentar cálculo.
 *
 * **Permitido:** número que aparece como texto ou célula de tabela.
 * **Proibido:** OCR, modelo multimodal, qualquer número derivado de pixel.
 *
 * É o Artigo I aplicado a uma fonte nova — número lido de pixel não rastreia até
 * nada. Há teste varrendo este diretório por `ocr`, `tesseract`, `imagick`,
 * `vision` e `multimodal`.
 *
 * ⚠️ **Fallback, nunca fonte primária.** O CSV traz leitura de 5 em 5 minutos; o
 * PDF traz o que a Medtronic decidiu resumir. Preferir o resumo quando o dado
 * existe é trocar dado por resumo de dado — e a troca seria invisível depois.
 *
 * ⚠️ **Domínio puro.** Quem abre arquivo é a implementação, em
 * `app/Infrastructure/Import/`.
 */
interface PdfAggregateReader
{
    /**
     * Os agregados que o arquivo contém.
     *
     * ⚠️ **PDF sem os agregados esperados devolve lista VAZIA, não exceção.** É a
     * mesma disciplina do CSV sem bloco: arquivo que não é o que se esperava é
     * caso previsto, não erro de programa. Quem chama decide o que dizer ao
     * usuário.
     *
     * @return list<PdfAggregate>
     */
    public function read(string $path): array;
}

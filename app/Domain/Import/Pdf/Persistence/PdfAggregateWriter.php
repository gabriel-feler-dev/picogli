<?php

declare(strict_types=1);

namespace App\Domain\Import\Pdf\Persistence;

use App\Domain\Import\Pdf\Value\PdfAggregate as PdfAggregateValue;
use App\Models\PdfAggregate;
use App\Models\SensorReading;

/**
 * Grava agregados de PDF (Spec 007, FR-705, §D6).
 *
 * ⚠️⚠️ **Esta classe não conhece o `EventExploder`, e nunca vai conhecer.** Ela
 * escreve numa tabela só — `pdf_aggregates` — e há teste varrendo o diretório para
 * garantir que nenhuma referência a tabela de evento apareça aqui.
 *
 * ## ⚠️ CSV existente não é substituído (§D6)
 *
 * Agregado de um período que já tem leitura de sensor é **gravado e marcado**, mas
 * a tela mostra o dado do CSV. O CSV traz leitura de 5 em 5 minutos; o PDF traz o
 * que a Medtronic decidiu resumir — preferir o resumo quando o dado existe é
 * trocar dado por resumo de dado, e a troca seria invisível depois.
 *
 * O agregado não é descartado: ele fica, marcado como redundante, porque descartar
 * apagaria a única prova de que o PDF foi importado.
 */
final class PdfAggregateWriter
{
    /**
     * @param  list<PdfAggregateValue>  $aggregates
     * @return int quantos foram gravados
     */
    public function write(int $userId, array $aggregates, ?int $importId = null): int
    {
        $gravados = 0;

        foreach ($aggregates as $agregado) {
            // Reimportar o mesmo relatório ATUALIZA; não empilha (Artigo VIII.4
            // por analogia).
            PdfAggregate::updateOrCreate(
                [
                    'user_id' => $userId,
                    'metric' => $agregado->metric,
                    'period_start' => $agregado->periodStart,
                    'period_end' => $agregado->periodEnd,
                ],
                [
                    'import_id' => $importId,
                    'value' => $agregado->value,
                    'unit' => $agregado->metric->unit(),
                    // ⚠️ Sempre a constante. O construtor do value object já
                    // recusou qualquer outra coisa.
                    'source' => PdfAggregateValue::SOURCE,
                ],
            );

            $gravados++;
        }

        return $gravados;
    }

    /**
     * O período já tem dado de CSV?
     *
     * ⚠️ Quem decide o que exibir é a tela, com esta resposta na mão. O writer não
     * esconde nem apaga o agregado — ele informa que existe fonte melhor.
     */
    public function hasCsvFor(int $userId, string $from, string $to): bool
    {
        return SensorReading::where('user_id', $userId)
            ->whereBetween('local_date', [$from, $to])
            ->exists();
    }
}

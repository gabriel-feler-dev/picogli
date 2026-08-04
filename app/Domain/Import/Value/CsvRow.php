<?php

declare(strict_types=1);

namespace App\Domain\Import\Value;

use App\Domain\Import\BlockType;
use App\Domain\Import\LocaleNormalizer;
use DateTimeImmutable;

/**
 * Uma linha de dados do CSV, com acesso por NOME de coluna.
 *
 * Acesso por nome, e não por índice, é decisão deliberada: o arquivo tem 54
 * colunas e `$fields[28]` num parser dessa largura é convite a erro silencioso
 * de deslocamento. Se o CareLink inserir uma coluna nova no meio, o acesso por
 * nome continua correto e o por índice passa a ler outra coisa sem reclamar.
 *
 * A linha carrega o `BlockType` de origem porque a semântica depende dele: as
 * mesmas 54 colunas significam coisas diferentes nos blocos Pump, AutoInsulin
 * e Sensor (§3.2). O `EventExploder` despacha por este campo.
 *
 * Toda leitura tipada passa pelo `LocaleNormalizer` — Artigo VIII.1.
 */
final readonly class CsvRow
{
    /**
     * @param  array<string, int>  $columnIndex  nome da coluna → posição
     * @param  list<string>  $fields  células cruas, na ordem do arquivo
     */
    public function __construct(
        public BlockType $block,
        private array $columnIndex,
        private array $fields,
        private LocaleNormalizer $normalizer,
        public int $lineNumber,
    ) {}

    /**
     * Célula crua e normalizada (trim, aspas, BOM). Vazio → null.
     *
     * Coluna inexistente também devolve null: exports de firmwares diferentes
     * podem não ter todas as 54 colunas, e isso não é motivo para explodir.
     */
    public function str(string $column): ?string
    {
        $index = $this->columnIndex[$column] ?? null;

        if ($index === null) {
            return null;
        }

        return $this->normalizer->cell($this->fields[$index] ?? null);
    }

    /** Valor numérico. Texto não numérico → null, nunca 0 (Artigo VIII.2). */
    public function num(string $column): ?float
    {
        $index = $this->columnIndex[$column] ?? null;

        if ($index === null) {
            return null;
        }

        return $this->normalizer->number($this->fields[$index] ?? null);
    }

    /** Valor inteiro (glicose em mg/dL, contadores). */
    public function int(string $column): ?int
    {
        $index = $this->columnIndex[$column] ?? null;

        if ($index === null) {
            return null;
        }

        return $this->normalizer->integer($this->fields[$index] ?? null);
    }

    public function filled(string $column): bool
    {
        return $this->str($column) !== null;
    }

    /**
     * Timestamp da linha, montado das colunas `Date` e `Time`.
     *
     * ⚠️ É HORA LOCAL DE PAREDE do dispositivo (§A5). O arquivo não carrega
     * fuso. Converter para UTC exige o fuso informado na importação, e isso
     * é responsabilidade do Job — não desta classe.
     */
    public function recordedAtLocal(): ?DateTimeImmutable
    {
        $index = $this->columnIndex['Date'] ?? null;
        $timeIndex = $this->columnIndex['Time'] ?? null;

        if ($index === null || $timeIndex === null) {
            return null;
        }

        return $this->normalizer->rowDateTime(
            $this->fields[$index] ?? null,
            $this->fields[$timeIndex] ?? null,
        );
    }

    /**
     * A coluna `Index` do CSV — única ordem confiável (§A6).
     *
     * Fim de horário de verão faz o timestamp local se repetir; este valor é
     * monotônico e desempata.
     */
    public function deviceIndex(): ?float
    {
        return $this->num('Index');
    }

    /**
     * Verdadeiro quando nenhuma coluna além de Index/Date/Time está preenchida.
     *
     * O arquivo real tem linhas assim — um timestamp sem nenhum evento
     * associado. Não são erro de parsing e não devem virar warning.
     */
    public function isEmptyEvent(): bool
    {
        foreach ($this->columnIndex as $column => $index) {
            if (in_array($column, ['Index', 'Date', 'Time'], true)) {
                continue;
            }

            if ($this->normalizer->cell($this->fields[$index] ?? null) !== null) {
                return false;
            }
        }

        return true;
    }

    /** Para diagnóstico em parse_warnings. Não usar em lógica. */
    public function rawFields(): array
    {
        return $this->fields;
    }
}

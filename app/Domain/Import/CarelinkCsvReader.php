<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Import\Value\CsvRow;
use App\Domain\Import\Value\FileHeader;
use Generator;
use RuntimeException;

/**
 * Leitor de export CSV do CareLink.
 *
 * Responsabilidades:
 *   - ler o cabeçalho (linhas 1–3)
 *   - segmentar o arquivo nos três blocos pelos separadores
 *   - mapear posição → nome de coluna
 *   - emitir `CsvRow` em streaming
 *
 * NÃO é responsabilidade dele: converter valores (é do `LocaleNormalizer`) nem
 * decidir o que uma linha significa (é do `EventExploder`).
 *
 * ## Streaming, e por quê
 *
 * `streamRows()` é um `Generator`: o arquivo nunca é carregado inteiro em
 * array. NFR-001 exige memória constante porque o alvo de produção é
 * hospedagem compartilhada, com `memory_limit` baixo. O export de referência
 * tem 4.321 linhas; um export de 90 dias terá ~25 mil.
 *
 * A alternativa óbvia — um generator por bloco — exigiria generators aninhados
 * sobre o mesmo file handle, que é onde leitores deste tipo costumam ficar
 * confusos e frágeis. Em vez disso, cada `CsvRow` carrega o `BlockType` de
 * origem, e o consumidor despacha por ele. Uma passada, um handle, sem estado
 * escondido.
 *
 * Referência: research.md §Estrutura macro, §Separadores.
 */
final class CarelinkCsvReader
{
    private const DELIMITER = ';';

    /** Quantas linhas do início formam o cabeçalho de identificação. */
    private const HEADER_LINE_COUNT = 3;

    /** Prefixo que marca uma linha separadora de bloco. */
    private const SEPARATOR_PREFIX = '-------';

    /** Primeira coluna do cabeçalho de colunas — usada para reconhecê-lo. */
    private const COLUMN_HEADER_FIRST_FIELD = 'Index';

    public function __construct(
        private readonly LocaleNormalizer $normalizer = new LocaleNormalizer(),
        private readonly HeaderParser $headerParser = new HeaderParser(),
    ) {}

    /**
     * Lê apenas as três primeiras linhas do arquivo.
     *
     * Separado de `streamRows()` de propósito: o Job precisa dos metadados
     * (modelo, período, serial) para criar o registro `imports` ANTES de
     * começar a processar milhares de linhas.
     */
    public function readHeader(string $path): FileHeader
    {
        $handle = $this->open($path);

        try {
            $lines = [];

            for ($i = 0; $i < self::HEADER_LINE_COUNT; $i++) {
                $line = fgets($handle);
                $lines[] = $line === false ? '' : $this->clean($line);
            }
        } finally {
            fclose($handle);
        }

        return $this->headerParser->parse($lines);
    }

    /**
     * Emite as linhas de dados dos três blocos, em ordem de arquivo.
     *
     * Ignora: cabeçalho de identificação, linhas vazias, separadores e os
     * cabeçalhos de coluna repetidos em cada bloco.
     *
     * @param  null|callable(string, int): void  $onWarning  recebe (mensagem, linha)
     * @return Generator<int, CsvRow>
     */
    public function streamRows(string $path, ?callable $onWarning = null): Generator
    {
        $handle = $this->open($path);

        try {
            $block = null;
            $columnIndex = [];
            $lineNumber = 0;

            while (($raw = fgets($handle)) !== false) {
                $lineNumber++;

                if ($lineNumber <= self::HEADER_LINE_COUNT) {
                    continue;
                }

                $line = $this->clean($raw);

                if (trim($line) === '') {
                    continue;
                }

                if (str_starts_with($line, self::SEPARATOR_PREFIX)) {
                    $block = BlockType::fromSeparator($line);

                    if ($block === null && $onWarning !== null) {
                        // Parser tolerante: bloco desconhecido num export futuro
                        // não aborta o import. As linhas dele são ignoradas até
                        // o próximo separador reconhecido.
                        $onWarning("Separador de bloco desconhecido: {$line}", $lineNumber);
                    }

                    // Cada bloco repete o cabeçalho de colunas na linha seguinte.
                    $columnIndex = [];

                    continue;
                }

                $fields = explode(self::DELIMITER, $line);
                $first = $this->normalizer->cell($fields[0] ?? null);

                // Cabeçalho de colunas: idêntico nos três blocos, aparece 3x.
                if ($first === self::COLUMN_HEADER_FIRST_FIELD) {
                    $columnIndex = $this->mapColumns($fields);

                    continue;
                }

                // Linha de dados fora de qualquer bloco reconhecido, ou antes do
                // cabeçalho de colunas: não há como interpretá-la.
                if ($block === null || $columnIndex === []) {
                    if ($onWarning !== null) {
                        $onWarning('Linha de dados sem bloco ou sem cabeçalho de colunas', $lineNumber);
                    }

                    continue;
                }

                yield new CsvRow(
                    block: $block,
                    columnIndex: $columnIndex,
                    fields: $fields,
                    normalizer: $this->normalizer,
                    lineNumber: $lineNumber,
                );
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Conta as linhas de dados por bloco.
     *
     * Existe para `imports.block_row_counts` (FR-010) e para o assert de
     * segmentação do gabarito: 544 / 14 / 3.749.
     *
     * @return array<string, int>
     */
    public function countRowsByBlock(string $path, ?callable $onWarning = null): array
    {
        $counts = [
            BlockType::Pump->value => 0,
            BlockType::AutoInsulin->value => 0,
            BlockType::Sensor->value => 0,
        ];

        foreach ($this->streamRows($path, $onWarning) as $row) {
            $counts[$row->block->value]++;
        }

        return $counts;
    }

    /**
     * Mapeia nome de coluna → índice.
     *
     * Nomes duplicados ficariam com o índice da PRIMEIRA ocorrência. Não
     * ocorre no formato conhecido, mas manter o comportamento explícito evita
     * que um export futuro com coluna repetida troque a leitura em silêncio.
     *
     * @param  list<string>  $fields
     * @return array<string, int>
     */
    private function mapColumns(array $fields): array
    {
        $map = [];

        foreach ($fields as $index => $field) {
            $name = $this->normalizer->cell($field);

            if ($name === null || isset($map[$name])) {
                continue;
            }

            $map[$name] = $index;
        }

        return $map;
    }

    /** Remove BOM (§A8) e terminadores de linha, preservando o resto. */
    private function clean(string $line): string
    {
        return rtrim($this->normalizer->stripBom($line), "\r\n");
    }

    /** @return resource */
    private function open(string $path)
    {
        if (! is_file($path)) {
            throw new RuntimeException("Export do CareLink não encontrado: {$path}");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Não foi possível abrir o export: {$path}");
        }

        return $handle;
    }
}

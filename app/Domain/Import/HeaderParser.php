<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Import\Value\FileHeader;

/**
 * Interpreta as linhas 1–3 de um export do CareLink.
 *
 * ⚠️ O cabeçalho usa DUAS convenções no mesmo bloco. Trecho real, com índices:
 *
 *   linha 1:  [0]Last Name  [1]First Name  [2]Patient ID  [3]System ID
 *             [4]Start Date [5]End Date
 *             [6]Device [7]MiniMed 780G MMT-1886
 *             [8]Hardware Version [9]A1.01
 *             [10]Firmware Version [11]8.13.2
 *
 *   linha 2:  [0]"<sobrenome>" [1]"<nome>" [2]"" [3]""
 *             [4]"16/07/26 00:00:00" [5]"29/07/26 00:00:00"
 *             [6]"Serial Number" [7]<serial>
 *             [8]Software Version [9](vazio)
 *
 *   linha 3:  [0]Patient DOB [1..5](vazios)
 *             [6]CGM [7]Guardian™ Sensor 3
 *
 *   índices 0–5 → mini-tabela: linha 1 são rótulos, linha 2 são valores
 *   índices 6+  → pares chave-valor INLINE dentro de cada linha, passo 2
 *
 * O bug que isso causa num parser só-posicional: `Device` (linha 1, índice 6)
 * casaria com `Serial Number` (linha 2, índice 6), e o modelo do dispositivo
 * viraria a string "Serial Number".
 *
 * Referência: research.md §Cabeçalho.
 */
final class HeaderParser
{
    /**
     * Índices que seguem a convenção posicional (rótulo na linha 1,
     * valor na linha 2).
     */
    private const POSITIONAL_MAX_INDEX = 5;

    /** Primeiro índice da convenção de pares chave-valor inline. */
    private const INLINE_START_INDEX = 6;

    /**
     * Chaves de identificação do paciente. Vão para `FileHeader::$patient`,
     * separadas do resto — Artigo VII.
     */
    private const PATIENT_KEYS = [
        'Last Name',
        'First Name',
        'Patient ID',
        'System ID',
        'Patient DOB',
    ];

    public function __construct(
        private readonly LocaleNormalizer $normalizer = new LocaleNormalizer(),
    ) {}

    /**
     * @param  list<string>  $lines  as três primeiras linhas do arquivo, cruas
     */
    public function parse(array $lines): FileHeader
    {
        $rows = array_map(
            fn (string $line): array => explode(';', $line),
            array_slice($lines, 0, 3),
        );

        $pairs = array_merge(
            $this->positionalPairs($rows[0] ?? [], $rows[1] ?? []),
            $this->inlinePairs($rows),
            $this->thirdLineLeadingPair($rows[2] ?? []),
        );

        $patient = [];
        $unknown = [];

        // Consome as chaves conhecidas; o que sobrar é desconhecido.
        $take = function (string $key) use (&$pairs): ?string {
            $value = $pairs[$key] ?? null;
            unset($pairs[$key]);

            return $value;
        };

        $deviceModel = $take('Device');
        $deviceSerial = $take('Serial Number');
        $hardwareVersion = $take('Hardware Version');
        $firmwareVersion = $take('Firmware Version');
        $softwareVersion = $take('Software Version');
        $cgmModel = $take('CGM');
        $startDate = $take('Start Date');
        $endDate = $take('End Date');

        foreach (self::PATIENT_KEYS as $key) {
            $value = $take($key);

            if ($value !== null) {
                $patient[$key] = $value;
            }
        }

        // Sobrou algo → chave nova numa versão futura do CareLink.
        // Vira parse_warnings; NÃO lança. Parser rígido quebraria no próximo export.
        foreach ($pairs as $key => $value) {
            $unknown[$key] = $value;
        }

        return new FileHeader(
            deviceModel: $deviceModel,
            deviceSerial: $deviceSerial,
            hardwareVersion: $hardwareVersion,
            firmwareVersion: $firmwareVersion,
            softwareVersion: $softwareVersion,
            cgmModel: $cgmModel,
            // ⚠️ §A2 — formato do cabeçalho é d/m/y, DIFERENTE das linhas de dados.
            periodStart: $this->normalizer->headerDateTime($startDate),
            periodEnd: $this->normalizer->headerDateTime($endDate),
            patient: $patient,
            unknownKeys: $unknown,
        );
    }

    /**
     * Convenção 1 — mini-tabela nos índices 0..5:
     * rótulo vem da linha 1, valor da linha 2, casados por índice.
     *
     * @param  list<string>  $labelRow
     * @param  list<string>  $valueRow
     * @return array<string, string|null>
     */
    private function positionalPairs(array $labelRow, array $valueRow): array
    {
        $pairs = [];

        for ($i = 0; $i <= self::POSITIONAL_MAX_INDEX; $i++) {
            $key = $this->normalizer->cell($labelRow[$i] ?? null);

            if ($key === null) {
                continue;
            }

            $pairs[$key] = $this->normalizer->cell($valueRow[$i] ?? null);
        }

        return $pairs;
    }

    /**
     * Convenção 2 — pares chave-valor inline a partir do índice 6,
     * em cada uma das três linhas independentemente.
     *
     * @param  list<list<string>>  $rows
     * @return array<string, string|null>
     */
    private function inlinePairs(array $rows): array
    {
        $pairs = [];

        foreach ($rows as $row) {
            $count = count($row);

            for ($i = self::INLINE_START_INDEX; $i < $count; $i += 2) {
                $key = $this->normalizer->cell($row[$i] ?? null);

                if ($key === null) {
                    continue;
                }

                $pairs[$key] = $this->normalizer->cell($row[$i + 1] ?? null);
            }
        }

        return $pairs;
    }

    /**
     * Caso especial — a linha 3 começa com uma chave no índice 0
     * (`Patient DOB`), cujo valor está no índice 1. Não segue nenhuma das
     * duas convenções gerais.
     *
     * @param  list<string>  $row
     * @return array<string, string|null>
     */
    private function thirdLineLeadingPair(array $row): array
    {
        $key = $this->normalizer->cell($row[0] ?? null);

        if ($key === null) {
            return [];
        }

        return [$key => $this->normalizer->cell($row[1] ?? null)];
    }
}

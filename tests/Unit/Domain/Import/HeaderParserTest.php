<?php

declare(strict_types=1);

use App\Domain\Import\HeaderParser;

/**
 * T003.3 — FR-002 (Leitura do cabeçalho)
 *
 * Ver research.md §Cabeçalho — estrutura híbrida com duas convenções.
 *
 * As três linhas abaixo reproduzem a estrutura do export de referência com
 * PII substituída. A estrutura (posições, campos vazios, aspas inconsistentes)
 * é idêntica ao arquivo real, porque é justamente ela que quebra parsers.
 */
function headerLines(): array
{
    return [
        'Last Name;First Name;Patient ID;System ID;Start Date;End Date;Device;MiniMed 780G MMT-1886;Hardware Version;A1.01;Firmware Version;8.13.2',
        '"Sobrenome";"Nome";"";"";"16/07/26 00:00:00";"29/07/26 00:00:00";"Serial Number";NG0000000X;Software Version;',
        'Patient DOB;;;;;;CGM;Guardian™ Sensor 3',
    ];
}

beforeEach(function () {
    $this->header = (new HeaderParser)->parse(headerLines());
});

describe('convenção inline (índices 6+)', function () {

    // ⚠️ O TESTE MAIS IMPORTANTE DESTE ARQUIVO.
    // Um parser só-posicional casaria `Device` (linha 1, índice 6) com
    // `Serial Number` (linha 2, índice 6).
    it('não confunde Device com Serial Number', function () {
        expect($this->header->deviceModel)
            ->toBe('MiniMed 780G MMT-1886')
            ->not->toBe('Serial Number');
    });

    it('lê o serial da linha 2', function () {
        expect($this->header->deviceSerial)->toBe('NG0000000X');
    });

    it('lê versões de hardware e firmware da linha 1', function () {
        expect($this->header->hardwareVersion)->toBe('A1.01');
        expect($this->header->firmwareVersion)->toBe('8.13.2');
    });

    it('lê o modelo de CGM da linha 3, com caractere não-ASCII', function () {
        expect($this->header->cgmModel)->toBe('Guardian™ Sensor 3');
    });

    it('trata valor inline vazio como null, não string vazia', function () {
        // "Software Version" é a última chave da linha 2 e não tem valor.
        expect($this->header->softwareVersion)->toBeNull();
    });
});

describe('convenção posicional (índices 0–5)', function () {

    // §A2 — o cabeçalho usa d/m/y. Lido com o parser das linhas de dados
    // (Y/m/d), "16/07/26" daria ano 16.
    it('lê o período com o parser de data do cabeçalho', function () {
        expect($this->header->periodStart)->not->toBeNull();
        expect($this->header->periodEnd)->not->toBeNull();
        expect($this->header->periodStart->format('Y-m-d'))->toBe('2026-07-16');
        expect($this->header->periodEnd->format('Y-m-d'))->toBe('2026-07-29');
    });
});

describe('separação de PII — Artigo VII', function () {

    it('agrupa campos de paciente fora das propriedades do objeto', function () {
        expect($this->header->patient)
            ->toHaveKey('Last Name')
            ->toHaveKey('First Name');

        expect($this->header->patient['Last Name'])->toBe('Sobrenome');
        expect($this->header->patient['First Name'])->toBe('Nome');
    });

    it('omite campos de paciente vazios em vez de guardar string vazia', function () {
        // Patient ID, System ID e Patient DOB estão vazios no export real.
        expect($this->header->patient)
            ->not->toHaveKey('Patient ID')
            ->not->toHaveKey('System ID')
            ->not->toHaveKey('Patient DOB');
    });

    // Esta é a garantia estrutural: toImportAttributes() é o que vai para o
    // banco, e nenhuma chave de paciente pode aparecer nele.
    it('não expõe nome do paciente nos atributos de import', function () {
        $attributes = $this->header->toImportAttributes();
        $serialized = json_encode($attributes, JSON_UNESCAPED_UNICODE);

        expect($serialized)
            ->not->toContain('Sobrenome')
            ->not->toContain('Nome')
            ->not->toContain('Last Name')
            ->not->toContain('First Name');
    });

    it('mantém device_serial nos atributos — vai ao banco, não à IA', function () {
        expect($this->header->toImportAttributes())
            ->toHaveKey('device_serial', 'NG0000000X');
    });
});

describe('tolerância a formato futuro', function () {

    it('não reporta chave desconhecida no export de referência', function () {
        // Critério de conclusão da fase: parse_warnings vazio no arquivo real.
        expect($this->header->hasUnknownKeys())->toBeFalse()
            ->and($this->header->unknownKeys)->toBe([]);
    });

    it('registra chave nova como desconhecida em vez de lançar exceção', function () {
        $lines = headerLines();
        $lines[2] .= ';Exercise Sensor;FitBand X9';

        $header = (new HeaderParser)->parse($lines);

        expect($header->hasUnknownKeys())->toBeTrue();
        expect($header->unknownKeys)->toHaveKey('Exercise Sensor', 'FitBand X9');
        // E os campos conhecidos continuam corretos.
        expect($header->deviceModel)->toBe('MiniMed 780G MMT-1886');
    });

    it('sobrevive a cabeçalho truncado sem lançar', function () {
        $header = (new HeaderParser)->parse(['Last Name;First Name', '"X";"Y"']);

        expect($header->deviceModel)->toBeNull();
        expect($header->periodStart)->toBeNull();
    });

    it('sobrevive a arquivo sem cabeçalho', function () {
        $header = (new HeaderParser)->parse([]);

        expect($header->deviceModel)->toBeNull();
        expect($header->patient)->toBe([]);
    });
});

describe('contra o arquivo real', function () {

    it('reproduz os valores do gabarito', function () {
        $path = requireReferenceExport();

        $handle = fopen($path, 'rb');
        $lines = [];
        for ($i = 0; $i < 3; $i++) {
            $line = fgets($handle);
            $lines[] = $line === false ? '' : rtrim($line, "\r\n");
        }
        fclose($handle);

        $header = (new HeaderParser)->parse($lines);

        // gabarito.md §Cabeçalho
        expect($header->deviceModel)->toBe('MiniMed 780G MMT-1886');
        expect($header->firmwareVersion)->toBe('8.13.2');
        expect($header->hardwareVersion)->toBe('A1.01');
        expect($header->cgmModel)->toContain('Guardian');
        expect($header->periodStart->format('Y-m-d'))->toBe('2026-07-16');
        expect($header->periodEnd->format('Y-m-d'))->toBe('2026-07-29');

        // Nenhuma chave desconhecida — critério de conclusão da fase 1.
        expect($header->unknownKeys)->toBe([]);

        // O arquivo real TEM nome de paciente, e ele fica isolado em ->patient.
        expect($header->patient)->not->toBe([]);
    });
});

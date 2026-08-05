<?php

declare(strict_types=1);

use App\Domain\Import\BlockType;
use App\Domain\Import\CarelinkCsvReader;

/**
 * T004.4 / T004.5 — FR-001 (Segmentação) e NFR-001 (memória constante)
 *
 * Ver research.md §Estrutura macro e §Separadores.
 */

/** Cabeçalho de colunas reduzido, com as colunas que os testes usam. */
function columnHeader(): string
{
    return 'Index;Date;Time;BG Source;BG Reading (mg/dL);Basal Rate (U/h);'
        .'Bolus Type;Bolus Volume Selected (U);Bolus Volume Delivered (U);'
        .'Alert;BWZ Carb Input (grams);Sensor Glucose (mg/dL);ISIG Value;'
        .'Bolus Number;Bolus Source;Sensor Exception';
}

function syntheticCsv(): string
{
    $lines = [
        'Last Name;First Name;Patient ID;System ID;Start Date;End Date;Device;MiniMed 780G MMT-1886;Hardware Version;A1.01;Firmware Version;8.13.2',
        '"Sobrenome";"Nome";"";"";"16/07/26 00:00:00";"29/07/26 00:00:00";"Serial Number";NG0000000X;Software Version;',
        'Patient DOB;;;;;;CGM;Guardian™ Sensor 3',
        '',
        '',
        '-------;MiniMed 780G MMT-1886;Pump;NG0000000X;------- ',
        columnHeader(),
        '0,00000;2026/07/29;17:00:00;;;2,0;;;;;;;;;;',
        '1,00000;2026/07/29;11:54:31;;;;Normal;8,0;8,0;;;;;85;CLOSED_LOOP_BG_CORRECTION_AND_FOOD_BOLUS;',
        '2,00000;2026/07/29;11:49:09;;;;;;;;40,00;;;;;',
        '-------;MiniMed 780G MMT-1886;Pump;NG0000000X;Aggregated Auto Insulin Data ',
        columnHeader(),
        '3,00000;2026/07/29;00:00:00;;;;Normal;27,895;27,895;;;;;;CLOSED_LOOP_AUTO_INSULIN;',
        '-------;MiniMed 780G MMT-1886;Sensor;NG0000000X;------- ',
        columnHeader(),
        '4,00000;2026/07/29;18:47:11;;;;;;;;;117;25,74;;;',
        '5,00000;2026/07/29;18:42:11;;;;;;;;;119;25,46;;;',
    ];

    // ⚠️ Gravado COM BOM, como o arquivo real (§A8).
    $path = tempnam(sys_get_temp_dir(), 'picogli_').'.csv';
    file_put_contents($path, "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n");

    return $path;
}

beforeEach(function () {
    $this->reader = new CarelinkCsvReader;
    $this->path = syntheticCsv();
});

afterEach(function () {
    @unlink($this->path);
});

describe('segmentação em blocos', function () {

    it('conta as linhas de dados de cada um dos três blocos', function () {
        expect($this->reader->countRowsByBlock($this->path))->toBe([
            'pump' => 3,
            'auto_insulin' => 1,
            'sensor' => 2,
        ]);
    });

    it('ignora cabeçalho de identificação, separadores e cabeçalhos de coluna', function () {
        $rows = iterator_to_array($this->reader->streamRows($this->path));

        // 6 linhas de dados no total; nada de separador ou cabeçalho vazando.
        expect($rows)->toHaveCount(6);
    });

    // O bug que isto pega: se a ordem de checagem do separador inverter, a
    // linha de insulina automática é classificada como Pump e seu total
    // DIÁRIO entra como bolus comum.
    it('atribui o bloco correto a cada linha', function () {
        $blocks = array_map(
            fn ($row) => $row->block,
            iterator_to_array($this->reader->streamRows($this->path)),
        );

        expect($blocks)->toBe([
            BlockType::Pump,
            BlockType::Pump,
            BlockType::Pump,
            BlockType::AutoInsulin,
            BlockType::Sensor,
            BlockType::Sensor,
        ]);
    });
});

describe('acesso por nome de coluna', function () {

    it('lê valores tipados com normalização de locale', function () {
        $rows = iterator_to_array($this->reader->streamRows($this->path));

        // linha do bolus entregue
        $bolus = $rows[1];
        expect($bolus->num('Bolus Volume Delivered (U)'))->toBe(8.0);
        expect($bolus->int('Bolus Number'))->toBe(85);
        expect($bolus->str('Bolus Source'))->toBe('CLOSED_LOOP_BG_CORRECTION_AND_FOOD_BOLUS');
        expect($bolus->recordedAtLocal()->format('Y-m-d H:i:s'))->toBe('2026-07-29 11:54:31');

        // linha do sensor
        $sensor = $rows[4];
        expect($sensor->int('Sensor Glucose (mg/dL)'))->toBe(117);
        expect($sensor->num('ISIG Value'))->toBe(25.74);
    });

    it('devolve null para coluna vazia, não zero', function () {
        $rows = iterator_to_array($this->reader->streamRows($this->path));

        // A linha de basal não tem bolus.
        expect($rows[0]->num('Bolus Volume Delivered (U)'))->toBeNull();
        expect($rows[0]->num('Basal Rate (U/h)'))->toBe(2.0);
    });

    it('devolve null para coluna inexistente sem lançar', function () {
        $rows = iterator_to_array($this->reader->streamRows($this->path));

        expect($rows[0]->str('Coluna Que Nao Existe'))->toBeNull();
        expect($rows[0]->num('Coluna Que Nao Existe'))->toBeNull();
    });

    it('preserva a coluna Index para desempate de DST (§A6)', function () {
        $rows = iterator_to_array($this->reader->streamRows($this->path));

        expect($rows[0]->deviceIndex())->toBe(0.0);
        expect($rows[1]->deviceIndex())->toBe(1.0);
    });

    it('remove o BOM da primeira célula da primeira linha de dados (§A8)', function () {
        $header = $this->reader->readHeader($this->path);

        // Se o BOM não fosse removido, "Last Name" cairia em unknownKeys
        // e o nome do paciente escaparia do agrupamento de PII.
        expect($header->unknownKeys)->toBe([]);
        expect($header->patient)->toHaveKey('Last Name', 'Sobrenome');
    });
});

describe('tolerância a formato inesperado', function () {

    it('avisa sobre separador desconhecido sem abortar a leitura', function () {
        $lines = file($this->path, FILE_IGNORE_NEW_LINES);
        array_splice($lines, 10, 0, ['-------;MiniMed 790G;Exercise;NG0000000X;------- ']);
        file_put_contents($this->path, implode("\r\n", $lines)."\r\n");

        $warnings = [];
        $rows = iterator_to_array($this->reader->streamRows(
            $this->path,
            function (string $message, int $line) use (&$warnings) {
                $warnings[] = compact('message', 'line');
            },
        ));

        expect($warnings)->not->toBeEmpty();
        expect($warnings[0]['message'])->toContain('Separador de bloco desconhecido');
        // As linhas dos blocos conhecidos continuam sendo lidas.
        expect($rows)->not->toBeEmpty();
    });

    it('lança exceção clara para arquivo ausente', function () {
        expect(fn () => $this->reader->readHeader('/caminho/inexistente.csv'))
            ->toThrow(RuntimeException::class, 'não encontrado');
    });
});

describe('memória constante — NFR-001', function () {

    it('não cresce proporcionalmente ao número de linhas', function () {
        // Gera um arquivo com ~20 mil linhas de sensor, ordem de grandeza de
        // um export de 90 dias.
        $path = tempnam(sys_get_temp_dir(), 'picogli_big_').'.csv';
        $handle = fopen($path, 'wb');
        fwrite($handle, "\xEF\xBB\xBFLast Name;First Name\r\n\"X\";\"Y\"\r\nPatient DOB\r\n\r\n");
        fwrite($handle, "-------;MiniMed 780G;Sensor;NG0;------- \r\n");
        fwrite($handle, columnHeader()."\r\n");
        for ($i = 0; $i < 20000; $i++) {
            fwrite($handle, "{$i},00000;2026/07/29;18:47:11;;;;;;;;;117;25,74;;;\r\n");
        }
        fclose($handle);

        gc_collect_cycles();
        $before = memory_get_usage();

        $count = 0;
        foreach ($this->reader->streamRows($path) as $row) {
            $count++;
        }

        $growth = memory_get_usage() - $before;

        expect($count)->toBe(20000);
        // 20 mil linhas materializadas em array passariam de 10 MB fácil.
        // Streaming mantém o crescimento na casa dos KB.
        expect($growth)->toBeLessThan(1_000_000);

        @unlink($path);
    });
});

describe('contra o arquivo real', function () {

    it('reproduz a segmentação do gabarito: 544 / 14 / 3.749', function () {
        $path = requireReferenceExport();

        $warnings = [];
        $counts = $this->reader->countRowsByBlock(
            $path,
            function (string $message, int $line) use (&$warnings) {
                $warnings[] = "linha {$line}: {$message}";
            },
        );

        expect($counts)->toBe([
            'pump' => 544,
            'auto_insulin' => 14,
            'sensor' => 3749,
        ]);

        // Critério de conclusão da fase 1: nenhum aviso no export de referência.
        expect($warnings)->toBe([]);
    });

    it('lê o arquivo real com memória constante', function () {
        $path = requireReferenceExport();

        gc_collect_cycles();
        $before = memory_get_usage();

        $count = 0;
        foreach ($this->reader->streamRows($path) as $row) {
            $count++;
        }

        expect($count)->toBe(544 + 14 + 3749);
        expect(memory_get_usage() - $before)->toBeLessThan(1_000_000);
    });
});

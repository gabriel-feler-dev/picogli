<?php

declare(strict_types=1);

use App\Domain\Import\LocaleNormalizer;

/**
 * T001.2 — FR-003 (Normalização de locale)
 *
 * Cobre o risco nº 1 do projeto. Ver:
 *   research.md §A1 (delimitador e decimal), §A2 (dois formatos de data)
 *   constitution.md Artigo VIII.1 e VIII.2
 *
 * O caso que mais importa aqui NÃO é "8,0 → 8.0". É
 * "STEP_0_POINT_025 → null". Um `(float)` ingênuo devolve 0.0 nesse
 * caso — sem erro, sem aviso — e é assim que uma dose de insulina
 * inexistente entra no banco parecendo legítima.
 */
beforeEach(function () {
    $this->n = new LocaleNormalizer();
});

describe('cell()', function () {
    it('remove espaços e aspas envolventes', function () {
        expect($this->n->cell('  "Feler"  '))->toBe('Feler');
        expect($this->n->cell('NG3670115H'))->toBe('NG3670115H');
    });

    // Artigo VIII.2 — vazio é null, nunca 0 nem ''.
    // Em `Bolus Volume Delivered`, vazio significa "esta linha não é uma
    // entrega de bolus". Tratar como zero criaria milhares de doses fantasma.
    it('converte vazio em null, não em string vazia', function (?string $input) {
        expect($this->n->cell($input))->toBeNull();
    })->with([null, '', '   ', '""', '"  "']);

    // O arquivo do CareLink é gravado COM BOM UTF-8. Sem remover, a primeira
    // chave do cabeçalho vira "\u{FEFF}Last Name", não é reconhecida, e o nome
    // do paciente escapa do agrupamento de PII (furo no Artigo VII).
    // Bug real, encontrado pelo teste contra o arquivo — não por inspeção.
    it('remove BOM UTF-8 da primeira célula', function () {
        expect($this->n->cell("\xEF\xBB\xBFLast Name"))->toBe('Last Name');
        expect($this->n->cell("\xEF\xBB\xBF\"Feler\""))->toBe('Feler');
    });

    it('não altera célula sem BOM', function () {
        expect($this->n->cell('Last Name'))->toBe('Last Name');
    });
});

describe('stripBom()', function () {
    it('remove o BOM apenas do início', function () {
        expect($this->n->stripBom("\xEF\xBB\xBFabc"))->toBe('abc');
        expect($this->n->stripBom('abc'))->toBe('abc');
        // BOM no meio não é BOM, é conteúdo — não mexer.
        expect($this->n->stripBom("a\xEF\xBB\xBFbc"))->toBe("a\xEF\xBB\xBFbc");
    });
});

describe('number()', function () {
    it('converte decimal com vírgula — o formato do arquivo', function () {
        expect($this->n->number('8,0'))->toBe(8.0);
        expect($this->n->number('6,725'))->toBe(6.725);
        expect($this->n->number('1,65'))->toBe(1.65);
        expect($this->n->number('0,00000'))->toBe(0.0);
    });

    it('trata ponto como separador de milhar quando há vírgula decimal', function () {
        expect($this->n->number('1.234,5'))->toBe(1234.5);
    });

    it('aceita decimal com ponto, para exports em locale en', function () {
        expect($this->n->number('8.0'))->toBe(8.0);
        expect($this->n->number('142'))->toBe(142.0);
    });

    it('aceita negativo — BWZ Correction Estimate pode ser negativo', function () {
        // Ocorre de verdade: correção negativa quando a glicose está abaixo do alvo.
        expect($this->n->number('-1,0'))->toBe(-1.0);
    });

    // ⚠️ O TESTE MAIS IMPORTANTE DESTE ARQUIVO.
    // Estes valores existem nas mesmas colunas que números, em linhas
    // diferentes. `(float)` devolveria 0.0 para todos.
    it('devolve null para texto não numérico, NUNCA zero', function (string $input) {
        expect($this->n->number($input))->toBeNull();
    })->with([
        'STEP_0_POINT_025',
        'CLOSED_LOOP_AUTO_INSULIN',
        'Normal',
        'Delivered',
        'RESERVOIR: vibration',
        'BG_SENT_FOR_CALIB',
        '8,0,0',
        '--',
    ]);

    it('devolve null para vazio', function () {
        expect($this->n->number(''))->toBeNull();
        expect($this->n->number(null))->toBeNull();
    });
});

describe('integer()', function () {
    it('converte glicose em mg/dL', function () {
        expect($this->n->integer('117'))->toBe(117);
        expect($this->n->integer('55'))->toBe(55);
    });

    it('herda a normalização de locale e arredonda', function () {
        expect($this->n->integer('117,4'))->toBe(117);
        expect($this->n->integer('117,6'))->toBe(118);
    });

    it('devolve null para texto, não zero', function () {
        expect($this->n->integer('STEP_0_POINT_025'))->toBeNull();
    });
});

describe('rowDateTime() — formato das linhas de dados (§A2)', function () {
    it('monta o timestamp a partir das colunas Date e Time', function () {
        $dt = $this->n->rowDateTime('2026/07/29', '17:00:00');

        expect($dt)->not->toBeNull();
        expect($dt->format('Y-m-d H:i:s'))->toBe('2026-07-29 17:00:00');
    });

    it('lê a primeira e a última leitura do export de referência', function () {
        expect($this->n->rowDateTime('2026/07/16', '00:04:07')->format('Y-m-d H:i:s'))
            ->toBe('2026-07-16 00:04:07');
        expect($this->n->rowDateTime('2026/07/29', '18:47:11')->format('Y-m-d H:i:s'))
            ->toBe('2026-07-29 18:47:11');
    });

    // Parse estrito: sem isso, createFromFormat rola o mês e devolve
    // um timestamp errado sem reclamar.
    it('devolve null para data inválida em vez de rolar o mês', function (string $date, string $time) {
        expect($this->n->rowDateTime($date, $time))->toBeNull();
    })->with([
        ['2026/13/45', '00:00:00'],
        ['2026/02/30', '00:00:00'],
        ['2026/07/29', '25:00:00'],
        ['29/07/2026', '00:00:00'],   // formato do cabeçalho, errado aqui
    ]);

    it('devolve null quando falta Date ou Time', function () {
        expect($this->n->rowDateTime(null, '17:00:00'))->toBeNull();
        expect($this->n->rowDateTime('2026/07/29', null))->toBeNull();
    });
});

describe('headerDateTime() — formato do cabeçalho (§A2)', function () {
    // O bug que este teste previne: "16/07/26" lido com o parser das
    // linhas de dados (Y/m/d) daria ano 16.
    it('interpreta ano de 2 dígitos e dia primeiro', function () {
        expect($this->n->headerDateTime('16/07/26 00:00:00')->format('Y-m-d'))
            ->toBe('2026-07-16');
        expect($this->n->headerDateTime('29/07/26 00:00:00')->format('Y-m-d'))
            ->toBe('2026-07-29');
    });

    it('aceita data sem a parte de hora', function () {
        expect($this->n->headerDateTime('16/07/26')->format('Y-m-d H:i:s'))
            ->toBe('2026-07-16 00:00:00');
    });

    it('não confunde os dois formatos', function () {
        // Formato de linha de dados passado ao parser de cabeçalho → null,
        // em vez de uma data silenciosamente errada.
        expect($this->n->headerDateTime('2026/07/29 17:00:00'))->toBeNull();
    });
});

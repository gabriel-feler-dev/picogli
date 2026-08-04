<?php

declare(strict_types=1);

use App\Domain\Import\BlockType;

/**
 * T002.2 — FR-001 (Segmentação em blocos)
 *
 * Ver research.md §Separadores.
 *
 * O caso que justifica este arquivo existir: o campo 2 do separador é `Pump`
 * TANTO no bloco de eventos da bomba QUANTO no bloco de insulina automática.
 * O que os distingue é o campo 4.
 *
 * Se a ordem de checagem inverter, o bloco `Aggregated Auto Insulin Data`
 * é classificado como `Pump`, e seus 14 totais DIÁRIOS entram no sistema
 * como se fossem 14 bolus comuns — inflando a insulina de cada dia sem
 * que nada quebre visivelmente.
 */
describe('fromSeparator()', function () {

    it('reconhece o bloco Pump', function () {
        expect(BlockType::fromSeparator('-------;MiniMed 780G MMT-1886;Pump;NG3670115H;------- '))
            ->toBe(BlockType::Pump);
    });

    // ⚠️ O TESTE MAIS IMPORTANTE DESTE ARQUIVO.
    it('reconhece o bloco de insulina automática mesmo com "Pump" no campo 2', function () {
        $separator = '-------;MiniMed 780G MMT-1886;Pump;NG3670115H;Aggregated Auto Insulin Data ';

        expect(BlockType::fromSeparator($separator))
            ->toBe(BlockType::AutoInsulin)
            ->not->toBe(BlockType::Pump);
    });

    it('reconhece o bloco Sensor', function () {
        expect(BlockType::fromSeparator('-------;MiniMed 780G MMT-1886;Sensor;NG3670115H;------- '))
            ->toBe(BlockType::Sensor);
    });

    it('tolera espaços em volta dos campos', function () {
        expect(BlockType::fromSeparator('-------; MiniMed 780G ; Sensor ; NG3670115H ;------- '))
            ->toBe(BlockType::Sensor);
    });

    // Parser tolerante: o formato pode ganhar blocos em versões futuras do
    // CareLink. Separador desconhecido vira warning no import, não exceção.
    it('devolve null para separador desconhecido, sem lançar exceção', function (string $line) {
        expect(BlockType::fromSeparator($line))->toBeNull();
    })->with([
        'separador de bloco futuro' => '-------;MiniMed 790G;Exercise;NG3670115H;------- ',
        'campos faltando' => '-------;MiniMed 780G',
        'linha vazia' => '',
        'só o prefixo' => '-------',
        'sem campo de tipo' => '-------;;;;',
    ]);

    it('cobre os três blocos do export de referência', function () {
        $separators = [
            '-------;MiniMed 780G MMT-1886;Pump;NG3670115H;------- ',
            '-------;MiniMed 780G MMT-1886;Pump;NG3670115H;Aggregated Auto Insulin Data ',
            '-------;MiniMed 780G MMT-1886;Sensor;NG3670115H;------- ',
        ];

        $detected = array_map(BlockType::fromSeparator(...), $separators);

        // Três blocos DISTINTOS — se dois colapsarem no mesmo, a contagem cai.
        expect($detected)->toBe([
            BlockType::Pump,
            BlockType::AutoInsulin,
            BlockType::Sensor,
        ]);
        expect(array_unique($detected, SORT_REGULAR))->toHaveCount(3);
    });
});

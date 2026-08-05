<?php

declare(strict_types=1);

use App\Domain\Metrics\Value\GlucoseReading;
use App\Domain\Metrics\Value\GlucoseSeries;
use App\Domain\Patterns\CalibrationPairer;

/**
 * T302.3 — pareamento sensor ↔ capilar (R10).
 *
 * Está no domínio puro, e não na persistência como o `plan.md` previa: o builder
 * já carrega a série inteira, então o pareamento é algoritmo em memória, não
 * `SELECT`. Este arquivo é a prova — roda sem banco.
 */
function bg(string $time, int $mgdl): array
{
    return ['at' => new DateTimeImmutable("2026-07-16 {$time}"), 'mgdl' => $mgdl];
}

function sensorSeries(array $pairs): GlucoseSeries
{
    return GlucoseSeries::of(array_map(
        fn (array $p) => new GlucoseReading(new DateTimeImmutable("2026-07-16 {$p[0]}"), $p[1]),
        $pairs,
    ));
}

describe('o pareamento', function () {

    it('casa com a leitura de sensor mais próxima', function () {
        $series = sensorSeries([['08:30:00', 150], ['08:35:00', 190], ['08:40:00', 210]]);

        $pairs = (new CalibrationPairer)->pair([bg('08:36:00', 200)], $series, 10);

        expect($pairs)->toHaveCount(1);
        expect($pairs[0]->sensorMgdl)->toBe(190);
        expect($pairs[0]->bgMgdl)->toBe(200);
        expect($pairs[0]->offsetMinutes)->toBe(1.0);
    });

    // ⚠️ Excluir é o comportamento certo. Aparear "com a mais próxima de
    // qualquer distância" produziria pares separados por horas, e um erro
    // relativo enorme que diria respeito à LACUNA, não ao sensor.
    it('exclui calibração sem leitura na janela, e o n reflete', function () {
        $series = sensorSeries([['08:00:00', 150], ['12:00:00', 160]]);

        $pairs = (new CalibrationPairer)->pair([
            bg('08:03:00', 155),   // dentro
            bg('10:00:00', 200),   // 2 h da mais próxima → fora
        ], $series, 10);

        expect($pairs)->toHaveCount(1);
        expect($pairs[0]->bgMgdl)->toBe(155);
    });

    it('a borda da janela é inclusiva', function () {
        $series = sensorSeries([['08:00:00', 150]]);

        expect((new CalibrationPairer)->pair([bg('08:10:00', 150)], $series, 10))->toHaveCount(1);
        expect((new CalibrationPairer)->pair([bg('08:10:01', 150)], $series, 10))->toHaveCount(0);
    });

    it('empate resolve para a leitura anterior, de forma determinística', function () {
        $series = sensorSeries([['08:00:00', 100], ['08:10:00', 200]]);

        // 08:05 está a 5 min das duas.
        $pairs = (new CalibrationPairer)->pair([bg('08:05:00', 150)], $series, 10);

        expect($pairs[0]->sensorMgdl)->toBe(100);
    });

    it('série vazia devolve zero pares', function () {
        expect((new CalibrationPairer)->pair([bg('08:00:00', 150)], GlucoseSeries::of([]), 10))
            ->toBe([]);
    });

    it('nenhuma calibração devolve zero pares', function () {
        expect((new CalibrationPairer)->pair([], sensorSeries([['08:00:00', 150]]), 10))
            ->toBe([]);
    });

    it('pareia antes da primeira e depois da última leitura', function () {
        $series = sensorSeries([['08:00:00', 150], ['08:05:00', 160]]);

        expect((new CalibrationPairer)->pair([bg('07:55:00', 150)], $series, 10)[0]->sensorMgdl)
            ->toBe(150);
        expect((new CalibrationPairer)->pair([bg('08:12:00', 160)], $series, 10)[0]->sensorMgdl)
            ->toBe(160);
    });

    // A busca binária tem de achar o mesmo vizinho que a varredura ingênua.
    it('a busca binária concorda com a varredura linear', function () {
        $series = GlucoseSeries::of(array_map(
            fn (int $i) => new GlucoseReading(
                (new DateTimeImmutable('2026-07-16 00:00:00'))->modify("+{$i} minutes"),
                100 + ($i % 90),
            ),
            range(0, 499),
        ));

        foreach ([0, 1, 7, 123, 250, 498, 499] as $minuto) {
            $alvo = (new DateTimeImmutable('2026-07-16 00:00:00'))->modify("+{$minuto} minutes");

            $esperado = null;
            $melhor = PHP_INT_MAX;

            foreach ($series->readings as $reading) {
                $distancia = abs($reading->at->getTimestamp() - $alvo->getTimestamp());

                if ($distancia < $melhor) {
                    $melhor = $distancia;
                    $esperado = $reading->mgdl;
                }
            }

            $pairs = (new CalibrationPairer)->pair(
                [['at' => $alvo, 'mgdl' => 150]],
                $series,
                10,
            );

            expect($pairs[0]->sensorMgdl)->toBe($esperado);
        }
    });
});

describe('o erro relativo', function () {

    it('é relativo ao capilar, que é a referência da calibração', function () {
        $series = sensorSeries([['08:00:00', 110]]);

        $pair = (new CalibrationPairer)->pair([bg('08:00:00', 100)], $series, 10)[0];

        // |110 − 100| / 100 = 10%. Se fosse relativo ao sensor daria 9,09%.
        expect($pair->relativeErrorPercent())->toBe(10.0);
        expect($pair->signedDifference())->toBe(10);
    });

    it('é absoluto: sensor abaixo do capilar dá erro positivo', function () {
        $series = sensorSeries([['08:00:00', 90]]);

        $pair = (new CalibrationPairer)->pair([bg('08:00:00', 100)], $series, 10)[0];

        expect($pair->relativeErrorPercent())->toBe(10.0);
        expect($pair->signedDifference())->toBe(-10);
    });

    // ⚠️ A janela não escolhe COM QUEM parear — a mais próxima é sempre a mais
    // próxima. Ela decide QUEM ENTRA, e é isso que muda o `n` e, com ele, o erro
    // médio. Duas janelas dão dois números, e os dois estão certos: o que os
    // distingue é a janela. Por isso ela viaja até a evidência do achado.
    it('a janela muda o n, e com ele o erro médio', function () {
        $series = sensorSeries([['08:00:00', 100], ['09:00:00', 200]]);

        $calibracoes = [
            bg('08:02:00', 100),   // 2 min — entra nas duas janelas, erro 0%
            bg('09:08:00', 100),   // 8 min — só na janela larga, erro 100%
        ];

        $estreita = (new CalibrationPairer)->pair($calibracoes, $series, 5);
        $larga = (new CalibrationPairer)->pair($calibracoes, $series, 10);

        expect($estreita)->toHaveCount(1);
        expect($larga)->toHaveCount(2);

        $erroEstreita = array_sum(array_map(fn ($p): float => $p->relativeErrorPercent(), $estreita))
            / count($estreita);
        $erroLarga = array_sum(array_map(fn ($p): float => $p->relativeErrorPercent(), $larga))
            / count($larga);

        expect($erroEstreita)->toBe(0.0);
        expect($erroLarga)->toBe(50.0);
    });
});

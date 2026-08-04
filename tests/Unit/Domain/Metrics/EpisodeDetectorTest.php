<?php

declare(strict_types=1);

use App\Domain\Metrics\EpisodeDetector;
use App\Domain\Metrics\Value\EpisodeType;
use App\Domain\Metrics\Value\GlucoseSeries;

/**
 * T104 — FR-106 (Episódios)
 *
 * A regra de término é a de `specs/002-metricas/spec.md` §D3: o episódio só
 * encerra após 15 minutos consecutivos de volta à faixa. Uma volta breve NÃO
 * encerra — glicose que sai, volta por 5 min e sai de novo é UM episódio
 * oscilante, não dois.
 */

/** Série a partir de valores em intervalos de 5 min, começando à meia-noite. */
function every5(array $values, string $start = '2026-07-26 00:00:00'): GlucoseSeries
{
    $at = new DateTimeImmutable($start);

    return GlucoseSeries::fromPairs(array_map(
        fn (int $i, int $v): array => [$at->modify('+'.($i * 5).' minutes')->format('Y-m-d H:i:s'), $v],
        array_keys($values),
        $values,
    ));
}

beforeEach(function () {
    $this->detector = new EpisodeDetector(metricsConfig());
});

describe('regra de término (§D3)', function () {

    it('uma volta breve à faixa NÃO encerra o episódio', function () {
        // Sai (65), volta por 10 min (75, 80), sai de novo (60...).
        // 10 min < 15 de recuperação → UM episódio.
        $series = every5([65, 75, 80, 60, 58, 62, 66, 90, 95, 100, 105]);

        $episodes = $this->detector->detect($series, EpisodeType::Hypoglycemia);

        expect($episodes)->toHaveCount(1);
        // Início na primeira abaixo (00:00), fim na última abaixo (00:30) = 30 min.
        expect($episodes[0]->durationMinutes)->toBe(30.0);
        expect($episodes[0]->nadir())->toBe(58);
    });

    it('15 min de volta encerram, e o fim é a última leitura FORA da faixa', function () {
        // Sai por 20 min, volta por 20 min, sai de novo → DOIS episódios.
        $series = every5([65, 60, 58, 62, 90, 95, 100, 105, 60, 58, 55, 62, 100, 110, 120]);

        $episodes = $this->detector->detect($series, EpisodeType::Hypoglycemia);

        expect($episodes)->toHaveCount(2);
        // Primeiro: 00:00 → 00:15. A janela de recuperação CONFIRMA o término,
        // não o estica.
        expect($episodes[0]->start->format('H:i'))->toBe('00:00');
        expect($episodes[0]->end->format('H:i'))->toBe('00:15');
        expect($episodes[0]->durationMinutes)->toBe(15.0);
        expect($episodes[1]->start->format('H:i'))->toBe('00:40');
    });

    it('excursão curta não é episódio', function () {
        // 10 min abaixo, abaixo do mínimo de 15.
        $series = every5([65, 60, 90, 100, 110, 120, 130]);

        expect($this->detector->detect($series, EpisodeType::Hypoglycemia))->toBe([]);
    });

    it('15 min exatos CONTAM — o limiar é inclusivo', function () {
        // 4 leituras abaixo = span de 15 min. É o episódio de 27/07 do gabarito.
        $series = every5([65, 60, 58, 66, 90, 95, 100, 105]);

        $episodes = $this->detector->detect($series, EpisodeType::Hypoglycemia);

        expect($episodes)->toHaveCount(1);
        expect($episodes[0]->durationMinutes)->toBe(15.0);
    });
});

describe('lacuna interrompe o episódio', function () {

    // ⚠️ Sem esta regra, a lacuna de 1347 min do export de referência poderia
    // virar um episódio de quase um dia — afirmação sobre um período em que
    // ninguém mediu nada.
    it('encerra na última leitura MEDIDA, não atravessa o vazio', function () {
        $series = GlucoseSeries::fromPairs([
            ['2026-07-26 00:00:00', 65],
            ['2026-07-26 00:05:00', 60],
            ['2026-07-26 00:10:00', 58],
            ['2026-07-26 00:15:00', 55],
            // lacuna de 6 h
            ['2026-07-26 06:15:00', 50],
            ['2026-07-26 06:20:00', 48],
            ['2026-07-26 06:25:00', 52],
            ['2026-07-26 06:30:00', 55],
            ['2026-07-26 07:00:00', 100],
        ]);

        $episodes = $this->detector->detect($series, EpisodeType::Hypoglycemia);

        expect($episodes)->toHaveCount(2);

        // O primeiro encerra às 00:15 — não vira um episódio de 6 h.
        expect($episodes[0]->durationMinutes)->toBe(15.0);
        expect($episodes[0]->interruptedByGap)->toBeTrue();

        // O segundo é independente.
        expect($episodes[1]->start->format('H:i'))->toBe('06:15');
        expect($episodes[1]->durationMinutes)->toBe(15.0);
    });

    it('marca episódio aberto no fim da série como interrompido', function () {
        // A série acaba com a glicose ainda abaixo: não se sabe o que veio depois.
        $series = every5([100, 65, 60, 58, 55]);

        $episodes = $this->detector->detect($series, EpisodeType::Hypoglycemia);

        expect($episodes)->toHaveCount(1);
        expect($episodes[0]->interruptedByGap)->toBeTrue();
        expect($episodes[0]->durationMinutes)->toBe(15.0);
    });
});

describe('duração é medida, não contada', function () {

    // `n × 5` e `fim − início` divergem exatamente onde houve falha de leitura,
    // e é ali que a diferença importa.
    it('usa minutos reais mesmo com leitura faltando no meio', function () {
        $series = GlucoseSeries::fromPairs([
            ['2026-07-26 00:00:00', 65],
            // falta a de 00:05 (uma leitura perdida, abaixo do limiar de lacuna)
            ['2026-07-26 00:10:00', 58],
            ['2026-07-26 00:20:00', 60],
            ['2026-07-26 00:40:00', 100],
            ['2026-07-26 00:45:00', 105],
            ['2026-07-26 00:50:00', 110],
            ['2026-07-26 00:55:00', 115],
        ]);

        $episodes = $this->detector->detect($series, EpisodeType::Hypoglycemia);

        expect($episodes)->toHaveCount(1);
        // 3 leituras × 5 min daria 15. O real é 20.
        expect($episodes[0]->readingCount)->toBe(3);
        expect($episodes[0]->durationMinutes)->toBe(20.0);
    });
});

describe('direção do limiar', function () {

    it('250 exatos NAO e hiperglicemia nivel 2 — o limiar e estrito', function () {
        //           00:00 00:05 00:10 00:15 00:20 00:25 00:30 00:35 00:40 ...
        $series = every5([250, 255, 260, 270, 265, 258, 254, 250, 240, 230, 220, 210]);

        // Acima de 250: 00:05 a 00:30 = 25 min. Menos que os 30 exigidos.
        // O 250 das 00:00 e das 00:35 conta como DENTRO da faixa.
        expect($this->detector->detect($series, EpisodeType::HyperglycemiaLevel2))->toBe([]);
    });

    it('confirma hiper quando o span acima de 250 chega a 30 min', function () {
        //           00:00 00:05 00:10 00:15 00:20 00:25 00:30 00:35 00:40 ...
        $series = every5([250, 255, 260, 270, 265, 258, 254, 252, 250, 240, 230, 220, 210]);

        $episodes = $this->detector->detect($series, EpisodeType::HyperglycemiaLevel2);

        expect($episodes)->toHaveCount(1);
        // Primeira acima 00:05, última acima 00:35 = 30 min exatos (inclusivo).
        expect($episodes[0]->start->format('H:i'))->toBe('00:05');
        expect($episodes[0]->end->format('H:i'))->toBe('00:35');
        expect($episodes[0]->durationMinutes)->toBe(30.0);
        expect($episodes[0]->peak())->toBe(270);
        expect($episodes[0]->interruptedByGap)->toBeFalse();
    });

    it('não confunde hipo com hiper na mesma série', function () {
        // Hiper: 00:00 a 00:35 (8 leituras >250) = 35 min.
        // Hipo:  00:55 a 01:10 (4 leituras <70)  = 15 min.
        $series = every5([
            300, 310, 305, 295, 280, 270, 260, 255,   // >250, 35 min
            200, 150, 100,                            // recuperação da hiper
            60, 55, 58, 62,                           // <70, 15 min
            100, 110, 120, 130,                       // recuperação da hipo
        ]);

        $hypo = $this->detector->detect($series, EpisodeType::Hypoglycemia);
        $hyper = $this->detector->detect($series, EpisodeType::HyperglycemiaLevel2);

        expect($hypo)->toHaveCount(1);
        expect($hypo[0]->nadir())->toBe(55);
        expect($hypo[0]->durationMinutes)->toBe(15.0);

        expect($hyper)->toHaveCount(1);
        expect($hyper[0]->peak())->toBe(310);
        expect($hyper[0]->durationMinutes)->toBe(35.0);
    });

    it('série sem excursão devolve lista vazia', function () {
        expect($this->detector->detect(every5([100, 110, 120, 130]), EpisodeType::Hypoglycemia))->toBe([]);
        expect($this->detector->detect(GlucoseSeries::of([]), EpisodeType::Hypoglycemia))->toBe([]);
    });
});

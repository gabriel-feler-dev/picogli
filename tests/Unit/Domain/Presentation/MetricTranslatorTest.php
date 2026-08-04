<?php

declare(strict_types=1);

use App\Domain\Metrics\Value\GlucoseStatistics;
use App\Domain\Metrics\Value\RangeDistribution;
use App\Domain\Metrics\Value\Validity;
use App\Domain\Presentation\MetricTranslator;
use App\Domain\Presentation\Value\MetricStatus;

/**
 * T203 — FR-203 (cards traduzidos), spec.md §D1 e §D2
 *
 * Este teste roda em `Unit` mas usa `__()`, que precisa do container. O binding
 * de `Feature` cobriria isso — mas aqui o que interessa é a tradução, não o
 * HTTP, então o arquivo declara o que precisa e nada mais.
 */
uses(Tests\TestCase::class);

function translator(): MetricTranslator
{
    return new MetricTranslator(config('clinical.targets'));
}

/** Distribuição do gabarito: TIR 83,9% · 12,9% · 1,9% · 1,3% · 0,0% */
function gabaritoDistribution(): RangeDistribution
{
    return new RangeDistribution(
        total: 3616,
        counts: ['very_low' => 0, 'low' => 47, 'target' => 3034, 'high' => 467, 'very_high' => 68],
        percentages: [
            'very_low' => 0.0,
            'low' => 1.2998,
            'target' => 83.9048,
            'high' => 12.9147,
            'very_high' => 1.8805,
        ],
    );
}

function gabaritoStatistics(): GlucoseStatistics
{
    return new GlucoseStatistics(
        count: 3616,
        mean: 142.0,
        standardDeviation: 41.0,
        coefficientOfVariation: 28.8,
        gmi: 6.70,
    );
}

beforeEach(function () {
    $this->cards = translator()->translate(
        gabaritoStatistics(),
        gabaritoDistribution(),
        Validity::Valid,
    );

    $this->byKey = collect($this->cards)->keyBy('key');
});

describe('os quatro cards do gabarito', function () {

    it('traduz TIR em horas por dia', function () {
        $card = $this->byKey['time_in_range'];

        // 83,9% × 24 h = 20,1 h
        expect($card->plainValue)->toBe('20 h por dia');
        expect($card->technicalValue)->toBe('TIR 83,9%');
        // meta 70% × 24 h = 16,8 h → 17 h
        expect($card->targetLabel)->toBe('meta: 17 h (70,0%)');
        expect($card->status)->toBe(MetricStatus::Met);
    });

    it('traduz CV em estabilidade', function () {
        $card = $this->byKey['coefficient_of_variation'];

        expect($card->plainValue)->toBe('Dias estáveis');
        expect($card->technicalValue)->toBe('CV 28,8%');
        expect($card->status)->toBe(MetricStatus::Met);
    });

    it('traduz GMI em HbA1c estimada, sem meta', function () {
        $card = $this->byKey['gmi'];

        expect($card->plainValue)->toBe('~6,7%');
        expect($card->technicalValue)->toBe('GMI 6,70%');
        // Artigo VI — sugerir alvo de HbA1c seria prescrever.
        expect($card->targetLabel)->toBeNull();
    });

    it('traduz TBR em minutos por dia', function () {
        $card = $this->byKey['time_below_range'];

        // 1,2998% × 1440 min = 18,7 min → 19 min
        expect($card->plainValue)->toBe('19 min por dia');
        expect($card->status)->toBe(MetricStatus::Met);
    });

    it('todo card carrega o valor técnico ao lado do traduzido (Artigo III)', function () {
        foreach ($this->cards as $card) {
            expect($card->technicalValue)->not->toBeEmpty();
            expect($card->plainValue)->not->toBe($card->technicalValue);
            expect($card->explanation)->not->toBeEmpty();
        }
    });
});

describe('comparação com a meta usa o valor EXATO', function () {

    // ⚠️ O detalhe que este teste protege: 70,4% e a meta de 70% arredondam
    // para a MESMA coisa em horas ("17 h"), mas a meta está atingida.
    // Comparar o valor exibido faria o card negar uma meta que foi batida.
    it('TIR de 70,4% arredonda igual à meta mas conta como atingida', function () {
        $distribution = new RangeDistribution(
            total: 1000,
            counts: ['very_low' => 0, 'low' => 0, 'target' => 704, 'high' => 296, 'very_high' => 0],
            percentages: ['very_low' => 0.0, 'low' => 0.0, 'target' => 70.4, 'high' => 29.6, 'very_high' => 0.0],
        );

        $card = collect(translator()->translate(gabaritoStatistics(), $distribution, Validity::Valid))
            ->keyBy('key')['time_in_range'];

        // Mesmo texto arredondado que a meta...
        expect($card->plainValue)->toBe('17 h por dia');
        expect($card->targetLabel)->toContain('17 h');
        // ...e ainda assim atingida, porque 70,4 > 70.
        expect($card->status)->toBe(MetricStatus::Met);
    });

    it('TIR de 69,6% arredonda igual à meta e conta como NÃO atingida', function () {
        $distribution = new RangeDistribution(
            total: 1000,
            counts: ['very_low' => 0, 'low' => 0, 'target' => 696, 'high' => 304, 'very_high' => 0],
            percentages: ['very_low' => 0.0, 'low' => 0.0, 'target' => 69.6, 'high' => 30.4, 'very_high' => 0.0],
        );

        $card = collect(translator()->translate(gabaritoStatistics(), $distribution, Validity::Valid))
            ->keyBy('key')['time_in_range'];

        expect($card->plainValue)->toBe('17 h por dia');
        expect($card->status)->toBe(MetricStatus::NotMet);
    });

    it('a direção da meta é respeitada: CV alto NÃO é atingido', function () {
        $unstable = new GlucoseStatistics(3616, 159.0, 67.0, 42.2, 7.11);

        $card = collect(translator()->translate($unstable, gabaritoDistribution(), Validity::Valid))
            ->keyBy('key')['coefficient_of_variation'];

        // 42,2% > meta de 36% → para CV, acima é NÃO atingido.
        expect($card->status)->toBe(MetricStatus::NotMet);
        expect($card->plainValue)->toBe('Dias com muita oscilação');
    });
});

describe('Artigo V — portão de validade', function () {

    it('marca GMI e CV como não confiáveis, sem esconder o número', function () {
        $cards = collect(translator()->translate(
            gabaritoStatistics(),
            gabaritoDistribution(),
            Validity::InsufficientDays,
        ))->keyBy('key');

        expect($cards['gmi']->status)->toBe(MetricStatus::Unreliable);
        expect($cards['coefficient_of_variation']->status)->toBe(MetricStatus::Unreliable);

        // O número CONTINUA lá. Esconder faria o usuário achar que faltam
        // dados; mostrar sem marca faria ele confiar.
        expect($cards['gmi']->plainValue)->toBe('~6,7%');
        expect($cards['coefficient_of_variation']->technicalValue)->toBe('CV 28,8%');
    });

    it('TIR e TBR não são marcados — não dependem do portão', function () {
        $cards = collect(translator()->translate(
            gabaritoStatistics(),
            gabaritoDistribution(),
            Validity::InsufficientCoverage,
        ))->keyBy('key');

        // O portão do Artigo V é sobre GMI e CV. Tempo na faixa é descritivo do
        // que foi medido, e vale para o que foi medido.
        expect($cards['time_in_range']->status)->toBe(MetricStatus::Met);
        expect($cards['time_below_range']->status)->toBe(MetricStatus::Met);
    });
});

describe('série vazia', function () {

    it('não devolve card nenhum em vez de cards com zero', function () {
        $cards = translator()->translate(
            GlucoseStatistics::empty(),
            RangeDistribution::empty(),
            Validity::InsufficientDays,
        );

        // "TIR 0,0%" sobre zero leitura seria uma afirmação falsa sobre o
        // período, não uma métrica.
        expect($cards)->toBe([]);
    });
});

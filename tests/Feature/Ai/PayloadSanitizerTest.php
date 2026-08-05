<?php

declare(strict_types=1);

use App\Domain\Ai\PayloadSanitizer;
use App\Domain\Import\BolusLinker;
use App\Domain\Import\CarelinkCsvReader;
use App\Domain\Import\EventExploder;
use App\Domain\Import\Persistence\MealEnricher;
use App\Domain\Import\SettingsInferrer;
use App\Domain\Metrics\Persistence\DailyMetricsWriter;
use App\Domain\Patterns\PatternEngine;
use App\Domain\Patterns\Persistence\PatternDatasetBuilder;
use App\Domain\Patterns\Persistence\PeriodReportWriter;
use App\Jobs\ComputeMetricsJob;
use App\Jobs\ComputePatternsJob;
use App\Jobs\ImportCsvJob;
use App\Models\User;

/**
 * T400 — o `PayloadSanitizer` e o teste anti-vazamento (FR-501, Artigo VII).
 *
 * ⚠️ Esta é a **primeira** tarefa da fase 5, e não é negociável. Se o sanitizer
 * viesse depois do provider, existiria uma janela em que o código chama a API sem
 * ele — e é nessa janela que alguém testa com dado real. Uma vez enviado, não
 * desfaz.
 */
beforeEach(function () {
    $this->user = User::factory()->create();

    (new ImportCsvJob($this->user->id, requireReferenceExport(), 'America/Sao_Paulo'))->handle(
        app(CarelinkCsvReader::class),
        app(EventExploder::class),
        app(BolusLinker::class),
        app(MealEnricher::class),
        app(SettingsInferrer::class),
    );
    (new ComputeMetricsJob($this->user->id))->handle(app(DailyMetricsWriter::class));

    $this->report = (new ComputePatternsJob($this->user->id))->handle(
        app(PatternDatasetBuilder::class),
        app(PatternEngine::class),
        app(PeriodReportWriter::class),
    );

    $this->sanitizer = app(PayloadSanitizer::class);

    $this->payload = $this->sanitizer->sanitize($this->report->findings, [
        'period_from' => (string) $this->report->period_start,
        'period_to' => (string) $this->report->period_end,
        'coverage_percent' => $this->report->coverage_pct,
        'span_days' => $this->report->span_days,
        'validity' => $this->report->validity,
    ]);
});

/**
 * ⚠️⚠️ **O TESTE QUE O ARTIGO VII EXIGE.**
 *
 * Duas camadas, de propósito (§D2).
 */
describe('o teste anti-vazamento', function () {

    /**
     * ⭐ **Camada 1: varredura DINÂMICA.**
     *
     * Lê o cabeçalho do export em tempo de execução e varre o payload por cada
     * valor encontrado. É a camada que importa: se um export futuro trouxer um
     * campo novo de identificação — `Caregiver Email`, digamos — ela acusa
     * sozinha. Uma lista fixa não acusaria, porque ninguém a teria atualizado.
     */
    it('nenhum valor do cabeçalho do CSV aparece no payload', function () {
        $header = app(CarelinkCsvReader::class)->readHeader(requireReferenceExport());

        $json = $this->payload->toJson();
        $verificados = 0;

        foreach ($header->patient as $campo => $valor) {
            $valor = trim((string) $valor);

            // Valores muito curtos dariam falso positivo contra qualquer texto.
            if (mb_strlen($valor) < 3) {
                continue;
            }

            $verificados++;

            expect(str_contains($json, $valor))->toBeFalse(
                "o payload contém '{$campo}' do cabeçalho: {$valor}"
            );
        }

        // Sem isto, um `$header->patient` vazio faria o teste passar varrendo o
        // vácuo — a falha mais silenciosa que um guarda pode ter.
        expect($verificados)->toBeGreaterThan(0);
    });

    /**
     * Camada 2: literais. Menos poderosa que a varredura, mas legível — documenta
     * o que se está protegendo para quem lê o teste.
     */
    it('não contém o sobrenome nem o número de série da bomba', function () {
        $json = $this->payload->toJson();

        foreach (['Feler', 'NG3670115H'] as $pii) {
            expect(str_contains($json, $pii))->toBeFalse("o payload contém '{$pii}'");
        }
    });

    it('não contém o nome do arquivo importado', function () {
        // `original_filename` costuma trazer o nome da pessoa.
        expect(str_contains(mb_strtolower($this->payload->toJson()), 'reference-export'))
            ->toBeFalse();
    });

    /**
     * ⚠️ O modelo não calcula (Artigo I). Mandar 3.616 leituras seria caro,
     * inútil e — pior — abriria a porta para ele fazer aritmética sobre elas.
     */
    it('não contém a série de leituras', function () {
        $json = $this->payload->toJson();

        // ⚠️ Procura a CHAVE `readings`, não a palavra: a evidência tem
        // `worst_readings`, `total_readings`, `dawn_readings`… e todas são
        // contagens legítimas. Foi o meu primeiro assert, e ele acusava dado
        // correto.
        expect(str_contains($json, '"readings":'))->toBeFalse();
        expect(str_contains($json, '"series":'))->toBeFalse();
        expect(str_contains($json, '"mgdl":'))->toBeFalse();

        // 3.616 leituras não cabem em 20 kB. O tamanho é a segunda barreira.
        expect(mb_strlen($json))->toBeLessThan(20000);
    });
});

/**
 * ⚠️ O teste que quebra quando alguém adiciona uma chave de evidência — de
 * propósito. É a barreira EDITORIAL: impede que uma chave nova e legítima saia
 * sem revisão humana.
 */
it('toda chave que o motor emite está na allowlist', function () {
    $permitidas = config('ai.payload_allowlist');
    $forasteiras = [];

    foreach ($this->report->findings as $finding) {
        foreach (array_keys($finding['evidence']) as $chave) {
            if (! in_array($chave, $permitidas, true)) {
                $forasteiras[] = $finding['rule_id'].'.'.$chave;
            }
        }
    }

    expect($forasteiras)->toBe([]);
    expect($this->payload->hasDroppedKeys())->toBeFalse();
});

it('preserva os 10 achados com evidência e severidade', function () {
    expect($this->payload->findings)->toHaveCount(10);
    expect($this->payload->findings[0]['rule_id'])->toBe('R1_DAYPART_DRIFT');
    expect($this->payload->findings[0]['severity'])->toBe('priority');
    expect($this->payload->findings[0]['evidence']['ratio'])->toBe(5.78);
});

/**
 * ⚠️ Sem `fallback_prose` DE PROPÓSITO. Mandá-la faria o modelo parafrasear um
 * texto em vez de escrever a partir dos números — e a prosa gerada passaria a
 * depender da estática, em vez de ambas dependerem da mesma evidência.
 */
it('não manda a prosa de fallback', function () {
    expect($this->payload->findings[0])->not->toHaveKey('fallback_prose');
    expect(str_contains($this->payload->toJson(), 'Queda de glicose dispara'))->toBeFalse();
});

it('mantém o denominador do período (Artigo V)', function () {
    expect($this->payload->period['period_from'])->toBe('2026-07-16');
    expect($this->payload->period['validity'])->toBe('valid');
    expect($this->payload->period['coverage_percent'])->toBeCloseToValue(91.1, 0.6);
});

describe('a allowlist', function () {

    it('descarta chave desconhecida e a registra', function () {
        $sujo = [[
            'rule_id' => 'R1_DAYPART_DRIFT',
            'severity' => 'info',
            'rank' => 4,
            'evidence' => ['ratio' => 5.78, 'patient_name' => 'Fulano de Tal'],
        ]];

        $payload = $this->sanitizer->sanitize($sujo, []);

        expect($payload->findings[0]['evidence'])->toBe(['ratio' => 5.78]);
        expect($payload->droppedKeys)->toContain('patient_name');
        // ⚠️ O descarte é VISÍVEL. Descarte silencioso seria pior que nenhuma
        // allowlist, porque pareceria que nada foi filtrado.
        expect($payload->hasDroppedKeys())->toBeTrue();
        expect(str_contains($payload->toJson(), 'Fulano'))->toBeFalse();
    });

    it('descarta valor não escalar mesmo com chave conhecida', function () {
        $payload = $this->sanitizer->sanitize([[
            'rule_id' => 'R1_DAYPART_DRIFT',
            'severity' => 'info',
            'rank' => 4,
            'evidence' => ['ratio' => ['aninhado' => 1]],
        ]], []);

        expect($payload->findings[0]['evidence'])->toBe([]);
        expect($payload->droppedKeys)->toContain('ratio');
    });

    // ⚠️ As chaves do CareLink não passam nem pela barreira estrutural da fase 4
    // (o regex de `Finding`) nem pela editorial daqui.
    it('recusa as chaves do cabeçalho do CareLink', function () {
        $payload = $this->sanitizer->sanitize([[
            'rule_id' => 'R1_DAYPART_DRIFT',
            'severity' => 'info',
            'rank' => 4,
            'evidence' => [
                'Last Name' => 'Feler',
                'Patient ID' => '123456',
                'BG Reading (mg/dL)' => 140,
                'Serial Number' => 'NG3670115H',
            ],
        ]], []);

        expect($payload->findings[0]['evidence'])->toBe([]);
        expect($payload->droppedKeys)->toHaveCount(4);

        $json = $payload->toJson();
        expect(str_contains($json, 'Feler'))->toBeFalse();
        expect(str_contains($json, 'NG3670115H'))->toBeFalse();
    });

    it('allowlist vazia explode em vez de devolver payload vazio', function () {
        expect(fn () => new PayloadSanitizer([]))
            ->toThrow(InvalidArgumentException::class, 'allowlist');
    });
});

/**
 * FR-501 — nenhuma outra classe monta payload para provedor.
 */
it('o sanitizer é a única porta de saída', function () {
    $arquivos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    $suspeitos = [];

    foreach ($arquivos as $arquivo) {
        if (! $arquivo->isFile() || $arquivo->getExtension() !== 'php') {
            continue;
        }

        $caminho = str_replace('\\', '/', $arquivo->getPathname());

        if (str_contains($caminho, '/Domain/Ai/')) {
            continue;
        }

        // ⚠️ **A exceção declarada, e é UMA.** O `GeminiProvider` é o único lugar
        // do projeto que fala com o provedor — é o que a arquitetura promete, e é
        // por isso que ele fica em `Infrastructure/`, fora do domínio puro.
        //
        // Que ele seja o ÚNICO está verificado noutro lugar, por um teste que
        // afirma o contrário deste: `GeminiProviderTest` → "só o GeminiProvider
        // conhece o endpoint". Os dois juntos fecham o cerco pelos dois lados.
        if (str_ends_with($caminho, 'Infrastructure/Ai/GeminiProvider.php')) {
            continue;
        }

        $codigo = file_get_contents($arquivo->getPathname());

        foreach (['generativelanguage', 'googleapis.com', 'v1beta/models'] as $marca) {
            if (str_contains($codigo, $marca)) {
                $suspeitos[] = basename($caminho).': '.$marca;
            }
        }
    }

    expect($suspeitos)->toBe([]);
});

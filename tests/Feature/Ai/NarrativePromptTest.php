<?php

declare(strict_types=1);

use App\Domain\Ai\PromptBuilder;
use App\Domain\Ai\Value\AiPayload;
use App\Infrastructure\Ai\FilePromptBuilder;

/**
 * T404 — o prompt de narrativa (FR-503, §D6).
 *
 * ⚠️ **O prompt é o texto que PRODUZ o texto.** Se ele disser "explique o que a
 * pessoa fez de errado", nenhuma varredura sobre a saída conserta isso com
 * confiança — a violação sairia com a fluência de um modelo de linguagem, e cada
 * geração seria diferente.
 *
 * Por isso ele entra nas MESMAS duas varreduras que cobrem o texto de interface:
 * vocabulário proibido (fase 3) e anti-conduta (fase 4, criada para R6).
 */
function promptPayload(): AiPayload
{
    return new AiPayload(
        period: [
            'period_from' => '2026-07-16',
            'period_to' => '2026-07-29',
            'coverage_percent' => 91.1,
            'validity' => 'valid',
        ],
        findings: [[
            'rule_id' => 'R1_DAYPART_DRIFT',
            'severity' => 'priority',
            'rank' => 4,
            'evidence' => ['worst_daypart' => 'afternoon', 'ratio' => 5.78],
        ]],
    );
}

describe('as seis regras rígidas (PicoGli.md §9.1)', function () {

    it('o prompt renderizado carrega as seis', function () {
        $prompt = app(PromptBuilder::class)->build(promptPayload());

        // 1 — apenas os números fornecidos
        expect($prompt)->toContain('Use APENAS os números fornecidos');
        expect($prompt)->toContain('Nunca calcule');

        // 2 — traduzir jargão
        expect($prompt)->toContain('Traduza o jargão');
        expect($prompt)->toContain('20 horas por dia na faixa boa');

        // 3 — mecanismo e consequência, nunca caráter
        expect($prompt)->toContain('mecanismo e consequência, nunca caráter');

        // 4 — nunca sugerir mudança de tratamento
        expect($prompt)->toContain('NUNCA sugira mudança de tratamento');
        expect($prompt)->toContain('endocrinologista');

        // 5 — começar pelo que está bom (T404.4)
        expect($prompt)->toContain('Comece pelo que está bom');

        // 6 — teto de palavras
        expect($prompt)->toContain('350 palavras');
        expect((string) config('ai.narrative.max_words'))->toBe('350');
    });

    /**
     * ⚠️ A regra 5 tem uma justificativa que o prompt precisa carregar, senão o
     * modelo a trata como cortesia e a abandona quando o dado é ruim.
     */
    it('explica POR QUE começar pelo que está bom', function () {
        $prompt = app(PromptBuilder::class)->build(promptPayload());

        expect($prompt)->toContain('Não é gentileza');
        // E cita o reenquadramento de R4, que é o achado mais valioso do motor.
        expect($prompt)->toContain('um único dia');
    });

    it('avisa que existe verificação de número depois dele', function () {
        // Sem esse aviso, o modelo não tem motivo para ser conservador com
        // números — e o descarte silencioso pareceria um bug.
        $prompt = app(PromptBuilder::class)->build(promptPayload());

        // ⚠️ O assert não pode atravessar quebra de linha: o prompt é markdown com
        // wrap em 80 colunas, e "descarta o texto inteiro" quebra no meio.
        expect($prompt)->toContain('sem procedência descarta');
    });
});

/**
 * ⚠️⚠️ **A tensão do §D6, resolvida por fonte única.**
 *
 * O prompt precisa CITAR as palavras proibidas para instruir o modelo. Se elas
 * estivessem escritas no arquivo, a varredura de vocabulário acusaria o próprio
 * prompt — o erro que a fase 3 cometeu ao acusar a documentação da regra.
 */
describe('as listas dos Artigos IV e VI', function () {

    it('o ARQUIVO do prompt não contém as palavras proibidas', function () {
        $arquivo = file_get_contents(resource_path(config('ai.narrative.prompt_path')));

        foreach (config('tone.forbidden_vocabulary') as $proibido) {
            expect(str_contains(mb_strtolower($arquivo), mb_strtolower($proibido)))
                ->toBeFalse("o arquivo do prompt contém '{$proibido}' escrito");
        }
    });

    it('mas o prompt RENDERIZADO carrega todas elas, como proibição', function () {
        $prompt = mb_strtolower(app(PromptBuilder::class)->build(promptPayload()));

        // ⚠️ Fonte única: a instrução que chega ao modelo é exatamente a lista
        // que o teste de vocabulário cobra. Com as listas duplicadas, uma palavra
        // acrescentada ao teste não chegaria ao modelo.
        foreach (config('tone.forbidden_vocabulary') as $proibido) {
            expect(str_contains($prompt, mb_strtolower($proibido)))
                ->toBeTrue("a proibição de '{$proibido}' não chegou ao prompt");
        }

        foreach (config('tone.forbidden_conduct') as $conduta) {
            expect(str_contains($prompt, mb_strtolower($conduta)))
                ->toBeTrue("a proibição de conduta '{$conduta}' não chegou ao prompt");
        }
    });

    it('as listas têm um único lugar de verdade', function () {
        expect(config('tone.forbidden_vocabulary'))->not->toBeEmpty();
        expect(config('tone.forbidden_conduct'))->not->toBeEmpty();

        // O teste de vocabulário lê daqui desde a fase 5.
        expect(forbiddenVocabulary())->toBe(config('tone.forbidden_vocabulary'));
    });
});

describe('o payload dentro do prompt', function () {

    it('vai como JSON, que é a mesma forma que o teste anti-vazamento inspeciona', function () {
        $prompt = app(PromptBuilder::class)->build(promptPayload());

        expect($prompt)->toContain('"period_from": "2026-07-16"');
        expect($prompt)->toContain('"rule_id": "R1_DAYPART_DRIFT"');
        expect($prompt)->toContain('"ratio": 5.78');

        // ⚠️ Reformatá-lo em prosa criaria uma SEGUNDA representação do dado, que
        // a verificação do Artigo VII não cobriria.
        expect($prompt)->toContain('```json');
    });

    it('não sobra placeholder no prompt renderizado', function () {
        $prompt = app(PromptBuilder::class)->build(promptPayload());

        foreach ([':vocabulario_proibido', ':conduta_proibida', ':periodo', ':achados'] as $ph) {
            expect(str_contains($prompt, $ph))->toBeFalse("sobrou {$ph}");
        }
    });

    it('o denominador do período chega ao modelo (Artigo V)', function () {
        $prompt = app(PromptBuilder::class)->build(promptPayload());

        expect($prompt)->toContain('"coverage_percent": 91.1');
        expect($prompt)->toContain('"validity": "valid"');
    });
});

/**
 * ⚠️ As três armadilhas que as fases 4 e 5 descobriram, levadas ao prompt. São
 * erros que o modelo cometeria naturalmente, e nenhum deles é pego por varredura
 * de palavra.
 */
describe('as armadilhas específicas que o prompt precisa avisar', function () {

    it('avisa que razão de carboidrato é contraintuitiva', function () {
        $prompt = app(PromptBuilder::class)->build(promptPayload());

        expect($prompt)->toContain('Número maior significa menos insulina');
    });

    it('avisa que o período de razão mais fraca não é o pior do dia', function () {
        // Foi a nuance que R6 expôs: CR mais fraco é a noite, mas o pior período
        // é a tarde. Dizer "é à noite que sua glicose fica mais alta" seria falso.
        $prompt = app(PromptBuilder::class)->build(promptPayload());

        expect($prompt)->toContain('não é necessariamente o pior do dia');
    });

    it('manda reconhecer o trabalho quando ele aparecer', function () {
        // O 22/07 do export: sensor caiu e a pessoa corrigiu com bolus manual —
        // dia de MAIS esforço, não de menos.
        $prompt = app(PromptBuilder::class)->build(promptPayload());

        expect($prompt)->toContain('Reconheça o trabalho');
    });

    it('manda CONECTAR os achados, não listá-los', function () {
        // Os cartões da tela já mostram cada achado isolado. Se o texto só os
        // repetir, não acrescentou nada.
        $prompt = app(PromptBuilder::class)->build(promptPayload());

        expect($prompt)->toContain('O valor está em **conectar**');
    });
});

it('prompt ausente falha alto, em vez de mandar string vazia ao provedor', function () {
    $builder = new FilePromptBuilder(
        '/caminho/que/nao/existe.md',
        ['x'],
        ['y'],
    );

    expect(fn () => $builder->build(promptPayload()))
        ->toThrow(RuntimeException::class, 'não encontrado');
});

<?php

declare(strict_types=1);

use App\Domain\Ai\Chat\EmergencyClassifier;

/**
 * T500 — a ligação entre a configuração real e a guarda (§D4).
 *
 * ⚠️ **O teste de unidade prova a LÓGICA com listas literais; este prova que as
 * listas de PRODUÇÃO chegam lá.** São coisas diferentes, e a segunda é a que
 * quebra em silêncio: uma chave renomeada em `config/tone.php` deixaria o
 * classificador nascer vazio, e vazio ele deixa tudo passar.
 *
 * O construtor recusa lista vazia justamente por isso — mas recusar só ajuda se
 * alguém resolver a classe. Este arquivo é quem resolve.
 */
it('o classificador resolvido do container usa as listas de produção', function () {
    $guarda = app(EmergencyClassifier::class);

    // As mesmas frases do teste de unidade, agora com a configuração real.
    expect($guarda->isEmergency('estou passando mal, tremendo e suando frio'))->toBeTrue();
    expect($guarda->isEmergency('estou com 40 agora'))->toBeTrue();
    expect($guarda->isEmergency('socorro'))->toBeTrue();

    expect($guarda->isEmergency('qual foi minha pior hipoglicemia no período?'))->toBeFalse();
    expect($guarda->isEmergency('minhas hipos estão diminuindo?'))->toBeFalse();
});

it('as três listas de config/tone.emergency existem e não estão vazias', function () {
    foreach (['risk_terms', 'presence_markers', 'standalone_terms'] as $lista) {
        expect(config("tone.emergency.{$lista}"))
            ->toBeArray()
            ->not->toBeEmpty("config/tone.emergency.{$lista} está vazia");
    }

    expect(config('tone.emergency.critical_low'))
        ->toBeLessThan(config('tone.emergency.critical_high'));
});

/**
 * ⚠️ **A orientação é o texto mais sensível do produto inteiro** — é o único que
 * alguém lê num momento de risco. Três coisas dele são requisito, não estilo.
 */
it('a orientação de emergência diz o que fazer, com o número do serviço', function () {
    $texto = app(EmergencyClassifier::class)->guidance();

    // 1. O que fazer, concreto.
    expect($texto)->toContain('192');
    expect(mb_strtolower($texto))->toContain('pronto-socorro');

    // 2. ⚠️ Artigo VI: encaminha ao serviço médico e não interpreta o quadro.
    expect(mb_strtolower($texto))->toContain('atendimento médico');

    // 3. ⚠️ Diz que o histórico continua aqui. Sem isso a orientação lê como
    //    porta fechada — e quem levou porta fechada não volta.
    expect(mb_strtolower($texto))->toContain('continuam aqui');
});

/**
 * ⚠️ **O motivo de o texto morar em `lang/` e não em `config/`.**
 *
 * A varredura de vocabulário proibido (Artigo IV) percorre o diretório `lang/`
 * inteiro, então este arquivo entrou na proteção no instante em que foi criado.
 * Em `config/`, a orientação passaria despercebida.
 *
 * Este teste registra a dependência: se alguém mover o texto para fora de
 * `lang/`, ele quebra e a razão fica escrita.
 */
it('a orientação vem de lang/, onde a varredura do Artigo IV a alcança', function () {
    expect(file_exists(base_path('lang/pt_BR/chat.php')))->toBeTrue();

    expect(__('chat.emergency_guidance'))
        ->not->toBe('chat.emergency_guidance')     // chave ausente se disfarça de texto
        ->toBe(app(EmergencyClassifier::class)->guidance());
});

it('a orientação não usa vocabulário que julga a pessoa', function () {
    $texto = mb_strtolower(app(EmergencyClassifier::class)->guidance());

    foreach (config('tone.forbidden_vocabulary') as $proibido) {
        expect(str_contains($texto, mb_strtolower($proibido)))->toBeFalse(
            "a orientação de emergência contém \"{$proibido}\""
        );
    }
});

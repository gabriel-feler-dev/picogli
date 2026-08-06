<?php

declare(strict_types=1);

/**
 * Textos fixos do chat (Spec 006).
 *
 * ⚠️ **Por que a orientação de emergência mora aqui e não em `config/tone.php`.**
 *
 * O `tasks.md` do T500.6 dizia `config/tone.php` — e implementar mostrou que é o
 * lugar errado. A varredura de vocabulário proibido (Artigo IV) percorre o
 * diretório `lang/`, então **todo arquivo de idioma entra na proteção no momento
 * em que é criado**. Em `config/`, esta orientação passaria despercebida pela
 * varredura, e ela é o texto mais sensível do produto inteiro: é o único que
 * alguém lê num momento de risco.
 *
 * As LISTAS do classificador continuam em `config/tone.php` — são configuração de
 * guarda. O TEXTO é prosa voltada ao usuário, e prosa vive em `lang/`, como toda
 * a das fases 3 e 4. Divergência registrada em `specs/006-ia-chat/tasks.md`.
 *
 * ⚠️ **Artigo VI, camada 4.** Este texto substitui a resposta do modelo, não a
 * acompanha. Quando ele aparece, **nenhuma chamada de rede aconteceu** — é o que
 * significa "segurança não depende do modelo".
 */
return [

    /*
    |--------------------------------------------------------------------------
    | A orientação fixa de emergência
    |--------------------------------------------------------------------------
    |
    | Três parágrafos, nesta ordem, e a ordem importa:
    |
    | 1. reconhecer o que a pessoa escreveu, sem diagnosticar (Artigo VI);
    | 2. o que fazer AGORA, concreto e com o número do serviço;
    | 3. dizer que o histórico continua aqui — porque a pessoa interrompida no
    |    meio de uma pergunta precisa saber que não perdeu nada.
    |
    | ⚠️ O terceiro parágrafo não é gentileza: sem ele, a orientação lê como
    | porta fechada, e quem levou uma porta fechada não volta.
    |
    */

    'emergency_guidance' => <<<'TEXT'
        Pelo que você escreveu, isso pode ser uma situação de urgência — e o PicoGli não é o lugar para ela. Este app olha para o histórico; ele não acompanha o que está acontecendo agora.

        Procure atendimento médico imediatamente: ligue **192** (SAMU) ou vá ao pronto-socorro mais próximo. Se houver alguém por perto, avise essa pessoa antes.

        Seus dados continuam aqui. Quando isso passar, a conversa recomeça de onde parou.
        TEXT,

];

<?php

declare(strict_types=1);

/**
 * A camada de chat (Spec 006).
 *
 * ⚠️ Separado de `config/ai.php` de propósito: aquele arquivo é sobre **falar
 * com um provedor** (cadeia de modelos, cooldown, allowlist da narrativa) e vale
 * para as duas fases. Este é sobre **conversar** — teto de laço, limites de
 * consulta, rate limit. Misturar faria a fase 5 herdar limites que não são dela.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | O prompt
    |--------------------------------------------------------------------------
    |
    | Relativo a `resources/`. O arquivo NÃO contém as palavras proibidas: elas
    | são interpoladas de `config/tone.php` na renderização (§D6 da fase 5).
    |
    */

    'prompt_path' => 'prompts/chat.pt_BR.md',

    /*
    |--------------------------------------------------------------------------
    | ⚠️ O teto do laço de tool calling (§D5)
    |--------------------------------------------------------------------------
    |
    | É a primeira vez neste projeto que o MODELO controla o fluxo. Um modelo
    | confuso pede ferramenta em laço até a cota do dia acabar — e o sintoma
    | seria "o chat parou de funcionar", horas depois, sem relação aparente com
    | a pergunta que causou.
    |
    | Cinco é folgado para o fluxo do §9.3 (que descreve 1–3 chamadas por turno)
    | e barato o suficiente para que o estouro não custe nada. Estouro é evento
    | LOGADO: se acontecer com frequência, ou uma ferramenta está mal descrita
    | ou falta uma.
    |
    */

    'max_tool_iterations' => 5,

    /*
    |--------------------------------------------------------------------------
    | ⚠️ O teto de span por consulta (§D2)
    |--------------------------------------------------------------------------
    |
    | Mora no `ChatScope` e vale para as dez ferramentas. Dez limites separados
    | seriam dez chances de esquecer um — e "como foi meu último ano?" viraria
    | varredura de 105 mil leituras.
    |
    | 400 dias cobre "o ano passado inteiro" com folga, e ainda assim recusa a
    | pergunta que varreria a base toda.
    |
    */

    'max_span_days' => 400,

    /*
    |--------------------------------------------------------------------------
    | Rate limit próprio (§D12, §11.3)
    |--------------------------------------------------------------------------
    |
    | ⚠️ Independente do cooldown da `ModelChain`, e os dois protegem coisas
    | diferentes: o cooldown protege a COTA do provedor; este protege o produto.
    | Um laço acidental no front consumiria as 1.500 requisições do dia antes de
    | qualquer cooldown perceber que há algo errado.
    |
    */

    'rate_limit' => [
        'messages_per_minute' => 10,
        'messages_per_day' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Resposta
    |--------------------------------------------------------------------------
    |
    | O prompt pede duas a cinco frases. Este teto é a rede: resposta muito longa
    | costuma significar que o modelo listou dados em vez de responder.
    |
    */

    'max_words' => 400,

];

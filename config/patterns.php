<?php

declare(strict_types=1);

/**
 * Limiares de disparo do motor de padrões (Spec 004, §D4).
 *
 * Estes números NÃO são constantes de código: são decisões de produto sobre
 * **quando um padrão merece ser mostrado**. Vão ser ajustados quando o motor
 * rodar sobre um segundo export, e é para isso que vivem aqui.
 *
 * ⚠️ Duas coisas diferentes moram em dois arquivos diferentes, de propósito:
 *
 *   config/clinical.php   O QUE as coisas significam — faixas, metas, o que é
 *                         um episódio, quais são os quatro períodos do dia.
 *   config/patterns.php   QUANDO uma regra dispara.
 *
 * Se os períodos do dia estivessem aqui, o dashboard e o motor poderiam
 * discordar sobre o que é "tarde". Eles são definição clínica, não limiar de
 * regra, e por isso ficam em `clinical.dayparts`.
 *
 * ## Validação
 *
 * `PatternsConfig` confere na construção que as dez regras estão presentes e que
 * cada uma tem as chaves declaradas em `RuleId::requiredConfigKeys()`. Config
 * incompleta explode ao inicializar o container — nunca devolve `null` no meio
 * de uma comparação, onde `null >= 2.0` é `false` e a regra deixa de disparar
 * **em silêncio**.
 *
 * ## Valores de referência
 *
 * Onde o export de referência ancora o limiar, o valor apurado está no
 * comentário — vem de `specs/001-fundacao-de-dados/gabarito.md` §Fase 4.
 */
return [

    'rules' => [

        /*
        |----------------------------------------------------------------------
        | R1 — Deriva por período do dia
        |----------------------------------------------------------------------
        |
        | Compara tempo-acima-da-faixa entre os quatro períodos de 6 h.
        |
        | No export de referência: tarde 24,10% contra madrugada 4,17% = 5,78x,
        | acima de `priority_ratio`. Um recorte ad-hoc daria 4,01x — por isso o
        | período é fixo (§D6): janela escolhida depois de ver o resultado
        | sempre maximiza o efeito.
        |
        | `min_readings_per_daypart` evita o caso em que um período com lacuna
        | grande produz percentual sobre 40 leituras e vence a comparação por
        | ruído. Os períodos do export têm entre 831 e 936.
        |
        */

        'r1' => [
            'min_readings_per_daypart' => 100,
            'ratio_threshold' => 2.0,
            'priority_ratio' => 5.0,
        ],

        /*
        |----------------------------------------------------------------------
        | R2 — Cluster de hipoglicemias
        |----------------------------------------------------------------------
        |
        | Agrupa episódios de hipo em janelas de 2 h por hora do dia.
        |
        | No export: 5 episódios → 3 em 00h–04h e 2 em 17h–18h = 100% em duas
        | janelas.
        |
        | `min_episodes` existe porque concentração sobre n=2 não é padrão, é
        | coincidência: dois episódios caem na mesma janela por acaso com
        | frequência alta.
        |
        */

        'r2' => [
            'window_hours' => 2,
            'min_episodes' => 3,
            'max_windows' => 2,
            'concentration_threshold' => 0.60,
        ],

        /*
        |----------------------------------------------------------------------
        | R3 — Montanha-russa (hipo → sobrecorreção → hiper)
        |----------------------------------------------------------------------
        |
        | `carbs_threshold_g` é o ponto delicado: 15 g tratam uma hipoglicemia
        | (regra dos 15). Acima de 30 g o carboidrato passou do que a correção
        | exigia — e isso é reação fisiológica à queda, não escolha.
        |
        | ⚠️ A janela conta a partir do NADIR, não do início do episódio. O
        | nadir é o momento em que a fome dispara.
        |
        | No export: 25/07, nadir 55 às 17:56 → 109 g em 3 refeições → episódio
        | >250 iniciado às 19:41.
        |
        */

        'r3' => [
            'window_hours' => 4,
            'carbs_threshold_g' => 30.0,
        ],

        /*
        |----------------------------------------------------------------------
        | R4 — Dia outlier (concentração de Pareto)
        |----------------------------------------------------------------------
        |
        | Por DIA CIVIL (§D7), não por episódio. No export: 2026-07-25 responde
        | por 71,4% do tempo >250 (50 de 70 leituras), e 12 dos 14 dias não têm
        | nenhuma leitura acima de 250.
        |
        | `min_total_readings` impede o achado sem substância: com 3 leituras
        | acima de 250 no período inteiro, uma delas já é 33% — e "um terço do
        | seu tempo muito alto veio de um dia" seria verdade e não significaria
        | nada.
        |
        */

        'r4' => [
            'pareto_threshold' => 0.40,
            'min_total_readings' => 12,
        ],

        /*
        |----------------------------------------------------------------------
        | R5 — Falha de sensor derrubando o loop fechado
        |----------------------------------------------------------------------
        |
        | Sem sensor o SmartGuard não opera e a bomba volta ao modo manual.
        |
        | No export: lacuna de 1.347 min em 21–22/07 → insulina automática de
        | 9,0 U em 22/07 contra média de 31,4 U. A queda é de 71%, bem além de
        | `auto_insulin_drop_ratio`.
        |
        | ⚠️ Registrado em MINUTOS. 1.347 min = 22,45 h fica em cima da borda de
        | arredondamento (Python formata 22,4 e PHP arredonda 22,5), e ancorar
        | limiar em valor formatado cria divergência fantasma.
        |
        */

        'r5' => [
            'min_gap_minutes' => 360,
            'auto_insulin_drop_ratio' => 0.50,
        ],

        /*
        |----------------------------------------------------------------------
        | R6 — Coerência entre configuração e resultado
        |----------------------------------------------------------------------
        |
        | ⚠️ A regra que mais se aproxima de conduta médica. DESCREVE a
        | observação e DEVOLVE A PERGUNTA ao médico. Nunca propõe valor novo de
        | CR, basal ou ISF (Artigo VI, camada 3).
        |
        | `min_boluses_per_daypart` exclui período com amostra insuficiente. No
        | export, a hora 06h tem CR de 10,0 g/U com amostra mínima; incluí-la
        | quebraria a leitura de tendência 5 → 6 → 8 g/U com um único ponto.
        |
        | `min_ratio_spread_g` evita disparar sobre variação irrelevante: CR de
        | 5,0 contra 5,5 g/U não sustenta nenhuma observação.
        |
        */

        'r6' => [
            'min_boluses_per_daypart' => 3,
            'min_ratio_spread_g' => 1.0,
        ],

        /*
        |----------------------------------------------------------------------
        | R7 — Aderência ao uso do sensor
        |----------------------------------------------------------------------
        |
        | Mesmo 70% do Artigo V, aplicado a DIA e não a período. No export a
        | cobertura do período (91,1%) aprova, mas 22/07 tem 34% — é a média
        | que dilui o problema, e encontrar o que a média dilui é o objetivo da
        | fase.
        |
        | 21/07 tem 73% e PASSA. O limiar não é aproximado.
        |
        */

        'r7' => [
            'coverage_threshold' => 0.70,
        ],

        /*
        |----------------------------------------------------------------------
        | R8 — Trocas de reservatório
        |----------------------------------------------------------------------
        |
        | `min_rewinds` é 2 porque com uma troca não existe intervalo. No
        | export: 3 rewinds, 2 intervalos observados, média 4,41 dias (§D8).
        |
        | ⚠️ `Rewind` rastreia troca de RESERVATÓRIO. Troca de cateter sem troca
        | de reservatório pode não aparecer. A prosa carrega essa incerteza e
        | NUNCA afirma aderência ruim a partir deste dado sozinho.
        |
        | ⚠️ Não existe limiar de "intervalo ideal" aqui, e a ausência é
        | deliberada: dizer que o intervalo observado é longo demais seria
        | recomendar mudança de conduta (Artigo VI). A regra relata a cadência
        | observada e os alertas que o próprio aparelho emitiu.
        |
        */

        'r8' => [
            'min_rewinds' => 2,
        ],

        /*
        |----------------------------------------------------------------------
        | R9 — Carga de calibração
        |----------------------------------------------------------------------
        |
        | No export: 39 calibrações em 14 dias = 2,8/dia.
        |
        | ⚠️ Teto de severidade em `Info`, imposto por `RuleId::maxSeverity()`.
        | O Guardian Sensor 3 EXIGE calibração — o número é característica do
        | equipamento, não escolha da pessoa.
        |
        */

        'r9' => [
            'min_calibrations' => 1,
        ],

        /*
        |----------------------------------------------------------------------
        | R10 — Qualidade do sensor
        |----------------------------------------------------------------------
        |
        | Erro relativo médio entre leitura de sensor e capilar de calibração
        | pareada. No export: 10,7% com n=39 e janela de ±10 min — dentro do
        | esperado para Guardian 3.
        |
        | `pairing_minutes` faz parte da evidência: sem a janela, o número não é
        | reproduzível. Duas janelas diferentes dão dois erros médios, e ambos
        | estão certos.
        |
        */

        'r10' => [
            'pairing_minutes' => 10,
            'min_pairs' => 10,
        ],

    ],

];

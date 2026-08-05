<?php

declare(strict_types=1);

/**
 * Prosa de fallback do motor de padrões (Spec 004, §D3).
 *
 * ⚠️ **Este texto é entregável, não placeholder.** O Artigo I diz que, sem IA, o
 * produto perde a redação e não o achado. Se o que estivesse aqui fosse
 * "R3 disparou", esse artigo seria falso — e ninguém descobriria até a API cair.
 *
 * ⚠️ **Artigo IV se aplica a cada palavra.** O teste de vocabulário proibido da
 * fase 3 varre o diretório `lang/` inteiro, então este arquivo entrou na
 * varredura no momento em que foi criado. O texto descreve **mecanismo e
 * consequência**; nunca caráter.
 *
 * ⚠️ **Artigo VI:** nenhum texto daqui sugere dose, basal, razão de carboidrato
 * ou mudança de tratamento. Onde a observação encosta em conduta, ela termina
 * devolvendo a pergunta ao médico.
 *
 * ## Placeholders
 *
 * Todo `:chave` corresponde a uma chave de `evidence` do achado — é o Artigo II
 * verificável: se a prosa cita um número, ele veio da evidência. O
 * `LangProseRenderer` **falha** se um placeholder não tiver chave; `:average` na
 * tela é visível, mas só depois de publicado.
 *
 * `:chave_label` é a tradução de um valor de evidência (o período do dia
 * `afternoon` vira "tarde"). A palavra vive aqui; a identidade vive na evidência.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Rótulos compartilhados
    |--------------------------------------------------------------------------
    */

    'dayparts' => [
        'dawn' => 'madrugada',
        'morning' => 'manhã',
        'afternoon' => 'tarde',
        'evening' => 'noite',
    ],

    'dayparts_range' => [
        'dawn' => 'meia-noite às 6h',
        'morning' => '6h ao meio-dia',
        'afternoon' => 'meio-dia às 18h',
        'evening' => '18h à meia-noite',
    ],

    /*
    |--------------------------------------------------------------------------
    | R1 — Deriva por período do dia
    |--------------------------------------------------------------------------
    |
    | A prosa alvo do PicoGli.md §8.2 é o modelo:
    |
    |   "Suas manhãs e madrugadas são muito estáveis. O problema começa por
    |    volta das 14h e se estende até as 22h — nesse intervalo você passa
    |    4 vezes mais tempo com glicose alta."
    |
    | Note o que ela NÃO faz: não diz o que causou, não sugere ajuste, e começa
    | pelo que está indo bem. Começar pelo bom não é gentileza — é precisão: a
    | madrugada realmente está estável, e omiti-la daria um retrato falso.
    |
    */

    'r1' => [
        'title' => 'Sua glicose se comporta de formas diferentes ao longo do dia',

        'prose' => 'No período da :best_daypart_label (:best_daypart_range) sua '
            .'glicose fica acima da faixa em :best_percent_above% do tempo. '
            .'Da :worst_daypart_label (:worst_daypart_range), em '
            .':worst_percent_above%. É :ratio vezes mais tempo com glicose alta '
            .'no mesmo dia. Um padrão que se repete por horário costuma ter '
            .'causa identificável — diferente de variação de um dia só.',

        'prose_no_ratio' => 'No período da :best_daypart_label '
            .'(:best_daypart_range) sua glicose praticamente não passa da faixa. '
            .'Da :worst_daypart_label (:worst_daypart_range), ela fica acima em '
            .':worst_percent_above% do tempo — uma diferença de '
            .':difference_pp pontos percentuais no mesmo dia. Um padrão que se '
            .'repete por horário costuma ter causa identificável.',

        'evidence' => [
            'worst_daypart' => 'Período com mais tempo acima da faixa',
            'worst_percent_above' => 'Tempo acima da faixa no pior período',
            'worst_readings' => 'Leituras no pior período',
            'best_daypart' => 'Período com menos tempo acima da faixa',
            'best_percent_above' => 'Tempo acima da faixa no melhor período',
            'best_readings' => 'Leituras no melhor período',
            'ratio' => 'Quantas vezes o pior período é maior que o melhor',
            'difference_pp' => 'Diferença em pontos percentuais',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | R7 — Aderência ao uso do sensor
    |--------------------------------------------------------------------------
    |
    | ⚠️ A prosa fala do QUE SE PERDE, nunca do esforço da pessoa. Sem sensor
    | acontecem duas coisas concretas e verificáveis: o SmartGuard não opera, e
    | as métricas do período ficam menos confiáveis. As duas são mecanismo.
    |
    | "Você deveria usar mais o sensor" seria a mesma informação e uma violação
    | do Artigo IV — além de inútil, porque quem fica sem sensor geralmente sabe.
    |
    */

    'r7' => [
        'title' => 'Dias com menos cobertura do sensor',

        'prose' => 'Em :days_below_threshold de :days_total dias o sensor '
            .'registrou menos de :threshold_percent% das leituras possíveis; o '
            .'menor foi :worst_date, com :worst_coverage_percent%. Nas horas sem '
            .'leitura o SmartGuard não tem como agir e a bomba volta ao modo '
            .'manual — e as médias desses dias falam por menos tempo do que '
            .'parecem. No período todo a cobertura foi de :period_coverage_percent%.',

        'evidence' => [
            'days_below_threshold' => 'Dias abaixo do limiar de cobertura',
            'days_total' => 'Dias com leitura no período',
            'threshold_percent' => 'Limiar de cobertura',
            'worst_date' => 'Dia com menor cobertura',
            'worst_coverage_percent' => 'Cobertura do pior dia',
            'period_coverage_percent' => 'Cobertura do período',
            'days_below_100' => 'Dias com alguma lacuna',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | R8 — Trocas de reservatório
    |--------------------------------------------------------------------------
    |
    | ⚠️ Duas restrições, as duas obrigatórias por requisito (FR-410):
    |
    |   1. O CAVEAT. `Rewind` rastreia troca de RESERVATÓRIO. Trocar o cateter
    |      sem trocar o reservatório pode não aparecer no arquivo. Sem essa
    |      ressalva, o número viraria afirmação sobre aderência — a partir de um
    |      dado que não sustenta a afirmação.
    |
    |   2. O `n`. Três trocas dão DOIS intervalos. Uma média de dois valores é
    |      frágil, e a prosa diz isso em vez de esconder atrás de um número.
    |
    | ⚠️ Nenhum texto aqui diz que o intervalo é longo ou curto demais. Dizer
    | isso seria recomendar mudança de conduta (Artigo VI). A regra relata a
    | cadência observada e os alertas que o PRÓPRIO APARELHO emitiu.
    |
    */

    'r8' => [
        'title' => 'Cadência das trocas de reservatório',

        'prose' => 'O arquivo registra :rewinds trocas de reservatório no '
            .'período, com :intervals intervalos entre elas, de '
            .':mean_interval_days dias em média. Vale ler com cuidado: o export '
            .'marca a troca do RESERVATÓRIO, então uma troca de cateter feita '
            .'sozinha pode não aparecer aqui — e :intervals intervalos são '
            .'poucos para falar de rotina.',

        'prose_with_reminders' => 'O arquivo registra :rewinds trocas de '
            .'reservatório no período, com :intervals intervalos entre elas, de '
            .':mean_interval_days dias em média. A bomba também emitiu '
            .':set_change_reminders avisos de troca de conjunto. Vale ler com '
            .'cuidado: o export marca a troca do RESERVATÓRIO, então uma troca '
            .'de cateter feita sozinha pode não aparecer aqui — e :intervals '
            .'intervalos são poucos para falar de rotina.',

        'evidence' => [
            'rewinds' => 'Trocas de reservatório registradas',
            'primes' => 'Cargas de linha (prime)',
            'intervals' => 'Intervalos observados entre trocas',
            'mean_interval_days' => 'Intervalo médio entre trocas',
            'shortest_interval_days' => 'Menor intervalo observado',
            'longest_interval_days' => 'Maior intervalo observado',
            'set_change_reminders' => 'Avisos de troca emitidos pela bomba',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | R9 — Carga de calibração
    |--------------------------------------------------------------------------
    |
    | ⚠️ **O contexto é o requisito, não um enfeite** (FR-411). "2,8 picadas de
    | dedo por dia" lido por quem não conhece o equipamento soa como cobrança.
    | O Guardian Sensor 3 EXIGE calibração: o número é característica do
    | aparelho, não escolha de quem o usa.
    |
    | Por isso esta regra tem teto de severidade em `Info`, imposto pelo enum
    | `RuleId::maxSeverity()`. Ela informa; não cobra.
    |
    | É o caso mais claro do projeto de uma regra que só conta linhas e ainda
    | assim pode violar o Artigo IV.
    |
    */

    'r9' => [
        'title' => 'Quantas calibrações o sensor pediu',

        'prose' => 'Foram :calibrations calibrações em :days dias, cerca de '
            .':per_day por dia. Esse número é característica do equipamento: o '
            .'Guardian Sensor 3 precisa de calibração por glicemia capilar para '
            .'funcionar, então as picadas de dedo fazem parte do uso normal '
            .'dele — não são um extra que dependia de escolha.',

        'evidence' => [
            'calibrations' => 'Calibrações registradas',
            'days' => 'Dias com leitura no período',
            'per_day' => 'Calibrações por dia',
        ],
    ],

];

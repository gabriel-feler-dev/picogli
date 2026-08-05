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
    | R2 — Cluster de hipoglicemias
    |--------------------------------------------------------------------------
    |
    | A prosa alvo do PicoGli.md §8.2:
    |
    |   "Suas quedas não são aleatórias: acontecem em dois horários — antes do
    |    jantar e de madrugada. Isso é um padrão, e padrões têm causa
    |    identificável."
    |
    | ⚠️ Note o que ela NÃO faz: não sugere reduzir basal, não estima dose, não
    | afirma a causa. Diz que existe padrão e que padrão tem causa — verdadeiro,
    | útil, e devolve a investigação a quem pode fazê-la (Artigo VI).
    |
    | ⚠️ `:episodes_outside` aparece no texto de propósito. Com 4 de 5 episódios
    | agrupados, dizer só "80%" deixaria o leitor supondo que todos se encaixam.
    | O que ficou fora faz parte do achado.
    |
    */

    'r2' => [
        'title' => 'Suas quedas de glicose se concentram em horários',

        'prose' => 'Das :episodes_total quedas abaixo de 70 no período, '
            .':episodes_clustered acontecem em dois horários: por volta de '
            .':window1_start e por volta de :window2_start. '
            .'Queda que se repete em horário parecido tende a ter causa '
            .'identificável — é diferente de uma queda isolada. '
            .'(:episodes_outside ficaram fora desses dois horários.)',

        'prose_single_window' => 'Das :episodes_total quedas abaixo de 70 no '
            .'período, :episodes_clustered acontecem por volta de '
            .':window1_start. Queda que se repete em horário parecido tende a '
            .'ter causa identificável — é diferente de uma queda isolada. '
            .'(:episodes_outside ficaram fora desse horário.)',

        'evidence' => [
            'episodes_total' => 'Episódios de hipoglicemia no período',
            'episodes_clustered' => 'Episódios dentro das janelas',
            'episodes_outside' => 'Episódios fora das janelas',
            'windows_used' => 'Janelas identificadas',
            'window_hours' => 'Largura da janela, em horas',
            'concentration_percent' => 'Concentração nas janelas',
            'worst_nadir' => 'Menor valor atingido',
            'window1_start' => 'Início da primeira janela',
            'window1_end' => 'Fim da primeira janela',
            'window1_episodes' => 'Episódios na primeira janela',
            'window1_nadir' => 'Menor valor na primeira janela',
            'window2_start' => 'Início da segunda janela',
            'window2_end' => 'Fim da segunda janela',
            'window2_episodes' => 'Episódios na segunda janela',
            'window2_nadir' => 'Menor valor na segunda janela',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | R4 — Dia outlier (concentração de Pareto)
    |--------------------------------------------------------------------------
    |
    | ⚠️ **A regra mais valiosa do conjunto, e a razão é o tom.** Ela inverte a
    | leitura que a pessoa faz de si mesma: "1,9% do tempo acima de 250" parece
    | um problema crônico e difuso. A verdade é que foram dois dias, e um deles
    | responde por 71% — nos outros doze, zero minutos.
    |
    | É o oposto de acusatório sem precisar de nenhum eufemismo. Só de medir a
    | coisa certa.
    |
    | A prosa alvo do PicoGli.md §8.2:
    |
    |   "Seu 'tempo em glicose muito alta' parece um problema constante, mas não
    |    é: 72% dele veio de um único dia. Nos outros 13 dias você ficou
    |    praticamente sempre abaixo de 250."
    |
    | Duas prosas porque as duas métricas pedem enquadramentos diferentes:
    | glicose muito alta é evento, hipoglicemia é risco.
    |
    */

    'r4' => [
        'title' => 'Um único dia responde pela maior parte',

        'prose_above_250' => 'O tempo com glicose acima de 250 parece constante, '
            .'mas não é: de :total_minutes minutos no período, '
            .':dominant_minutes vieram de um único dia, :dominant_date — '
            .':contribution_percent% do total. Nos outros :clean_days dias com '
            .'leitura você não passou nenhum minuto acima de 250. Um evento '
            .'isolado e um problema constante pedem leituras diferentes.',

        'prose_below_70' => 'O tempo com glicose abaixo de 70 se concentra em um '
            .'dia: de :total_minutes minutos no período, :dominant_minutes '
            .'vieram de :dominant_date — :contribution_percent% do total. '
            .'Em :clean_days dos :days_total dias não houve nenhuma leitura '
            .'abaixo de 70.',

        'evidence' => [
            'metric' => 'Métrica avaliada',
            'dominant_date' => 'Dia com maior contribuição',
            'dominant_readings' => 'Leituras no dia dominante',
            'dominant_minutes' => 'Minutos no dia dominante',
            'total_readings' => 'Leituras no período',
            'total_minutes' => 'Minutos no período',
            'contribution_percent' => 'Contribuição do dia dominante',
            'days_total' => 'Dias com leitura',
            'days_affected' => 'Dias com alguma ocorrência',
            'clean_days' => 'Dias sem nenhuma ocorrência',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | R5 — Falha de sensor derrubando o loop fechado
    |--------------------------------------------------------------------------
    |
    | ⚠️ **O achado que atravessa dois blocos do CSV** — a lacuna vem do bloco
    | Sensor, a insulina automática do bloco `Aggregated Auto Insulin Data`.
    | Nenhum relatório da Medtronic mostra essa conexão.
    |
    | A prosa alvo do PicoGli.md §8.2:
    |
    |   "Em 22/07 seu sensor ficou 22 horas fora do ar. Sem sensor, o SmartGuard
    |    não funciona e a bomba volta ao modo manual — foi seu único dia com quase
    |    nenhuma insulina automática, e você teve que compensar com bolus."
    |
    | ⚠️ O achado é sobre o EQUIPAMENTO, não sobre a pessoa. O texto explica o
    | mecanismo e para ali: não diz que o sensor deveria ter sido trocado antes,
    | não estima quanta insulina "faltou", não sugere nada. Inclusive porque o
    | dado não sustentaria nenhuma dessas afirmações.
    |
    | A última frase reconhece o esforço em vez de cobrá-lo — quem passou o dia
    | corrigindo com bolus manual trabalhou mais, não menos.
    |
    */

    'r5' => [
        'title' => 'Sensor fora do ar derrubou a insulina automática',

        'prose' => 'Em :affected_date seu sensor ficou :gap_hours horas sem '
            .'registrar (:gap_minutes minutos, de :gap_start a :gap_end). Sem '
            .'leitura de sensor o SmartGuard não tem como decidir, e a bomba '
            .'volta ao modo manual: naquele dia a insulina automática foi de '
            .':auto_insulin_u U contra :period_mean_auto_insulin_u U de média no '
            .'período — :drop_percent% menos. A parte automática do dia caiu para '
            .':day_automatic_fraction_percent% do total, contra '
            .':period_automatic_fraction_percent% no período. O resto veio de '
            .'bolus, ou seja: naquele dia o ajuste que a bomba costuma fazer '
            .'sozinha passou a ser feito à mão.',

        'evidence' => [
            'gap_minutes' => 'Duração da lacuna, em minutos',
            'gap_hours' => 'Duração da lacuna, em horas',
            'gap_start' => 'Início da lacuna',
            'gap_end' => 'Fim da lacuna',
            'affected_date' => 'Dia mais afetado pela lacuna',
            'gap_minutes_on_date' => 'Minutos da lacuna nesse dia',
            'auto_insulin_u' => 'Insulina automática no dia',
            'period_mean_auto_insulin_u' => 'Média de insulina automática no período',
            'drop_percent' => 'Queda em relação à média',
            'day_automatic_fraction_percent' => 'Fração automática no dia',
            'period_automatic_fraction_percent' => 'Fração automática no período',
            'day_bolus_insulin_u' => 'Bolus no dia',
            'day_coverage_percent' => 'Cobertura do sensor no dia',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | R10 — Qualidade do sensor
    |--------------------------------------------------------------------------
    |
    | ⚠️ A JANELA DE PAREAMENTO APARECE NO TEXTO. Duas janelas diferentes
    | produzem dois erros médios, e os dois estão certos — o que os distingue é
    | a janela. Sem ela, "10,7%" não é reproduzível.
    |
    | ⚠️ Nenhuma das duas prosas recomenda trocar sensor, recalibrar ou procurar
    | suporte. Isso seria conduta sobre equipamento médico (Artigo VI). A regra
    | relata e para.
    |
    */

    'r10' => [
        'title' => 'Quanto o sensor difere da glicemia capilar',

        'prose' => 'Comparando cada calibração capilar com a leitura do sensor '
            .'mais próxima (dentro de :window_minutes minutos), a diferença '
            .'média foi de :mean_error_percent% em :pairs comparações. Isso está '
            .'dentro do que se espera de um sensor como o Guardian 3, que '
            .'trabalha com uma margem em relação à glicemia de dedo — as duas '
            .'medidas não são a mesma coisa e não precisam coincidir.',

        'prose_above_expected' => 'Comparando cada calibração capilar com a '
            .'leitura do sensor mais próxima (dentro de :window_minutes '
            .'minutos), a diferença média foi de :mean_error_percent% em '
            .':pairs comparações — acima da margem de :expected_error_percent% '
            .'que se costuma esperar. Vale saber que calibração feita durante '
            .'variação rápida de glicose aumenta essa diferença sem que o sensor '
            .'esteja pior.',

        'evidence' => [
            'pairs' => 'Comparações pareadas',
            'window_minutes' => 'Janela de pareamento',
            'mean_error_percent' => 'Diferença média',
            'median_error_percent' => 'Diferença mediana',
            'max_error_percent' => 'Maior diferença',
            'mean_offset_minutes' => 'Distância média entre as medidas',
            'expected_error_percent' => 'Margem esperada',
            'pairs_sensor_higher' => 'Comparações em que o sensor leu mais alto',
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

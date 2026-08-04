<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Textos voltados ao usuário
|--------------------------------------------------------------------------
|
| ⚠️ ARTIGO IV — tom não acusatório. Todo texto aqui descreve MECANISMO e
| CONSEQUÊNCIA, nunca caráter.
|
| Vocabulário PROIBIDO, verificado por teste que varre este diretório:
|   "você deveria", "falta de", "descuido", "descontrole", "errado",
|   "ruim", "culpa", "falhou"
|
| O teste não é formalidade. Um app que soa acusatório sobre dado de saúde é
| desinstalado, e aí nenhuma outra qualidade importa.
|
*/

return [

    'unit' => [
        'hours' => ':value h',
        'minutes' => ':value min',
    ],

    'time_in_range' => [
        'label' => 'Na faixa boa',
        'plain' => ':hours por dia',
        'technical' => 'TIR :percent',
        'target' => 'meta: :hours (:percent)',
        'explanation' => 'É quanto tempo a sua glicose ficou entre 70 e 180 mg/dL. '
            .'É a medida que mais se relaciona com como você se sente no dia a dia.',
    ],

    'variability' => [
        'label' => 'Estabilidade',
        // Descreve o padrão do dia, não a pessoa.
        'plain_stable' => 'Dias estáveis',
        'plain_unstable' => 'Dias com muita oscilação',
        'technical' => 'CV :percent',
        'target' => 'meta: menos de :percent',
        'explanation' => 'Mede o tamanho das subidas e descidas. '
            .'Quanto menor, mais parecida com uma estrada plana e menos com uma montanha-russa.',
    ],

    'gmi' => [
        'label' => 'Equivale a HbA1c',
        'plain' => '~:percent%',
        'technical' => 'GMI :percent%',
        // Sem meta: o alvo de HbA1c é individualizado pelo médico (Artigo VI).
        'explanation' => 'É uma estimativa da sua hemoglobina glicada calculada a partir do sensor. '
            .'O alvo de HbA1c é definido junto com a sua equipe médica.',
    ],

    'time_below_range' => [
        'label' => 'Abaixo da faixa',
        'plain' => ':minutes por dia',
        'technical' => ':percent do tempo',
        'target' => 'meta: menos de :percent',
        'explanation_none_severe' => 'É o tempo com glicose abaixo de 70 mg/dL. '
            .'Nenhuma queda chegou ao nível severo (abaixo de 54) neste período.',
        'explanation_severe' => 'É o tempo com glicose abaixo de 70 mg/dL. '
            .'Parte dele ficou abaixo de 54 — vale levar esse dado à sua equipe médica.',
    ],

    'coverage' => [
        'label' => 'Período analisado',
        // Artigo V — o denominador nunca fica escondido.
        'summary' => ':days dias · :percent de captura do sensor',
        'span_note' => 'intervalo real: :span dias',
        'readings' => ':count leituras de :expected esperadas',
    ],

    'validity' => [
        'unreliable' => 'Estimativa pouco confiável',
        'insufficient_days' => 'Estes números precisam de pelo menos :days dias de dados '
            .'para serem interpretáveis. O período tem :actual.',
        'insufficient_coverage' => 'O sensor cobriu :percent do período. '
            .'Abaixo de :required a estimativa fica imprecisa.',
        'stale_metrics' => 'Há um recálculo de métricas pendente para este período.',
    ],

];

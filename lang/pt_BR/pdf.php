<?php

declare(strict_types=1);

/**
 * Agregados de relatório em PDF (Spec 007, item 3).
 *
 * ⚠️ **O vocabulário é o mesmo do resto do produto** — "tempo na faixa", não
 * "TIR". É o que permite pôr um agregado de PDF ao lado da métrica equivalente de
 * CSV e a pessoa reconhecer que falam da mesma coisa, com procedências diferentes.
 */
return [

    'title' => 'Resumos de relatório em PDF',

    /*
    |--------------------------------------------------------------------------
    | ⚠️ A marcação de procedência (§D7)
    |--------------------------------------------------------------------------
    |
    | Não é aviso legal: é o Artigo V por analogia. Um número resumido pela
    | Medtronic e um calculado sobre 3.616 leituras não têm a mesma força, e
    | exibi-los sem distinção seria esconder o denominador — só que o
    | denominador, aqui, é "de onde isso veio".
    |
    */

    'source_note' => 'Números lidos de um relatório em PDF. São resumos prontos, '
        .'não cálculos sobre suas leituras — por isso aparecem separados.',

    'superseded' => 'Este período também tem export em CSV. Os números das outras '
        .'telas vêm dele, que é mais detalhado.',

    'metrics' => [
        'mean_glucose' => 'Média de glicose',
        'time_in_range_percent' => 'Tempo na faixa',
        'time_above_180_percent' => 'Tempo acima de 180',
        'time_above_250_percent' => 'Tempo acima de 250',
        'time_below_70_percent' => 'Tempo abaixo de 70',
        'time_below_54_percent' => 'Tempo abaixo de 54',
        'cv_percent' => 'Variabilidade',
        'gmi' => 'GMI',
        'coverage_percent' => 'Uso do sensor',
        'total_insulin_u' => 'Insulina total',
    ],

];

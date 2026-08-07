<?php

declare(strict_types=1);

/**
 * Tela de refeições (Spec 007, §10.5).
 *
 * ⚠️ Este arquivo entra na varredura do Artigo IV no momento em que é criado — a
 * varredura percorre o diretório `lang/`, não uma lista de arquivos.
 *
 * ⚠️ **Artigo VI:** nenhum texto aqui sugere o que comer, quanto comer ou como
 * dosar. A tela descreve o que aconteceu depois de cada refeição. Onde a leitura
 * encosta em conduta, ela para.
 */
return [

    'title' => 'Refeições',
    'subtitle' => 'O que aconteceu com a glicose depois de cada uma',

    /*
    |--------------------------------------------------------------------------
    | O rótulo
    |--------------------------------------------------------------------------
    |
    | ⚠️ O convite é para NOMEAR, não para descrever. "Pizza" e "feijoada" são o
    | que habilita agrupamento; um diário de refeição pediria outra spec.
    |
    */

    'label_field' => 'Rótulo',
    'label_placeholder' => 'pizza, feijoada, café da manhã…',
    'label_hint' => 'Nomeie refeições parecidas com o mesmo rótulo e elas passam a aparecer agrupadas.',
    'label_saved' => 'Rótulo salvo.',

    /*
    |--------------------------------------------------------------------------
    | Colunas
    |--------------------------------------------------------------------------
    */

    'carbs' => 'Carboidrato',
    'carb_ratio' => 'Razão vigente',
    'bg_input' => 'Glicose na hora',
    'peak' => 'Pico em 2 h',
    'delta' => 'Subida',
    'settled' => 'Depois de 4 h',
    'no_response' => 'Sem leitura de sensor por perto',

    /*
    |--------------------------------------------------------------------------
    | Agrupamento — ⚠️ o denominador nunca sai de vista
    |--------------------------------------------------------------------------
    |
    | "Pizza sobe 87 mg/dL" sobre duas refeições é ruído com cara de conclusão
    | (Artigo V). A contagem aparece ao lado da média, sempre.
    |
    */

    'groups_title' => 'Por rótulo',
    'groups_empty' => 'Rotule algumas refeições e elas aparecem agrupadas aqui.',
    'group_count' => ':count refeições',
    'group_count_one' => '1 refeição',
    'group_with_response' => ':count com leitura de sensor',
    'group_small_sample' => 'Poucas refeições ainda — a média muda bastante com a próxima.',

    /*
    |--------------------------------------------------------------------------
    | Vazios
    |--------------------------------------------------------------------------
    */

    'empty_title' => 'Nenhuma refeição no período',
    'empty_body' => 'As refeições vêm das linhas da calculadora de bolus do export. '
        .'Se você não usou a calculadora, elas não aparecem aqui.',

];

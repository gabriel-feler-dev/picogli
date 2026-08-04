<?php

declare(strict_types=1);

/*
| ⚠️ Artigo IV — sujeito ao teste de vocabulário proibido, como todo arquivo
| deste diretório.
*/

return [

    'queued' => 'Arquivo recebido. A importação roda em segundo plano e o resumo '
        .'aparece aqui quando terminar.',

    'status' => [
        'pending' => 'Na fila',
        'processing' => 'Importando',
        'done' => 'Concluída',
        'failed' => 'Não concluída',
    ],

    'reconciles' => 'Todas as linhas deste bloco foram classificadas.',
    'does_not_reconcile' => 'Há linhas deste bloco sem classificação — vale conferir os avisos.',

    'explanation' => 'O resumo mostra, bloco por bloco, o que cada linha do arquivo virou. '
        .'A soma dos itens confere com o total de linhas: é assim que se verifica que nada '
        .'ficou de fora.',

];

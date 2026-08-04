<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Fuso padrão sugerido na importação
    |--------------------------------------------------------------------------
    |
    | ⚠️ O CSV do CareLink NÃO carrega fuso (§A5): os timestamps são hora local
    | de parede do aparelho. Este valor é apenas o padrão do formulário — o
    | usuário confirma antes de importar.
    |
    | Errar o fuso desloca todo insight de horário mantendo os números
    | plausíveis, e é por isso que ele não é adivinhado em silêncio.
    |
    */

    'default_timezone' => env('PICOGLI_DEFAULT_TIMEZONE', 'America/Sao_Paulo'),

];

<?php

declare(strict_types=1);

/**
 * Parâmetros clínicos de referência.
 *
 * Estes valores NÃO são constantes de código. São metas de consenso que:
 *   - variam por população (gestação, idoso frágil, criança, T2D);
 *   - podem ser individualizadas pelo médico do paciente.
 *
 * Manter em config permite ajustar sem tocar em lógica, e deixa explícito
 * que são parâmetros, não verdades absolutas.
 *
 * Fontes:
 *   - Battelino T. et al. Clinical Targets for CGM Data Interpretation.
 *     Diabetes Care, 2019.  (metas de TIR/TAR/TBR/CV)
 *   - Bergenstal R. et al. Glucose Management Indicator (GMI).
 *     Diabetes Care, 2018.  (fórmula do GMI)
 *   - Danne T. et al. International Consensus on Use of CGM.
 *     Diabetes Care, 2017.  (episódios, 14 dias / 70% de captura)
 *
 * ⚠️ Confira contra a diretriz vigente ao implementar a Spec 002.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Faixas de glicose (mg/dL)
    |--------------------------------------------------------------------------
    |
    | Limites que definem TIR, TAR e TBR. Os intervalos são fechados nos dois
    | extremos onde indicado, e a soma dos cinco percentuais deve dar 100% —
    | invariante testada na Spec 002.
    |
    */

    'ranges' => [
        'very_low' => ['max' => 53],               // < 54        (TBR nível 2)
        'low' => ['min' => 54, 'max' => 69],       // 54–69       (TBR nível 1)
        'target' => ['min' => 70, 'max' => 180],   // 70–180      (TIR)
        'high' => ['min' => 181, 'max' => 250],    // 181–250     (TAR nível 1)
        'very_high' => ['min' => 251],             // > 250       (TAR nível 2)
    ],

    /*
    |--------------------------------------------------------------------------
    | Metas (adulto com diabetes tipo 1)
    |--------------------------------------------------------------------------
    |
    | Percentual de tempo. `direction` diz se a meta é atingida ficando
    | acima ou abaixo do valor — a UI usa isso para decidir o indicador
    | de "dentro/fora da meta" sem hardcode por métrica.
    |
    */

    'targets' => [
        'time_in_range' => ['value' => 70.0, 'direction' => 'above'],
        'time_above_180' => ['value' => 25.0, 'direction' => 'below'],
        'time_above_250' => ['value' => 5.0, 'direction' => 'below'],
        'time_below_70' => ['value' => 4.0, 'direction' => 'below'],
        'time_below_54' => ['value' => 1.0, 'direction' => 'below'],
        'coefficient_of_variation' => ['value' => 36.0, 'direction' => 'below'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Portão de validade — Artigo V da constituição
    |--------------------------------------------------------------------------
    |
    | GMI e CV só são interpretáveis com dados suficientes. Abaixo destes
    | limiares, a métrica é exibida marcada como não confiável, ou não é
    | exibida.
    |
    | `min_days_rounding_floor`: um span de 13,8 dias arredonda para 14 se
    | for >= este valor. Decisão de produto documentada em gabarito.md —
    | aceitável SOMENTE porque a UI sempre mostra o span real ao lado.
    | Nunca esconder o denominador.
    |
    */

    'validity' => [
        'min_days' => 14,
        'min_coverage' => 0.70,
        'min_days_rounding_floor' => 13.5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensor
    |--------------------------------------------------------------------------
    |
    | `readings_per_day`: um CGM a cada 5 minutos → 288/dia. Denominador do
    | cálculo de cobertura.
    |
    | `interval_minutes`: usado para detectar lacuna. Um intervalo maior que
    | `gap_threshold_minutes` entre leituras consecutivas é lacuna, e deve
    | aparecer como DESCONTINUIDADE no gráfico — nunca linha interpolada.
    | Interpolar é inventar dado.
    |
    */

    'sensor' => [
        'readings_per_day' => 288,
        'interval_minutes' => 5,
        'gap_threshold_minutes' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Detecção de episódios
    |--------------------------------------------------------------------------
    |
    | Regra de consenso: o episódio INICIA após `min_duration_minutes`
    | consecutivos fora da faixa, e TERMINA após `recovery_minutes`
    | consecutivos de volta na faixa.
    |
    | ⚠️ A condição de término importa. Encerrar na primeira leitura de volta
    | à faixa fragmenta um episódio oscilante em vários. O gabarito atual foi
    | apurado com a versão simplificada — ver a nota em gabarito.md §Episódios.
    |
    */

    'episodes' => [
        'hypoglycemia' => [
            'threshold' => 70,
            'min_duration_minutes' => 15,
            'recovery_minutes' => 15,
        ],
        'hyperglycemia_level2' => [
            'threshold' => 250,
            'min_duration_minutes' => 30,
            'recovery_minutes' => 15,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Períodos do dia
    |--------------------------------------------------------------------------
    |
    | Usados pela regra R1 (deriva por período) na Spec 004 e pelo perfil
    | horário do dashboard. Hora local — nunca UTC (Artigo VIII.5).
    |
    | Os rótulos são o que o usuário lê, então seguem o Artigo IV: descrevem
    | o horário, não julgam.
    |
    */

    'dayparts' => [
        'dawn' => ['label' => 'madrugada', 'from' => 0, 'to' => 5],
        'morning' => ['label' => 'manhã', 'from' => 6, 'to' => 11],
        'afternoon' => ['label' => 'tarde', 'from' => 12, 'to' => 17],
        'evening' => ['label' => 'noite', 'from' => 18, 'to' => 23],
    ],

    /*
    |--------------------------------------------------------------------------
    | GMI
    |--------------------------------------------------------------------------
    |
    | GMI% = intercept + slope × média(mg/dL)
    | Bergenstal 2018: 3,31 + 0,02392 × média
    |
    | Em config porque a fórmula depende da unidade e poderia ser revisada.
    | O cálculo em si é código (Artigo I) — nunca IA.
    |
    */

    'gmi' => [
        'intercept' => 3.31,
        'slope' => 0.02392,
    ],

    /*
    |--------------------------------------------------------------------------
    | Unidade
    |--------------------------------------------------------------------------
    |
    | Unidade interna de armazenamento. Exports em mmol/L são convertidos na
    | importação, e a unidade original fica gravada em `imports.glucose_unit`
    | (§A7 — nunca assumir).
    |
    | Fator: 1 mmol/L = 18,0182 mg/dL
    |
    */

    'unit' => [
        'internal' => 'mg/dL',
        'mmoll_to_mgdl' => 18.0182,
    ],

];

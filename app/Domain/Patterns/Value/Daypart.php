<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Value;

/**
 * Os quatro períodos do dia (§D6).
 *
 * ⚠️ **O enum carrega identidade, não limites.** As horas de cada período vivem
 * em `config/clinical.dayparts` e chegam injetadas no `DaypartAggregator`.
 *
 * *Por quê:* se os limites estivessem aqui, existiriam duas definições de
 * "tarde" — uma no enum e outra na config que o dashboard já usa — e um dia elas
 * discordariam. O valor de cada case é exatamente a chave da config, e o
 * agregador **valida** que os dois conjuntos casam.
 *
 * ## Por que blocos fixos de 6 h
 *
 * A análise exploratória comparou `00h–12h` com `15h–22h` e achou razão de 4x.
 * Com blocos fixos a razão real é 5,78x (gabarito §Fase 4) — maior, e obtida sem
 * escolher o recorte depois de ver o resultado. Janela ajustada ao dado sempre
 * maximiza o efeito que se quer mostrar; é a diferença entre medir e convencer.
 */
enum Daypart: string
{
    case Dawn = 'dawn';
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';
}

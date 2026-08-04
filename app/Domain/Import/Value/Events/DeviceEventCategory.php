<?php

declare(strict_types=1);

namespace App\Domain\Import\Value\Events;

/**
 * Categorias de evento de dispositivo.
 *
 * `Alert` e `AlertCleared` são deliberadamente separados: o CSV usa colunas
 * distintas (`Alert` e `User Cleared Alerts`), e a diferença é semântica —
 * um é o alerta disparando, o outro é o usuário dispensando. Cruzar os dois
 * dá tempo de resposta a alerta.
 */
enum DeviceEventCategory: string
{
    case Alert = 'alert';
    case AlertCleared = 'alert_cleared';
    case Suspend = 'suspend';
    case Rewind = 'rewind';
    case Prime = 'prime';
    case SensorState = 'sensor_state';

    /**
     * Calibração aceita pelo sensor (`Sensor Calibration BG`).
     *
     * Distinta da glicemia capilar enviada para calibrar, que é um
     * `BgReadingEvent` com `used_for_calibration = true`. As duas aparecem em
     * linhas diferentes do arquivo, 39 de cada no export de referência.
     * Para calcular erro do sensor (MARD), o valor relevante é o aceito.
     */
    case Calibration = 'calibration';
}

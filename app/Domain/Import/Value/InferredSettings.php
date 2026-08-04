<?php

declare(strict_types=1);

namespace App\Domain\Import\Value;

use App\Models\DeviceSettingsSnapshot;

/**
 * Perfil do aparelho reconstruído a partir dos bolus.
 *
 * O CSV não traz o relatório "Definições do dispositivo", mas cada linha BWZ
 * registra a razão de carboidrato e a sensibilidade **vigentes naquele
 * momento** — o que basta para reconstruir o perfil por horário.
 *
 * ⚠️ É reconstrução, não leitura. Só há dado nas horas em que houve bolus:
 * `carbRatioProfile` tem buracos, e isso é informação (o usuário não comeu
 * naquela faixa no período), não falha.
 */
final readonly class InferredSettings
{
    /**
     * @param  array<int, float>  $carbRatioProfile  hora local (0–23) → g/U
     * @param  list<float>  $isfValues  sensibilidades distintas, em mg/dL/U
     * @param  array<int, float>|null  $basalProfile  hora local → U/h
     * @param  list<string>  $conflicts  horas com mais de um valor no período
     */
    public function __construct(
        public array $carbRatioProfile,
        public array $isfValues,
        public ?array $basalProfile,
        public array $conflicts = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->carbRatioProfile === [] && $this->isfValues === [];
    }

    public function fingerprint(): string
    {
        return DeviceSettingsSnapshot::makeFingerprint(
            $this->carbRatioProfile,
            $this->isfValues,
            $this->basalProfile,
        );
    }
}

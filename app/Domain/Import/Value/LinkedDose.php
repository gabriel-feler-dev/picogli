<?php

declare(strict_types=1);

namespace App\Domain\Import\Value;

use App\Domain\Import\Value\Events\MealEvent;
use DateTimeImmutable;

/**
 * Uma dose de insulina consolidada — o resultado de juntar as até três linhas
 * que o CSV usa para representar um bolus (§A3).
 *
 * ## Uma dose = uma linha em `insulin_doses`
 *
 * Pedido e entrega são a MESMA dose, não duas. Se cada linha do CSV virasse um
 * registro, um bolus com wizard geraria 2 linhas com o mesmo `bolus_number` em
 * timestamps diferentes — e qualquer contagem de "quantos bolus você tomou"
 * daria o dobro.
 *
 * ## Qual timestamp manda
 *
 * `$recordedAtLocal` é o do **pedido**, não o da entrega. Duas razões:
 *
 *   1. É o instante em que a pessoa agiu — e é o instante da refeição, o que
 *      permite ligar dose ↔ refeição por igualdade exata de timestamp.
 *   2. Análise de resposta glicêmica pós-refeição se ancora em quando se
 *      começou a comer, não em quando a bomba terminou de entregar.
 *
 * O instante da entrega fica em `$deliveredAtLocal` (~5 min depois), preservado
 * para quem precisar calcular insulina ativa.
 */
final readonly class LinkedDose
{
    public function __construct(
        public DateTimeImmutable $recordedAtLocal,
        public string $kind,
        public ?string $rawSource,
        public bool $isAutomatic,
        public ?float $unitsSelected,
        public ?float $unitsDelivered,
        public ?int $bolusNumber,
        public ?string $cancellationReason,
        public ?DateTimeImmutable $deliveredAtLocal,
        public ?MealEvent $meal,
        public int $sourceLine,
    ) {}

    public function isCancelled(): bool
    {
        return $this->cancellationReason !== null;
    }

    /**
     * Entrega parcial: pediu X, entregou menos — e não foi cancelado.
     *
     * Distinto de cancelado, que não tem volume nenhum.
     */
    public function isPartial(): bool
    {
        return $this->unitsSelected !== null
            && $this->unitsDelivered !== null
            && abs($this->unitsSelected - $this->unitsDelivered) > 0.0005;
    }

    public function hasMeal(): bool
    {
        return $this->meal !== null;
    }
}

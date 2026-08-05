<?php

declare(strict_types=1);

namespace App\Domain\Patterns\Value;

use DateTimeImmutable;

/**
 * Uma refeição, reduzida ao que as regras precisam ver.
 *
 * ⚠️ Não é o model `Meal`. O dataset é a resposta à pergunta "o que o motor de
 * padrões pode ver?" (§D2) — e Eloquent dentro dele daria a resposta errada de
 * duas formas: as regras deixariam de ser testáveis com arrays, e uma regra
 * poderia navegar relação e ir ao banco por trás.
 *
 * `$carbRatio` é a razão de carboidrato **vigente no momento do bolus**, lida da
 * linha BWZ. É o que permite a R6 reconstruir o perfil do aparelho por período
 * sem ter o relatório de definições.
 */
final readonly class MealPoint
{
    public function __construct(
        public DateTimeImmutable $at,
        public float $carbsG,
        public ?float $carbRatio = null,
    ) {}

    public function hour(): int
    {
        return (int) $this->at->format('G');
    }

    public function localDate(): string
    {
        return $this->at->format('Y-m-d');
    }
}

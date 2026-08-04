<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Value;

/**
 * Tipo de excursão glicêmica.
 *
 * A DIREÇÃO faz parte do tipo, e não é detalhe: hipoglicemia é `< limiar` e
 * hiperglicemia é `> limiar`. Um detector que tratasse os dois com o mesmo
 * comparador acharia 100% do período em "episódio" de um dos lados.
 */
enum EpisodeType: string
{
    case Hypoglycemia = 'hypoglycemia';
    case HyperglycemiaLevel2 = 'hyperglycemia_level2';

    /** A leitura está fora da faixa para este tipo? */
    public function isExcursion(int $mgdl, int $threshold): bool
    {
        return match ($this) {
            self::Hypoglycemia => $mgdl < $threshold,
            self::HyperglycemiaLevel2 => $mgdl > $threshold,
        };
    }

    /**
     * O valor mais extremo entre dois — nadir na hipo, pico na hiper.
     *
     * Ter isto no enum evita um `if` de direção espalhado pelo detector, que é
     * onde esse tipo de inversão se esconde.
     */
    public function moreExtreme(int $a, int $b): int
    {
        return match ($this) {
            self::Hypoglycemia => min($a, $b),
            self::HyperglycemiaLevel2 => max($a, $b),
        };
    }

    public function configKey(): string
    {
        return $this->value;
    }
}

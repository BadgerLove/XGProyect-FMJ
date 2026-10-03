<?php

declare(strict_types=1);

namespace App\Services\Game\Formulas;

/**
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 */
class ProductionService
{
    public function maxStorable(int $storageLevel): int
    {
        // OGame: 5000 * floor(2.5 * e^(20 * level / 33)) -> 10,000 at level 0 (the floor was missing until 3 Oct 2026)
        return 5000 * (int) floor(2.5 * exp(20 * $storageLevel / 33));
    }

    public function maxProductionPercentage(int $maxEnergy, int $energyUsed): int
    {
        if ($maxEnergy == 0 && $energyUsed > 0) {
            return 0;
        }

        if ($maxEnergy > 0 && ($energyUsed + $maxEnergy) < 0) {
            $percentage = (int) floor($maxEnergy / ($energyUsed * -1) * 100);

            return min($percentage, 100);
        }

        return 100;
    }

    public function productionAmount(float $production, float $boost, float $multiplier = 0.0, bool $isEnergy = false): float
    {
        if ($isEnergy) {
            return ceil($production * $boost);
        }

        return floor($production * $multiplier * $boost);
    }

    public function currentProduction(float $resource, int $maxProductionPercentage): float
    {
        return $resource * 0.01 * $maxProductionPercentage;
    }
}

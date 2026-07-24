<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Xgp\App\Core\Enumerators\BuildingsEnumerator as Buildings;

/**
 * Days of Investment Return (DOIR) calculator for buildings and research.
 *
 * Ported from TBot's CalculationService.cs concept — picks whatever
 * pays back the fastest instead of following a fixed build order.
 *
 * DOIR = total_cost / (production_increase_per_day)
 * Lower DOIR = better investment.
 *
 * For non-production items (drives, combat tech), we assign strategic
 * value based on what they unlock.
 */
class ROICalculator
{
    /**
     * Server resource multiplier (from options table).
     */
    private const SERVER_SPEED = 5;

    /**
     * Average planet temperature (used for deuterium production calc).
     * Most planets sit around 40°C average.
     */
    private const DEFAULT_TEMP = 40;

    /**
     * Building base costs [metal, crystal, deuterium, factor].
     * Copied from BotBrain for consistency.
     */
    private const BUILDING_COSTS = [
        Buildings::BUILDING_METAL_MINE       => ['metal' => 60,    'crystal' => 15,   'deuterium' => 0,     'factor' => 1.5],
        Buildings::BUILDING_CRYSTAL_MINE      => ['metal' => 48,    'crystal' => 24,   'deuterium' => 0,     'factor' => 1.6],
        Buildings::BUILDING_DEUTERIUM_SINTETIZER => ['metal' => 225,   'crystal' => 75,   'deuterium' => 0,     'factor' => 1.5],
        Buildings::BUILDING_SOLAR_PLANT       => ['metal' => 75,    'crystal' => 30,   'deuterium' => 0,     'factor' => 1.5],
        Buildings::BUILDING_FUSION_REACTOR    => ['metal' => 900,   'crystal' => 360,  'deuterium' => 180,   'factor' => 1.8],
        Buildings::BUILDING_ROBOT_FACTORY     => ['metal' => 400,   'crystal' => 120,  'deuterium' => 250,   'factor' => 2.0],
        Buildings::BUILDING_NANO_FACTORY      => ['metal' => 1000000, 'crystal' => 500000, 'deuterium' => 100000, 'factor' => 2.0],
        Buildings::BUILDING_HANGAR            => ['metal' => 400,   'crystal' => 200,  'deuterium' => 100,   'factor' => 2.0],
        Buildings::BUILDING_METAL_STORE       => ['metal' => 2000,  'crystal' => 0,    'deuterium' => 0,     'factor' => 2.0],
        Buildings::BUILDING_CRYSTAL_STORE     => ['metal' => 2000,  'crystal' => 1000, 'deuterium' => 0,     'factor' => 2.0],
        Buildings::BUILDING_DEUTERIUM_TANK    => ['metal' => 2000,  'crystal' => 2000, 'deuterium' => 0,     'factor' => 2.0],
        Buildings::BUILDING_LABORATORY        => ['metal' => 200,   'crystal' => 400,  'deuterium' => 200,   'factor' => 2.0],
    ];

    /**
     * Research base costs [metal, crystal, deuterium, factor].
     */
    private const RESEARCH_COSTS = [
        106 => ['metal' => 200,   'crystal' => 1000,  'deuterium' => 200,   'factor' => 2.0],  // Espionage
        108 => ['metal' => 0,     'crystal' => 400,   'deuterium' => 600,   'factor' => 2.0],  // Computer
        109 => ['metal' => 800,   'crystal' => 200,   'deuterium' => 0,     'factor' => 2.0],  // Weapons
        110 => ['metal' => 200,   'crystal' => 600,   'deuterium' => 0,     'factor' => 2.0],  // Shielding
        111 => ['metal' => 1000,  'crystal' => 0,     'deuterium' => 0,     'factor' => 2.0],  // Armour
        113 => ['metal' => 0,     'crystal' => 800,   'deuterium' => 400,   'factor' => 2.0],  // Energy
        114 => ['metal' => 0,     'crystal' => 4000,  'deuterium' => 2000,  'factor' => 2.0],  // Hyperspace Tech
        115 => ['metal' => 400,   'crystal' => 0,     'deuterium' => 600,   'factor' => 2.0],  // Combustion
        117 => ['metal' => 2000,  'crystal' => 4000,  'deuterium' => 600,   'factor' => 2.0],  // Impulse
        118 => ['metal' => 10000, 'crystal' => 20000, 'deuterium' => 6000,  'factor' => 2.0],  // Hyperspace Drive
        120 => ['metal' => 200,   'crystal' => 100,   'deuterium' => 0,     'factor' => 2.0],  // Laser
        121 => ['metal' => 1000,  'crystal' => 300,   'deuterium' => 100,   'factor' => 2.0],  // Ionic
        122 => ['metal' => 2000,  'crystal' => 4000,  'deuterium' => 1000,  'factor' => 2.0],  // Plasma
        124 => ['metal' => 4000,  'crystal' => 8000,  'deuterium' => 4000,  'factor' => 1.75], // Astrophysics
        199 => ['metal' => 0,     'crystal' => 0,     'deuterium' => 0,     'factor' => 2.0],  // Graviton (energy only)
    ];

    // ─── Building DOIR ───────────────────────────────────────────────

    /**
     * Calculate Days of Investment Return for a building upgrade.
     *
     * Returns the number of days for the upgrade to pay for itself
     * through increased production. Lower = better investment.
     *
     * For non-production buildings (hangar, lab, robot factory), returns
     * a strategic value estimate.
     *
     * @param  array<string, mixed>  $planet  Planet data array
     * @param  int                   $buildingId  Building constant
     * @param  int                   $currentLevel  Current building level
     * @return float  Days to pay back (PHP_INT_MAX if not worth it)
     */
    public static function calcBuildingDOIR(array $planet, int $buildingId, int $currentLevel): float
    {
        $cost = self::getBuildingCost($buildingId, $currentLevel);
        $totalCost = $cost['metal'] + $cost['crystal'] + $cost['deuterium'];

        if ($totalCost <= 0) {
            return PHP_INT_MAX;
        }

        // Calculate production increase per day
        $dailyIncrease = self::calcBuildingDailyIncrease($planet, $buildingId, $currentLevel);

        if ($dailyIncrease <= 0) {
            // Non-production building — use strategic value
            return self::calcStrategicDOIR($buildingId, $currentLevel, $totalCost);
        }

        return $totalCost / $dailyIncrease;
    }

    /**
     * Calculate the daily resource increase from upgrading a building.
     *
     * Returns increase in resource units per day (metal, crystal, or deut).
     * For energy buildings, returns the energy increase (which gates other production).
     */
    private static function calcBuildingDailyIncrease(array $planet, int $buildingId, int $currentLevel): float
    {
        $oldProd = self::calcBuildingHourlyProduction($planet, $buildingId);
        // Temporarily increment level for new production calc
        $planetCopy = $planet;
        $col = self::getBuildingColumn($buildingId);
        $planetCopy[$col] = ($planet[$col] ?? 0) + 1;
        $newProd = self::calcBuildingHourlyProduction($planetCopy, $buildingId);

        return ($newProd - $oldProd) * 24;
    }

    /**
     * Calculate hourly resource production for a building at its current level.
     */
    public static function calcBuildingHourlyProduction(array $planet, int $buildingId): float
    {
        $level = (int) ($planet[self::getBuildingColumn($buildingId)] ?? 0);

        return match ($buildingId) {
            Buildings::BUILDING_METAL_MINE => self::calcMetalProduction($level, $planet),
            Buildings::BUILDING_CRYSTAL_MINE => self::calcCrystalProduction($level, $planet),
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => self::calcDeuteriumProduction($level, $planet),
            Buildings::BUILDING_SOLAR_PLANT => self::calcSolarProduction($level),
            Buildings::BUILDING_FUSION_REACTOR => self::calcFusionProduction($level, $planet),
            default => 0,
        };
    }

    /**
     * Map building ID to planet database column.
     */
    private static function getBuildingColumn(int $buildingId): string
    {
        $columns = [
            Buildings::BUILDING_METAL_MINE        => 'building_metal_mine',
            Buildings::BUILDING_CRYSTAL_MINE       => 'building_crystal_mine',
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => 'building_deuterium_sintetizer',
            Buildings::BUILDING_SOLAR_PLANT        => 'building_solar_plant',
            Buildings::BUILDING_FUSION_REACTOR     => 'building_fusion_reactor',
            Buildings::BUILDING_ROBOT_FACTORY      => 'building_robot_factory',
            Buildings::BUILDING_NANO_FACTORY       => 'building_nano_factory',
            Buildings::BUILDING_HANGAR            => 'building_hangar',
            Buildings::BUILDING_METAL_STORE        => 'building_metal_store',
            Buildings::BUILDING_CRYSTAL_STORE      => 'building_crystal_store',
            Buildings::BUILDING_DEUTERIUM_TANK     => 'building_deuterium_tank',
            Buildings::BUILDING_LABORATORY        => 'building_laboratory',
        ];

        return $columns[$buildingId] ?? 'building_metal_mine';
    }

    // ─── Production Formulas ─────────────────────────────────────────

    /**
     * Metal mine hourly production.
     * Formula: 30 * level * 1.1^level * speed * position_bonus
     */
    private static function calcMetalProduction(int $level, array $planet): float
    {
        if ($level <= 0) return 0;

        $position = (int) ($planet['planet_planet'] ?? 5);
        $positionBonus = self::getPositionMetalBonus($position);
        $baseProd = 30 * $positionBonus;

        return $baseProd * $level * pow(1.1, $level) * self::SERVER_SPEED;
    }

    /**
     * Crystal mine hourly production.
     * Formula: 20 * level * 1.1^level * speed * position_bonus
     */
    private static function calcCrystalProduction(int $level, array $planet): float
    {
        if ($level <= 0) return 0;

        $position = (int) ($planet['planet_planet'] ?? 5);
        $positionBonus = self::getPositionCrystalBonus($position);
        $baseProd = 20 * $positionBonus;

        return $baseProd * $level * pow(1.1, $level) * self::SERVER_SPEED;
    }

    /**
     * Deuterium synthesizer hourly production.
     * Formula: 10 * level * 1.1^level * ((-0.004 * temp) + 1.36) * speed
     */
    private static function calcDeuteriumProduction(int $level, array $planet): float
    {
        if ($level <= 0) return 0;

        $temp = self::DEFAULT_TEMP; // Could use planet temp if available
        $tempFactor = (-0.004 * $temp) + 1.36;

        return 10 * $level * pow(1.1, $level) * $tempFactor * self::SERVER_SPEED;
    }

    /**
     * Solar plant hourly energy production.
     * Formula: 20 * level * 1.1^level
     */
    private static function calcSolarProduction(int $level): float
    {
        if ($level <= 0) return 0;
        return 20 * $level * pow(1.1, $level);
    }

    /**
     * Fusion reactor hourly energy production.
     * Formula: 30 * level * (1.05 + 0.01 * energy_tech)^level
     */
    private static function calcFusionProduction(int $level, array $planet): float
    {
        if ($level <= 0) return 0;

        $energyTech = (int) ($planet['research_energy_technology'] ?? 0);
        return 30 * $level * pow(1.05 + 0.01 * $energyTech, $level);
    }

    /**
     * Position-based metal production bonus (slot 6-8 are best).
     */
    private static function getPositionMetalBonus(int $position): float
    {
        return match ($position) {
            6, 10 => 1.17,
            7, 9  => 1.23,
            8     => 1.35,
            default => 1.0,
        };
    }

    /**
     * Position-based crystal production bonus (slot 1-3 are best).
     */
    private static function getPositionCrystalBonus(int $position): float
    {
        return match ($position) {
            1 => 1.3,
            2 => 1.2,
            3 => 1.1,
            default => 1.0,
        };
    }

    // ─── Strategic DOIR (non-production buildings) ───────────────────

    /**
     * Assign strategic DOIR for buildings that don't directly produce resources.
     *
     * These are harder to quantify — we estimate based on what they enable.
     * Lower = higher priority.
     */
    private static function calcStrategicDOIR(int $buildingId, int $currentLevel, float $totalCost): float
    {
        // Costs scale exponentially, so we normalize by base cost
        $baseCostFactor = max(1, $totalCost / 1000);

        return match ($buildingId) {
            // Research Lab: enables research which has massive long-term value
            // Priority scales with level (early labs are cheap and high-value)
            Buildings::BUILDING_LABORATORY => match (true) {
                $currentLevel < 3  => 0.5,   // Lab 1-3: CRITICAL (unlocks Astrophysics)
                $currentLevel < 6  => 2.0,   // Lab 4-6: important (more research)
                $currentLevel < 10 => 5.0,   // Lab 7-10: nice to have
                default            => 15.0,  // Lab 11-12: endgame (Graviton)
            },

            // Hangar: enables ship production
            Buildings::BUILDING_HANGAR => match (true) {
                $currentLevel < 2  => 1.0,   // Hangar 1-2: critical for ships
                $currentLevel < 4  => 2.0,   // Hangar 3-4: Large Cargo, Colony Ship
                $currentLevel < 7  => 4.0,   // Hangar 5-6: Cruiser
                $currentLevel < 10 => 6.0,   // Hangar 7-9: Battleship
                default            => 10.0,  // Hangar 10-12: endgame
            },

            // Robot Factory: reduces build time
            Buildings::BUILDING_ROBOT_FACTORY => match (true) {
                $currentLevel < 2  => 2.0,   // RF 1-2: early speed boost
                $currentLevel < 5  => 4.0,   // RF 3-5: solid speed
                $currentLevel < 8  => 8.0,   // RF 6-8: diminishing returns
                default            => 20.0,  // RF 9-10: endgame
            },

            // Nanite Factory: massive build time reduction
            Buildings::BUILDING_NANO_FACTORY => 3.0, // Always high priority (halves build time per level)

            // Storage: only build when needed (handled by storage check in BotBrain)
            Buildings::BUILDING_METAL_STORE,
            Buildings::BUILDING_CRYSTAL_STORE,
            Buildings::BUILDING_DEUTERIUM_TANK => 50.0, // Very low priority — only when full

            // Default: scale by cost
            default => $baseCostFactor * 10.0,
        };
    }

    // ─── Research DOIR ───────────────────────────────────────────────

    /**
     * Calculate DOIR for a research upgrade.
     *
     * For production research (Plasma, Energy), calculates actual production increase.
     * For strategic research (drives, combat, Astrophysics), assigns strategic value.
     *
     * @param  array<string, mixed>  $user    User data with research levels
     * @param  array<string, mixed>  $planet  Planet data (for production calcs)
     * @param  int                   $researchId  Research ID
     * @return float  Days to pay back
     */
    public static function calcResearchDOIR(array $user, array $planet, int $researchId): float
    {
        $currentLevel = (int) ($user['research_' . self::getResearchColumn($researchId)] ?? 0);
        $cost = self::getResearchCost($researchId, $currentLevel);

        if ($cost === null) {
            return PHP_INT_MAX;
        }

        $totalCost = $cost['metal'] + $cost['crystal'] + $cost['deuterium'];

        if ($totalCost <= 0) {
            return PHP_INT_MAX;
        }

        $dailyIncrease = self::calcResearchDailyIncrease($user, $planet, $researchId, $currentLevel);

        if ($dailyIncrease <= 0) {
            return self::calcResearchStrategicDOIR($researchId, $currentLevel, $totalCost, $user);
        }

        return $totalCost / $dailyIncrease;
    }

    /**
     * Calculate daily resource increase from a research upgrade.
     */
    private static function calcResearchDailyIncrease(array $user, array $planet, int $researchId, int $currentLevel): float
    {
        $newLevel = $currentLevel + 1;

        switch ($researchId) {
            case 122: // Plasma Tech: +1% metal, +0.66% crystal, +0.33% deut per level
                $metalBonus = 0.01;
                $crystalBonus = 0.0066;
                $deutBonus = 0.0033;

                $metalProd = self::calcMetalProduction((int) ($planet['building_metal_mine'] ?? 0), $planet);
                $crystalProd = self::calcCrystalProduction((int) ($planet['building_crystal_mine'] ?? 0), $planet);
                $deutProd = self::calcDeuteriumProduction((int) ($planet['building_deuterium_sintetizer'] ?? 0), $planet);

                return ($metalProd * $metalBonus + $crystalProd * $crystalBonus + $deutProd * $deutBonus) * 24;

            case 113: // Energy Tech: enables higher mine levels (energy gating)
                // Hard to quantify — return energy increase proxy
                return 100 * 24; // Placeholder: energy tech is always useful

            default:
                return 0;
        }
    }

    /**
     * Strategic DOIR for non-production research.
     */
    private static function calcResearchStrategicDOIR(int $researchId, int $currentLevel, float $totalCost, array $user): float
    {
        $baseCostFactor = max(1, $totalCost / 5000);

        return match ($researchId) {
            // Astrophysics: enables colonies (massive long-term value)
            // Each level = 2 colony slots on odd levels
            124 => match (true) {
                $currentLevel < 1  => 0.3,  // Astro 1: CRITICAL — first colony
                $currentLevel < 3  => 0.5,  // Astro 2-3: 3-4 colonies
                $currentLevel < 5  => 1.0,  // Astro 4-5: 5-6 colonies
                $currentLevel < 7  => 2.0,  // Astro 6-7: 7-8 colonies
                default            => 5.0,  // Astro 8+: more colonies
            },

            // Espionage: unlocks spying + required for Astrophysics
            106 => match (true) {
                $currentLevel < 4  => 0.5,  // Esp 1-4: critical path to Astro
                $currentLevel < 8  => 3.0,  // Esp 5-8: better spying
                default            => 10.0,
            },

            // Impulse Drive: unlocks ships + required for Astrophysics
            117 => match (true) {
                $currentLevel < 3  => 0.5,  // Imp 1-3: critical path to Astro
                $currentLevel < 4  => 1.0,  // Imp 4: Cruiser
                $currentLevel < 8  => 3.0,  // Imp 5-8: speed upgrades
                default            => 8.0,
            },

            // Combustion Drive: unlocks Small/Large Cargo
            115 => match (true) {
                $currentLevel < 2  => 0.5,  // Comb 1-2: Small Cargo
                $currentLevel < 6  => 1.0,  // Comb 3-6: Large Cargo
                $currentLevel < 10 => 3.0,  // Comb 7-10: speed
                default            => 8.0,
            },

            // Energy Tech: enables everything
            113 => match (true) {
                $currentLevel < 4  => 0.3,  // En 1-4: foundation
                $currentLevel < 8  => 1.5,  // En 5-8: Hyperspace path
                $currentLevel < 12 => 4.0,  // En 9-12: Plasma path
                default            => 10.0,
            },

            // Laser Tech: required for Ionic
            120 => match (true) {
                $currentLevel < 5  => 1.0,  // Las 1-5: Ionic prereq
                $currentLevel < 10 => 3.0,  // Las 6-10: Plasma prereq
                default            => 8.0,
            },

            // Ionic Tech: required for Plasma
            121 => match (true) {
                $currentLevel < 5  => 2.0,  // Ionic 1-5: Plasma prereq
                default            => 8.0,
            },

            // Armour Tech: combat effectiveness
            111 => match (true) {
                $currentLevel < 5  => 1.5,  // Armour 1-5: basic defense
                $currentLevel < 10 => 4.0,  // Armour 6-10: solid
                default            => 10.0,
            },

            // Weapons Tech: combat effectiveness
            109 => match (true) {
                $currentLevel < 5  => 1.5,  // Weapons 1-5: basic attack
                $currentLevel < 10 => 4.0,  // Weapons 6-10: solid
                default            => 10.0,
            },

            // Shielding Tech: combat + Hyperspace prereq
            110 => match (true) {
                $currentLevel < 5  => 2.0,  // Shield 1-5: basic
                $currentLevel < 6  => 1.0,  // Shield 6: Hyperspace Tech prereq!
                default            => 6.0,
            },

            // Hyperspace Tech: unlocks Hyperspace Drive + Battleship path
            114 => match (true) {
                $currentLevel < 3  => 2.0,  // HS Tech 1-3: HS Drive prereq
                default            => 6.0,
            },

            // Hyperspace Drive: unlocks Battleship, fastest ships
            118 => match (true) {
                $currentLevel < 4  => 3.0,  // HS Drive 1-4: Battleship
                $currentLevel < 8  => 5.0,  // HS Drive 5-8: speed
                default            => 10.0,
            },

            // Computer Tech: fleet slots
            108 => match (true) {
                $currentLevel < 3  => 2.0,  // Comp 1-3: fleet slots
                default            => 8.0,
            },

            // Graviton: endgame (Death Star) — costs energy only
            199 => 25.0,

            // Default: scale by cost
            default => $baseCostFactor * 10.0,
        };
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Get building cost at a given level.
     *
     * @return array{metal: float, crystal: float, deuterium: float}
     */
    public static function getBuildingCost(int $buildingId, int $currentLevel): array
    {
        $base = self::BUILDING_COSTS[$buildingId] ?? ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'factor' => 2.0];
        $factor = $base['factor'];

        return [
            'metal'     => round($base['metal'] * pow($factor, $currentLevel)),
            'crystal'   => round($base['crystal'] * pow($factor, $currentLevel)),
            'deuterium' => round($base['deuterium'] * pow($factor, $currentLevel)),
        ];
    }

    /**
     * Get research cost at a given level.
     *
     * @return array{metal: float, crystal: float, deuterium: float}|null
     */
    public static function getResearchCost(int $researchId, int $level): ?array
    {
        $base = self::RESEARCH_COSTS[$researchId] ?? null;

        if ($base === null) {
            return null;
        }

        $factor = $base['factor'];

        return [
            'metal'     => round($base['metal'] * pow($factor, $level)),
            'crystal'   => round($base['crystal'] * pow($factor, $level)),
            'deuterium' => round($base['deuterium'] * pow($factor, $level)),
        ];
    }

    /**
     * Map research ID to database column suffix.
     */
    private static function getResearchColumn(int $researchId): string
    {
        $columns = [
            106 => 'espionage_technology',
            108 => 'computer_technology',
            109 => 'weapons_technology',
            110 => 'shielding_technology',
            111 => 'armour_technology',
            113 => 'energy_technology',
            114 => 'hyperspace_technology',
            115 => 'combustion_drive',
            117 => 'impulse_drive',
            118 => 'hyperspace_drive',
            120 => 'laser_technology',
            121 => 'ionic_technology',
            122 => 'plasma_technology',
            123 => 'intergalactic_research_network',
            124 => 'astrophysics',
            199 => 'graviton_technology',
        ];

        return $columns[$researchId] ?? 'energy_technology';
    }

    /**
     * Get total cost (metal + crystal + deut) for a building upgrade.
     */
    public static function getBuildingTotalCost(int $buildingId, int $currentLevel): float
    {
        $cost = self::getBuildingCost($buildingId, $currentLevel);
        return $cost['metal'] + $cost['crystal'] + $cost['deuterium'];
    }

    /**
     * Get total cost for a research upgrade.
     */
    public static function getResearchTotalCost(int $researchId, int $currentLevel): float
    {
        $cost = self::getResearchCost($researchId, $currentLevel);
        if ($cost === null) return 0;
        return $cost['metal'] + $cost['crystal'] + $cost['deuterium'];
    }
}

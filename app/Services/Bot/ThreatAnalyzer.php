<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\DB;

/**
 * Analyzes neighborhood threats and recommends counter-units.
 *
 * Scans nearby planets to see what ships/defenses they have,
 * then recommends the best counter-builds for the bot.
 */
class ThreatAnalyzer
{
    /**
     * Analyze the neighborhood and recommend counter-units.
     *
     * @param  array<string, mixed>  $botPlanet
     * @return array{ship_priority: array<int, int>, defense_priority: array<int, int>, threat_level: string}
     */
    public function analyzeThreats(array $botPlanet): array
    {
        $prefix = DB::getTablePrefix();
        $botGalaxy = (int) $botPlanet['planet_galaxy'];
        $botSystem = (int) $botPlanet['planet_system'];
        $botUserId = (int) $botPlanet['planet_user_id'];

        $systemMin = max(1, $botSystem - 10);
        $systemMax = $botSystem + 10;

        // Get aggregate ship/defense counts in the neighborhood
        $rows = DB::select(
            "SELECT
                SUM(s.`ship_small_cargo_ship`) AS total_small_cargo,
                SUM(s.`ship_big_cargo_ship`) AS total_big_cargo,
                SUM(s.`ship_light_fighter`) AS total_light_fighter,
                SUM(s.`ship_heavy_fighter`) AS total_heavy_fighter,
                SUM(s.`ship_cruiser`) AS total_cruiser,
                SUM(s.`ship_battleship`) AS total_battleship,
                SUM(s.`ship_destroyer`) AS total_destroyer,
                SUM(s.`ship_deathstar`) AS total_deathstar,
                SUM(d.`defense_rocket_launcher`) AS total_rocket_launcher,
                SUM(d.`defense_light_laser`) AS total_light_laser,
                SUM(d.`defense_heavy_laser`) AS total_heavy_laser,
                SUM(d.`defense_gauss_cannon`) AS total_gauss_cannon,
                SUM(d.`defense_ion_cannon`) AS total_ion_cannon,
                SUM(d.`defense_plasma_turret`) AS total_plasma_turret,
                COUNT(*) AS neighbor_count
            FROM `{$prefix}planets` AS p
            INNER JOIN `{$prefix}ships` AS s ON s.`ship_planet_id` = p.`planet_id`
            INNER JOIN `{$prefix}defenses` AS d ON d.`defense_planet_id` = p.`planet_id`
            WHERE p.`planet_galaxy` = ?
                AND p.`planet_system` BETWEEN ? AND ?
                AND p.`planet_type` = 1
                AND p.`planet_destroyed` = 0
                AND p.`planet_user_id` != ?",
            [$botGalaxy, $systemMin, $systemMax, $botUserId]
        );

        $neighborhood = (array) ($rows[0] ?? []);

        // Calculate threat composition
        $totalShips = (int) ($neighborhood['total_light_fighter'] ?? 0)
            + (int) ($neighborhood['total_heavy_fighter'] ?? 0)
            + (int) ($neighborhood['total_cruiser'] ?? 0)
            + (int) ($neighborhood['total_battleship'] ?? 0)
            + (int) ($neighborhood['total_destroyer'] ?? 0)
            + (int) ($neighborhood['total_deathstar'] ?? 0);

        $totalDefenses = (int) ($neighborhood['total_rocket_launcher'] ?? 0)
            + (int) ($neighborhood['total_light_laser'] ?? 0)
            + (int) ($neighborhood['total_heavy_laser'] ?? 0)
            + (int) ($neighborhood['total_gauss_cannon'] ?? 0)
            + (int) ($neighborhood['total_ion_cannon'] ?? 0)
            + (int) ($neighborhood['total_plasma_turret'] ?? 0);

        // Determine dominant threat type
        $dominantShip = $this->getDominantUnit([
            'light_fighter'  => (int) ($neighborhood['total_light_fighter'] ?? 0),
            'heavy_fighter'  => (int) ($neighborhood['total_heavy_fighter'] ?? 0),
            'cruiser'        => (int) ($neighborhood['total_cruiser'] ?? 0),
            'battleship'     => (int) ($neighborhood['total_battleship'] ?? 0),
            'destroyer'      => (int) ($neighborhood['total_destroyer'] ?? 0),
        ]);

        $dominantDefense = $this->getDominantUnit([
            'rocket_launcher' => (int) ($neighborhood['total_rocket_launcher'] ?? 0),
            'light_laser'     => (int) ($neighborhood['total_light_laser'] ?? 0),
            'heavy_laser'     => (int) ($neighborhood['total_heavy_laser'] ?? 0),
            'gauss_cannon'    => (int) ($neighborhood['total_gauss_cannon'] ?? 0),
            'plasma_turret'   => (int) ($neighborhood['total_plasma_turret'] ?? 0),
        ]);

        // Determine threat level
        $threatLevel = 'low';

        if ($totalShips > 100 || $totalDefenses > 500) {
            $threatLevel = 'medium';
        }

        if ($totalShips > 500 || $totalDefenses > 2000) {
            $threatLevel = 'high';
        }

        // Recommend counter-units
        $shipPriority = $this->getCounterShips($dominantShip, $threatLevel);
        $defensePriority = $this->getCounterDefenses($dominantShip, $threatLevel);

        return [
            'ship_priority'    => $shipPriority,
            'defense_priority' => $defensePriority,
            'threat_level'     => $threatLevel,
        ];
    }

    /**
     * Get the dominant unit type from a composition.
     *
     * @param  array<string, int>  $composition
     */
    private function getDominantUnit(array $composition): string
    {
        $dominant = 'none';
        $maxCount = 0;

        foreach ($composition as $type => $count) {
            if ($count > $maxCount) {
                $maxCount = $count;
                $dominant = $type;
            }
        }

        return $dominant;
    }

    /**
     * Recommend counter-ships based on the dominant enemy ship type.
     *
     * OGame counter logic:
     * - Light Fighter → Cruiser (rapid fire 6x) or Heavy Fighter
     * - Heavy Fighter → Cruiser or Battleship
     * - Cruiser → Battleship or Destroyer
     * - Battleship → Destroyer or Deathstar
     * - Destroyer → Deathstar or Reaper
     *
     * @return array<int, int>  Ship ID => priority (lower = higher priority)
     */
    private function getCounterShips(string $dominantShip, string $threatLevel): array
    {
        $counters = match ($dominantShip) {
            'light_fighter' => [
                206 => 1,  // Cruiser (rapid fire 6x vs light fighters)
                205 => 2,  // Heavy Fighter
                204 => 3,  // Light Fighter (mirror)
                202 => 4,  // Small Cargo (for raiding)
            ],
            'heavy_fighter' => [
                206 => 1,  // Cruiser
                207 => 2,  // Battleship
                205 => 3,  // Heavy Fighter (mirror)
                204 => 4,  // Light Fighter
            ],
            'cruiser' => [
                207 => 1,  // Battleship
                213 => 2,  // Destroyer
                206 => 3,  // Cruiser (mirror)
                205 => 4,  // Heavy Fighter
            ],
            'battleship' => [
                213 => 1,  // Destroyer
                215 => 2,  // Reaper
                207 => 3,  // Battleship (mirror)
                206 => 4,  // Cruiser
            ],
            'destroyer' => [
                214 => 1,  // Deathstar
                215 => 2,  // Reaper
                213 => 3,  // Destroyer (mirror)
                207 => 4,  // Battleship
            ],
            default => [
                204 => 1,  // Light Fighter (default aggressive)
                202 => 2,  // Small Cargo
                205 => 3,  // Heavy Fighter
                206 => 4,  // Cruiser
            ],
        };

        // If threat is high, add more defensive ships
        if ($threatLevel === 'high') {
            $counters[207] = min($counters[207] ?? 5, 2); // Battleship higher priority
            $counters[213] = min($counters[213] ?? 6, 3); // Destroyer higher priority
        }

        asort($counters);

        return $counters;
    }

    /**
     * Recommend counter-defenses based on the dominant enemy ship type.
     *
     * @return array<int, int>  Defense ID => priority (lower = higher priority)
     */
    private function getCounterDefenses(string $dominantShip, string $threatLevel): array
    {
        $counters = match ($dominantShip) {
            'light_fighter' => [
                402 => 1,  // Light Laser (cheap, effective vs light ships)
                401 => 2,  // Rocket Launcher (cheapest)
                403 => 3,  // Heavy Laser
                502 => 4,  // Small Shield Dome
            ],
            'heavy_fighter' => [
                403 => 1,  // Heavy Laser
                404 => 2,  // Gauss Cannon
                402 => 3,  // Light Laser
                502 => 4,  // Small Shield Dome
            ],
            'cruiser' => [
                404 => 1,  // Gauss Cannon
                405 => 2,  // Ion Cannon
                403 => 3,  // Heavy Laser
                502 => 4,  // Small Shield Dome
            ],
            'battleship' => [
                406 => 1,  // Plasma Turret
                404 => 2,  // Gauss Cannon
                405 => 3,  // Ion Cannon
                503 => 4,  // Large Shield Dome
            ],
            'destroyer' => [
                406 => 1,  // Plasma Turret
                503 => 2,  // Large Shield Dome
                404 => 3,  // Gauss Cannon
                405 => 4,  // Ion Cannon
            ],
            default => [
                401 => 1,  // Rocket Launcher (cheapest)
                402 => 2,  // Light Laser
                403 => 3,  // Heavy Laser
                502 => 4,  // Small Shield Dome
            ],
        };

        asort($counters);

        return $counters;
    }
}

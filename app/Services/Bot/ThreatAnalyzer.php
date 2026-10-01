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
        $botGalaxy = (int) $botPlanet['planet_galaxy'];
        $botSystem = (int) $botPlanet['planet_system'];
        $botUserId = (int) $botPlanet['planet_user_id'];

        $systemMin = max(1, $botSystem - 10);
        $systemMax = $botSystem + 10;

        // Dale's rule (2026-10-01): bots only use what a player could see. This summed every
        // neighbour's LIVE ships and defences. Now: the bot's own newest unexpired spy report per
        // planet within +-10 systems, plus the fleets that actually attacked it in the last 7 days
        // (its own battle reports).
        $units = [];
        $seen = [];
        $reports = DB::table('bot_intel')
            ->where('bot_user_id', $botUserId)
            ->where('galaxy', $botGalaxy)
            ->whereBetween('system', [$systemMin, $systemMax])
            ->where('expires_at', '>', time())
            ->orderByDesc('scanned_at')
            ->get(['system', 'planet', 'fleet_data', 'defense_data']);
        foreach ($reports as $report) {
            $key = "{$report->system}:{$report->planet}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            foreach ((json_decode((string) $report->fleet_data, true) ?: []) + (json_decode((string) $report->defense_data, true) ?: []) as $id => $count) {
                $units[(int) $id] = ($units[(int) $id] ?? 0) + (int) $count;
            }
        }
        $attacks = DB::table('bot_combat_log')
            ->where('defender_id', $botUserId)
            ->where('created_at', '>', now()->subDays(7))
            ->pluck('attacker_fleet');
        foreach ($attacks as $json) {
            foreach ((json_decode((string) $json, true) ?: []) as $id => $count) {
                $units[(int) $id] = ($units[(int) $id] ?? 0) + (int) $count;
            }
        }

        $neighborhood = [
            'total_small_cargo' => $units[202] ?? 0, 'total_big_cargo' => $units[203] ?? 0,
            'total_light_fighter' => $units[204] ?? 0, 'total_heavy_fighter' => $units[205] ?? 0,
            'total_cruiser' => $units[206] ?? 0, 'total_battleship' => $units[207] ?? 0,
            'total_destroyer' => $units[213] ?? 0, 'total_deathstar' => $units[214] ?? 0,
            'total_rocket_launcher' => $units[401] ?? 0, 'total_light_laser' => $units[402] ?? 0,
            'total_heavy_laser' => $units[403] ?? 0, 'total_gauss_cannon' => $units[404] ?? 0,
            'total_ion_cannon' => $units[405] ?? 0, 'total_plasma_turret' => $units[406] ?? 0,
            'neighbor_count' => count($seen),
        ];

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

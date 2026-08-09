<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Xgp\App\Libraries\BattleEngine\Core\Battle;
use Xgp\App\Libraries\BattleEngine\Models\Fleet;
use Xgp\App\Libraries\BattleEngine\Models\Player;
use Xgp\App\Libraries\BattleEngine\Models\PlayerGroup;
use Xgp\App\Libraries\BattleEngine\Models\Defense;
use Xgp\App\Libraries\BattleEngine\Models\Ship;
use Xgp\App\Libraries\BattleEngine\Models\ShipType;

/**
 * Wraps the OPBE battle engine for bot decision-making.
 *
 * Simulates battles without affecting the real game to determine
 * if an attack is likely to succeed before committing ships.
 */
class BattleSimulator
{
    /**
     * Ship combat stats: [id => [shield, power, cost]]
     * Cost is used for hull calculation: hull = COST_TO_ARMOUR * sum(cost)
     */
    private const SHIP_STATS = [
        202 => ['shield' => 10,    'power' => 5,     'cost' => [2000, 2000, 0],   'storage' => 5000],
        203 => ['shield' => 25,    'power' => 5,     'cost' => [6000, 6000, 0],   'storage' => 25000],
        204 => ['shield' => 10,    'power' => 50,    'cost' => [3000, 1000, 0],   'storage' => 50],
        205 => ['shield' => 25,    'power' => 150,   'cost' => [6000, 4000, 0],   'storage' => 100],
        206 => ['shield' => 50,    'power' => 400,   'cost' => [20000, 7000, 2000], 'storage' => 800],
        207 => ['shield' => 200,   'power' => 1000,  'cost' => [45000, 15000, 0], 'storage' => 1500],
        208 => ['shield' => 100,   'power' => 50,    'cost' => [10000, 20000, 10000], 'storage' => 7500],
        209 => ['shield' => 10,    'power' => 1,     'cost' => [10000, 6000, 2000], 'storage' => 20000],
        210 => ['shield' => 0.01,  'power' => 0.01,  'cost' => [0, 1000, 0],      'storage' => 5],
        211 => ['shield' => 500,   'power' => 1000,  'cost' => [50000, 25000, 15000], 'storage' => 500],
        212 => ['shield' => 1,     'power' => 1,     'cost' => [0, 2000, 500],     'storage' => 0],
        213 => ['shield' => 500,   'power' => 2000,  'cost' => [60000, 50000, 15000], 'storage' => 2000],
        214 => ['shield' => 50000, 'power' => 200000, 'cost' => [5000000, 4000000, 1000000], 'storage' => 1000000],
        215 => ['shield' => 700,   'power' => 2800,  'cost' => [85000, 55000, 20000], 'storage' => 10000],
    ];

    /**
     * Defense combat stats: [id => [shield, power, cost]]
     */
    private const DEFENSE_STATS = [
        401 => ['shield' => 20,    'power' => 80,    'cost' => [2000, 0, 0]],
        402 => ['shield' => 25,    'power' => 100,   'cost' => [1500, 500, 0]],
        403 => ['shield' => 100,   'power' => 250,   'cost' => [6000, 2000, 0]],
        404 => ['shield' => 200,   'power' => 1100,  'cost' => [20000, 15000, 2000]],
        405 => ['shield' => 500,   'power' => 150,   'cost' => [2000, 6000, 0]],
        406 => ['shield' => 300,   'power' => 3000,  'cost' => [50000, 50000, 30000]],
        502 => ['shield' => 2000,  'power' => 1,     'cost' => [10000, 10000, 0]],
        503 => ['shield' => 10000, 'power' => 1,     'cost' => [50000, 50000, 0]],
    ];

    /**
     * Rapid fire values: [attacker_id => [defender_id => rapid_fire]]
     */
    private const RAPID_FIRE = [
        202 => [210 => 5, 212 => 5],
        203 => [210 => 5, 212 => 5],
        204 => [210 => 5, 212 => 5],
        205 => [202 => 3, 210 => 5, 212 => 5],
        206 => [204 => 6, 210 => 5, 212 => 5, 401 => 10],
        207 => [210 => 5, 212 => 5],
        208 => [210 => 5, 212 => 5],
        209 => [210 => 5, 212 => 5],
        211 => [210 => 5, 212 => 5, 401 => 20, 402 => 20, 403 => 10, 404 => 5, 405 => 10, 406 => 5],
        213 => [210 => 5, 212 => 5],
        214 => [202 => 250, 203 => 250, 204 => 200, 205 => 100, 206 => 33, 207 => 30, 208 => 250, 209 => 250, 210 => 1250, 211 => 25, 212 => 1250, 213 => 5, 215 => 10],
        215 => [210 => 5, 212 => 5, 204 => 3, 205 => 3],
    ];

    /**
     * Simulate a battle between an attacker fleet and a defender planet.
     *
     * @param  array<int, int>  $attackerShips  Ship ID => count
     * @param  array<string, mixed>  $defenderPlanet  Defender's planet (flat array with ships + defenses)
     * @param  array<string, mixed>  $attackerUser  Attacker's user (for tech levels)
     * @param  array<string, mixed>  $defenderUser  Defender's user (for tech levels)
     *
     * @return array{winner: string, attacker_losses: int, defender_losses: int, attacker_ships_remaining: int, defender_ships_remaining: int, loot_metal: int, loot_crystal: int, loot_deuterium: int}
     */
    public function simulate(array $attackerShips, array $defenderPlanet, array $attackerUser, array $defenderUser): array
    {
        // Build attacker fleet
        $attackerFleet = $this->buildFleet(1, $attackerShips);
        $attackerPlayer = new Player(
            id: 1,
            fleets: [$attackerFleet],
            weapons_tech: (int) ($attackerUser['research_weapons_technology'] ?? 0),
            shields_tech: (int) ($attackerUser['research_shielding_technology'] ?? 0),
            armour_tech: (int) ($attackerUser['research_armour_technology'] ?? 0),
        );

        // Build defender fleet (ships on planet)
        $defenderShips = $this->extractDefenderShips($defenderPlanet);
        $defenderFleet = $this->buildFleet(2, $defenderShips);

        // Add defenses to defender fleet
        $defenderDefenses = $this->extractDefenderDefenses($defenderPlanet);

        foreach ($defenderDefenses as $defenseId => $count) {
            if ($count > 0) {
                $stats = self::DEFENSE_STATS[$defenseId] ?? null;

                if ($stats) {
                    $shipType = new Defense(
                        id: $defenseId,
                        count: $count,
                        rf: [],
                        shield: $stats['shield'],
                        cost: $stats['cost'],
                        power: $stats['power'],
                    );
                    $defenderFleet->addShipType($shipType);
                }
            }
        }

        $defenderPlayer = new Player(
            id: 2,
            fleets: [$defenderFleet],
            weapons_tech: (int) ($defenderUser['research_weapons_technology'] ?? 0),
            shields_tech: (int) ($defenderUser['research_shielding_technology'] ?? 0),
            armour_tech: (int) ($defenderUser['research_armour_technology'] ?? 0),
        );

        // Run simulation
        $attackers = new PlayerGroup([$attackerPlayer]);
        $defenders = new PlayerGroup([$defenderPlayer]);

        $battle = new Battle($attackers, $defenders);
        ob_start();
        $battle->startBattle();
        ob_end_clean();

        $report = $battle->getReport();

        // Use report for accurate results
        $attackerInitial = array_sum($attackerShips);
        $defenderInitial = array_sum($defenderShips) + array_sum($defenderDefenses);

        $attackerLost = $report->getTotalAttackersLostUnits();
        $defenderLost = $report->getTotalDefendersLostUnits();

        $attackerRemaining = max(0, $attackerInitial - $attackerLost);
        $defenderRemaining = max(0, $defenderInitial - $defenderLost);

        // Determine winner from remaining units
        if ($attackerRemaining > 0 && $defenderRemaining == 0) {
            $winner = 'attacker';
        } elseif ($attackerRemaining == 0 && $defenderRemaining > 0) {
            $winner = 'defender';
        } else {
            $winner = 'draw';
        }

        // Loot from report
        $steal = $report->getSteal();
        $lootMetal = (int) ($steal['metal'] ?? 0);
        $lootCrystal = (int) ($steal['crystal'] ?? 0);
        $lootDeuterium = (int) ($steal['deuterium'] ?? 0);

        // Debris field
        $debris = $report->getDebris();
        $moonProb = $report->getMoonProb();

        return [
            'winner'                    => $winner,
            'attacker_losses'           => $attackerLost,
            'defender_losses'           => $defenderLost,
            'attacker_ships_remaining'  => $attackerRemaining,
            'defender_ships_remaining'  => $defenderRemaining,
            'loot_metal'                => $lootMetal,
            'loot_crystal'              => $lootCrystal,
            'loot_deuterium'            => $lootDeuterium,
            'debris_metal'              => (int) ($debris[0] ?? 0),
            'debris_crystal'            => (int) ($debris[1] ?? 0),
            'moon_chance'               => $moonProb,
            'rounds'                    => $report->getLastRoundNumber(),
        ];
    }

    /**
     * Quick check: would the attacker likely win?
     *
     * Faster than full simulation — just compares total power.
     *
     * @param  array<int, int>  $attackerShips
     * @param  array<string, mixed>  $defenderPlanet
     */
    public function quickWinCheck(array $attackerShips, array $defenderPlanet): bool
    {
        $attackerPower = $this->calculateTotalPower($attackerShips);
        $defenderShips = $this->extractDefenderShips($defenderPlanet);
        $defenderDefenses = $this->extractDefenderDefenses($defenderPlanet);
        $defenderPower = $this->calculateTotalPower($defenderShips) + $this->calculateTotalPower($defenderDefenses);

        // Need 1.5x power to be confident
        return $attackerPower > ($defenderPower * 1.5);
    }

    /**
     * Build a Fleet object from a ship ID => count array.
     *
     * @param  int  $fleetId
     * @param  array<int, int>  $ships
     */
    private function buildFleet(int $fleetId, array $ships): Fleet
    {
        $fleet = new Fleet($fleetId);

        foreach ($ships as $shipId => $count) {
            if ($count <= 0) {
                continue;
            }

            $stats = self::SHIP_STATS[$shipId] ?? null;

            if ($stats === null) {
                continue;
            }

            $shipType = new Ship(
                id: $shipId,
                count: $count,
                rf: self::RAPID_FIRE[$shipId] ?? [],
                shield: $stats['shield'],
                cost: $stats['cost'],
                power: $stats['power'],
            );

            $fleet->addShipType($shipType);
        }

        return $fleet;
    }

    /**
     * Extract ship counts from a planet flat array.
     *
     * @param  array<string, mixed>  $planet
     * @return array<int, int>
     */
    private function extractDefenderShips(array $planet): array
    {
        $shipMap = [
            202 => 'ship_small_cargo_ship',
            203 => 'ship_big_cargo_ship',
            204 => 'ship_light_fighter',
            205 => 'ship_heavy_fighter',
            206 => 'ship_cruiser',
            207 => 'ship_battleship',
            208 => 'ship_colony_ship',
            209 => 'ship_recycler',
            210 => 'ship_espionage_probe',
            211 => 'ship_bomber',
            212 => 'ship_solar_satellite',
            213 => 'ship_destroyer',
            214 => 'ship_deathstar',
            215 => 'ship_reaper',
        ];

        $ships = [];

        foreach ($shipMap as $id => $column) {
            $count = (int) ($planet[$column] ?? 0);

            if ($count > 0) {
                $ships[$id] = $count;
            }
        }

        return $ships;
    }

    /**
     * Extract defense counts from a planet flat array.
     *
     * @param  array<string, mixed>  $planet
     * @return array<int, int>
     */
    private function extractDefenderDefenses(array $planet): array
    {
        $defenseMap = [
            401 => 'defense_rocket_launcher',
            402 => 'defense_light_laser',
            403 => 'defense_heavy_laser',
            404 => 'defense_gauss_cannon',
            405 => 'defense_ion_cannon',
            406 => 'defense_plasma_turret',
            502 => 'defense_small_shield_dome',
            503 => 'defense_large_shield_dome',
        ];

        $defenses = [];

        foreach ($defenseMap as $id => $column) {
            $count = (int) ($planet[$column] ?? 0);

            if ($count > 0) {
                $defenses[$id] = $count;
            }
        }

        return $defenses;
    }

    /**
     * Calculate total combat power of a fleet/defense.
     *
     * @param  array<int, int>  $units
     */
    private function calculateTotalPower(array $units): int
    {
        $total = 0;

        foreach ($units as $id => $count) {
            $stats = self::SHIP_STATS[$id] ?? self::DEFENSE_STATS[$id] ?? null;

            if ($stats) {
                $total += $stats['power'] * $count;
            }
        }

        return $total;
    }
}

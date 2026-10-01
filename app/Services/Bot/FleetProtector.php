<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Libraries\FleetsLib;

/**
 * Protects bot planets from incoming attacks by fleet saving.
 *
 * Scans the fleets table for incoming attacks, evaluates threat level,
 * and sends hold missions that move fleet + resources to safety and bring them back afterwards.
 */
class FleetProtector
{
    /** Assumed strength per ship when the bot's espionage is too low to see the make-up (~ a heavy fighter). */
    private const UNSEEN_SHIP_STRENGTH = 150;

    use PreparesLegacySql;

    public function __construct(
        private readonly FleetDispatcher $dispatcher,
    ) {
    }

    /**
     * Check for incoming attacks on a bot's planet.
     *
     * @param  array<string, mixed>  $botPlanet  Bot's planet (flat array)
     * @return list<array{fleet_id: int, fleet_array: string, fleet_amount: int, fleet_end_time: int, fleet_owner: int, strength: int}>
     */
    public function getIncomingAttacks(array $botPlanet): array
    {
        $prefix = DB::getTablePrefix();
        $now = time();

        // Attacks on THIS row (planet or moon — a moon used to react to attacks on its planet and
        // "save" to itself), single or ACS, not yet landed. fleet_start_time is the arrival;
        // fleet_end_time is the return for human fleets (bots write the arrival into both).
        $rows = DB::select(
            "SELECT
                f.`fleet_id`,
                f.`fleet_array`,
                f.`fleet_amount`,
                f.`fleet_start_time` AS `fleet_end_time`,
                f.`fleet_owner`
            FROM `{$prefix}fleets` AS f
            WHERE f.`fleet_end_galaxy` = ?
                AND f.`fleet_end_system` = ?
                AND f.`fleet_end_planet` = ?
                AND f.`fleet_end_type` = ?
                AND f.`fleet_mission` IN (1, 2)
                AND f.`fleet_mess` = 0
                AND f.`fleet_start_time` > ?
            ORDER BY f.`fleet_start_time` ASC",
            [
                (int) $botPlanet['planet_galaxy'],
                (int) $botPlanet['planet_system'],
                (int) $botPlanet['planet_planet'],
                (int) ($botPlanet['planet_type'] ?? 1),
                $now,
            ]
        );

        $attacks = [];

        // A player sees an incoming fleet's exact make-up only from espionage 8 (FleetsLib); below
        // that, just how many ships. Same for bots (2026-10-01): guess the strength from the count.
        $espionage = (int) DB::table('research')->where('research_user_id', (int) $botPlanet['planet_user_id'])
            ->value('research_espionage_technology');

        foreach ($rows as $row) {
            $ships = FleetsLib::getFleetShipsArray((string) $row->fleet_array);
            $strength = $espionage >= 8
                ? $this->calculateFleetStrength($ships)
                : (int) $row->fleet_amount * self::UNSEEN_SHIP_STRENGTH;

            $attacks[] = [
                'fleet_id'      => (int) $row->fleet_id,
                'fleet_array'   => (string) $row->fleet_array,
                'fleet_amount'  => (int) $row->fleet_amount,
                'fleet_end_time' => (int) $row->fleet_end_time,
                'fleet_owner'   => (int) $row->fleet_owner,
                'strength'      => $strength,
            ];
        }

        return $attacks;
    }

    /**
     * Decide whether to fleet save and execute it.
     *
     * @param  array<string, mixed>  $botPlanet
     * @param  array<string, mixed>  $botUser
     * @param  list<array{fleet_id: int, strength: int, fleet_end_time: int}>  $attacks
     *
     * @return bool True if fleet save was dispatched
     */
    public function attemptFleetSave(array $botPlanet, array $botUser, array $attacks): bool
    {
        if (empty($attacks)) {
            return false;
        }

        // Calculate total incoming threat
        $totalThreat = 0;

        foreach ($attacks as $attack) {
            $totalThreat += $attack['strength'];
        }

        // Calculate bot's defense strength
        $botDefense = $this->calculatePlanetDefense($botPlanet);
        $botFleet = $this->calculatePlanetFleet($botPlanet);
        $botStrength = $botDefense + $botFleet;

        // Only fleet save if threat exceeds our strength
        if ($totalThreat <= $botStrength) {
            return false;
        }

        // Gather all ships to save
        $shipsToSave = $this->gatherShips($botPlanet);

        if (empty($shipsToSave)) {
            return false;
        }

        // Pick a safe destination
        $destination = $this->pickSafeDestination($botPlanet);

        if ($destination === null) {
            return false;
        }

        // Calculate how long the fleet needs to stay away
        // Longest attack arrival time + buffer
        $latestArrival = 0;

        foreach ($attacks as $attack) {
            $latestArrival = max($latestArrival, $attack['fleet_end_time']);
        }

        // Stay away for: time until attack lands + 30 minutes buffer
        $stayDuration = ($latestArrival - time()) + 1800;

        // Hold, not Deploy: ships + resources come home by themselves when the stay ends.
        // Deploy (until 29 Sep) left everything on the moon for good — 178M metal and a third of
        // all bot ships had piled up there.
        $fleetId = $this->dispatcher->sendHold($botPlanet, $botUser, $destination, $shipsToSave, $stayDuration, true);

        return $fleetId !== null;
    }

    /**
     * Nightly fleet save — send fleet away before bot goes to sleep.
     *
     * @param  array<string, mixed>  $botPlanet
     * @param  array<string, mixed>  $botUser
     * @param  array{tz_offset: int, active_start: int, active_end: int}  $profile
     *
     * @return bool True if fleet save was dispatched
     */
    public function nightlyFleetSave(array $botPlanet, array $botUser, array $profile): bool
    {
        // Only turtles do nightly fleet saves
        $personality = $profile['personality'] ?? 'raider';

        if ($personality !== 'turtle') {
            return false;
        }

        // Check if bot is about to go to sleep (within 30 min of active_end)
        $botHour = (int) gmdate('G', time() + ($profile['tz_offset'] * 3600));
        $activeEnd = (int) $profile['active_end'];

        // Not near sleep time
        if ($botHour !== ($activeEnd - 1 + 24) % 24 && $botHour !== $activeEnd) {
            return false;
        }

        // Check if there are already incoming attacks (don't double-save)
        $attacks = $this->getIncomingAttacks($botPlanet);

        if (!empty($attacks)) {
            return false; // Reactive save will handle it
        }

        // Already saved this evening (the window is two hours long and the tick runs every 15 min)
        // (any state: a hold that has landed is fleet_mess 2, which hasActiveFleetFromPlanet skips)
        if ($this->hasHoldOut($botPlanet)) {
            return false;
        }

        // Gather ships
        $shipsToSave = $this->gatherShips($botPlanet);

        if (empty($shipsToSave)) {
            return false;
        }

        // Pick destination
        $destination = $this->pickSafeDestination($botPlanet);

        if ($destination === null) {
            return false;
        }

        // Stay away until active_start (the bot's "wake up" time)
        $activeStart = (int) $profile['active_start'];

        // Calculate seconds until active_start
        $hoursUntilWake = ($activeStart - $botHour + 24) % 24;

        if ($hoursUntilWake < 2) {
            $hoursUntilWake = 8; // Minimum 8 hours away
        }

        $stayDuration = $hoursUntilWake * 3600;

        // Hold until wake-up, then everything comes home (see attemptFleetSave)
        $fleetId = $this->dispatcher->sendHold($botPlanet, $botUser, $destination, $shipsToSave, $stayDuration, true);

        return $fleetId !== null;
    }

    /**
     * Does this planet/moon have a hold (fleet save) out that hasn't come home yet?
     *
     * @param  array<string, mixed>  $botPlanet
     */
    public function hasHoldOut(array $botPlanet): bool
    {
        return DB::table('fleets')
            ->where('fleet_owner', (int) $botPlanet['planet_user_id'])
            ->where('fleet_start_galaxy', (int) $botPlanet['planet_galaxy'])
            ->where('fleet_start_system', (int) $botPlanet['planet_system'])
            ->where('fleet_start_planet', (int) $botPlanet['planet_planet'])
            ->where('fleet_start_type', (int) ($botPlanet['planet_type'] ?? 1))
            ->where('fleet_mission', 5)
            ->exists();
    }

    /**
     * Gather all ships from a planet into a fleet array.
     *
     * @param  array<string, mixed>  $botPlanet
     * @return array<int, int>  Ship ID => count
     */
    private function gatherShips(array $botPlanet): array
    {
        $shipColumns = [
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

        foreach ($shipColumns as $id => $column) {
            $count = (int) ($botPlanet[$column] ?? 0);

            if ($count > 0 && $id !== 212) { // Don't fleet save solar satellites
                $ships[$id] = $count;
            }
        }

        return $ships;
    }

    /**
     * Pick a safe destination for fleet save.
     *
     * Strategy:
     * 1. If bot has a moon → deploy to moon
     * 2. Otherwise → deploy to a random far system in same galaxy
     *
     * @param  array<string, mixed>  $botPlanet
     * @return array{galaxy: int, system: int, planet: int, type: int}|null
     */
    private function pickSafeDestination(array $botPlanet): ?array
    {
        $prefix = DB::getTablePrefix();
        $userId = (int) $botPlanet['planet_user_id'];
        $currentPlanetId = (int) $botPlanet['planet_id'];

        // A moon under attack goes to its own planet. Until 29 Sep a moon picked "the moon at
        // these coordinates" — itself.
        if ((int) ($botPlanet['planet_type'] ?? 1) === 3) {
            return [
                'galaxy'  => (int) $botPlanet['planet_galaxy'],
                'system'  => (int) $botPlanet['planet_system'],
                'planet'  => (int) $botPlanet['planet_planet'],
                'type'    => 1,
            ];
        }

        // First choice: moon at current planet
        $moon = DB::selectOne(
            "SELECT `planet_galaxy`, `planet_system`, `planet_planet`
            FROM `{$prefix}planets`
            WHERE `planet_galaxy` = ?
                AND `planet_system` = ?
                AND `planet_planet` = ?
                AND `planet_type` = 3
                AND `planet_user_id` = ?
            LIMIT 1",
            [
                (int) $botPlanet['planet_galaxy'],
                (int) $botPlanet['planet_system'],
                (int) $botPlanet['planet_planet'],
                $userId,
            ]
        );

        if ($moon) {
            return [
                'galaxy'  => (int) $moon->planet_galaxy,
                'system'  => (int) $moon->planet_system,
                'planet'  => (int) $moon->planet_planet,
                'type'    => 3,
            ];
        }

        // Second choice: another planet owned by this bot
        $otherPlanet = DB::selectOne(
            "SELECT `planet_galaxy`, `planet_system`, `planet_planet`, `planet_type`
            FROM `{$prefix}planets`
            WHERE `planet_user_id` = ?
                AND `planet_id` != ?
                AND `planet_type` = 1
            ORDER BY RAND()
            LIMIT 1",
            [$userId, $currentPlanetId]
        );

        if ($otherPlanet) {
            return [
                'galaxy'  => (int) $otherPlanet->planet_galaxy,
                'system'  => (int) $otherPlanet->planet_system,
                'planet'  => (int) $otherPlanet->planet_planet,
                'type'    => (int) $otherPlanet->planet_type,
            ];
        }

        // No moon, no other planets — nowhere to fleet save
        return null;
    }

    /**
     * Calculate combat strength of a fleet.
     *
     * @param  array<int, int>  $ships
     */
    private function calculateFleetStrength(array $ships): int
    {
        $power = [
            202 => 5, 203 => 5, 204 => 50, 205 => 150, 206 => 400,
            207 => 1000, 208 => 50, 209 => 1, 210 => 0, 211 => 1000,
            212 => 1, 213 => 2000, 214 => 200000, 215 => 2800,
        ];

        $total = 0;

        foreach ($ships as $shipId => $count) {
            $total += ($power[$shipId] ?? 0) * $count;
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $planet
     */
    private function calculatePlanetDefense(array $planet): int
    {
        $defensePower = [
            'defense_rocket_launcher'  => 80,
            'defense_light_laser'      => 100,
            'defense_heavy_laser'      => 250,
            'defense_ion_cannon'       => 500,
            'defense_gauss_cannon'     => 1100,
            'defense_plasma_turret'    => 3000,
        ];

        $total = 0;

        foreach ($defensePower as $column => $power) {
            $total += (int) ($planet[$column] ?? 0) * $power;
        }

        $total += (int) ($planet['defense_small_shield_dome'] ?? 0) * 2000;
        $total += (int) ($planet['defense_large_shield_dome'] ?? 0) * 10000;

        return $total;
    }

    /**
     * @param  array<string, mixed>  $planet
     */
    private function calculatePlanetFleet(array $planet): int
    {
        $shipPower = [
            'ship_small_cargo_ship'  => 5,
            'ship_big_cargo_ship'    => 5,
            'ship_light_fighter'     => 50,
            'ship_heavy_fighter'     => 150,
            'ship_cruiser'           => 400,
            'ship_battleship'        => 1000,
            'ship_destroyer'         => 2000,
            'ship_deathstar'         => 200000,
            'ship_reaper'            => 2800,
        ];

        $total = 0;

        foreach ($shipPower as $column => $power) {
            $total += (int) ($planet[$column] ?? 0) * $power;
        }

        return $total;
    }
}

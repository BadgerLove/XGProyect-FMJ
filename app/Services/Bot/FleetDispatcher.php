<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Services\Game\Formulas\FleetsService;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\MissionsEnumerator as Missions;
use Xgp\App\Libraries\FleetsLib;

/**
 * Dispatches fleets directly to the database for bot accounts.
 *
 * Bypasses the UI fleet flow (Fleet1→2→3→4) and inserts fleet records
 * directly. Uses existing FleetsLib for speed/consumption calculations.
 */
class FleetDispatcher
{
    use PreparesLegacySql;

    /**
     * Send espionage probes to a target.
     *
     * @param  array<string, mixed>  $botPlanet  Bot's planet (flat array with ships)
     * @param  array<string, mixed>  $botUser    Bot's user (flat array with research)
     * @param  array{galaxy: int, system: int, planet: int}  $target  Target coordinates
     * @param  int  $probeCount  Number of probes to send
     *
     * @return int|null Fleet ID if dispatched, null on failure
     */
    public function sendSpy(array $botPlanet, array $botUser, array $target, int $probeCount = 1): ?int
    {
        $shipColumn = 'ship_espionage_probe';
        $available = (int) ($botPlanet[$shipColumn] ?? 0);

        if ($available < $probeCount) {
            return null;
        }

        $ships = [210 => $probeCount];
        $fuel = $this->calculateFuel($ships, $botPlanet, $botUser, $target);

        if ($fuel === null) {
            return null;
        }

        // Deduct fuel from planet
        $this->deductFuel($botPlanet['planet_id'], $fuel);

        // Deduct probes from planet
        $this->deductShips($botPlanet['planet_id'], $ships);

        // Calculate flight times
        $flightDuration = $this->calculateFlightDuration($ships, $botPlanet, $botUser, $target);
        $now = time();
        $arrivalTime = $now + $flightDuration;

        return $this->insertFleet([
            'fleet_owner'           => $botPlanet['planet_user_id'],
            'fleet_mission'         => Missions::SPY,
            'fleet_amount'          => $probeCount,
            'fleet_array'           => serialize($ships),
            'fleet_start_time'      => $arrivalTime,
            'fleet_start_galaxy'    => $botPlanet['planet_galaxy'],
            'fleet_start_system'    => $botPlanet['planet_system'],
            'fleet_start_planet'    => $botPlanet['planet_planet'],
            'fleet_start_type'      => 1,
            'fleet_end_time'        => $arrivalTime,
            'fleet_end_stay'        => 0,
            'fleet_end_galaxy'      => $target['galaxy'],
            'fleet_end_system'      => $target['system'],
            'fleet_end_planet'      => $target['planet'],
            'fleet_end_type'        => 1,
            'fleet_target_obj'      => 0,
            'fleet_resource_metal'  => 0,
            'fleet_resource_crystal' => 0,
            'fleet_resource_deuterium' => $fuel,
            'fleet_fuel'            => $fuel,
            'fleet_target_owner'    => 0,
            'fleet_group'           => '0',
            'fleet_mess'            => 0,
            'fleet_creation'        => $now,
        ]);
    }

    /**
     * Send an attack fleet to a target.
     *
     * @param  array<string, mixed>  $botPlanet  Bot's planet (flat array with ships)
     * @param  array<string, mixed>  $botUser    Bot's user (flat array with research)
     * @param  array{galaxy: int, system: int, planet: int, user_id: int}  $target
     * @param  array<int, int>  $ships  Ship ID => count to send
     *
     * @return int|null Fleet ID if dispatched, null on failure
     */
    public function sendAttack(array $botPlanet, array $botUser, array $target, array $ships): ?int
    {
        // Verify bot has enough ships
        foreach ($ships as $shipId => $count) {
            $column = $this->getShipColumn($shipId);
            $available = (int) ($botPlanet[$column] ?? 0);

            if ($available < $count) {
                return null;
            }
        }

        $fuel = $this->calculateFuel($ships, $botPlanet, $botUser, $target);

        if ($fuel === null) {
            return null;
        }

        // Check if bot can afford fuel
        $deuterium = (float) ($botPlanet['planet_deuterium'] ?? 0);

        if ($deuterium < $fuel) {
            return null;
        }

        // Deduct fuel
        $this->deductFuel($botPlanet['planet_id'], $fuel);

        // Deduct ships
        $this->deductShips($botPlanet['planet_id'], $ships);

        // Calculate flight times
        $flightDuration = $this->calculateFlightDuration($ships, $botPlanet, $botUser, $target);
        $now = time();
        $arrivalTime = $now + $flightDuration;
        $totalShips = array_sum($ships);

        return $this->insertFleet([
            'fleet_owner'           => $botPlanet['planet_user_id'],
            'fleet_mission'         => Missions::ATTACK,
            'fleet_amount'          => $totalShips,
            'fleet_array'           => serialize($ships),
            'fleet_start_time'      => $arrivalTime,
            'fleet_start_galaxy'    => $botPlanet['planet_galaxy'],
            'fleet_start_system'    => $botPlanet['planet_system'],
            'fleet_start_planet'    => $botPlanet['planet_planet'],
            'fleet_start_type'      => 1,
            'fleet_end_time'        => $arrivalTime,
            'fleet_end_stay'        => 0,
            'fleet_end_galaxy'      => $target['galaxy'],
            'fleet_end_system'      => $target['system'],
            'fleet_end_planet'      => $target['planet'],
            'fleet_end_type'        => 1,
            'fleet_target_obj'      => 0,
            'fleet_resource_metal'  => 0,
            'fleet_resource_crystal' => 0,
            'fleet_resource_deuterium' => $fuel,
            'fleet_fuel'            => $fuel,
            'fleet_target_owner'    => $target['user_id'] ?? 0,
            'fleet_group'           => '0',
            'fleet_mess'            => 0,
            'fleet_creation'        => $now,
        ]);
    }

    /**
     * Send a deploy mission (fleet save).
     *
     * @param  array<string, mixed>  $botPlanet
     * @param  array<string, mixed>  $botUser
     * @param  array{galaxy: int, system: int, planet: int, type: int}  $destination
     * @param  array<int, int>  $ships  Ship ID => count
     * @param  int  $stayDuration  How long to stay (seconds)
     *
     * @return int|null Fleet ID
     */
    public function sendDeploy(array $botPlanet, array $botUser, array $destination, array $ships, int $stayDuration): ?int
    {
        // Verify bot has enough ships
        foreach ($ships as $shipId => $count) {
            $column = $this->getShipColumn($shipId);
            $available = (int) ($botPlanet[$column] ?? 0);

            if ($available < $count) {
                return null;
            }
        }

        $target = ['galaxy' => $destination['galaxy'], 'system' => $destination['system'], 'planet' => $destination['planet']];
        $fuel = $this->calculateFuel($ships, $botPlanet, $botUser, $target);

        if ($fuel === null) {
            return null;
        }

        $deuterium = (float) ($botPlanet['planet_deuterium'] ?? 0);

        if ($deuterium < $fuel) {
            return null;
        }

        // Deduct fuel and ships
        $this->deductFuel($botPlanet['planet_id'], $fuel);
        $this->deductShips($botPlanet['planet_id'], $ships);

        // Also carry resources on the fleet (fleet save includes resources)
        $metal = (int) ($botPlanet['planet_metal'] ?? 0);
        $crystal = (int) ($botPlanet['planet_crystal'] ?? 0);
        $deutRemaining = max(0, (int) ($botPlanet['planet_deuterium'] ?? 0) - $fuel);

        // Deduct resources from planet
        $this->deductResources($botPlanet['planet_id'], $metal, $crystal, $deutRemaining);

        $flightDuration = $this->calculateFlightDuration($ships, $botPlanet, $botUser, $target);
        $now = time();
        $arrivalTime = $now + $flightDuration;
        $totalShips = array_sum($ships);

        return $this->insertFleet([
            'fleet_owner'           => $botPlanet['planet_user_id'],
            'fleet_mission'         => 4, // DEPLOY
            'fleet_amount'          => $totalShips,
            'fleet_array'           => serialize($ships),
            'fleet_start_time'      => $arrivalTime,
            'fleet_start_galaxy'    => $botPlanet['planet_galaxy'],
            'fleet_start_system'    => $botPlanet['planet_system'],
            'fleet_start_planet'    => $botPlanet['planet_planet'],
            'fleet_start_type'      => 1,
            'fleet_end_time'        => $arrivalTime + $stayDuration,
            'fleet_end_stay'        => $arrivalTime,
            'fleet_end_galaxy'      => $destination['galaxy'],
            'fleet_end_system'      => $destination['system'],
            'fleet_end_planet'      => $destination['planet'],
            'fleet_end_type'        => $destination['type'] ?? 1,
            'fleet_target_obj'      => 0,
            'fleet_resource_metal'  => $metal,
            'fleet_resource_crystal' => $crystal,
            'fleet_resource_deuterium' => $deutRemaining,
            'fleet_fuel'            => $fuel,
            'fleet_target_owner'    => 0,
            'fleet_group'           => '0',
            'fleet_mess'            => 0,
            'fleet_creation'        => $now,
        ]);
    }

    /**
     * Send a colonization mission.
     *
     * @param  array<string, mixed>  $botPlanet
     * @param  array<string, mixed>  $botUser
     * @param  array{galaxy: int, system: int, planet: int}  $target
     * @param  array<int, int>  $ships
     *
     * @return int|null Fleet ID
     */
    public function sendColonize(array $botPlanet, array $botUser, array $target, array $ships): ?int
    {
        // Verify bot has enough ships
        foreach ($ships as $shipId => $count) {
            $column = $this->getShipColumn($shipId);
            $available = (int) ($botPlanet[$column] ?? 0);

            if ($available < $count) {
                return null;
            }
        }

        $fuel = $this->calculateFuel($ships, $botPlanet, $botUser, $target);

        if ($fuel === null) {
            return null;
        }

        $deuterium = (float) ($botPlanet['planet_deuterium'] ?? 0);

        if ($deuterium < $fuel) {
            return null;
        }

        $this->deductFuel($botPlanet['planet_id'], $fuel);
        $this->deductShips($botPlanet['planet_id'], $ships);

        // Colony ship carries some resources to bootstrap the new planet
        $metal = min((int) ($botPlanet['planet_metal'] ?? 0), 5000);
        $crystal = min((int) ($botPlanet['planet_crystal'] ?? 0), 5000);
        $deutRemaining = min(max(0, (int) ($botPlanet['planet_deuterium'] ?? 0) - $fuel), 3000);

        $this->deductResources($botPlanet['planet_id'], $metal, $crystal, $deutRemaining);

        $flightDuration = $this->calculateFlightDuration($ships, $botPlanet, $botUser, $target);
        $now = time();
        $arrivalTime = $now + $flightDuration;
        $totalShips = array_sum($ships);

        return $this->insertFleet([
            'fleet_owner'           => $botPlanet['planet_user_id'],
            'fleet_mission'         => 7, // COLONIZE
            'fleet_amount'          => $totalShips,
            'fleet_array'           => serialize($ships),
            'fleet_start_time'      => $arrivalTime,
            'fleet_start_galaxy'    => $botPlanet['planet_galaxy'],
            'fleet_start_system'    => $botPlanet['planet_system'],
            'fleet_start_planet'    => $botPlanet['planet_planet'],
            'fleet_start_type'      => 1,
            'fleet_end_time'        => $arrivalTime,
            'fleet_end_stay'        => 0,
            'fleet_end_galaxy'      => $target['galaxy'],
            'fleet_end_system'      => $target['system'],
            'fleet_end_planet'      => $target['planet'],
            'fleet_end_type'        => 1,
            'fleet_target_obj'      => 0,
            'fleet_resource_metal'  => $metal,
            'fleet_resource_crystal' => $crystal,
            'fleet_resource_deuterium' => $deutRemaining,
            'fleet_fuel'            => $fuel,
            'fleet_target_owner'    => 0,
            'fleet_group'           => '0',
            'fleet_mess'            => 0,
            'fleet_creation'        => $now,
        ]);
    }

    /**
     * Send a recycling mission to collect debris.
     *
     * @param  array<string, mixed>  $botPlanet
     * @param  array<string, mixed>  $botUser
     * @param  array{galaxy: int, system: int, planet: int}  $target  Debris field location
     * @param  array<int, int>  $ships  Recyclers to send
     *
     * @return int|null Fleet ID
     */
    public function sendRecycle(array $botPlanet, array $botUser, array $target, array $ships): ?int
    {
        // Verify bot has enough recyclers
        foreach ($ships as $shipId => $count) {
            $column = $this->getShipColumn($shipId);
            $available = (int) ($botPlanet[$column] ?? 0);

            if ($available < $count) {
                return null;
            }
        }

        $fuel = $this->calculateFuel($ships, $botPlanet, $botUser, $target);

        if ($fuel === null) {
            return null;
        }

        $deuterium = (float) ($botPlanet['planet_deuterium'] ?? 0);

        if ($deuterium < $fuel * 1.5) {
            return null; // Need 50% reserve for return trip
        }

        $this->deductFuel($botPlanet['planet_id'], $fuel);
        $this->deductShips($botPlanet['planet_id'], $ships);

        $flightDuration = $this->calculateFlightDuration($ships, $botPlanet, $botUser, $target);
        $now = time();
        $totalShips = array_sum($ships);

        return $this->insertFleet([
            'fleet_owner'           => $botPlanet['planet_user_id'],
            'fleet_mission'         => 8, // RECYCLE
            'fleet_amount'          => $totalShips,
            'fleet_array'           => serialize($ships),
            'fleet_start_time'      => $now,
            'fleet_start_galaxy'    => $botPlanet['planet_galaxy'],
            'fleet_start_system'    => $botPlanet['planet_system'],
            'fleet_start_planet'    => $botPlanet['planet_planet'],
            'fleet_start_type'      => 1,
            'fleet_end_time'        => $now + $flightDuration,
            'fleet_end_stay'        => 0,
            'fleet_end_galaxy'      => $target['galaxy'],
            'fleet_end_system'      => $target['system'],
            'fleet_end_planet'      => $target['planet'],
            'fleet_end_type'        => 1,
            'fleet_target_obj'      => 0,
            'fleet_resource_metal'  => 0,
            'fleet_resource_crystal' => 0,
            'fleet_resource_deuterium' => 0,
            'fleet_fuel'            => $fuel,
            'fleet_target_owner'    => 0,
            'fleet_group'           => '0',
            'fleet_mess'            => 0,
            'fleet_creation'        => $now,
        ]);
    }

    /**
     * Send a transport mission (resource sharing between bots).
     *
     * @param  array<string, mixed>  $botPlanet
     * @param  array<string, mixed>  $botUser
     * @param  array{galaxy: int, system: int, planet: int}  $destination
     * @param  int  $metal
     * @param  int  $crystal
     * @param  int  $deuterium
     *
     * @return int|null Fleet ID
     */
    public function sendTransport(array $botPlanet, array $botUser, array $destination, int $metal, int $crystal, int $deuterium): ?int
    {
        // Find the best cargo ship available
        $bigCargo = (int) ($botPlanet['ship_big_cargo_ship'] ?? 0);
        $smallCargo = (int) ($botPlanet['ship_small_cargo_ship'] ?? 0);

        $totalResources = $metal + $crystal + $deuterium;
        $bigCargoCapacity = 25000;
        $smallCargoCapacity = 5000;

        $ships = [];

        if ($bigCargo > 0) {
            $needed = (int) ceil($totalResources / $bigCargoCapacity);
            $ships[203] = min($bigCargo, $needed);
        } elseif ($smallCargo > 0) {
            $needed = (int) ceil($totalResources / $smallCargoCapacity);
            $ships[202] = min($smallCargo, $needed);
        } else {
            return null; // No cargo ships
        }

        // Verify we have enough ships
        foreach ($ships as $shipId => $count) {
            $column = $this->getShipColumn($shipId);
            if ((int) ($botPlanet[$column] ?? 0) < $count) {
                return null;
            }
        }

        $fuel = $this->calculateFuel($ships, $botPlanet, $botUser, $destination);
        if ($fuel === null) {
            return null;
        }

        $deuteriumAvailable = (float) ($botPlanet['planet_deuterium'] ?? 0);
        if ($deuteriumAvailable < $fuel + $deuterium) {
            return null;
        }

        // Deduct fuel, ships, and resources
        $this->deductFuel($botPlanet['planet_id'], $fuel);
        $this->deductShips($botPlanet['planet_id'], $ships);
        $this->deductResources($botPlanet['planet_id'], $metal, $crystal, $deuterium);

        $flightDuration = $this->calculateFlightDuration($ships, $botPlanet, $botUser, $destination);
        $now = time();
        $arrivalTime = $now + $flightDuration;
        $totalShips = array_sum($ships);

        return $this->insertFleet([
            'fleet_owner'           => $botPlanet['planet_user_id'],
            'fleet_mission'         => 3, // TRANSPORT
            'fleet_amount'          => $totalShips,
            'fleet_array'           => serialize($ships),
            'fleet_start_time'      => $arrivalTime,
            'fleet_start_galaxy'    => $botPlanet['planet_galaxy'],
            'fleet_start_system'    => $botPlanet['planet_system'],
            'fleet_start_planet'    => $botPlanet['planet_planet'],
            'fleet_start_type'      => 1,
            'fleet_end_time'        => $arrivalTime,
            'fleet_end_stay'        => 0,
            'fleet_end_galaxy'      => $destination['galaxy'],
            'fleet_end_system'      => $destination['system'],
            'fleet_end_planet'      => $destination['planet'],
            'fleet_end_type'        => 1,
            'fleet_target_obj'      => 0,
            'fleet_resource_metal'  => $metal,
            'fleet_resource_crystal' => $crystal,
            'fleet_resource_deuterium' => $deuterium,
            'fleet_fuel'            => $fuel,
            'fleet_target_owner'    => 0,
            'fleet_group'           => '0',
            'fleet_mess'            => 0,
            'fleet_creation'        => $now,
        ]);
    }

    /**
     * Check if a bot has any fleets currently flying.
     */
    public function hasActiveFleet(int $userId): bool
    {
        return DB::table('fleets')
            ->where('fleet_owner', $userId)
            ->where('fleet_mess', 0)
            ->exists();
    }

    /**
     * Check if a specific planet has an outbound fleet.
     */
    public function hasActiveFleetFromPlanet(int $galaxy, int $system, int $planet, int $type = 1): bool
    {
        return DB::table('fleets')
            ->where('fleet_start_galaxy', $galaxy)
            ->where('fleet_start_system', $system)
            ->where('fleet_start_planet', $planet)
            ->where('fleet_start_type', $type)
            ->where('fleet_mess', 0)
            ->exists();
    }

    /**
     * Insert a fleet record into the database.
     *
     * @return int Fleet ID
     */
    private function insertFleet(array $data): int
    {
        DB::table('fleets')->insert($data);

        return (int) DB::getPdo()->lastInsertId();
    }

    /**
     * Calculate fuel cost for a fleet trip.
     *
     * @param  array<int, int>  $ships
     * @param  array<string, mixed>  $planet
     * @param  array<string, mixed>  $user
     * @param  array{galaxy: int, system: int, planet: int}  $target
     */
    private function calculateFuel(array $ships, array $planet, array $user, array $target): ?int
    {
        $distance = FleetsLib::targetDistance(
            (int) $planet['planet_galaxy'],
            (int) $target['galaxy'],
            (int) $planet['planet_system'],
            (int) $target['system'],
            (int) $planet['planet_planet'],
            (int) $target['planet']
        );

        $speeds = FleetsLib::fleetMaxSpeed($ships, $user);
        $maxSpeed = min($speeds);
        $speedFactor = app(\App\Services\SettingsService::class)->getInt('game_speed') / 2500;
        $duration = FleetsLib::missionDuration(10, $maxSpeed, $distance, (int) $speedFactor);

        return (int) FleetsLib::fleetConsumption($ships, 10, (int) $duration, $distance, $user);
    }

    /**
     * Calculate flight duration in seconds.
     *
     * @param  array<int, int>  $ships
     */
    private function calculateFlightDuration(array $ships, array $planet, array $user, array $target): int
    {
        $distance = FleetsLib::targetDistance(
            (int) $planet['planet_galaxy'],
            (int) $target['galaxy'],
            (int) $planet['planet_system'],
            (int) $target['system'],
            (int) $planet['planet_planet'],
            (int) $target['planet']
        );

        $speeds = FleetsLib::fleetMaxSpeed($ships, $user);
        $maxSpeed = min($speeds);
        $speedFactor = app(\App\Services\SettingsService::class)->getInt('game_speed') / 2500;

        return (int) FleetsLib::missionDuration(10, $maxSpeed, $distance, (int) $speedFactor);
    }

    /**
     * Deduct fuel (deuterium) from a planet.
     */
    private function deductFuel(int $planetId, int $fuel): void
    {
        DB::table('planets')
            ->where('planet_id', $planetId)
            ->decrement('planet_deuterium', $fuel);
    }

    /**
     * Deduct resources from a planet.
     */
    private function deductResources(int $planetId, int $metal, int $crystal, int $deuterium): void
    {
        DB::table('planets')
            ->where('planet_id', $planetId)
            ->update([
                'planet_metal' => DB::raw("GREATEST(planet_metal - {$metal}, 0)"),
                'planet_crystal' => DB::raw("GREATEST(planet_crystal - {$crystal}, 0)"),
                'planet_deuterium' => DB::raw("GREATEST(planet_deuterium - {$deuterium}, 0)"),
            ]);
    }

    /**
     * Deduct ships from a planet.
     *
     * @param  array<int, int>  $ships
     */
    private function deductShips(int $planetId, array $ships): void
    {
        foreach ($ships as $shipId => $count) {
            $column = $this->getShipColumn($shipId);

            DB::table('ships')
                ->where('ship_planet_id', $planetId)
                ->decrement($column, $count);
        }
    }

    /**
     * Map ship ID to database column name.
     */
    private function getShipColumn(int $shipId): string
    {
        $columns = [
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

        return $columns[$shipId] ?? 'ship_small_cargo_ship';
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Services\Game\Formulas\FleetsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\MissionsEnumerator as Missions;
use Xgp\App\Core\Objects;
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

        // Reserve the probes on the live row first (refused if the planet is short), then pay the fuel
        if (!$this->deductShips($botPlanet['planet_id'], $ships)) {
            return null;
        }
        $this->deductFuel($botPlanet['planet_id'], $fuel);

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
            'fleet_start_type'      => $this->originType($botPlanet),
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

        // Reserve the ships on the live row first (refused if the planet is short), then pay the fuel
        if (!$this->deductShips($botPlanet['planet_id'], $ships)) {
            return null;
        }
        $this->deductFuel($botPlanet['planet_id'], $fuel);

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
            'fleet_start_type'      => $this->originType($botPlanet),
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
     * @param  int  $stayDuration  Unused by the game's Deploy handler (kept for callers)
     * @param  array{metal?: int, crystal?: int, deuterium?: int}|null  $cargo  What to carry (null = everything)
     *
     * @return int|null Fleet ID
     */
    public function sendDeploy(array $botPlanet, array $botUser, array $destination, array $ships, int $stayDuration, ?array $cargo = null): ?int
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

        // Reserve the ships on the live row first (refused if the planet is short), then pay the fuel
        if (!$this->deductShips($botPlanet['planet_id'], $ships)) {
            return null;
        }
        $this->deductFuel($botPlanet['planet_id'], $fuel);

        // Carry what the ships can hold. Deploy is one-way: the cargo stays at the destination.
        // Until 29 Sep this loaded EVERYTHING whatever the hold size, and was used for fleet saves
        // (stranding it on the moon) and for "support" to other bots (giving it away).
        // $cargo overrides what to carry (moon return keeps the next lunar building's cost behind).
        $metal = (int) ($cargo['metal'] ?? $botPlanet['planet_metal'] ?? 0);
        $crystal = (int) ($cargo['crystal'] ?? $botPlanet['planet_crystal'] ?? 0);
        $deutRemaining = (int) ($cargo['deuterium'] ?? max(0, (int) ($botPlanet['planet_deuterium'] ?? 0) - $fuel));
        [$metal, $crystal, $deutRemaining] = $this->clampToCapacity($ships, $botUser, $metal, $crystal, $deutRemaining);
        // Never more than the planet holds after the fuel (deductResources floors at 0, so an
        // over-sized load would create resources)
        $metal = min($metal, max(0, (int) ($botPlanet['planet_metal'] ?? 0)));
        $crystal = min($crystal, max(0, (int) ($botPlanet['planet_crystal'] ?? 0)));
        $deutRemaining = min($deutRemaining, max(0, (int) ($botPlanet['planet_deuterium'] ?? 0) - $fuel));

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
            'fleet_start_type'      => $this->originType($botPlanet),
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
     * Send a hold mission (Missions::STAY): fly to the destination, park there until the stay
     * ends, then fly home with ships AND cargo (legacy Stay.php restores to the start planet).
     *
     * While parked (fleet_start_time < now <= fleet_end_stay) the fleet fights in any battle at
     * the destination (Attack.php getAllFleetsByEndCoordsAndTimes). Used for fleet saves and for
     * defensive support; both used Deploy until 29 Sep, which never came back.
     *
     * @param  array<string, mixed>  $botPlanet
     * @param  array<string, mixed>  $botUser
     * @param  array{galaxy: int, system: int, planet: int, type?: int}  $destination
     * @param  array<int, int>  $ships  Ship ID => count
     * @param  int  $stayDuration  Seconds to hold after arriving
     * @param  bool  $carryResources  Load the planet's resources (up to the fleet's capacity)
     *
     * @return int|null Fleet ID
     */
    public function sendHold(array $botPlanet, array $botUser, array $destination, array $ships, int $stayDuration, bool $carryResources): ?int
    {
        foreach ($ships as $shipId => $count) {
            if ((int) ($botPlanet[$this->getShipColumn($shipId)] ?? 0) < $count) {
                return null;
            }
        }

        $target = ['galaxy' => $destination['galaxy'], 'system' => $destination['system'], 'planet' => $destination['planet']];
        $fuel = $this->calculateFuel($ships, $botPlanet, $botUser, $target);

        if ($fuel === null || (float) ($botPlanet['planet_deuterium'] ?? 0) < $fuel) {
            return null;
        }

        if (!$this->deductShips($botPlanet['planet_id'], $ships)) {
            return null;
        }
        $this->deductFuel($botPlanet['planet_id'], $fuel);

        $metal = 0;
        $crystal = 0;
        $deut = 0;

        if ($carryResources) {
            [$metal, $crystal, $deut] = $this->clampToCapacity(
                $ships,
                $botUser,
                (int) ($botPlanet['planet_metal'] ?? 0),
                (int) ($botPlanet['planet_crystal'] ?? 0),
                max(0, (int) ($botPlanet['planet_deuterium'] ?? 0) - $fuel)
            );
            $this->deductResources($botPlanet['planet_id'], $metal, $crystal, $deut);
        }

        $flightDuration = $this->calculateFlightDuration($ships, $botPlanet, $botUser, $target);
        $now = time();
        $arrivalTime = $now + $flightDuration;
        $stayEnd = $arrivalTime + max(0, $stayDuration);

        return $this->insertFleet([
            'fleet_owner'           => $botPlanet['planet_user_id'],
            'fleet_mission'         => Missions::STAY,
            'fleet_amount'          => array_sum($ships),
            'fleet_array'           => serialize($ships),
            'fleet_start_time'      => $arrivalTime,
            'fleet_start_galaxy'    => $botPlanet['planet_galaxy'],
            'fleet_start_system'    => $botPlanet['planet_system'],
            'fleet_start_planet'    => $botPlanet['planet_planet'],
            'fleet_start_type'      => $this->originType($botPlanet),
            'fleet_end_time'        => $stayEnd + $flightDuration,
            'fleet_end_stay'        => $stayEnd,
            'fleet_end_galaxy'      => $destination['galaxy'],
            'fleet_end_system'      => $destination['system'],
            'fleet_end_planet'      => $destination['planet'],
            'fleet_end_type'        => $destination['type'] ?? 1,
            'fleet_target_obj'      => 0,
            'fleet_resource_metal'  => $metal,
            'fleet_resource_crystal' => $crystal,
            'fleet_resource_deuterium' => $deut,
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

        if (!$this->deductShips($botPlanet['planet_id'], $ships)) {
            return null;
        }
        $this->deductFuel($botPlanet['planet_id'], $fuel);

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
            'fleet_start_type'      => $this->originType($botPlanet),
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

        if (!$this->deductShips($botPlanet['planet_id'], $ships)) {
            return null;
        }
        $this->deductFuel($botPlanet['planet_id'], $fuel);

        // Recycle.php collects the field once fleet_start_time (= arrival) has passed and brings
        // the fleet home at fleet_end_time. Until 7 Sep start_time was "now", so the debris was
        // scooped the instant the recyclers left and they were back after a single leg.
        $flightDuration = $this->calculateFlightDuration($ships, $botPlanet, $botUser, $target);
        $now = time();
        $arrivalTime = $now + $flightDuration;
        $totalShips = array_sum($ships);

        return $this->insertFleet([
            'fleet_owner'           => $botPlanet['planet_user_id'],
            'fleet_mission'         => 8, // RECYCLE
            'fleet_amount'          => $totalShips,
            'fleet_array'           => serialize($ships),
            'fleet_start_time'      => $arrivalTime,
            'fleet_start_galaxy'    => $botPlanet['planet_galaxy'],
            'fleet_start_system'    => $botPlanet['planet_system'],
            'fleet_start_planet'    => $botPlanet['planet_planet'],
            'fleet_start_type'      => $this->originType($botPlanet),
            'fleet_end_time'        => $arrivalTime + $flightDuration,
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
     * Send an expedition fleet to slot 16 (deep space).
     *
     * Expedition timing:
     *   - fleet_start_time = arrival at destination
     *   - fleet_end_stay   = end of stay (arrival + stay duration)
     *   - fleet_end_time   = arrival back home (stay end + return flight)
     *   - fleet_mess       = 0 (outbound)
     *
     * @param  array<string, mixed>  $botPlanet
     * @param  array<string, mixed>  $botUser
     * @param  int  $targetSystem  System to send expedition to
     * @param  int  $targetGalaxy  Galaxy (usually same as bot's)
     * @param  array<int, int>  $ships  Ship ID => count
     * @param  int  $stayDuration  How long to stay at slot 16 (seconds)
     *
     * @return int|null Fleet ID if dispatched, null on failure
     */
    public function sendExpedition(
        array $botPlanet,
        array $botUser,
        int $targetGalaxy,
        int $targetSystem,
        array $ships,
        int $stayDuration
    ): ?int {
        // Verify bot has enough ships
        foreach ($ships as $shipId => $count) {
            $column = $this->getShipColumn($shipId);
            $available = (int) ($botPlanet[$column] ?? 0);

            if ($available < $count) {
                return null;
            }
        }

        // Slot 16 is always planet 16
        $target = ['galaxy' => $targetGalaxy, 'system' => $targetSystem, 'planet' => 16];
        $fuel = $this->calculateFuel($ships, $botPlanet, $botUser, $target);

        if ($fuel === null) {
            return null;
        }

        $deuterium = (float) ($botPlanet['planet_deuterium'] ?? 0);

        // Need extra deuterium for the return trip (approximate: fuel * 1.5)
        if ($deuterium < $fuel * 1.5) {
            return null;
        }

        // Reserve the ships on the live row first (refused if the planet is short), then pay the fuel
        if (!$this->deductShips($botPlanet['planet_id'], $ships)) {
            return null;
        }
        $this->deductFuel($botPlanet['planet_id'], $fuel);

        // Calculate flight times
        $flightDuration = $this->calculateFlightDuration($ships, $botPlanet, $botUser, $target);
        $now = time();
        $arrivalTime = $now + $flightDuration;
        $stayEndTime = $arrivalTime + $stayDuration;
        // Return flight takes same duration as outbound
        $returnArrival = $stayEndTime + $flightDuration;
        $totalShips = array_sum($ships);

        return $this->insertFleet([
            'fleet_owner'           => $botPlanet['planet_user_id'],
            'fleet_mission'         => Missions::EXPEDITION,
            'fleet_amount'          => $totalShips,
            'fleet_array'           => serialize($ships),
            'fleet_start_time'      => $arrivalTime,
            'fleet_start_galaxy'    => $botPlanet['planet_galaxy'],
            'fleet_start_system'    => $botPlanet['planet_system'],
            'fleet_start_planet'    => $botPlanet['planet_planet'],
            'fleet_start_type'      => $this->originType($botPlanet),
            'fleet_end_time'        => $returnArrival,
            'fleet_end_stay'        => $stayEndTime,
            'fleet_end_galaxy'      => $targetGalaxy,
            'fleet_end_system'      => $targetSystem,
            'fleet_end_planet'      => 16,
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
     * Count the expeditions a user has out — outbound, exploring or returning. The game's own
     * slot check (legacy Libraries/Game/Fleets.php) counts every expedition row; until 29 Sep
     * this counted outbound only, so returning fleets freed their slot early and 23 bots were
     * over their limit.
     */
    public function countActiveExpeditions(int $userId): int
    {
        return (int) DB::table('fleets')
            ->where('fleet_owner', $userId)
            ->where('fleet_mission', Missions::EXPEDITION)
            ->count();
    }

    /**
     * Send a transport mission (resource sharing between bots).
     *
     * @param  array<string, mixed>  $botPlanet
     * @param  array<string, mixed>  $botUser
     * @param  array{galaxy: int, system: int, planet: int, type?: int}  $destination  type 3 = the moon
     * @param  int  $metal
     * @param  int  $crystal
     * @param  int  $deuterium
     *
     * @return int|null Fleet ID
     */
    public function sendTransport(array $botPlanet, array $botUser, array $destination, int $metal, int $crystal, int $deuterium, ?array $withShips = null): ?int
    {
        if ($withShips !== null) {
            // Caller chose the fleet (moon return shuttles with every ship on the moon)
            $ships = $withShips;
        } else {
            $ships = $this->pickCargoShips($botPlanet, $botUser, $metal + $crystal + $deuterium);
            if ($ships === null) {
                return null; // No cargo ships
            }
        }

        // Too few ships for the whole load: carry what fits. Until 29 Sep the full amount flew
        // anyway (692K in 3 Small Cargos).
        [$metal, $crystal, $deuterium] = $this->clampToCapacity($ships, $botUser, $metal, $crystal, $deuterium);
        // ...and never more than the planet holds (the caller's figures can be from before a spend)
        $metal = min($metal, max(0, (int) ($botPlanet['planet_metal'] ?? 0)));
        $crystal = min($crystal, max(0, (int) ($botPlanet['planet_crystal'] ?? 0)));

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
        if ($withShips !== null) {
            // Shuttle: carry the deuterium that is left after paying the fuel
            $deuterium = (int) min($deuterium, max(0, $deuteriumAvailable - $fuel));
        }
        if ($deuteriumAvailable < $fuel + $deuterium) {
            return null;
        }

        // Reserve the ships on the live row first (refused if the planet is short), then fuel and cargo
        if (!$this->deductShips($botPlanet['planet_id'], $ships)) {
            return null;
        }
        $this->deductFuel($botPlanet['planet_id'], $fuel);
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
            'fleet_start_type'      => $this->originType($botPlanet),
            // Home after the return leg (was = arrival: the ships reappeared at home on delivery)
            'fleet_end_time'        => $arrivalTime + $flightDuration,
            'fleet_end_stay'        => 0,
            'fleet_end_galaxy'      => $destination['galaxy'],
            'fleet_end_system'      => $destination['system'],
            'fleet_end_planet'      => $destination['planet'],
            'fleet_end_type'        => $destination['type'] ?? 1,
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
     * Cargo ships for a load: Large Cargos if any, else Small Cargos, as many as it needs.
     *
     * @return array<int, int>|null
     */
    private function pickCargoShips(array $botPlanet, array $botUser, int $totalResources): ?array
    {
        $bigCargo = (int) ($botPlanet['ship_big_cargo_ship'] ?? 0);
        $smallCargo = (int) ($botPlanet['ship_small_cargo_ship'] ?? 0);
        $hyper = (int) ($botUser['research_hyperspace_technology'] ?? 0);

        if ($bigCargo > 0) {
            return [203 => min($bigCargo, max(1, (int) ceil($totalResources / FleetsLib::getMaxStorage(25000, $hyper))))];
        }

        if ($smallCargo > 0) {
            return [202 => min($smallCargo, max(1, (int) ceil($totalResources / FleetsLib::getMaxStorage(5000, $hyper))))];
        }

        return null;
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
     * Count all fleets a bot has out (any mission, any state) — the game's fleet-slot rule.
     */
    public function countActiveFleets(int $userId): int
    {
        return (int) DB::table('fleets')
            ->where('fleet_owner', $userId)
            ->count();
    }

    /**
     * Check if a specific planet has an outbound fleet.
     *
     * @param  array<int, int>|null  $missions  Restrict to these mission ids (null = any mission)
     */
    public function hasActiveFleetFromPlanet(int $galaxy, int $system, int $planet, int $type = 1, ?array $missions = null): bool
    {
        $query = DB::table('fleets')
            ->where('fleet_start_galaxy', $galaxy)
            ->where('fleet_start_system', $system)
            ->where('fleet_start_planet', $planet)
            ->where('fleet_start_type', $type)
            ->where('fleet_mess', 0);

        if ($missions !== null) {
            $query->whereIn('fleet_mission', $missions);
        }

        return $query->exists();
    }

    /**
     * Seconds a fleet needs to fly from $botPlanet to $target (100 % speed, as every bot send).
     *
     * @param  array<int, int>  $ships
     * @param  array{galaxy: int, system: int, planet: int}  $target
     */
    public function flightSeconds(array $ships, array $botPlanet, array $botUser, array $target): int
    {
        return $this->calculateFlightDuration($ships, $botPlanet, $botUser, $target);
    }

    /**
     * Total cargo space of a fleet, with the hyperspace bonus, from the game's own price list.
     *
     * @param  array<int, int>  $ships
     */
    public function cargoCapacity(array $ships, array $botUser): int
    {
        $prices = Objects::getInstance()->getPrice();
        $hyper = (int) ($botUser['research_hyperspace_technology'] ?? 0);
        $capacity = 0;

        foreach ($ships as $shipId => $count) {
            $capacity += (int) $count * FleetsLib::getMaxStorage((int) ($prices[$shipId]['capacity'] ?? 0), $hyper);
        }

        return $capacity;
    }

    /**
     * Fit metal/crystal/deuterium into the fleet's hold, filling metal, then crystal, then deut.
     *
     * @param  array<int, int>  $ships
     * @return array{0: int, 1: int, 2: int}
     */
    private function clampToCapacity(array $ships, array $botUser, int $metal, int $crystal, int $deuterium): array
    {
        $free = $this->cargoCapacity($ships, $botUser);
        $metal = max(0, min($metal, $free));
        $free -= $metal;
        $crystal = max(0, min($crystal, $free));
        $free -= $crystal;
        $deuterium = max(0, min($deuterium, $free));

        return [$metal, $crystal, $deuterium];
    }

    /**
     * The origin's real type (1 planet, 3 moon). Every send wrote 1 until 29 Sep, so fleets that
     * left a moon came home to the planet and moon-origin fleets were invisible to
     * hasActiveFleetFromPlanet(..., 3).
     *
     * @param  array<string, mixed>  $botPlanet
     */
    private function originType(array $botPlanet): int
    {
        return (int) ($botPlanet['planet_type'] ?? 1);
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
     * Reserve ships on a planet for a fleet.
     *
     * Each column is decremented only if the live row still holds enough, all inside one transaction.
     * Returns false — with nothing deducted — when the planet is short, and the caller must abandon the
     * dispatch. The tick used to spend ships from a planet row it had read before a fleet save emptied
     * the planet, which drove counts negative and made OPBE throw when that planet was next attacked
     * (2:401:7 = -3 probes, 13 Sep). Callers reserve ships BEFORE paying fuel, so a refusal costs nothing.
     *
     * @param  array<int, int>  $ships  Ship ID => count
     */
    private function deductShips(int $planetId, array $ships): bool
    {
        DB::beginTransaction();

        try {
            foreach ($ships as $shipId => $count) {
                $count = (int) $count;

                if ($count <= 0) {
                    continue;
                }

                $column = $this->getShipColumn($shipId);

                $affected = DB::table('ships')
                    ->where('ship_planet_id', $planetId)
                    ->where($column, '>=', $count)
                    ->decrement($column, $count);

                if ($affected !== 1) {
                    DB::rollBack();
                    Log::warning("FleetDispatcher: planet {$planetId} has fewer than {$count} {$column} — dispatch refused");

                    return false;
                }
            }

            DB::commit();

            return true;
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
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

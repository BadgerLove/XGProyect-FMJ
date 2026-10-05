<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Core\GameObjects\Defense as DefenseObject;
use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\Ship as ShipObject;
use App\Services\Game\Formulas\FleetsService;
use Xgp\App\Libraries\BattleEngine\Core\Battle;
use Xgp\App\Libraries\BattleEngine\Models\Defense;
use Xgp\App\Libraries\BattleEngine\Models\Fleet;
use Xgp\App\Libraries\BattleEngine\Models\Player;
use Xgp\App\Libraries\BattleEngine\Models\PlayerGroup;
use Xgp\App\Libraries\BattleEngine\Models\Ship;
use Xgp\App\Libraries\BattleEngine\Models\ShipType;

/**
 * Wraps the OPBE battle engine for the Battle Simulator page and the bots' attack decisions.
 *
 * Every unit is built from the game's own GameObjectRegistry, exactly as Missions\Attack builds it for a
 * real fight: shield, attack and rapid fire from the registry, hull = (metal + crystal) / 10. Until 5 Oct 2026
 * this class carried private copies of those tables, which had drifted: deuterium was counted into hull
 * (plasma turret 13,000 instead of 10,000, bomber +20 %), and the rapid fire table was years out of date.
 */
class BattleSimulator
{
    /** Units that take part in a fight, as in Missions\Attack (missiles 502/503 do not). */
    private const SHIP_MIN_ID = 202;
    private const SHIP_MAX_ID = 215;
    private const DEFENSE_MIN_ID = 401;
    private const DEFENSE_MAX_ID = 408;

    public function __construct(
        private readonly GameObjectRegistry $registry,
        private readonly FleetsService $fleetsService,
    ) {
    }

    /**
     * Simulate a battle between an attacker fleet and a defender planet.
     *
     * @param  array<int, int>  $attackerShips  Ship ID => count
     * @param  array<string, mixed>  $defenderPlanet  Defender's planet (flat array with ships + defenses + resources)
     * @param  array<string, mixed>  $attackerUser  Attacker's user (for tech levels)
     * @param  array<string, mixed>  $defenderUser  Defender's user (for tech levels)
     *
     * @return array<string, mixed>
     */
    public function simulate(array $attackerShips, array $defenderPlanet, array $attackerUser, array $defenderUser): array
    {
        $attackerShips = $this->combatUnits($attackerShips, self::SHIP_MIN_ID, self::SHIP_MAX_ID);

        $attackerFleet = new Fleet(1);
        foreach ($attackerShips as $shipId => $count) {
            $attackerFleet->addShipType($this->makeUnit($shipId, $count));
        }
        $attackerPlayer = new Player(
            id: 1,
            fleets: [$attackerFleet],
            weapons_tech: (int) ($attackerUser['research_weapons_technology'] ?? 0),
            shields_tech: (int) ($attackerUser['research_shielding_technology'] ?? 0),
            armour_tech: (int) ($attackerUser['research_armour_technology'] ?? 0),
        );

        // Defender: ships on the planet and its defences fight as one home fleet, as in a real attack
        $defenderShips = $this->extractDefenderShips($defenderPlanet);
        $defenderDefenses = $this->extractDefenderDefenses($defenderPlanet);
        $defenderFleet = new Fleet(2);
        foreach ($defenderShips + $defenderDefenses as $unitId => $count) {
            $defenderFleet->addShipType($this->makeUnit($unitId, $count));
        }
        $defenderPlayer = new Player(
            id: 2,
            fleets: [$defenderFleet],
            weapons_tech: (int) ($defenderUser['research_weapons_technology'] ?? 0),
            shields_tech: (int) ($defenderUser['research_shielding_technology'] ?? 0),
            armour_tech: (int) ($defenderUser['research_armour_technology'] ?? 0),
        );

        $battle = new Battle(new PlayerGroup([$attackerPlayer]), new PlayerGroup([$defenderPlayer]));
        $battle->startBattle();
        $report = $battle->getReport();

        // Winner straight from the engine (attackerHasWin / isAdraw: the same calls CombatLogService uses
        // for the real battle). Until 6 Sep 2026 this subtracted getTotalAttackersLostUnits(), which is the
        // RESOURCE VALUE of the lost ships, not a count, so any fight with real losses read as "draw".
        if ($report->attackerHasWin()) {
            $winner = 'attacker';
        } elseif ($report->isAdraw()) {
            $winner = 'draw';
        } else {
            $winner = 'defender';
        }

        $attackerFinal = $this->extractFleetComposition($report->getAfterBattleAttackers());
        $defenderFinalAll = $this->extractFleetComposition($report->getAfterBattleDefenders());

        $attackerInitialCount = array_sum($attackerShips);
        $defenderInitialCount = array_sum($defenderShips) + array_sum($defenderDefenses);
        $attackerRemaining = array_sum($attackerFinal);
        $defenderRemaining = array_sum($defenderFinalAll);

        // Defender has both ships and defenses: separate them (defences counted after the 70 % repair)
        $defenderShipsFinal = [];
        $defenderDefensesFinal = [];
        foreach ($defenderFinalAll as $id => $count) {
            if ($id >= self::DEFENSE_MIN_ID) {
                $defenderDefensesFinal[$id] = $count;
            } else {
                $defenderShipsFinal[$id] = $count;
            }
        }

        $debris = $report->getDebris();
        $loot = $winner === 'attacker'
            ? $this->estimateLoot($attackerFinal, (int) ($attackerUser['research_hyperspace_technology'] ?? 0), $defenderPlanet)
            : ['metal' => 0, 'crystal' => 0, 'deuterium' => 0];

        return [
            'winner'                    => $winner,
            'attacker_losses'           => max(0, $attackerInitialCount - $attackerRemaining),
            'defender_losses'           => max(0, $defenderInitialCount - $defenderRemaining),
            'attacker_ships_remaining'  => $attackerRemaining,
            'defender_ships_remaining'  => $defenderRemaining,
            'attacker_ships_detail'     => $this->buildDetail($attackerShips, $attackerFinal),
            'defender_ships_detail'     => $this->buildDetail($defenderShips, $defenderShipsFinal),
            'defender_defenses_detail'  => $this->buildDetail($defenderDefenses, $defenderDefensesFinal),
            'attacker_lost_costs'       => $this->flattenLostUnits($report->getAttackersLostUnits()),
            'defender_lost_costs'       => $this->flattenLostUnits($report->getDefendersLostUnits()),
            'loot_metal'                => $loot['metal'],
            'loot_crystal'              => $loot['crystal'],
            'loot_deuterium'            => $loot['deuterium'],
            'debris_metal'              => (int) ($debris[0] ?? 0),
            'debris_crystal'            => (int) ($debris[1] ?? 0),
            'moon_chance'               => $report->getMoonProb(),
            'rounds'                    => $report->getLastRoundNumber(),
        ];
    }

    /**
     * Quick check: would the attacker likely win? Compares raw attack totals, no simulation.
     *
     * @param  array<int, int>  $attackerShips
     * @param  array<string, mixed>  $defenderPlanet
     */
    public function quickWinCheck(array $attackerShips, array $defenderPlanet): bool
    {
        $attackerPower = $this->calculateTotalPower($this->combatUnits($attackerShips, self::SHIP_MIN_ID, self::SHIP_MAX_ID));
        $defenderPower = $this->calculateTotalPower($this->extractDefenderShips($defenderPlanet))
            + $this->calculateTotalPower($this->extractDefenderDefenses($defenderPlanet));

        // Need 1.5x power to be confident
        return $attackerPower > ($defenderPower * 1.5);
    }

    /**
     * One engine unit built the way Missions\Attack::getShipType builds it for a real fight.
     */
    private function makeUnit(int $id, int $count): ShipType
    {
        $object = $this->registry->get($id);
        $price = $object->getPrice();
        $cost = [$price->getMetal(), $price->getCrystal()];   // hull = COST_TO_ARMOUR * (metal + crystal), never deuterium

        if ($object instanceof ShipObject) {
            return new Ship($id, $count, $object->getRapidFire()->toArray(), $object->getShield(), $cost, $object->getAttack());
        }

        if ($object instanceof DefenseObject) {
            return new Defense($id, $count, $object->getRapidFire()->toArray(), $object->getShield(), $cost, $object->getAttack());
        }

        throw new \InvalidArgumentException("Game object {$id} is not a ship or a defence");
    }

    /**
     * Keep only real fighting units with a positive count.
     *
     * @param  array<int, int>  $units
     * @return array<int, int>
     */
    private function combatUnits(array $units, int $minId, int $maxId): array
    {
        $kept = [];

        foreach ($units as $id => $count) {
            $id = (int) $id;
            $count = (int) $count;

            if ($count > 0 && $id >= $minId && $id <= $maxId && $this->registry->has($id)) {
                $kept[$id] = $count;
            }
        }

        return $kept;
    }

    /**
     * What the surviving attackers carry home if they win: 50 % of each resource, loaded in the game's
     * order (metal to a third of the hold, crystal to half the rest, deuterium, then metal and crystal
     * again), capped by cargo capacity with the attacker's Hyperspace Technology. Same rule as
     * Missions\Attack::plunder.
     *
     * @param  array<int, int>  $survivors  Ship ID => count after the battle
     * @param  array<string, mixed>  $defenderPlanet
     * @return array{metal: int, crystal: int, deuterium: int}
     */
    private function estimateLoot(array $survivors, int $hyperspace, array $defenderPlanet): array
    {
        $capacity = 0;
        foreach ($survivors as $shipId => $count) {
            $object = $this->registry->get((int) $shipId);
            if ($object instanceof ShipObject) {
                $capacity += $count * $this->fleetsService->getMaxStorage($object->getCapacity(), $hyperspace);
            }
        }

        $metal = max(0, (float) ($defenderPlanet['planet_metal'] ?? 0)) / 2;
        $crystal = max(0, (float) ($defenderPlanet['planet_crystal'] ?? 0)) / 2;
        $deuterium = max(0, (float) ($defenderPlanet['planet_deuterium'] ?? 0)) / 2;
        $steal = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];

        $stolen = min($capacity / 3, $metal);
        $steal['metal'] += $stolen;
        $metal -= $stolen;
        $capacity -= $stolen;

        $stolen = min($capacity / 2, $crystal);
        $steal['crystal'] += $stolen;
        $crystal -= $stolen;
        $capacity -= $stolen;

        $stolen = min($capacity, $deuterium);
        $steal['deuterium'] += $stolen;
        $capacity -= $stolen;

        $stolen = min($capacity / 2, $metal);
        $steal['metal'] += $stolen;
        $capacity -= $stolen;

        $stolen = min($capacity, $crystal);
        $steal['crystal'] += $stolen;

        return [
            'metal' => (int) floor($steal['metal']),
            'crystal' => (int) floor($steal['crystal']),
            'deuterium' => (int) floor($steal['deuterium']),
        ];
    }

    /**
     * Extract ship/defense counts from a PlayerGroup (after battle).
     *
     * @return array<int, int>  Unit ID => count
     */
    private function extractFleetComposition(PlayerGroup $playerGroup): array
    {
        $composition = [];

        foreach ($playerGroup->getIterator() as $player) {
            foreach ($player->getIterator() as $fleet) {
                foreach ($fleet->getIterator() as $unitId => $unit) {
                    $count = $unit->getCount();
                    if ($count > 0) {
                        $composition[$unitId] = ($composition[$unitId] ?? 0) + $count;
                    }
                }
            }
        }

        return $composition;
    }

    /**
     * Build initial→final detail arrays from initial and final counts.
     *
     * @param  array<int, int>  $initial
     * @param  array<int, int>  $final
     * @return array<int, array{initial: int, final: int}>
     */
    private function buildDetail(array $initial, array $final): array
    {
        $detail = [];

        foreach ($initial as $id => $count) {
            $detail[$id] = [
                'initial' => $count,
                'final'   => $final[$id] ?? 0,
            ];
        }

        return $detail;
    }

    /**
     * Flatten the nested lost-units structure to [unitId => [metal, crystal]].
     *
     * @param  array  $lostUnits  From getAttackersLostUnits() / getDefendersLostUnits()
     * @return array<int, array{metal: int, crystal: int}>
     */
    private function flattenLostUnits(array $lostUnits): array
    {
        $flat = [];

        foreach ($lostUnits as $player) {
            foreach ($player as $fleet) {
                foreach ($fleet as $role => $unitTypes) {
                    foreach ($unitTypes as $unitId => $costs) {
                        $flat[$unitId] = [
                            'metal'   => (int) ($costs[0] ?? 0),
                            'crystal' => (int) ($costs[1] ?? 0),
                        ];
                    }
                }
            }
        }

        return $flat;
    }

    /**
     * Ship counts from a planet flat array, by the registry's column names.
     *
     * @param  array<string, mixed>  $planet
     * @return array<int, int>
     */
    private function extractDefenderShips(array $planet): array
    {
        return $this->extractUnits($planet, self::SHIP_MIN_ID, self::SHIP_MAX_ID);
    }

    /**
     * Defence counts from a planet flat array (401-408; missiles do not fight).
     *
     * @param  array<string, mixed>  $planet
     * @return array<int, int>
     */
    private function extractDefenderDefenses(array $planet): array
    {
        return $this->extractUnits($planet, self::DEFENSE_MIN_ID, self::DEFENSE_MAX_ID);
    }

    /**
     * @param  array<string, mixed>  $planet
     * @return array<int, int>
     */
    private function extractUnits(array $planet, int $minId, int $maxId): array
    {
        $units = [];

        for ($id = $minId; $id <= $maxId; $id++) {
            if (!$this->registry->has($id)) {
                continue;
            }

            $count = (int) ($planet[$this->registry->get($id)->getName()] ?? 0);

            if ($count > 0) {
                $units[$id] = $count;
            }
        }

        return $units;
    }

    /**
     * Calculate total attack of a set of units.
     *
     * @param  array<int, int>  $units
     */
    private function calculateTotalPower(array $units): float
    {
        $total = 0.0;

        foreach ($units as $id => $count) {
            $object = $this->registry->get((int) $id);
            if ($object instanceof ShipObject || $object instanceof DefenseObject) {
                $total += $object->getAttack() * $count;
            }
        }

        return $total;
    }
}

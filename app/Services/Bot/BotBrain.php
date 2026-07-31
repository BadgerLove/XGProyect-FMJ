<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\Planets;
use App\Models\User;
use App\Services\Bot\ThreatAnalyzer;
use App\Services\Game\Formulas\ProductionService;
use Xgp\App\Core\Enumerators\BuildingsEnumerator as Buildings;

/**
 * Bot decision engine — Tier 1 (buildings) + Tier 2 (ships, research, attacks).
 *
 * Supports multiple personalities: passive, raider, turtle, balanced.
 */
class BotBrain
{
    /**
     * All building types a bot might construct, ordered by personality weight.
     * @var array<string, array<int, float>>
     */
    private const BUILDING_WEIGHTS = [
        'raider' => [
            Buildings::BUILDING_METAL_MINE       => 1.2,
            Buildings::BUILDING_CRYSTAL_MINE      => 1.0,
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => 0.6,
            Buildings::BUILDING_SOLAR_PLANT       => 1.0,
            Buildings::BUILDING_HANGAR            => 1.5,
            Buildings::BUILDING_LABORATORY        => 1.0,
            Buildings::BUILDING_ROBOT_FACTORY     => 0.8,
            Buildings::BUILDING_NANO_FACTORY      => 1.0,
        ],
        'turtle' => [
            Buildings::BUILDING_METAL_MINE       => 1.2,
            Buildings::BUILDING_CRYSTAL_MINE      => 1.0,
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => 0.8,
            Buildings::BUILDING_SOLAR_PLANT       => 1.0,
            Buildings::BUILDING_HANGAR            => 1.2,
            Buildings::BUILDING_LABORATORY        => 1.0,
            Buildings::BUILDING_ROBOT_FACTORY     => 0.8,
            Buildings::BUILDING_NANO_FACTORY      => 1.0,
        ],
        'passive' => [
            Buildings::BUILDING_METAL_MINE       => 1.5,
            Buildings::BUILDING_CRYSTAL_MINE      => 1.5,
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => 1.2,
            Buildings::BUILDING_SOLAR_PLANT       => 1.0,
            Buildings::BUILDING_HANGAR            => 0.5,
            Buildings::BUILDING_LABORATORY        => 0.8,
            Buildings::BUILDING_ROBOT_FACTORY     => 0.8,
            Buildings::BUILDING_NANO_FACTORY      => 1.0,
        ],
    ];

    /**
     * Ship build priority for raiders.
     * [ship_id => min_hangar_level]
     * @var array<int, int>
     */
    private const SHIP_PRIORITY_RAIDER = [
        210 => 1,  // Espionage Probe (scouting — capped at 10 total)
        202 => 1,  // Small Cargo (raid ships — cheap, fast)
        204 => 1,  // Light Fighter (combat — main attack ship)
        205 => 3,  // Heavy Fighter (better combat — needs hangar 3, Impulse 2, Armour 2)
        208 => 4,  // Colony Ship (expansion — needs hangar 4, Impulse 3, cap at 1)
        203 => 4,  // Large Cargo (bigger raids — needs hangar 4, Combustion 6)
        206 => 5,  // Cruiser (heavy combat — needs hangar 5, Impulse 4, Ionic 2)
        207 => 7,  // Battleship (endgame — needs hangar 7, Hyperspace Drive 4)
    ];

    /**
     * Ship/defense build priority for turtles.
     * [id => min_hangar_level]
     * @var array<int, int>
     */
    private const SHIP_PRIORITY_TURTLE = [
        401 => 1,  // Rocket Launcher (cheap, mass defense)
        402 => 1,  // Light Laser (better defense)
        403 => 2,  // Heavy Laser (needs hangar 2)
        404 => 3,  // Gauss Cannon (strong defense — needs hangar 3)
        502 => 1,  // Small Shield Dome (shield)
        405 => 4,  // Ion Cannon (needs hangar 4)
        406 => 6,  // Plasma Turret (endgame defense — needs hangar 6)
    ];

    /**
     * Build priority for passive bots — probes for intel + defenses only.
     * No combat ships. Passive bots are resource targets, not fighters.
     * @var array<int, int>
     */
    private const SHIP_PRIORITY_PASSIVE = [
        210 => 1,  // Espionage Probe (intel — cap at 5)
        401 => 1,  // Rocket Launcher (cheap defense)
        402 => 1,  // Light Laser (better defense)
        403 => 2,  // Heavy Laser (needs hangar 2)
        404 => 3,  // Gauss Cannon (needs hangar 3)
        502 => 1,  // Small Shield Dome
        405 => 4,  // Ion Cannon (needs hangar 4)
    ];

    /**
     * Moon building priority — sequential, no ROI needed.
     *
     * Moons can NOT have mines, solar plants, fusion reactors, or storage.
     * Only these buildings are valid: Lunar Base, Robot Factory, Sensor Phalanx,
     * Jump Gate, Missile Silo, and (if we add it) Research Lab.
     *
     * Priority:
     *   1. Lunar Base — adds fields (everything else needs fields)
     *   2. Robot Factory — speeds up all construction
     *   3. Sensor Phalanx — spy on neighbors in range (STRATEGIC: see fleet movements)
     *   4. Missile Silo — defense + IPM capability
     *   5. Jump Gate — instant fleet teleport between moons (endgame, needs 2 moons)
     *
     * @var array<int, array{cap: int, min_rf: int}>
     */
    public const MOON_BUILDING_PRIORITY = [
        Buildings::BUILDING_MONDBASIS  => ['cap' => 10, 'min_rf' => 0],  // Lunar Base — 3 fields per level
        Buildings::BUILDING_ROBOT_FACTORY => ['cap' => 10, 'min_rf' => 0],  // Robot Factory — build speed
        Buildings::BUILDING_PHALANX    => ['cap' => 5,  'min_rf' => 0],  // Sensor Phalanx — scout neighbors
        Buildings::BUILDING_MISSILE_SILO => ['cap' => 5,  'min_rf' => 0],  // Missile Silo — defense
        Buildings::BUILDING_JUMP_GATE  => ['cap' => 1,  'min_rf' => 0],  // Jump Gate — instant travel
    ];

    /**
     * Research priority for raiders (dead code — see nextResearch() for actual order).
     * @var array<int, int>
     */
    private const RESEARCH_RAIDER = [
        113 => 8,  // Energy Tech
        115 => 6,  // Combustion Drive
        120 => 6,  // Laser Tech
        106 => 3,  // Espionage Tech
        117 => 4,  // Impulse Drive
        111 => 5,  // Armour Tech
        121 => 2,  // Ionic Tech
        109 => 5,  // Weapons Tech
        108 => 3,  // Computer Tech
        110 => 5,  // Shielding Tech
        114 => 3,  // Hyperspace Tech
        118 => 4,  // Hyperspace Drive
    ];

    public function __construct(
        private readonly ProductionService $productionService,
        private readonly ThreatAnalyzer $threatAnalyzer,
        private readonly BattleSimulator $simulator,
    ) {
    }

    /**
     * Get the bot's personality.
     *
     * @param  array<string, mixed>  $user
     */
    public function getPersonality(array $user): string
    {
        $profile = json_decode((string) ($user['bot_profile'] ?? '{}'), true);

        return $profile['personality'] ?? 'raider';
    }

    /**
     * Maximum DOIR (in days) before we stop building. Buildings that take
     * longer than this to pay back are not worth the investment.
     */
    private const MAX_DOIR_DAYS = 90.0;

    /**
     * How many hours of production storage should hold before we upgrade it.
     * Default 12h — TBot's standard.
     */
    private const DEPOSIT_HOURS = 12;

    /**
     * Decide what building the bot should construct next.
     *
     * Full TBot-style priority chain:
     *   1. Terraformer (skip — bots don't need it)
     *   2. Energy Source (if energy negative)
     *   3. Deposits/Storage (hours-based, not %-based)
     *   4. Facilities (RF, Nanites, Shipyard, Research Lab — with smart gating)
     *   5. Mines (ROI-based — lowest DOIR first)
     *   6. Facilities — force pass (any remaining)
     *
     * @param  array<string, mixed>  $planet
     * @param  array<string, mixed>  $user
     */
    public function nextBuilding(array $planet, array $user): ?int
    {
        // ─── Moon path ─────────────────────────────────────────────
        // Moons have completely different buildings — route early
        if ($this->isMoon($planet)) {
            return $this->nextMoonBuilding($planet, $user);
        }

        $personality = $this->getPersonality($user);
        $weights = self::BUILDING_WEIGHTS[$personality] ?? self::BUILDING_WEIGHTS['raider'];

        // ─── 1. Energy Source ───────────────────────────────────────
        // Energy is NEVER deferred to research — it's a prerequisite for everything.
        // Negative energy throttles mine production, starving the bot of resources.
        if ($this->isEnergyNegative($planet)) {
            // Try Solar Plant first
            $solarLevel = (int) ($planet['building_solar_plant'] ?? 0);
            if ($solarLevel < 30 && $this->canAfford(Buildings::BUILDING_SOLAR_PLANT, $solarLevel, $planet)) {
                return Buildings::BUILDING_SOLAR_PLANT;
            }

            // Solar too expensive — try Fusion Reactor (cheaper, more energy, costs deut)
            // Fusion requires Energy Tech 3 (standard OGame prerequisite)
            $energyTech = (int) ($user['research_energy_technology'] ?? 0);
            if ($energyTech >= 3) {
                $fusionLevel = (int) ($planet['building_fusion_reactor'] ?? 0);
                if ($fusionLevel < 30 && $this->canAfford(Buildings::BUILDING_FUSION_REACTOR, $fusionLevel, $planet)) {
                    return Buildings::BUILDING_FUSION_REACTOR;
                }
            }
        }

        // ─── 2. Deposits/Storage (hours-based) ──────────────────────
        // Skip deposits in early game (OptimizeForStart)
        if (!$this->isEarlyGame($planet)) {
            $deposit = $this->getNextDeposit($planet);
            if ($deposit !== null) {
                return $deposit;
            }
        }

        // ─── 3. Facilities (smart gating) ───────────────────────────
        // Facilities are infrastructure — they unlock ships, research, construction speed.
        // Never deferred to research; the smart gate already ensures they're cost-effective.
        $facility = $this->getNextFacility($planet, $user, $weights);
        if ($facility !== null) {
            return $facility;
        }

        // ─── 4. Mines (ROI-based, lowest DOIR first) ────────────────
        $mine = $this->getNextMine($planet, $weights);
        if ($mine !== null) {
            // Research deferral: if research is a better investment, skip building
            // and save resources. Energy and deposits already handled above.
            if ($this->shouldDeferToResearch($mine, $planet, $user)) {
                return null;
            }
            return $mine;
        }

        // ─── 5. Facilities — force pass (any remaining) ─────────────
        // Never deferred — these are prerequisites the bot needs.
        return $this->getNextFacilityForce($planet, $user, $weights);
    }

    // ─── Building Decision Helpers ──────────────────────────────────

    /**
     * Check whether building should be deferred in favour of research.
     *
     * Compares the next research DOIR against the candidate building DOIR.
     * If research is a better investment (lower DOIR), the bot skips building
     * and lets resources accumulate for the research instead.
     *
     * This prevents the building system from perpetually spending resources
     * that should be saved for critical tech like Impulse Drive (gateway to colonies).
     */
    private function shouldDeferToResearch(int $buildingId, array $planet, array $user): bool
    {
        $researchId = $this->nextResearch($user);

        if ($researchId === null) {
            return false; // Nothing to research — don't defer
        }

        // CRITICAL: Only defer if the bot can actually AFFORD the research.
        // Otherwise we get a deadlock: defer building → save resources →
        // can't afford research → resources idle → repeat forever.
        $researchLevel = (int) ($user['research_' . $this->getResearchColumn($researchId)] ?? 0);
        $researchCost = $this->getResearchCost($researchId, $researchLevel);
        if ($researchCost === null) {
            return false;
        }

        $metal = (float) ($planet['planet_metal'] ?? 0);
        $crystal = (float) ($planet['planet_crystal'] ?? 0);
        $deuterium = (float) ($planet['planet_deuterium'] ?? 0);

        $canAffordResearch = $metal >= $researchCost['metal']
            && $crystal >= $researchCost['crystal']
            && $deuterium >= $researchCost['deuterium'];

        if (!$canAffordResearch) {
            return false; // Can't afford research — keep building instead
        }

        $buildingLevel = $this->getBuildingLevel($buildingId, $planet);
        $buildingDoir = ROICalculator::calcBuildingDOIR($planet, $buildingId, $buildingLevel);
        $researchDoir = ROICalculator::calcResearchDOIR($user, $planet, $researchId);

        // Research wins if it has a lower (better) DOIR
        return $researchDoir < $buildingDoir;
    }

    // ─── Moon Building Logic ──────────────────────────────────────

    /**
     * Check if this planet is a moon (planet_type = 3).
     *
     * @param  array<string, mixed>  $planet
     */
    private function isMoon(array $planet): bool
    {
        return ((int) ($planet['planet_type'] ?? 1)) === 3;
    }

    /**
     * Decide what to build next on a moon.
     *
     * Moons are strategic assets — Sensor Phalanx lets you spy on neighbors'
     * fleet movements, Jump Gate enables instant fleet deployment. High priority
     * for dominating a local area.
     *
     * Moon buildings are sequential (no ROI calculation needed):
     *   Lunar Base → Robot Factory → Sensor Phalanx → Missile Silo → Jump Gate
     *
     * @param  array<string, mixed>  $planet
     * @param  array<string, mixed>  $user
     */
    private function nextMoonBuilding(array $planet, array $user): ?int
    {
        foreach (self::MOON_BUILDING_PRIORITY as $buildingId => $config) {
            $level = $this->getBuildingLevel($buildingId, $planet);
            $cap = $config['cap'];

            if ($level >= $cap) {
                continue;
            }

            // Check Robot Factory prerequisite for Sensor Phalanx / Jump Gate
            if ($config['min_rf'] > 0) {
                $rfLevel = $this->getBuildingLevel(Buildings::BUILDING_ROBOT_FACTORY, $planet);
                if ($rfLevel < $config['min_rf']) {
                    continue;
                }
            }

            // Prerequisites: Sensor Phalanx needs Lunar Base >= 1, Jump Gate needs Lunar Base >= 1
            if ($buildingId === Buildings::BUILDING_PHALANX || $buildingId === Buildings::BUILDING_JUMP_GATE) {
                $lunarBaseLevel = $this->getBuildingLevel(Buildings::BUILDING_MONDBASIS, $planet);
                if ($lunarBaseLevel < 1) {
                    continue;
                }
            }

            // Missile Silo needs Lunar Base >= 1 too
            if ($buildingId === Buildings::BUILDING_MISSILE_SILO) {
                $lunarBaseLevel = $this->getBuildingLevel(Buildings::BUILDING_MONDBASIS, $planet);
                if ($lunarBaseLevel < 1) {
                    continue;
                }
            }

            // Jump Gate needs Lunar Base >= 1 AND Robot Factory >= 1
            if ($buildingId === Buildings::BUILDING_JUMP_GATE) {
                $rfLevel = $this->getBuildingLevel(Buildings::BUILDING_ROBOT_FACTORY, $planet);
                if ($rfLevel < 1) {
                    continue;
                }
            }

            if ($this->canAfford($buildingId, $level, $planet)) {
                return $buildingId;
            }

            // Can't afford this one — still the right priority, just wait for resources
            // Don't skip to a cheaper building; moons should build in strict order
            return null;
        }

        // All moon buildings maxed — nothing to build
        return null;
    }

    /**
     * Check if planet has negative energy (need more solar/fusion).
     */
    private function isEnergyNegative(array $planet): bool
    {
        $energyMax = (int) ($planet['planet_energy_max'] ?? 0);
        $energyUsed = (int) ($planet['planet_energy_used'] ?? 0);
        return $energyMax > 0 && ($energyMax + $energyUsed) < 0;
    }

    /**
     * Check if planet is in early game — skip deposits if mines are low.
     * TBot OptimizeForStart: skip deposits until mines reach ~12/12/10.
     */
    private function isEarlyGame(array $planet): bool
    {
        $metal = (int) ($planet['building_metal_mine'] ?? 0);
        $crystal = (int) ($planet['building_crystal_mine'] ?? 0);
        $deut = (int) ($planet['building_deuterium_sintetizer'] ?? 0);
        $solar = (int) ($planet['building_solar_plant'] ?? 0);
        $fusion = (int) ($planet['building_fusion_reactor'] ?? 0);
        $rf = (int) ($planet['building_robot_factory'] ?? 0);
        $hangar = (int) ($planet['building_hangar'] ?? 0);
        $lab = (int) ($planet['building_laboratory'] ?? 0);

        return $metal < 13 && $crystal < 12 && $deut < 10
            && $solar < 13 && $fusion < 5 && $rf < 5
            && $hangar < 5 && $lab < 5;
    }

    /**
     * Get the next deposit/storage to build (hours-based logic).
     *
     * Only builds storage when current capacity can't hold DEPOSIT_HOURS
     * worth of production, OR when storage is literally full (forceIfFull).
     */
    private function getNextDeposit(array $planet): ?int
    {
        $order = [
            Buildings::BUILDING_DEUTERIUM_TANK,
            Buildings::BUILDING_CRYSTAL_STORE,
            Buildings::BUILDING_METAL_STORE,
        ];

        foreach ($order as $storageId) {
            $level = $this->getBuildingLevel($storageId, $planet);
            if ($level >= 20) continue;

            if ($this->shouldBuildDeposit($storageId, $planet) && $this->canAfford($storageId, $level, $planet)) {
                return $storageId;
            }
        }

        return null;
    }

    /**
     * TBot-style deposit check: build if capacity < DEPOSIT_HOURS * hourly production
     * OR if resource is literally full (forceIfFull).
     */
    private function shouldBuildDeposit(int $storageId, array $planet): bool
    {
        $map = [
            Buildings::BUILDING_METAL_STORE    => ['resource' => 'planet_metal',      'prod' => 'metal'],
            Buildings::BUILDING_CRYSTAL_STORE  => ['resource' => 'planet_crystal',    'prod' => 'crystal'],
            Buildings::BUILDING_DEUTERIUM_TANK => ['resource' => 'planet_deuterium',  'prod' => 'deuterium'],
        ];

        $config = $map[$storageId] ?? null;
        if ($config === null) return false;

        $level = $this->getBuildingLevel($storageId, $planet);
        $capacity = $this->calcStorageCapacity($level);
        $hourlyProd = $this->calcHourlyProduction($planet, $config['prod']);
        $current = (float) ($planet[$config['resource']] ?? 0);

        // forceIfFull: storage is literally overflowing
        if ($current >= $capacity) {
            return true;
        }

        // DepositHours: capacity can't hold N hours of production
        if ($capacity < self::DEPOSIT_HOURS * $hourlyProd) {
            return true;
        }

        return false;
    }

    /**
     * Calculate storage capacity for a given level.
     * OGame formula: 5000 * floor(2.5 * e^(20*level/33))
     */
    private function calcStorageCapacity(int $level): float
    {
        return $this->productionService->maxStorable($level);
    }

    /**
     * Calculate approximate hourly resource production.
     */
    private function calcHourlyProduction(array $planet, string $resource): float
    {
        return match ($resource) {
            'metal' => ROICalculator::calcBuildingHourlyProduction($planet, Buildings::BUILDING_METAL_MINE),
            'crystal' => ROICalculator::calcBuildingHourlyProduction($planet, Buildings::BUILDING_CRYSTAL_MINE),
            'deuterium' => ROICalculator::calcBuildingHourlyProduction($planet, Buildings::BUILDING_DEUTERIUM_SINTETIZER),
            default => 0,
        };
    }

    // ─── Facility Selection ─────────────────────────────────────────

    /**
     * Get the next facility to build (smart gating).
     *
     * Robot Factory → Nanites → Shipyard → Research Lab — in order.
     * Only builds if: prereqs met, not at cap, and cheaper than next mine.
     */
    private function getNextFacility(array $planet, array $user, array $weights): ?int
    {
        $caps = [
            Buildings::BUILDING_ROBOT_FACTORY => 10,
            Buildings::BUILDING_NANO_FACTORY  => 5,
            Buildings::BUILDING_HANGAR        => 12,
            Buildings::BUILDING_LABORATORY    => 12,
        ];

        $priority = [
            Buildings::BUILDING_ROBOT_FACTORY,
            Buildings::BUILDING_NANO_FACTORY,
            Buildings::BUILDING_HANGAR,
            Buildings::BUILDING_LABORATORY,
        ];

        foreach ($priority as $facilityId) {
            $level = $this->getBuildingLevel($facilityId, $planet);
            $cap = $caps[$facilityId] ?? 20;

            if ($level >= $cap) continue;

            // Check prerequisites
            if (!$this->meetsBuildingPrerequisites($facilityId, $planet, $user)) continue;

            // Smart gate: only build if cheaper than the next mine
            $facilityCost = $this->getBuildingCostTotal($facilityId, $level);
            $nextMineCost = $this->getCheapestMineCost($planet, $weights);

            if ($facilityCost > $nextMineCost && $nextMineCost > 0) {
                continue; // Facility is more expensive than next mine — build mine first
            }

            // Check DOIR cap
            $doir = ROICalculator::calcBuildingDOIR($planet, $facilityId, $level);
            if ($doir > self::MAX_DOIR_DAYS) continue;

            if ($this->canAfford($facilityId, $level, $planet)) {
                return $facilityId;
            }
        }

        return null;
    }

    /**
     * Get the next facility in force mode (skip the smart gate).
     * Used as fallback when no mine is buildable.
     */
    private function getNextFacilityForce(array $planet, array $user, array $weights): ?int
    {
        $caps = [
            Buildings::BUILDING_ROBOT_FACTORY => 10,
            Buildings::BUILDING_NANO_FACTORY  => 5,
            Buildings::BUILDING_HANGAR        => 12,
            Buildings::BUILDING_LABORATORY    => 12,
            Buildings::BUILDING_SOLAR_PLANT   => 30,
        ];

        $priority = [
            Buildings::BUILDING_ROBOT_FACTORY,
            Buildings::BUILDING_NANO_FACTORY,
            Buildings::BUILDING_HANGAR,
            Buildings::BUILDING_LABORATORY,
            Buildings::BUILDING_SOLAR_PLANT,
        ];

        foreach ($priority as $buildingId) {
            $level = $this->getBuildingLevel($buildingId, $planet);
            $cap = $caps[$buildingId] ?? 20;

            if ($level >= $cap) continue;
            if (!$this->meetsBuildingPrerequisites($buildingId, $planet, $user)) continue;

            if ($this->canAfford($buildingId, $level, $planet)) {
                return $buildingId;
            }
        }

        return null;
    }

    /**
     * Check building prerequisites (e.g. Shipyard needs Robotics 2, Nanites needs Computer 10).
     */
    private function meetsBuildingPrerequisites(int $buildingId, array $planet, array $user): bool
    {
        return match ($buildingId) {
            Buildings::BUILDING_HANGAR =>
                ((int) ($planet['building_robot_factory'] ?? 0)) >= 2,
            Buildings::BUILDING_NANO_FACTORY =>
                ((int) ($planet['building_robot_factory'] ?? 0)) >= 10
                && ((int) ($user['research_computer_technology'] ?? 0)) >= 10,
            Buildings::BUILDING_TERRAFORMER =>
                ((int) ($user['research_energy_technology'] ?? 0)) >= 12,
            default => true, // No prereq
        };
    }

    // ─── Mine Selection (ROI-based) ─────────────────────────────────

    /**
     * Get the next mine to build using ROI — lowest DOIR wins.
     */
    private function getNextMine(array $planet, array $weights): ?int
    {
        $mineIds = [
            Buildings::BUILDING_METAL_MINE,
            Buildings::BUILDING_CRYSTAL_MINE,
            Buildings::BUILDING_DEUTERIUM_SINTETIZER,
        ];

        $caps = [
            Buildings::BUILDING_METAL_MINE        => 30,
            Buildings::BUILDING_CRYSTAL_MINE       => 30,
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => 30,
        ];

        $bestMine = null;
        $bestScore = PHP_FLOAT_MAX;

        foreach ($mineIds as $mineId) {
            $level = $this->getBuildingLevel($mineId, $planet);
            $cap = $caps[$mineId] ?? 30;

            if ($level >= $cap) continue;
            if (!$this->canAfford($mineId, $level, $planet)) continue;

            $doir = ROICalculator::calcBuildingDOIR($planet, $mineId, $level);
            if ($doir > self::MAX_DOIR_DAYS) continue;

            $weight = $weights[$mineId] ?? 1.0;
            $score = $doir / $weight;

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMine = $mineId;
            }
        }

        return $bestMine;
    }

    /**
     * Get the cheapest affordable mine upgrade cost.
     * Used for smart facility gating.
     */
    private function getCheapestMineCost(array $planet, array $weights): float
    {
        $cheapest = PHP_FLOAT_MAX;

        foreach ([Buildings::BUILDING_METAL_MINE, Buildings::BUILDING_CRYSTAL_MINE, Buildings::BUILDING_DEUTERIUM_SINTETIZER] as $mineId) {
            $level = $this->getBuildingLevel($mineId, $planet);
            if ($level >= 30) continue;
            if (!isset($weights[$mineId]) || $weights[$mineId] <= 0) continue;

            $cost = $this->getBuildingCostTotal($mineId, $level);
            if ($cost < $cheapest) {
                $cheapest = $cost;
            }
        }

        return $cheapest;
    }

    /**
     * Get total cost (metal + crystal + deut) for a building upgrade.
     */
    private function getBuildingCostTotal(int $buildingId, int $level): float
    {
        $cost = $this->getBuildingCost($buildingId, $level);
        return $cost['metal'] + $cost['crystal'] + $cost['deuterium'];
    }

    /**
     * Decide what ship or defense to build in the shipyard.
     *
     * @param  array<string, mixed>  $planet
     * @param  array<string, mixed>  $user
     *
     * @return array{ship_id: int, count: int}|null
     */
    /**
     * Maximum total ships per planet (existing + hangar queue + new request).
     * Prevents bots from spamming hundreds of cheap ships.
     */
    private const SHIP_CAPS = [
        210 => 10,   // Espionage Probe — 10 is plenty for scouting
        401 => 50,   // Rocket Launcher — cap for passive bots
        402 => 30,   // Light Laser
        403 => 15,   // Heavy Laser
        404 => 10,   // Gauss Cannon
        405 => 5,    // Ion Cannon
        502 => 3,    // Small Shield Dome
        212 => 5,    // Solar Satellite
        208 => 1,    // Colony Ship — only ever need 1
        209 => 3,    // Recycler
    ];

    public function nextShip(array $planet, array $user): ?array
    {
        $hangarLevel = (int) ($planet['building_hangar'] ?? 0);

        if ($hangarLevel < 1) {
            return null;
        }

        $personality = $this->getPersonality($user);

        // Analyze neighborhood threats for counter-building
        $threats = $this->threatAnalyzer->analyzeThreats($planet);

        // Use counter-build priority if threat is medium or high
        if ($threats['threat_level'] !== 'low' && $personality !== 'passive') {
            $shipPriority = $threats['ship_priority'];
        } else {
            $shipPriority = match ($personality) {
                'turtle'  => self::SHIP_PRIORITY_TURTLE,
                'passive' => self::SHIP_PRIORITY_PASSIVE,
                default   => self::SHIP_PRIORITY_RAIDER,
            };
        }

        // Parse hangar queue to count pending ships
        $hangarQueue = $this->parseHangarQueue($planet['planet_b_hangar_id'] ?? '');

        foreach ($shipPriority as $shipId => $minHangar) {
            if ($hangarLevel < $minHangar) {
                continue;
            }

            // Check ship cap (existing + queued + requested must not exceed cap)
            if (isset(self::SHIP_CAPS[$shipId])) {
                $shipColumn = $this->getShipColumn($shipId);
                $existing = (int) ($planet[$shipColumn] ?? 0);
                $queued = $hangarQueue[$shipId] ?? 0;
                $totalWithQueued = $existing + $queued;

                if ($totalWithQueued >= self::SHIP_CAPS[$shipId]) {
                    continue; // Already at cap, skip to next ship
                }
            }

            // Check if bot can afford at least 1
            $cost = $this->getShipCost($shipId);

            if ($cost === null) {
                continue;
            }

            $metal = (float) ($planet['planet_metal'] ?? 0);
            $crystal = (float) ($planet['planet_crystal'] ?? 0);
            $deuterium = (float) ($planet['planet_deuterium'] ?? 0);

            if ($metal >= $cost['metal'] && $crystal >= $cost['crystal'] && $deuterium >= $cost['deuterium']) {
                // Calculate how many we can afford
                $maxByMetal = $cost['metal'] > 0 ? (int) floor($metal / $cost['metal']) : PHP_INT_MAX;
                $maxByCrystal = $cost['crystal'] > 0 ? (int) floor($crystal / $cost['crystal']) : PHP_INT_MAX;
                $maxByDeuterium = $cost['deuterium'] > 0 ? (int) floor($deuterium / $cost['deuterium']) : PHP_INT_MAX;

                $count = min($maxByMetal, $maxByCrystal, $maxByDeuterium, 20); // Cap at 20 per tick

                // If ship has a total cap, limit count to remaining room
                if (isset(self::SHIP_CAPS[$shipId])) {
                    $remaining = self::SHIP_CAPS[$shipId] - $totalWithQueued;
                    $count = min($count, max(0, $remaining));
                }

                if ($count >= 1) {
                    return ['ship_id' => $shipId, 'count' => $count, 'cost' => $cost];
                }
            }
        }

        return null;
    }

    /**
     * Parse the hangar queue string (e.g. "210,20;202,5;") into ship_id => count.
     */
    private function parseHangarQueue(string $queue): array
    {
        $result = [];
        if (empty($queue)) {
            return $result;
        }

        $entries = explode(';', $queue);
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if (empty($entry)) {
                continue;
            }
            $parts = explode(',', $entry);
            if (count($parts) === 2) {
                $shipId = (int) $parts[0];
                $count = (int) $parts[1];
                $result[$shipId] = ($result[$shipId] ?? 0) + $count;
            }
        }

        return $result;
    }

    /**
     * Map ship ID to planet column name.
     */
    private function getShipColumn(int $shipId): string
    {
        $columns = [
            202 => 'ship_small_cargo_ship', 203 => 'ship_big_cargo_ship',
            204 => 'ship_light_fighter', 205 => 'ship_heavy_fighter',
            206 => 'ship_cruiser', 207 => 'ship_battleship',
            208 => 'ship_colony_ship', 209 => 'ship_recycler',
            210 => 'ship_espionage_probe', 211 => 'ship_bomber',
            212 => 'ship_solar_satellite', 213 => 'ship_destroyer',
            214 => 'ship_deathstar', 215 => 'ship_reaper',
        ];

        return $columns[$shipId] ?? "ship_{$shipId}";
    }

    /**
     * Decide what research to conduct next.
     *
     * Uses ROI-based selection — picks the research that gives the
     * best return on investment (including strategic value for tech
     * that doesn't directly produce resources).
     *
     * @param  array<string, mixed>  $user
     *
     * @return int|null Research ID or null
     */
    public function nextResearch(array $user, array $planet = []): ?int
    {
        // Already researching?
        if ((int) ($user['research_current_research'] ?? 0) > 0) {
            return null;
        }

        $bestResearch = null;
        $bestAffordableResearch = null;
        $bestScore = PHP_FLOAT_MAX;
        $bestAffordableScore = PHP_FLOAT_MAX;

        // All researchable techs
        $researchIds = [
            106, 108, 109, 110, 111, 113, 114, 115, 117, 118, 120, 121, 122, 124, 199,
        ];

        foreach ($researchIds as $researchId) {
            // Check prerequisites
            if (!$this->meetsResearchPrerequisites($researchId, $user)) {
                continue;
            }

            $currentLevel = (int) ($user['research_' . $this->getResearchColumn($researchId)] ?? 0);

            // Don't research past reasonable caps
            $maxLevel = match ($researchId) {
                106 => 12,   // Espionage
                108 => 10,   // Computer
                109 => 15,   // Weapons
                110 => 15,   // Shielding
                111 => 15,   // Armour
                113 => 12,   // Energy
                114 => 8,    // Hyperspace Tech
                115 => 15,   // Combustion
                117 => 15,   // Impulse
                118 => 10,   // Hyperspace Drive
                120 => 12,   // Laser
                121 => 10,   // Ionic
                122 => 10,   // Plasma
                124 => 8,    // Astrophysics (8 colonies max for bots)
                199 => 1,    // Graviton
                default => 10,
            };

            if ($currentLevel >= $maxLevel) {
                continue;
            }

            // Calculate DOIR
            $doir = ROICalculator::calcResearchDOIR($user, $planet, $researchId);

            // Track best overall (for reference)
            if ($doir < $bestScore) {
                $bestScore = $doir;
                $bestResearch = $researchId;
            }

            // Track best AFFORDABLE — this is what we actually return.
            // Prevents deadlock: bot defers building for research it can't afford,
            // resources sit idle, nothing happens for hours.
            if (!empty($planet) && $doir < $bestAffordableScore) {
                $cost = $this->getResearchCost($researchId, $currentLevel);
                if ($cost !== null) {
                    $metal = (float) ($planet['planet_metal'] ?? 0);
                    $crystal = (float) ($planet['planet_crystal'] ?? 0);
                    $deuterium = (float) ($planet['planet_deuterium'] ?? 0);

                    if ($metal >= $cost['metal'] && $crystal >= $cost['crystal'] && $deuterium >= $cost['deuterium']) {
                        $bestAffordableScore = $doir;
                        $bestAffordableResearch = $researchId;
                    }
                }
            }
        }

        // Return affordable research if available, otherwise null
        // (null means building system takes over instead of deadlocking)
        return $bestAffordableResearch;
    }

    /**
     * Check if a bot meets the prerequisites for a research.
     *
     * Prereq map from GameObjectRegistry (Lab level not checked here —
     * game engine rejects if lab is too low).
     */
    private function meetsResearchPrerequisites(int $researchId, array $user): bool
    {
        $requirements = [
            106 => [],                          // Espionage Tech — no prereqs (Lab 3)
            108 => [],                          // Computer Tech — no prereqs (Lab 1)
            109 => [],                          // Weapons Tech — no prereqs (Lab 4)
            110 => [113 => 3],                  // Shielding Tech — Energy 3 (Lab 6)
            111 => [],                          // Armour Tech — no prereqs (Lab 2)
            113 => [],                          // Energy Tech — no prereqs (Lab 1)
            114 => [113 => 5, 110 => 5],        // Hyperspace Tech — Energy 5, Shielding 5 (Lab 7)
            115 => [113 => 1],                  // Combustion Drive — Energy 1 (Lab 1)
            117 => [113 => 1],                  // Impulse Drive — Energy 1 (Lab 2)
            118 => [114 => 3],                  // Hyperspace Drive — Hyperspace Tech 3 (Lab 7)
            120 => [113 => 2],                  // Laser Tech — Energy 2 (Lab 1)
            121 => [113 => 4, 120 => 5],        // Ionic Tech — Energy 4, Laser 5 (Lab 4)
            122 => [113 => 8, 120 => 10, 121 => 5],  // Plasma Tech — Energy 8, Laser 10, Ionic 5 (Lab 5)
            124 => [106 => 4, 117 => 3],        // Astrophysics — Espionage 4, Impulse 3 (Lab 3)
            199 => [],                          // Graviton — no prereqs (Lab 12)
        ];

        $reqs = $requirements[$researchId] ?? [];

        foreach ($reqs as $reqId => $reqLevel) {
            $currentLevel = (int) ($user['research_' . $this->getResearchColumn($reqId)] ?? 0);
            if ($currentLevel < $reqLevel) {
                return false;
            }
        }

        return true;
    }

    /**
     * Decide whether to attack a target.
     *
     * @param  array<string, mixed>  $botPlanet
     * @param  array<string, mixed>  $botUser
     * @param  array{resources: int, defense_strength: int, distance: int}  $target
     *
     * @return array<int, int>|null Ship fleet to send, or null if shouldn't attack
     */
    public function planAttack(array $botPlanet, array $botUser, array $target): ?array
    {
        $personality = $this->getPersonality($botUser);

        if ($personality === 'passive') {
            return null;
        }

        // Calculate available combat ships
        $availableShips = $this->getAvailableCombatShips($botPlanet);

        if (empty($availableShips)) {
            return null;
        }

        // Build the attack fleet
        $fleet = [];

        // Send light fighters first (cheapest combat ship)
        if (isset($availableShips[204]) && $availableShips[204] > 0) {
            $fleet[204] = min($availableShips[204], 50);
        }

        // Calculate required loot capacity (we want to steal as much as possible, up to 100% just in case)
        $lootCapacityNeeded = (int) ($target['resources'] ?? 0);
        
        // Try to add Large Cargos first (25,000 capacity each)
        if ($lootCapacityNeeded > 0 && isset($availableShips[203]) && $availableShips[203] > 0) {
            $largeCargosNeeded = (int) ceil($lootCapacityNeeded / 25000);
            $largeCargosToSend = min($availableShips[203], $largeCargosNeeded);
            
            if ($largeCargosToSend > 0) {
                $fleet[203] = $largeCargosToSend;
                $lootCapacityNeeded -= ($largeCargosToSend * 25000);
            }
        }

        // Fill remaining need with Small Cargos (5,000 capacity each)
        if ($lootCapacityNeeded > 0 && isset($availableShips[202]) && $availableShips[202] > 0) {
            $smallCargosNeeded = (int) ceil($lootCapacityNeeded / 5000);
            $smallCargosToSend = min($availableShips[202], $smallCargosNeeded);
            
            if ($smallCargosToSend > 0) {
                $fleet[202] = $smallCargosToSend;
            }
        }

        // If we have heavy fighters, add some
        if (isset($availableShips[205]) && $availableShips[205] > 0) {
            $fleet[205] = min($availableShips[205], 10);
        }

        // Add cruisers if available
        if (isset($availableShips[206]) && $availableShips[206] > 0) {
            $fleet[206] = min($availableShips[206], 5);
        }

        if (empty($fleet)) {
            return null;
        }

        // Check fuel affordability before simulation
        $fuel = $this->estimateFuel($fleet, $botPlanet, $botUser, $target);

        if ($fuel > 0 && (float) ($botPlanet['planet_deuterium'] ?? 0) < $fuel * 2) {
            return null; // Not enough fuel (keep 2x reserve)
        }

        // Use the REAL battle simulator instead of rough power check
        $defenderPlanet = $target['planet_data'] ?? [];

        if (empty($defenderPlanet)) {
            return null; // No defender data available
        }

        $defenderUser = [
            'research_weapons_technology' => (int) ($defenderPlanet['research_weapons_technology'] ?? 0),
            'research_shielding_technology' => (int) ($defenderPlanet['research_shielding_technology'] ?? 0),
            'research_armour_technology' => (int) ($defenderPlanet['research_armour_technology'] ?? 0),
        ];

        try {
            $simResult = $this->simulator->simulate($fleet, $defenderPlanet, $botUser, $defenderUser);
        } catch (\Throwable $e) {
            return null;
        }

        // Only attack if we win and losses are acceptable
        $attackerInitial = array_sum($fleet);
        $lossRate = $attackerInitial > 0 ? $simResult['attacker_losses'] / $attackerInitial : 1;

        // Personality-based loss tolerance
        $maxLossRate = match ($personality) {
            'raider' => 0.7,   // Raiders accept 70% losses for good loot
            'turtle' => 0.3,   // Turtles only attack if they'll barely lose anything
            'balanced' => 0.5, // Balanced: up to 50% losses
            default => 0.5,
        };

        // Resource pressure: if bot can't afford next build soon, take riskier fights
        if ($this->isUnderResourcePressure($botPlanet)) {
            $maxLossRate = min($maxLossRate + 0.2, 0.95); // Up to 95% losses when desperate
        }

        if ($simResult['winner'] !== 'attacker' || $lossRate > $maxLossRate) {
            return null;
        }

        return $fleet;
    }

    /**
     * Check if a bot is under resource pressure (can't afford next build soon).
     * Under-pressure bots take riskier fights to get resources.
     *
     * @param  array<string, mixed>  $planet
     */
    private function isUnderResourcePressure(array $planet): bool
    {
        $metal = (float) ($planet['planet_metal'] ?? 0);
        $crystal = (float) ($planet['planet_crystal'] ?? 0);
        $deuterium = (float) ($planet['planet_deuterium'] ?? 0);
        $totalResources = $metal + $crystal + $deuterium;

        // Calculate approximate hourly income from mines
        $metalLevel = (int) ($planet['building_metal_mine'] ?? 0);
        $crystalLevel = (int) ($planet['building_crystal_mine'] ?? 0);
        $deutLevel = (int) ($planet['building_deuterium_sintetizer'] ?? 0);

        // Rough hourly income estimate (base * level * 1.1^level * speed factor)
        $hourlyIncome = 0;
        if ($metalLevel > 0) $hourlyIncome += 30 * $metalLevel * pow(1.1, $metalLevel) * 30;
        if ($crystalLevel > 0) $hourlyIncome += 20 * $crystalLevel * pow(1.1, $crystalLevel) * 30;
        if ($deutLevel > 0) $hourlyIncome += 10 * $deutLevel * pow(1.1, $deutLevel) * 30;

        if ($hourlyIncome <= 0) {
            return false; // Can't calculate, assume not under pressure
        }

        // If total resources are less than 5 hours of production, bot needs to raid
        return $totalResources < ($hourlyIncome * 5);
    }

    /**
     * Get available combat ships on a planet.
     *
     * @param  array<string, mixed>  $planet
     * @return array<int, int>
     */
    private function getAvailableCombatShips(array $planet): array
    {
        $combatShips = [
            202 => (int) ($planet['ship_small_cargo_ship'] ?? 0),
            203 => (int) ($planet['ship_big_cargo_ship'] ?? 0),
            204 => (int) ($planet['ship_light_fighter'] ?? 0),
            205 => (int) ($planet['ship_heavy_fighter'] ?? 0),
            206 => (int) ($planet['ship_cruiser'] ?? 0),
            207 => (int) ($planet['ship_battleship'] ?? 0),
            211 => (int) ($planet['ship_bomber'] ?? 0),
            213 => (int) ($planet['ship_destroyer'] ?? 0),
            214 => (int) ($planet['ship_deathstar'] ?? 0),
            215 => (int) ($planet['ship_reaper'] ?? 0),
        ];

        return array_filter($combatShips, fn ($count) => $count > 0);
    }

    /**
     * Calculate the combat strength of a fleet.
     *
     * @param  array<int, int>  $ships
     */
    private function calculateFleetStrength(array $ships): int
    {
        $power = [
            202 => 5,    // Small Cargo
            203 => 5,    // Large Cargo
            204 => 50,   // Light Fighter
            205 => 150,  // Heavy Fighter
            206 => 400,  // Cruiser
            207 => 1000, // Battleship
            208 => 50,   // Colony Ship
            209 => 1,    // Recycler
            210 => 0,    // Espionage Probe
            211 => 1000, // Bomber
            213 => 2000, // Destroyer
            214 => 200000, // Deathstar
            215 => 2800, // Reaper
        ];

        $total = 0;

        foreach ($ships as $shipId => $count) {
            $total += ($power[$shipId] ?? 0) * $count;
        }

        return $total;
    }

    /**
     * Rough fuel estimate for an attack fleet.
     *
     * @param  array<int, int>  $ships
     * @param  array<string, mixed>  $planet
     * @param  array<string, mixed>  $user
     * @param  array{distance: int}  $target
     */
    private function estimateFuel(array $ships, array $planet, array $user, array $target): int
    {
        $consumption = [
            202 => 10, 203 => 50, 204 => 20, 205 => 75,
            206 => 300, 207 => 500, 210 => 1,
        ];

        $totalConsumption = 0;

        foreach ($ships as $shipId => $count) {
            $totalConsumption += ($consumption[$shipId] ?? 50) * $count;
        }

        // Rough estimate: consumption * distance / 35000
        return (int) ($totalConsumption * $target['distance'] / 35000 + 1);
    }

    /**
     * Get the current level of a building on a planet.
     *
     * @param  array<string, mixed>  $planet
     */
    public function getBuildingLevel(int $buildingId, array $planet): int
    {
        $columns = [
            Buildings::BUILDING_METAL_MINE       => 'building_metal_mine',
            Buildings::BUILDING_CRYSTAL_MINE      => 'building_crystal_mine',
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => 'building_deuterium_sintetizer',
            Buildings::BUILDING_SOLAR_PLANT       => 'building_solar_plant',
            Buildings::BUILDING_FUSION_REACTOR    => 'building_fusion_reactor',
            Buildings::BUILDING_ROBOT_FACTORY     => 'building_robot_factory',
            Buildings::BUILDING_NANO_FACTORY      => 'building_nano_factory',
            Buildings::BUILDING_HANGAR            => 'building_hangar',
            Buildings::BUILDING_METAL_STORE       => 'building_metal_store',
            Buildings::BUILDING_CRYSTAL_STORE     => 'building_crystal_store',
            Buildings::BUILDING_DEUTERIUM_TANK    => 'building_deuterium_tank',
            Buildings::BUILDING_LABORATORY        => 'building_laboratory',
            Buildings::BUILDING_TERRAFORMER       => 'building_terraformer',
            Buildings::BUILDING_ALLY_DEPOSIT      => 'building_ally_deposit',
            Buildings::BUILDING_MISSILE_SILO      => 'building_missile_silo',
            Buildings::BUILDING_MONDBASIS         => 'building_mondbasis',
            Buildings::BUILDING_PHALANX           => 'building_phalanx',
            Buildings::BUILDING_JUMP_GATE         => 'building_jump_gate',
        ];

        $column = $columns[$buildingId] ?? null;

        return $column ? (int) ($planet[$column] ?? 0) : 0;
    }

    /**
     * @param  array<string, mixed>  $planet
     */
    private function canAfford(int $buildingId, int $currentLevel, array $planet): bool
    {
        $cost = $this->getBuildingCost($buildingId, $currentLevel);

        $metal = (float) ($planet['planet_metal'] ?? 0);
        $crystal = (float) ($planet['planet_crystal'] ?? 0);
        $deuterium = (float) ($planet['planet_deuterium'] ?? 0);

        return $metal >= $cost['metal']
            && $crystal >= $cost['crystal']
            && $deuterium >= $cost['deuterium'];
    }

    /**
     * @return array{metal: float, crystal: float, deuterium: float}
     */
    private function getBuildingCost(int $buildingId, int $currentLevel): array
    {
        $baseCosts = [
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
            Buildings::BUILDING_TERRAFORMER       => ['metal' => 0,     'crystal' => 50000, 'deuterium' => 100000, 'factor' => 2.0],
            Buildings::BUILDING_ALLY_DEPOSIT      => ['metal' => 20000, 'crystal' => 40000, 'deuterium' => 0,     'factor' => 2.0],
            Buildings::BUILDING_MISSILE_SILO      => ['metal' => 20000, 'crystal' => 20000, 'deuterium' => 1000,  'factor' => 2.0],
            Buildings::BUILDING_MONDBASIS         => ['metal' => 20000, 'crystal' => 40000, 'deuterium' => 20000, 'factor' => 2.0],
            Buildings::BUILDING_PHALANX           => ['metal' => 20000, 'crystal' => 40000, 'deuterium' => 20000, 'factor' => 2.0],
            Buildings::BUILDING_JUMP_GATE         => ['metal' => 2000000, 'crystal' => 4000000, 'deuterium' => 2000000, 'factor' => 2.0],
        ];

        $base = $baseCosts[$buildingId] ?? ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'factor' => 2.0];
        $factor = $base['factor'];

        return [
            'metal'     => round($base['metal'] * pow($factor, $currentLevel)),
            'crystal'   => round($base['crystal'] * pow($factor, $currentLevel)),
            'deuterium' => round($base['deuterium'] * pow($factor, $currentLevel)),
        ];
    }

    /**
     * Get ship cost by ID.
     *
     * @return array{metal: int, crystal: int, deuterium: int}|null
     */
    private function getShipCost(int $shipId): ?array
    {
        $costs = [
            202 => ['metal' => 2000,  'crystal' => 2000,  'deuterium' => 0],
            203 => ['metal' => 6000,  'crystal' => 6000,  'deuterium' => 0],
            204 => ['metal' => 3000,  'crystal' => 1000,  'deuterium' => 0],
            205 => ['metal' => 6000,  'crystal' => 4000,  'deuterium' => 0],
            206 => ['metal' => 20000, 'crystal' => 7000,  'deuterium' => 2000],
            207 => ['metal' => 45000, 'crystal' => 15000, 'deuterium' => 0],
            208 => ['metal' => 10000, 'crystal' => 20000, 'deuterium' => 10000],
            209 => ['metal' => 10000, 'crystal' => 6000,  'deuterium' => 2000],
            210 => ['metal' => 0,     'crystal' => 1000,  'deuterium' => 0],
            211 => ['metal' => 50000, 'crystal' => 25000, 'deuterium' => 15000],
            212 => ['metal' => 0,     'crystal' => 2000,  'deuterium' => 500],
            213 => ['metal' => 60000, 'crystal' => 50000, 'deuterium' => 15000],
            214 => ['metal' => 5000000, 'crystal' => 4000000, 'deuterium' => 1000000],
            215 => ['metal' => 85000, 'crystal' => 55000, 'deuterium' => 20000],
            // Defenses
            401 => ['metal' => 2000,  'crystal' => 0,     'deuterium' => 0],
            402 => ['metal' => 1500,  'crystal' => 500,   'deuterium' => 0],
            403 => ['metal' => 6000,  'crystal' => 2000,  'deuterium' => 0],
            404 => ['metal' => 20000, 'crystal' => 15000, 'deuterium' => 2000],
            405 => ['metal' => 2000,  'crystal' => 6000,  'deuterium' => 0],
            406 => ['metal' => 50000, 'crystal' => 50000, 'deuterium' => 30000],
            502 => ['metal' => 10000, 'crystal' => 10000, 'deuterium' => 0],
        ];

        return $costs[$shipId] ?? null;
    }

    /**
     * Get research cost by ID and level.
     *
     * @return array{metal: float, crystal: float, deuterium: float}|null
     */
    private function getResearchCost(int $researchId, int $level): ?array
    {
        $baseCosts = [
            106 => ['metal' => 200,   'crystal' => 1000,  'deuterium' => 200,   'factor' => 2.0],
            108 => ['metal' => 0,     'crystal' => 400,   'deuterium' => 600,   'factor' => 2.0],
            109 => ['metal' => 800,   'crystal' => 200,   'deuterium' => 0,     'factor' => 2.0],
            110 => ['metal' => 200,   'crystal' => 600,   'deuterium' => 0,     'factor' => 2.0],
            111 => ['metal' => 1000,  'crystal' => 0,     'deuterium' => 0,     'factor' => 2.0],
            113 => ['metal' => 0,     'crystal' => 800,   'deuterium' => 400,   'factor' => 2.0],
            115 => ['metal' => 400,   'crystal' => 0,     'deuterium' => 600,   'factor' => 2.0],
            117 => ['metal' => 2000,  'crystal' => 4000,  'deuterium' => 600,   'factor' => 2.0],
            118 => ['metal' => 10000, 'crystal' => 20000, 'deuterium' => 6000,  'factor' => 2.0],
            120 => ['metal' => 200,   'crystal' => 100,   'deuterium' => 0,     'factor' => 2.0],
            121 => ['metal' => 1000,  'crystal' => 300,   'deuterium' => 100,   'factor' => 2.0],
            122 => ['metal' => 2000,  'crystal' => 4000,  'deuterium' => 1000,  'factor' => 2.0],
        ];

        $base = $baseCosts[$researchId] ?? null;

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
    private function getResearchColumn(int $researchId): string
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
     * @param  array<string, mixed>  $planet
     */
    private function needsStorage(int $buildingId, array $planet): bool
    {
        $storageMap = [
            Buildings::BUILDING_METAL_STORE    => ['resource' => 'planet_metal',      'store' => 'building_metal_store'],
            Buildings::BUILDING_CRYSTAL_STORE  => ['resource' => 'planet_crystal',    'store' => 'building_crystal_store'],
            Buildings::BUILDING_DEUTERIUM_TANK => ['resource' => 'planet_deuterium',  'store' => 'building_deuterium_tank'],
        ];

        if (!isset($storageMap[$buildingId])) {
            return false;
        }

        $map = $storageMap[$buildingId];
        $current = (float) ($planet[$map['resource']] ?? 0);
        $storeLevel = (int) ($planet[$map['store']] ?? 0);
        $max = $this->productionService->maxStorable($storeLevel);

        return $current > ($max * 0.9);
    }
}

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
class BotBrainROI
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
     * Decide what building the bot should construct next.
     *
     * Uses ROI (Return on Investment) calculation — picks the building
     * that pays for itself fastest instead of following a fixed order.
     *
     * @param  array<string, mixed>  $planet
     * @param  array<string, mixed>  $user
     */
    public function nextBuilding(array $planet, array $user): ?int
    {
        $personality = $this->getPersonality($user);

        // Energy check — always prioritize solar plant if energy is negative
        $energyMax = (int) ($planet['planet_energy_max'] ?? 0);
        $energyUsed = (int) ($planet['planet_energy_used'] ?? 0);

        if ($energyMax > 0 && ($energyMax + $energyUsed) < 0) {
            $solarLevel = (int) ($planet['building_solar_plant'] ?? 0);

            if ($solarLevel < 30 && $this->canAfford(Buildings::BUILDING_SOLAR_PLANT, $solarLevel, $planet)) {
                return Buildings::BUILDING_SOLAR_PLANT;
            }
        }

        // Storage check — build if storage is >90% full (critical override)
        $storageIds = [
            Buildings::BUILDING_METAL_STORE,
            Buildings::BUILDING_CRYSTAL_STORE,
            Buildings::BUILDING_DEUTERIUM_TANK,
        ];

        foreach ($storageIds as $storageId) {
            if ($this->needsStorage($storageId, $planet)) {
                $level = $this->getBuildingLevel($storageId, $planet);
                if ($this->canAfford($storageId, $level, $planet)) {
                    return $storageId;
                }
            }
        }

        // ROI-based building selection
        $bestBuilding = null;
        $bestScore = PHP_FLOAT_MAX; // Lower is better (shorter payback)

        $caps = [
            Buildings::BUILDING_METAL_MINE        => 30,
            Buildings::BUILDING_CRYSTAL_MINE       => 30,
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => 30,
            Buildings::BUILDING_SOLAR_PLANT        => 30,
            Buildings::BUILDING_ROBOT_FACTORY      => 10,
            Buildings::BUILDING_HANGAR             => 12,
            Buildings::BUILDING_LABORATORY         => 12,
            Buildings::BUILDING_NANO_FACTORY       => 5,
        ];

        $weights = self::BUILDING_WEIGHTS[$personality] ?? self::BUILDING_WEIGHTS['raider'];

        foreach ($weights as $buildingId => $personalityWeight) {
            if ($personalityWeight <= 0) {
                continue; // Personality explicitly deprioritizes this
            }

            $level = $this->getBuildingLevel($buildingId, $planet);
            $cap = $caps[$buildingId] ?? 20;

            if ($level >= $cap) {
                continue;
            }

            // Skip if can't afford
            if (!$this->canAfford($buildingId, $level, $planet)) {
                continue;
            }

            // Calculate ROI score: DOIR ÷ personality weight
            $doir = ROICalculator::calcBuildingDOIR($planet, $buildingId, $level);
            $score = $doir / $personalityWeight;

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestBuilding = $buildingId;
            }
        }

        return $bestBuilding;
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
        $bestScore = PHP_FLOAT_MAX;

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

            if ($doir < $bestScore) {
                $bestScore = $doir;
                $bestResearch = $researchId;
            }
        }

        return $bestResearch;
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

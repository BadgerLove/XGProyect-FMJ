<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Core\GameObjects\GameObjectRegistry;
use App\Services\Bot\ThreatAnalyzer;
use App\Services\Game\DevelopmentDataService;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\Game\Formulas\ProductionService;
use Xgp\App\Core\Enumerators\BuildingsEnumerator as Buildings;

/**
 * Bot decision engine: buildings, research, shipyard and attack planning.
 *
 * Phase 2 (30 Sep 2026) rebuilt the economy half so a bot goes from a fresh start to end-game:
 *  - every requirement and price comes from the game itself (DevelopmentsService /
 *    DevelopmentDataService); the old hand-copied tables had drifted (storage 2x, Fusion without
 *    its Deuterium 5 requirement, "502" used as a shield dome when it is the anti-ballistic missile);
 *  - colonisation is the early-game spine (research walk to Astrophysics, Shipyard 4, colony ship);
 *  - building is field-aware and keeps two fields back for Nanite + Terraformer, the way out of a
 *    full planet (314 of 365 homes were full with no way out on 29 Sep);
 *  - research follows a goal ladder through the tech tree and is saved for, instead of only
 *    buying what the shipyard left over;
 *  - the shipyard builds the best ship it may legally build, after the colony-ship, probe,
 *    recycler and cargo floors.
 *
 * Personalities: raider, balanced, turtle, passive.
 */
class BotBrain
{
    // ─── Buildings ───────────────────────────────────────────────────────────

    /** Mine weights per personality (divide the mine's days-to-pay-back; higher = preferred). */
    private const BUILDING_WEIGHTS = [
        'raider' => [
            Buildings::BUILDING_METAL_MINE           => 1.2,
            Buildings::BUILDING_CRYSTAL_MINE         => 1.4,
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => 0.7,
        ],
        'balanced' => [
            Buildings::BUILDING_METAL_MINE           => 1.2,
            Buildings::BUILDING_CRYSTAL_MINE         => 1.4,
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => 0.8,
        ],
        'turtle' => [
            Buildings::BUILDING_METAL_MINE           => 1.2,
            Buildings::BUILDING_CRYSTAL_MINE         => 1.4,
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => 0.8,
        ],
        'passive' => [
            Buildings::BUILDING_METAL_MINE           => 1.5,
            Buildings::BUILDING_CRYSTAL_MINE         => 1.5,
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => 1.2,
        ],
    ];

    /** Highest level the brain will take each building to. */
    private const BUILDING_CAPS = [
        Buildings::BUILDING_METAL_MINE           => 35,
        Buildings::BUILDING_CRYSTAL_MINE         => 32,
        Buildings::BUILDING_DEUTERIUM_SINTETIZER => 32,
        Buildings::BUILDING_SOLAR_PLANT          => 30,
        Buildings::BUILDING_FUSION_REACTOR       => 22,
        Buildings::BUILDING_ROBOT_FACTORY        => 10,
        Buildings::BUILDING_NANO_FACTORY         => 7,
        Buildings::BUILDING_HANGAR               => 12,
        Buildings::BUILDING_LABORATORY           => 12,
        Buildings::BUILDING_METAL_STORE          => 14,
        Buildings::BUILDING_CRYSTAL_STORE        => 14,
        Buildings::BUILDING_DEUTERIUM_TANK       => 14,
        Buildings::BUILDING_TERRAFORMER          => 12,
    ];

    /**
     * Moon build order (strict: wait for the next one rather than skip to a cheaper one), but a
     * building the moon may never have is skipped. The Missile Silo is gone from the list: it
     * needs a Shipyard, which moons never get, so the old order would have frozen every moon at it.
     *
     * @var array<int, array{cap: int, min_rf: int}>
     */
    public const MOON_BUILDING_PRIORITY = [
        Buildings::BUILDING_MONDBASIS     => ['cap' => 10, 'min_rf' => 0],  // Lunar Base — fields
        Buildings::BUILDING_ROBOT_FACTORY => ['cap' => 10, 'min_rf' => 0],  // Robot Factory — build speed
        Buildings::BUILDING_PHALANX       => ['cap' => 5,  'min_rf' => 0],  // Sensor Phalanx — watch neighbours
        Buildings::BUILDING_JUMP_GATE     => ['cap' => 1,  'min_rf' => 0],  // Jump Gate — needs Hyperspace Tech 7
    ];

    /** Fields held back for Nanite Factory and Terraformer while they are still missing. */
    private const RESERVED_FIELDS_NANITE = 1;
    private const RESERVED_FIELDS_TERRAFORMER = 1;

    /** Below this many usable fields only the essentials get built (no storage/extra facilities). */
    private const TIGHT_FIELDS = 12;

    /** Returned by saveOrFix(): the item can't be reached soon, so don't save for it; carry on down the chain. */
    private const FALL_THROUGH = -1;

    /** Save for something only if the planet's income reaches it within this many hours. */
    private const MAX_SAVE_HOURS = 96.0;

    /** Research (or the colony ship) the income reaches within this many hours (x5 terms) is saved for first. */
    private const RESEARCH_FIRST_HOURS = 6.0;

    /** Saving longer than this: build from the surplus the saved-for price doesn't need. */
    private const SPEND_WHILE_SAVING_HOURS = 0.2;

    /** How many hours of production storage should hold. */
    private const DEPOSIT_HOURS = 12;

    /** Buildings that pay back slower than this (days) are not worth it. */
    private const MAX_DOIR_DAYS = 90.0;

    // ─── Research ────────────────────────────────────────────────────────────

    /**
     * The research goal ladder, walked in order. A goal whose requirement is missing becomes that
     * requirement (research), or a Lab request for the research planet. The first rungs are the
     * colonisation spine: Astrophysics 1 needs Espionage 4 + Impulse 3 + Lab 3.
     *
     * @var list<array{0: int, 1: int}>  [tech id, target level]
     */
    private const RESEARCH_GOALS = [
        [113, 1], [115, 2], [108, 1], [106, 2], [115, 3], [117, 3], [106, 4], [124, 1],   // cargos + a 4th fleet slot, first colony
        [108, 2], [124, 3],                                                      // second colony
        [113, 3], [110, 2], [115, 6], [108, 4], [124, 5],                       // recyclers, large cargo, 3rd colony
        [111, 3], [109, 3], [117, 4], [120, 5], [121, 2], [113, 6],             // cruisers
        [124, 7], [110, 5], [113, 8], [114, 5], [118, 4], [106, 6],             // battleships, 4th colony
        [108, 10], [113, 12],                                                    // Nanite + Terraformer
        [124, 9], [109, 8], [110, 8], [111, 8], [120, 10], [121, 5], [122, 5], [117, 6], // bombers, 5th colony
        [118, 6], [114, 8], [120, 12], [118, 7], [124, 11],                      // destroyers, battlecruisers
        [109, 12], [110, 12], [111, 12], [122, 7], [115, 12], [117, 10], [124, 13], [108, 12],
    ];

    /** Goals the walk may look past one that waits for the Lab. */
    private const RESEARCH_LOOKAHEAD = 4;

    /** Research caps for the DOIR fallback once the ladder is done. */
    private const RESEARCH_CAPS = [
        106 => 12, 108 => 14, 109 => 16, 110 => 16, 111 => 16, 113 => 16, 114 => 12, 115 => 16,
        117 => 14, 118 => 12, 120 => 14, 121 => 10, 122 => 12, 124 => 13,
    ];

    // ─── Shipyard ────────────────────────────────────────────────────────────

    /**
     * Combat lists, BEST FIRST: the shipyard buys the first one it may build and afford. Until
     * 30 Sep the raider list ran cheapest-first and the loop took the first affordable entry, so
     * raiders bought Light Fighters for ever.
     */
    private const COMBAT_SHIPS = [
        'raider'   => [215, 213, 207, 211, 206, 205, 204],
        'balanced' => [213, 215, 207, 206, 211, 205, 204],
        'turtle'   => [207, 206, 205, 204],
    ];

    /** Defence lists, best first (407/408 = the real shield domes; 502 was the ABM). */
    private const DEFENCES = [
        'turtle'  => [406, 404, 405, 403, 402, 401],
        'passive' => [405, 403, 402, 401],
    ];

    /** Share of the spendable (above-reserve) resources combat/defence may use per tick. */
    private const COMBAT_SPEND_SHARE = [
        'raider' => 0.4, 'balanced' => 0.3, 'turtle' => 0.3, 'passive' => 0.2,
    ];

    /**
     * Warships + defences (ship and defence cost / 1000, cargos, probes, recyclers and satellites not
     * counted) as a share of the bot's total points it aims for. Below it, military spend ignores
     * the reserve.
     */
    private const MILITARY_TARGET = ['raider' => 0.30, 'balanced' => 0.22, 'turtle' => 0.25, 'passive' => 0.12];

    /** Military spend multiplier until the first colony exists. */
    private const ECONOMY_FIRST_SHARE = 0.5;

    /** Turtles put this share of their military spend into defence (rest into ships). */
    private const TURTLE_DEFENCE_SHARE = 0.6;

    /** Hard caps (owned + queued) for support ships. */
    private const SHIP_CAPS = [
        210 => 30,   // Espionage Probe
        212 => 400,  // Solar Satellite
        208 => 1,    // Colony Ship (one at a time)
        209 => 40,   // Recycler
        407 => 1,    // Small Shield Dome (the game allows one)
        408 => 1,    // Large Shield Dome
    ];

    private const PROBE_FLOOR = 15;
    private const PROBE_FLOOR_QUIET = 5;   // turtles + passive: enough to see who is coming
    private const PROBE_START = 6;         // bought ahead of the reserve
    private const RECYCLER_FLOOR = 10;
    /** Small cargos (raids, expeditions, colony kits) until Large Cargos are allowed. */
    private const SMALL_CARGO_FLOOR = ['raider' => 16, 'balanced' => 12, 'turtle' => 8, 'passive' => 6];

    /** The first small cargos on the main planet, bought ahead of what the planet saves for. */
    private const SMALL_CARGO_START = ['raider' => 5, 'balanced' => 4, 'turtle' => 3, 'passive' => 2];

    private const LARGE_CARGO_FLOOR = 10;

    /** Per-tick ceiling on one shipyard order, whatever the budget. */
    private const MAX_ORDER = 2000;

    // ─── State ───────────────────────────────────────────────────────────────

    /**
     * When true, affordability checks pass — wantedBuildingCost() uses it to ask "what would you
     * build if money were no object?" without duplicating the selection logic.
     */
    private bool $ignoreAffordability = false;

    /** Lab level the last researchPlan() needed on the research planet (0 = none). */
    public int $lastLabNeeded = 0;

    /** Why the last nextBuilding() returned what it did (for tick output / debugging). */
    public string $lastBuildingReason = '';

    public function __construct(
        private readonly ProductionService $productionService,
        private readonly ThreatAnalyzer $threatAnalyzer,
        private readonly BattleSimulator $simulator,
        private readonly DevelopmentsService $developments,
        private readonly DevelopmentDataService $developmentData,
        private readonly GameObjectRegistry $registry,
    ) {
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Game rules (the game's own services — never hand-copied tables)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Object id => level for this planet + user (buildings, ships, defences from the planet,
     * research from the user).
     *
     * @param  array<string, mixed>  $planet
     * @param  array<string, mixed>  $user
     * @return array<int, int>
     */
    public function levels(array $planet, array $user): array
    {
        return $this->developmentData->levelsFromData($planet, $user);
    }

    /** Does the game allow building / researching $id here? */
    public function allowed(int $id, array $planet, array $user): bool
    {
        return $this->developments->isDevelopmentAllowed($id, $this->levels($planet, $user));
    }

    /**
     * Price of taking $id from $level to $level + 1 (ships/defences: one unit).
     *
     * @return array{metal: float, crystal: float, deuterium: float}
     */
    public function price(int $id, int $level = 0): array
    {
        $isUnit = $id >= 200 && $id < 600;
        $cost = $this->developments->developmentPrice($id, $level, !$isUnit);

        return [
            'metal' => (float) ($cost['metal'] ?? 0),
            'crystal' => (float) ($cost['crystal'] ?? 0),
            'deuterium' => (float) ($cost['deuterium'] ?? 0),
        ];
    }

    /** Fields the planet can hold (field_max + 5 per Terraformer level). */
    public function maxFields(array $planet): int
    {
        return $this->developments->maxFields(
            (int) ($planet['planet_field_max'] ?? 0),
            (int) ($planet['building_terraformer'] ?? 0)
        );
    }

    /** Free building fields, less what is already queued. */
    public function freeFields(array $planet, int $queued = 0): int
    {
        return $this->maxFields($planet) - (int) ($planet['planet_field_current'] ?? 0) - $queued;
    }

    /**
     * @param  array{metal: float, crystal: float, deuterium: float}  $cost
     * @param  array{metal?: float, crystal?: float, deuterium?: float}  $reserve
     */
    private function canPay(array $cost, array $planet, array $reserve = []): bool
    {
        foreach (['metal', 'crystal', 'deuterium'] as $res) {
            $have = (float) ($planet["planet_{$res}"] ?? 0) - (float) ($reserve[$res] ?? 0);
            if ($have < $cost[$res]) {
                return false;
            }
        }

        return true;
    }

    private function canAfford(int $buildingId, int $currentLevel, array $planet): bool
    {
        if ($this->ignoreAffordability) {
            return true;
        }

        return $this->canPay($this->price($buildingId, $currentLevel), $planet);
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Buildings
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Cost of the building the brain WANTS next, ignoring what it can afford right now. Null when
     * there is nothing it can place (full fields) or nothing worth building.
     *
     * BotTick uses this as the shipyard reserve: combat spending may only use what is left above it.
     *
     * @param  array<string, mixed>  $planet
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $ctx  see nextBuilding()
     * @return array{building_id: int, metal: float, crystal: float, deuterium: float}|null
     */
    public function wantedBuildingCost(array $planet, array $user, array $ctx = []): ?array
    {
        $this->ignoreAffordability = true;
        try {
            $buildingId = $this->nextBuilding($planet, $user, $ctx);
        } finally {
            $this->ignoreAffordability = false;
        }

        if ($buildingId === null) {
            return null;
        }

        return ['building_id' => $buildingId] + $this->price($buildingId, $this->getBuildingLevel($buildingId, $planet));
    }

    /**
     * @param  array<string, mixed>  $user
     */
    public function getPersonality(array $user): string
    {
        $profile = json_decode((string) ($user['bot_profile'] ?? '{}'), true);
        $personality = is_array($profile) ? ($profile['personality'] ?? 'raider') : 'raider';

        return in_array($personality, ['raider', 'balanced', 'turtle', 'passive'], true) ? $personality : 'raider';
    }

    /**
     * Next building for this planet (or null: nothing affordable worth building / no room).
     *
     * Order:
     *   0. Moons: strict lunar order.
     *   1. The way out of a full planet: Terraformer, else Nanite (they use the fields held back).
     *   2. Energy when in deficit (Solar / Fusion; satellites are the shipyard's field-free fix).
     *   3. Colonisation push: Robot 2 -> Shipyard 4 on the colony yard, Lab to what research needs.
     *   4. Storage when the next costs will not fit, or storage is full (not when fields are tight).
     *   5. Facilities: Robot 10, Nanite, Shipyard, Lab (research planet only), cheaper than a mine.
     *   6. Mines, best payback first.
     *   7. Facilities again, without the "cheaper than a mine" gate.
     *
     * @param  array<string, mixed>  $planet
     * @param  array<string, mixed>  $user
     * @param  array{research_planet?: bool, lab_needed?: int, colony_push?: bool, colony_yard?: bool, queued?: int}  $ctx
     */
    public function nextBuilding(array $planet, array $user, array $ctx = []): ?int
    {
        $this->lastBuildingReason = '';

        if ($this->isMoon($planet)) {
            return $this->nextMoonBuilding($planet, $user);
        }

        $personality = $this->getPersonality($user);
        $weights = self::BUILDING_WEIGHTS[$personality];
        $isResearchPlanet = (bool) ($ctx['research_planet'] ?? true);

        // Research / colony ship close enough to save for: buildings spend only what it doesn't need
        $planet = $this->withSavingsSetAside($planet, $ctx);

        // ─── Fields ──────────────────────────────────────────────────────────
        $free = $this->freeFields($planet, (int) ($ctx['queued'] ?? 0));
        $nanite = (int) ($planet['building_nano_factory'] ?? 0);
        $terraformer = (int) ($planet['building_terraformer'] ?? 0);
        $heldBack = ($nanite === 0 ? self::RESERVED_FIELDS_NANITE : 0)
            + ($terraformer === 0 ? self::RESERVED_FIELDS_TERRAFORMER : 0);
        $usable = $free - $heldBack;

        if ($free <= 0) {
            $this->lastBuildingReason = 'fields full';
            return null;
        }

        // ─── 1. The way out: Terraformer, then Nanite ────────────────────────
        if ($usable <= 2) {
            if ($terraformer < self::BUILDING_CAPS[Buildings::BUILDING_TERRAFORMER]
                && $this->allowed(Buildings::BUILDING_TERRAFORMER, $planet, $user)
            ) {
                $this->lastBuildingReason = 'terraformer (fields tight)';
                $pick = $this->saveOrFix(Buildings::BUILDING_TERRAFORMER, $terraformer, $planet, $user);
                if ($pick !== self::FALL_THROUGH) {
                    return $pick;
                }
            }

            if ($nanite === 0 && $this->allowed(Buildings::BUILDING_NANO_FACTORY, $planet, $user)) {
                $this->lastBuildingReason = 'nanite (fields tight)';
                $pick = $this->saveOrFix(Buildings::BUILDING_NANO_FACTORY, 0, $planet, $user);
                if ($pick !== self::FALL_THROUGH) {
                    return $pick;
                }
            }
        }

        if ($usable <= 0) {
            // Only the held-back fields are left and the Terraformer is not researched yet: the
            // research ladder is working towards Energy 12 / Computer 10; build nothing, save nothing.
            $this->lastBuildingReason = 'fields held for nanite/terraformer';
            return null;
        }

        $tight = $usable < self::TIGHT_FIELDS;

        // Robot Factory 10 is on the road to Nanite: finish it before the fields run out
        if ($tight) {
            $robot = (int) ($planet['building_robot_factory'] ?? 0);
            if ($robot < 10 && $this->canAfford(Buildings::BUILDING_ROBOT_FACTORY, $robot, $planet)) {
                $this->lastBuildingReason = 'robot factory (road to nanite)';
                return Buildings::BUILDING_ROBOT_FACTORY;
            }
        }

        // ─── 2. Energy ───────────────────────────────────────────────────────
        if ($this->isEnergyNegative($planet) && !$tight) {
            $energy = $this->nextEnergyBuilding($planet, $user);
            if ($energy !== null) {
                $this->lastBuildingReason = 'energy';
                return $energy;
            }
        }

        // ─── 2b. Income gap: the research / colony ship being saved for needs a resource this
        // planet does not produce at all (a fresh planet has no Deuterium Synthesizer) ───────
        foreach (['metal', 'crystal', 'deuterium'] as $res) {
            $need = (float) ($ctx["need_{$res}"] ?? 0);
            if ($need > (float) ($planet["planet_{$res}"] ?? 0) && $this->hourly($planet, $res) <= 0) {
                $mine = self::MINE_FOR[$res];
                $level = $this->getBuildingLevel($mine, $planet);
                if ($level < self::BUILDING_CAPS[$mine] && $this->canAfford($mine, $level, $planet)) {
                    $this->lastBuildingReason = "no {$res} income";
                    return $mine;
                }
            }
        }

        // ─── 2c. Storage too small for what the planet saves for (research / colony ship) ──
        foreach (self::STORE_FOR as $res => $store) {
            $need = (float) ($ctx["need_{$res}"] ?? 0);
            $level = $this->getBuildingLevel($store, $planet);
            if ($need > 0.95 * $this->productionService->maxStorable($level)
                && $level < self::BUILDING_CAPS[$store] && $this->canAfford($store, $level, $planet)
            ) {
                $this->lastBuildingReason = "{$res} storage for what research needs";
                return $store;
            }
        }

        // ─── 3. Colonisation push + the Lab research needs ───────────────────
        $mineStart = (int) ($planet['building_metal_mine'] ?? 0) >= 5 && (int) ($planet['building_crystal_mine'] ?? 0) >= 3;

        if ($mineStart && (bool) ($ctx['colony_push'] ?? false) && (bool) ($ctx['colony_yard'] ?? false)) {
            foreach ([Buildings::BUILDING_ROBOT_FACTORY => 2, Buildings::BUILDING_HANGAR => 4] as $facility => $target) {
                $level = $this->getBuildingLevel($facility, $planet);
                if ($level < $target && $this->allowed($facility, $planet, $user)) {
                    $this->lastBuildingReason = 'colony push';
                    $pick = $this->saveOrFix($facility, $level, $planet, $user);
                    if ($pick !== self::FALL_THROUGH) {
                        return $pick;
                    }
                    break;
                }
            }
        }

        $labNeeded = (int) ($ctx['lab_needed'] ?? 0);
        $lab = (int) ($planet['building_laboratory'] ?? 0);
        if ($mineStart && $isResearchPlanet && $lab < $labNeeded) {
            $this->lastBuildingReason = "lab {$labNeeded} for research";
            $pick = $this->saveOrFix(Buildings::BUILDING_LABORATORY, $lab, $planet, $user);
            if ($pick !== self::FALL_THROUGH) {
                return $pick;
            }
        }

        // ─── 4. Storage ──────────────────────────────────────────────────────
        if (!$tight && !$this->isEarlyGame($planet)) {
            $deposit = $this->getNextDeposit($planet, $ctx);
            if ($deposit !== null) {
                $this->lastBuildingReason = 'storage';
                return $deposit;
            }
        }

        // ─── 5. Facilities (cheaper than the next mine) ──────────────────────
        $facility = $this->getNextFacility($planet, $user, $weights, $isResearchPlanet, $tight, true);
        if ($facility !== null) {
            $this->lastBuildingReason = 'facility';
            return $facility;
        }

        // ─── 6. Mines ────────────────────────────────────────────────────────
        $mine = $this->getNextMine($planet, $user, $this->weightsForNeeds($weights, $planet, $ctx));
        if ($mine !== null) {
            $this->lastBuildingReason = 'mine';
            return $tight ? $mine : $this->powerFirst($mine, $planet, $user);
        }

        // ─── 7. Facilities without the gate, then energy for the next mines ──
        $facility = $this->getNextFacility($planet, $user, $weights, $isResearchPlanet, $tight, false);
        if ($facility !== null) {
            $this->lastBuildingReason = 'facility (force)';
            return $facility;
        }

        if (!$tight) {
            $energy = $this->nextEnergyBuilding($planet, $user);
            if ($energy !== null && $this->energySurplus($planet) < 0.1 * max(1, (int) ($planet['planet_energy_max'] ?? 0))) {
                $this->lastBuildingReason = 'energy headroom';
                return $energy;
            }
        }

        $this->lastBuildingReason = 'nothing affordable';
        return null;
    }

    /**
     * A full planet (0 free fields) whose way out is allowed right now (Terraformer, or Nanite when
     * it is missing) demolishes one level of the building it misses least, so the way out has a
     * field. Returns the building to tear down (queue it in 'destroy' mode) or null.
     *
     * Needed for planets that filled up before Phase 2 held two fields back: on 30 Sep 317 of 365
     * live homes sat at exactly 0 free fields, so even with Energy 12 the Terraformer could never
     * have been placed.
     *
     * @param  array{research_planet?: bool}  $ctx
     */
    public function nextDemolition(array $planet, array $user, array $ctx = []): ?int
    {
        if ($this->isMoon($planet) || $this->freeFields($planet) > 0) {
            return null;
        }

        $terraformer = (int) ($planet['building_terraformer'] ?? 0);
        $wayOut = ($terraformer < self::BUILDING_CAPS[Buildings::BUILDING_TERRAFORMER]
                && $this->allowed(Buildings::BUILDING_TERRAFORMER, $planet, $user))
            || ((int) ($planet['building_nano_factory'] ?? 0) === 0 && $this->allowed(Buildings::BUILDING_NANO_FACTORY, $planet, $user));

        if (!$wayOut) {
            return null;
        }

        $candidates = [Buildings::BUILDING_MISSILE_SILO, Buildings::BUILDING_ALLY_DEPOSIT];
        if (!(bool) ($ctx['research_planet'] ?? true)) {
            $candidates[] = Buildings::BUILDING_LABORATORY;
        }
        $candidates = array_merge($candidates, [
            Buildings::BUILDING_DEUTERIUM_TANK, Buildings::BUILDING_CRYSTAL_STORE, Buildings::BUILDING_METAL_STORE,
        ]);

        $ionTech = (int) ($user['research_ionic_technology'] ?? 0);
        foreach ($candidates as $id) {
            $level = $this->getBuildingLevel($id, $planet);
            if ($level < 1) {
                continue;
            }

            $cost = $this->developments->developmentPrice($id, $level, true, true, $ionTech);
            $cost = [
                'metal' => (float) ($cost['metal'] ?? 0),
                'crystal' => (float) ($cost['crystal'] ?? 0),
                'deuterium' => (float) ($cost['deuterium'] ?? 0),
            ];
            if ($this->canPay($cost, $planet)) {
                $this->lastBuildingReason = 'demolish for the way out';
                return $id;
            }
        }

        return null;
    }

    /**
     * The research (or colony ship) the planet waits for (ctx need_*): when the planet's income reaches
     * it within RESEARCH_FIRST_HOURS, the building brain sees only what is left after setting its
     * price aside. Without this every level of every mine took the crystal first, and in the sim 870 of
     * 1,000 bots sat for days short of the 4,000 crystal for Impulse Drive 1 (7 Oct 2026).
     * Further off than that, buildings carry on and the growing income brings the research closer.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function withSavingsSetAside(array $planet, array $ctx): array
    {
        if ($this->ignoreAffordability) {
            return $planet;
        }

        $wait = 0.0;
        $any = false;
        foreach (['metal', 'crystal', 'deuterium'] as $res) {
            $need = (float) ($ctx["need_{$res}"] ?? 0);
            $have = (float) ($planet["planet_{$res}"] ?? 0);
            if ($need <= 0) {
                continue;
            }
            $any = true;
            // Price bigger than the store: the storage step must be able to pay for the bigger store
            $storeLevel = $this->getBuildingLevel(self::STORE_FOR[$res], $planet);
            if ($need > 0.95 * $this->productionService->maxStorable($storeLevel)) {
                return $planet;
            }
            if ($need > $have) {
                $income = $this->hourly($planet, $res);
                $wait = $income > 0 ? max($wait, ($need - $have) / $income) : PHP_FLOAT_MAX;
            }
        }
        if (!$any || $wait > BotSpeed::hours(self::RESEARCH_FIRST_HOURS)) {
            return $planet;
        }

        foreach (['metal', 'crystal', 'deuterium'] as $res) {
            $have = (float) ($planet["planet_{$res}"] ?? 0);
            $planet["planet_{$res}"] = $have - min($have, (float) ($ctx["need_{$res}"] ?? 0));
        }

        return $planet;
    }

    /** Mine that produces each resource. */
    private const MINE_FOR = [
        'metal' => Buildings::BUILDING_METAL_MINE,
        'crystal' => Buildings::BUILDING_CRYSTAL_MINE,
        'deuterium' => Buildings::BUILDING_DEUTERIUM_SINTETIZER,
    ];

    /** Storage for each resource. */
    private const STORE_FOR = [
        'metal' => Buildings::BUILDING_METAL_STORE,
        'crystal' => Buildings::BUILDING_CRYSTAL_STORE,
        'deuterium' => Buildings::BUILDING_DEUTERIUM_TANK,
    ];

    /**
     * A building the planet must save for: return it if affordable; if it can never be paid as
     * things stand, return what fixes that (storage too small for the price: that storage; no
     * income of a needed resource: that mine); if the income would take longer than
     * MAX_SAVE_HOURS, FALL_THROUGH (don't block on it); otherwise null = keep saving.
     *
     * The fresh-universe simulation (30 Sep) deadlocked every new bot on Robot Factory 1: it
     * costs 200 deuterium and no new planet has a Deuterium Synthesizer, so "save for it" was
     * for ever. Storage caps (12,500 at level 0) make the same trap for Lab 7 / Shipyard 6.
     *
     * @return int|null  building id, null (save), or FALL_THROUGH
     */
    private function saveOrFix(int $id, int $level, array $planet, array $user): ?int
    {
        $cost = $this->price($id, $level);

        if ($this->canPay($cost, $planet)) {
            return $id;
        }

        $slowest = 0.0;
        foreach (['metal', 'crystal', 'deuterium'] as $res) {
            $have = (float) ($planet["planet_{$res}"] ?? 0);
            if ($cost[$res] <= $have) {
                continue;
            }

            // Price bigger than what storage holds: grow the storage first
            $store = self::STORE_FOR[$res];
            $storeLevel = $this->getBuildingLevel($store, $planet);
            if ($cost[$res] > 0.98 * $this->productionService->maxStorable($storeLevel)) {
                if ($storeLevel < self::BUILDING_CAPS[$store] && $this->freeFields($planet) > 0) {
                    $this->lastBuildingReason .= " -> {$res} storage first";
                    return $this->canAfford($store, $storeLevel, $planet) ? $store : null;
                }
                return self::FALL_THROUGH;
            }

            // No income of this resource at all: build its mine first
            if ($this->hourly($planet, $res) <= 0) {
                $mine = self::MINE_FOR[$res];
                $mineLevel = $this->getBuildingLevel($mine, $planet);
                if ($mineLevel < self::BUILDING_CAPS[$mine] && $this->freeFields($planet) > 0) {
                    $this->lastBuildingReason .= " -> {$res} mine first";
                    return $this->canAfford($mine, $mineLevel, $planet) ? $mine : null;
                }
                return self::FALL_THROUGH;
            }

            $slowest = max($slowest, ($cost[$res] - $have) / $this->hourly($planet, $res));
        }

        if ($slowest > BotSpeed::hours(self::MAX_SAVE_HOURS)) {
            return self::FALL_THROUGH;
        }

        if ($this->ignoreAffordability) {
            return $id;
        }

        if ($slowest > BotSpeed::hours(self::SPEND_WHILE_SAVING_HOURS)) {
            $spend = $this->spendWhileSaving($cost, $planet, $user);
            if ($spend !== null) {
                return $spend;
            }
        }

        return null;
    }

    /**
     * While saving, build from what the saved-for building does not need. On 6 Oct ~600 live bots
     * sat for days saving for Shipyard / Robot 2 / Lab 1, short only deuterium (5-20 deut/h), with
     * metal and crystal at the storage cap and their production thrown away. A Deuterium
     * Synthesizer costs only metal and crystal, so the bottleneck's mine comes first, then energy,
     * then the other mines; the price being saved for always stays put.
     *
     * @param  array{metal: float, crystal: float, deuterium: float}  $target
     */
    private function spendWhileSaving(array $target, array $planet, array $user): ?int
    {
        $nanite = (int) ($planet['building_nano_factory'] ?? 0);
        $terraformer = (int) ($planet['building_terraformer'] ?? 0);
        $heldBack = ($nanite === 0 ? self::RESERVED_FIELDS_NANITE : 0)
            + ($terraformer === 0 ? self::RESERVED_FIELDS_TERRAFORMER : 0);
        if ($this->freeFields($planet) - $heldBack <= 0) {
            return null;
        }

        $reserve = [];
        $waits = [];
        foreach (['metal', 'crystal', 'deuterium'] as $res) {
            $have = (float) ($planet["planet_{$res}"] ?? 0);
            $reserve[$res] = min($target[$res], $have);
            if ($target[$res] > $have) {
                $waits[$res] = ($target[$res] - $have) / max(0.001, $this->hourly($planet, $res));
            }
        }
        arsort($waits);

        $candidates = [];
        foreach (array_keys($waits) as $res) {
            $candidates[] = self::MINE_FOR[$res];
        }
        if ($this->isEnergyNegative($planet)) {
            $candidates[] = Buildings::BUILDING_SOLAR_PLANT;
        }

        $others = [];
        foreach (self::MINE_FOR as $mineId) {
            if (!in_array($mineId, $candidates, true)) {
                $others[$mineId] = ROICalculator::calcBuildingDOIR($planet, $mineId, $this->getBuildingLevel($mineId, $planet));
            }
        }
        asort($others);
        $candidates = array_merge($candidates, array_keys($others));

        foreach ($candidates as $id) {
            $level = $this->getBuildingLevel($id, $planet);
            if ($level >= self::BUILDING_CAPS[$id]) {
                continue;
            }
            if ($this->canPay($this->price($id, $level), $planet, $reserve)) {
                $this->lastBuildingReason .= ' -> spend surplus while saving';
                return $id === Buildings::BUILDING_SOLAR_PLANT ? $id : $this->powerFirst($id, $planet, $user, $reserve);
            }
        }

        return null;
    }

    /** The planet's hourly income of a resource (the game keeps it on the planet row). */
    private function hourly(array $planet, string $res): float
    {
        return (float) ($planet["planet_{$res}_perhour"] ?? 0);
    }

    /**
     * A mine level that would take the planet's energy below zero: the energy building first when it can
     * be paid (above $reserve), else the mine anyway. Mines bought from a surplus put 589 live planets
     * into deficit on 8 Oct, and a deficit slows every mine on the planet until the Solar Plant comes.
     *
     * @param  array{metal?: float, crystal?: float, deuterium?: float}  $reserve
     */
    private function powerFirst(int $mineId, array $planet, array $user, array $reserve = []): int
    {
        if ($mineId === Buildings::BUILDING_SOLAR_PLANT || $mineId === Buildings::BUILDING_FUSION_REACTOR) {
            return $mineId;
        }
        $formula = $this->registry->get($mineId)->getProduction();
        if ($formula === null) {
            return $mineId;
        }
        $temp = (float) ($planet['planet_temp_max'] ?? 0);
        $tech = (int) ($user['research_energy_technology'] ?? 0);
        $level = $this->getBuildingLevel($mineId, $planet);
        $energy = fn (int $l): float => $l <= 0 ? 0.0 : abs($formula->calculateEnergy($l, 10, $temp, $tech));
        if ($this->energySurplus($planet) >= $energy($level + 1) - $energy($level)) {
            return $mineId;
        }

        $energyId = $this->ignoreAffordability ? null : $this->nextEnergyBuilding($planet, $user);
        if ($energyId !== null && $this->canPay($this->price($energyId, $this->getBuildingLevel($energyId, $planet)), $planet, $reserve)) {
            $this->lastBuildingReason .= ' -> power first';
            return $energyId;
        }

        return $mineId;
    }

    /** Solar until level 20, then Fusion when the game allows it (Deut 5 + Energy 3), else Solar. */
    private function nextEnergyBuilding(array $planet, array $user): ?int
    {
        $solar = (int) ($planet['building_solar_plant'] ?? 0);
        $fusion = (int) ($planet['building_fusion_reactor'] ?? 0);
        $fusionOk = $fusion < self::BUILDING_CAPS[Buildings::BUILDING_FUSION_REACTOR]
            && $this->allowed(Buildings::BUILDING_FUSION_REACTOR, $planet, $user);

        $order = $solar >= 20 && $fusionOk
            ? [Buildings::BUILDING_FUSION_REACTOR, Buildings::BUILDING_SOLAR_PLANT]
            : [Buildings::BUILDING_SOLAR_PLANT, Buildings::BUILDING_FUSION_REACTOR];

        foreach ($order as $id) {
            $level = $this->getBuildingLevel($id, $planet);
            if ($level >= self::BUILDING_CAPS[$id]) {
                continue;
            }
            if ($id === Buildings::BUILDING_FUSION_REACTOR && !$fusionOk) {
                continue;
            }
            if ($this->canAfford($id, $level, $planet)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Next moon building: strict order, but skip what the moon may never have.
     */
    private function nextMoonBuilding(array $planet, array $user): ?int
    {
        foreach (self::MOON_BUILDING_PRIORITY as $buildingId => $config) {
            $level = $this->getBuildingLevel($buildingId, $planet);

            if ($level >= $config['cap']) {
                continue;
            }

            if (!$this->allowed($buildingId, $planet, $user)) {
                continue; // e.g. Jump Gate before Hyperspace Tech 7 — skip, don't freeze the moon
            }

            if ($this->freeFields($planet) <= 0 && $buildingId !== Buildings::BUILDING_MONDBASIS) {
                continue;
            }

            if ($this->canAfford($buildingId, $level, $planet)) {
                return $buildingId;
            }

            return null; // right priority, wait for resources
        }

        return null;
    }

    private function isMoon(array $planet): bool
    {
        return ((int) ($planet['planet_type'] ?? 1)) === 3;
    }

    /**
     * Energy: planet_energy_max = production (positive), planet_energy_used = consumption, stored
     * NEGATIVE. Net = max + used; deficit when net < 0.
     */
    public function isEnergyNegative(array $planet): bool
    {
        return $this->energyDeficit($planet) > 0;
    }

    public function energyDeficit(array $planet): int
    {
        $energyMax = (int) ($planet['planet_energy_max'] ?? 0);
        $energyUsed = (int) ($planet['planet_energy_used'] ?? 0);

        if ($energyMax <= 0 && $energyUsed === 0) {
            return 0; // moon / nothing built yet
        }

        $net = $energyMax + $energyUsed;

        return $net < 0 ? -$net : 0;
    }

    private function energySurplus(array $planet): int
    {
        return (int) ($planet['planet_energy_max'] ?? 0) + (int) ($planet['planet_energy_used'] ?? 0);
    }

    /** Energy one Solar Satellite gives here (GameObjectRegistry formula). */
    public function satelliteEnergy(array $planet): int
    {
        $temp = (float) ($planet['planet_temp_max'] ?? 0);

        return max(1, (int) floor(($temp + 140) / 6));
    }

    /** Early game = storage not worth a field yet (TBot OptimizeForStart). */
    private function isEarlyGame(array $planet): bool
    {
        return (int) ($planet['building_metal_mine'] ?? 0) < 12
            && (int) ($planet['building_crystal_mine'] ?? 0) < 10;
    }

    /**
     * Storage: build when it is full, when it can't hold DEPOSIT_HOURS of production, or when the
     * cost the planet is saving for (ctx 'need_*') won't fit.
     */
    private function getNextDeposit(array $planet, array $ctx): ?int
    {
        $map = [
            Buildings::BUILDING_DEUTERIUM_TANK => ['planet_deuterium', 'deuterium', Buildings::BUILDING_DEUTERIUM_SINTETIZER],
            Buildings::BUILDING_CRYSTAL_STORE  => ['planet_crystal', 'crystal', Buildings::BUILDING_CRYSTAL_MINE],
            Buildings::BUILDING_METAL_STORE    => ['planet_metal', 'metal', Buildings::BUILDING_METAL_MINE],
        ];

        foreach ($map as $storageId => [$column, $res, $mineId]) {
            $level = $this->getBuildingLevel($storageId, $planet);
            if ($level >= self::BUILDING_CAPS[$storageId]) {
                continue;
            }

            $capacity = $this->productionService->maxStorable($level);
            $hourly = ROICalculator::calcBuildingHourlyProduction($planet, $mineId);
            $need = (float) ($ctx["need_{$res}"] ?? 0);

            $wanted = (float) ($planet[$column] ?? 0) >= $capacity * 0.98
                || $capacity < BotSpeed::x1Hours(self::DEPOSIT_HOURS) * $hourly
                || $need > $capacity * 0.95;

            if ($wanted && $this->canAfford($storageId, $level, $planet)) {
                return $storageId;
            }
        }

        return null;
    }

    /**
     * Facilities: Robot Factory, Nanite, Shipyard, Lab (research planet only).
     * $gated: only when cheaper than the next mine (and paying back inside MAX_DOIR_DAYS).
     */
    private function getNextFacility(array $planet, array $user, array $weights, bool $isResearchPlanet, bool $tight, bool $gated): ?int
    {
        $caps = [
            Buildings::BUILDING_ROBOT_FACTORY => 10,
            Buildings::BUILDING_NANO_FACTORY  => self::BUILDING_CAPS[Buildings::BUILDING_NANO_FACTORY],
            Buildings::BUILDING_HANGAR        => $tight ? 8 : 12,
            Buildings::BUILDING_LABORATORY    => $isResearchPlanet ? 12 : 0, // colonies don't research
        ];

        $nextMineCost = $gated ? $this->getCheapestMineCost($planet, $weights) : 0.0;

        foreach ($caps as $facilityId => $cap) {
            $level = $this->getBuildingLevel($facilityId, $planet);

            if ($level >= $cap) {
                continue;
            }
            if (!$this->allowed($facilityId, $planet, $user)) {
                continue;
            }

            if ($gated) {
                $cost = $this->price($facilityId, $level);
                $total = $cost['metal'] + $cost['crystal'] + $cost['deuterium'];
                if ($nextMineCost > 0 && $total > $nextMineCost) {
                    continue;
                }
            }

            if ($this->canAfford($facilityId, $level, $planet)) {
                return $facilityId;
            }
        }

        return null;
    }

    /**
     * Personality mine weights, with the resource that research / the colony ship is waiting on
     * tripled. The sim showed 207 of 365 fresh bots with research waiting on deuterium while the
     * Deuterium Synthesizer ranked last (weight 0.7).
     *
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    private function weightsForNeeds(array $weights, array $planet, array $ctx): array
    {
        foreach (self::MINE_FOR as $res => $mineId) {
            if ((float) ($ctx["need_{$res}"] ?? 0) > (float) ($planet["planet_{$res}"] ?? 0)) {
                $weights[$mineId] = ($weights[$mineId] ?? 1.0) * 3.0;
            }
        }

        return $weights;
    }

    /** Next mine by payback time (lowest DOIR / personality weight), within caps and energy. */
    private function getNextMine(array $planet, array $user, array $weights): ?int
    {
        $bestMine = null;
        $bestScore = PHP_FLOAT_MAX;

        foreach ([Buildings::BUILDING_METAL_MINE, Buildings::BUILDING_CRYSTAL_MINE, Buildings::BUILDING_DEUTERIUM_SINTETIZER] as $mineId) {
            $level = $this->getBuildingLevel($mineId, $planet);

            if ($level >= self::BUILDING_CAPS[$mineId]) {
                continue;
            }
            if (!$this->canAfford($mineId, $level, $planet)) {
                continue;
            }

            $doir = ROICalculator::calcBuildingDOIR($planet, $mineId, $level);
            if ($doir > BotSpeed::hours(self::MAX_DOIR_DAYS) && $level >= 10) {
                continue;
            }

            $score = $doir / ($weights[$mineId] ?? 1.0);

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMine = $mineId;
            }
        }

        return $bestMine;
    }

    private function getCheapestMineCost(array $planet, array $weights): float
    {
        $cheapest = 0.0;

        foreach (array_keys($weights) as $mineId) {
            $level = $this->getBuildingLevel($mineId, $planet);
            if ($level >= self::BUILDING_CAPS[$mineId]) {
                continue;
            }

            $cost = $this->price($mineId, $level);
            $total = $cost['metal'] + $cost['crystal'] + $cost['deuterium'];
            if ($cheapest === 0.0 || $total < $cheapest) {
                $cheapest = $total;
            }
        }

        return $cheapest;
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Research
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * What to research next on $planet (the research planet) and whether it can be paid now.
     *
     * Walks RESEARCH_GOALS; a goal whose requirement is missing turns into that requirement. A
     * missing Lab level is reported in lab_needed (the building brain builds it) and the walk goes
     * on to the next goal that can start today. Once the ladder is done, the best DOIR tech
     * below RESEARCH_CAPS.
     *
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $planet
     * @return array{id: int, level: int, cost: array{metal: float, crystal: float, deuterium: float}, affordable: bool, lab_needed: int}|null
     */
    public function researchPlan(array $user, array $planet): ?array
    {
        $this->lastLabNeeded = 0;

        if ((int) ($user['research_current_research'] ?? 0) > 0) {
            return null;
        }

        $levels = $this->levels($planet, $user);
        $labNeeded = 0;
        $pick = null;
        $lookAhead = 0;

        foreach (self::RESEARCH_GOALS as [$techId, $target]) {
            if (($levels[$techId] ?? 0) >= $target) {
                continue;
            }

            // While a goal waits for the Lab, fill the gap with the next few goals only — not
            // expensive far-off ones that would eat the colonisation money.
            if ($labNeeded > 0 && ++$lookAhead > self::RESEARCH_LOOKAHEAD) {
                break;
            }

            $step = $this->resolveResearch($techId, $levels, 0);

            if ($step['lab'] > 0) {
                // The FIRST goal blocked by the Lab sets the Lab target (not the highest: a fresh
                // planet would otherwise build Lab 7 before its first research)
                if ($labNeeded === 0) {
                    $labNeeded = $step['lab'];
                }
                continue; // try the next goal that can start now
            }

            if ($step['id'] !== null) {
                $pick = $step['id'];
                break;
            }
        }

        if ($pick === null && $labNeeded === 0) {
            $pick = $this->bestDoirResearch($user, $planet, $levels);
        }

        $this->lastLabNeeded = $labNeeded;

        if ($pick === null) {
            return null;
        }

        $level = $levels[$pick] ?? 0;
        $cost = $this->price($pick, $level);

        return [
            'id' => $pick,
            'level' => $level,
            'cost' => $cost,
            'affordable' => $this->canPay($cost, $planet),
            'lab_needed' => $labNeeded,
        ];
    }

    /**
     * Follow requirements down to something researchable now.
     *
     * @param  array<int, int>  $levels
     * @return array{id: int|null, lab: int}
     */
    private function resolveResearch(int $techId, array $levels, int $depth): array
    {
        if ($depth > 8) {
            return ['id' => null, 'lab' => 0];
        }

        $object = $this->registry->get($techId);
        foreach ($object->getRequirements() as $reqId => $reqLevel) {
            $reqId = (int) $reqId;
            $reqLevel = (int) $reqLevel;

            if (($levels[$reqId] ?? 0) >= $reqLevel) {
                continue;
            }

            if ($reqId === Buildings::BUILDING_LABORATORY) {
                return ['id' => null, 'lab' => $reqLevel];
            }

            if ($reqId >= 100 && $reqId < 200) {
                $deeper = $this->resolveResearch($reqId, $levels, $depth + 1);
                if ($deeper['id'] !== null || $deeper['lab'] > 0) {
                    return $deeper;
                }
            }

            return ['id' => null, 'lab' => 0]; // a building we don't handle here
        }

        return ['id' => $techId, 'lab' => 0];
    }

    /** DOIR fallback once the ladder is complete. */
    private function bestDoirResearch(array $user, array $planet, array $levels): ?int
    {
        $best = null;
        $bestScore = PHP_FLOAT_MAX;

        foreach (self::RESEARCH_CAPS as $techId => $cap) {
            if (($levels[$techId] ?? 0) >= $cap) {
                continue;
            }
            if (!$this->developments->isDevelopmentAllowed($techId, $levels)) {
                continue;
            }

            $doir = ROICalculator::calcResearchDOIR($user, $planet, $techId);
            if ($doir < $bestScore) {
                $bestScore = $doir;
                $best = $techId;
            }
        }

        return $best;
    }

    /**
     * Research to start NOW (affordable) or null. Kept for callers that only want a yes/no
     * (the idle ladder's retry).
     */
    public function nextResearch(array $user, array $planet = []): ?int
    {
        if (empty($planet)) {
            return null;
        }

        $plan = $this->researchPlan($user, $planet);

        return $plan !== null && $plan['affordable'] ? $plan['id'] : null;
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Shipyard
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * What the shipyard builds this tick.
     *
     * Order: satellites (energy deficit) -> colony ship (ctx want_colony_ship) -> probes ->
     * recyclers -> cargo -> combat/defence (best legal first, a share of the spendable budget).
     * Everything is checked against the game's requirements: until 30 Sep BotTick wrote ships into
     * the hangar queue without a check and ThreatAnalyzer's ranks were read as shipyard levels.
     *
     * @param  array<string, mixed>  $planet
     * @param  array<string, mixed>  $user
     * @param  array{metal?: float, crystal?: float, deuterium?: float}  $reserve  left untouched for the
     *         building / research / colony ship the planet is saving for (floors below ignore it)
     * @param  array{want_colony_ship?: bool, economy_first?: bool, main_planet?: bool, flying?: array<int, int>, military_ratio?: float, reserve_far?: bool}  $ctx
     * @return array{ship_id: int, count: int, cost: array{metal: float, crystal: float, deuterium: float}}|null
     */
    public function nextShip(array $planet, array $user, array $reserve = [], array $ctx = []): ?array
    {
        if ((int) ($planet['building_hangar'] ?? 0) < 1) {
            return null;
        }

        $personality = $this->getPersonality($user);
        $queue = $this->parseHangarQueue((string) ($planet['planet_b_hangar_id'] ?? ''));
        // Owned = home + hangar queue + the bot's fleets in flight (raids, expeditions): counting only
        // what is home bought a fresh cargo floor every time the cargos were out
        $flying = $ctx['flying'] ?? [];
        $have = fn (int $id): int => (int) ($planet[$this->getShipColumn($id)] ?? 0) + ($queue[$id] ?? 0) + (int) ($flying[$id] ?? 0);

        // ─── Satellites: the field-free energy fix ───────────────────────────
        // Satellites cost 500 deuterium each — early on a Solar Plant level is far cheaper (the sim's
        // fresh bots bought 363 and starved research of deuterium). Only once Solar is high or the
        // fields are tight.
        $deficit = $this->energyDeficit($planet);
        $solarDear = (int) ($planet['building_solar_plant'] ?? 0) >= 20 || $this->freeFields($planet) <= self::TIGHT_FIELDS + 2;
        if ($deficit > 0 && $solarDear && !$this->isMoon($planet)) {
            $needed = (int) ceil($deficit / $this->satelliteEnergy($planet)) - ($queue[212] ?? 0);
            $needed = min($needed, self::SHIP_CAPS[212] - $have(212), 50);
            $order = $this->affordableOrder(212, $needed, $planet, $user, []);
            if ($order !== null) {
                return $order;
            }
        }

        // ─── Colony ship (ignores the reserve: it IS what the planet saves for) ──
        if (($ctx['want_colony_ship'] ?? false) && $have(208) < 1) {
            $order = $this->affordableOrder(208, 1, $planet, $user, []);
            if ($order !== null) {
                return $order;
            }
        }

        // ─── The first small cargos (ignore the reserve, before probes) ──────
        // Small cargos raid and run expeditions until Large Cargos are allowed. At x1 a raid pays for
        // days of saving; the old floor of 5 above the reserve left 1,000 bots with 439 cargos and no
        // raids (7 Oct 2026).
        $start = self::SMALL_CARGO_START[$personality];
        if (($ctx['main_planet'] ?? true) && $have(202) + 5 * $have(203) < $start) {
            $order = $this->affordableOrder(202, $start - $have(202), $planet, $user, []);
            if ($order !== null) {
                return $order;
            }
        }

        // ─── Probes ──────────────────────────────────────────────────────────
        // Probes only on the main planets (spying starts from the attack origin); every colony
        // stocking 15-30 gave ~70 per bot in the sim.
        $probeFloor = in_array($personality, ['turtle', 'passive'], true) ? self::PROBE_FLOOR_QUIET : self::PROBE_FLOOR;
        if (($ctx['main_planet'] ?? true) && $have(210) < $probeFloor) {
            // Two spy missions' worth first, whatever is being saved for; the rest above the reserve
            // (15 probes = 15K crystal ahead of research starved Impulse Drive in the sim, 7 Oct)
            $start = min($probeFloor, self::PROBE_START);
            $order = $have(210) < $start
                ? $this->affordableOrder(210, $start - $have(210), $planet, $user, [])
                : $this->affordableOrder(210, $probeFloor - $have(210), $planet, $user, $reserve);
            if ($order !== null) {
                return $order;
            }
        }

        // ─── Recyclers (debris is income) ────────────────────────────────────
        if ($personality !== 'passive' && $have(209) < self::RECYCLER_FLOOR) {
            $order = $this->affordableOrder(209, self::RECYCLER_FLOOR - $have(209), $planet, $user, $reserve);
            if ($order !== null) {
                return $order;
            }
        }

        // ─── Cargo ───────────────────────────────────────────────────────────
        if ($have(203) < self::LARGE_CARGO_FLOOR && $this->allowed(203, $planet, $user)) {
            $order = $this->affordableOrder(203, self::LARGE_CARGO_FLOOR - $have(203), $planet, $user, $reserve);
            if ($order !== null) {
                return $order;
            }
        } elseif (!$this->allowed(203, $planet, $user)) {
            // The rest of the small-cargo floor, above the reserve
            $floor = self::SMALL_CARGO_FLOOR[$personality];
            if ($have(202) < $floor) {
                $order = $this->affordableOrder(202, $floor - $have(202), $planet, $user, $reserve);
                if ($order !== null) {
                    return $order;
                }
            }
        }

        // ─── Combat / defence ────────────────────────────────────────────────
        // Below the personality's military target the fleet/defence spend ignores the reserve: what the
        // planet saves for always outran the stock, and the sim's 1,000 bots owned 24 warships after a
        // week of raiding (7 Oct 2026).
        // Only when what the planet saves for is far off (ctx reserve_far); a near reserve is kept.
        $behind = isset($ctx['military_ratio']) && (float) $ctx['military_ratio'] < self::MILITARY_TARGET[$personality]
            && (bool) ($ctx['reserve_far'] ?? true);

        return $this->militaryOrder($planet, $user, $behind ? [] : $reserve, $personality, $have, (bool) ($ctx['economy_first'] ?? false));
    }

    /**
     * Best legal combat ship or defence the budget buys: a share of what is above the reserve.
     *
     * @param  callable(int): int  $have
     */
    private function militaryOrder(array $planet, array $user, array $reserve, string $personality, callable $have, bool $economyFirst = false): ?array
    {
        // Until the first colony exists the money goes to the colonisation road (Dale, 30 Sep:
        // "colony ships is the first thing you should be going for")
        $share = self::COMBAT_SPEND_SHARE[$personality] * ($economyFirst ? self::ECONOMY_FIRST_SHARE : 1.0);
        $budget = [];
        foreach (['metal', 'crystal', 'deuterium'] as $res) {
            $budget[$res] = max(0.0, ((float) ($planet["planet_{$res}"] ?? 0) - (float) ($reserve[$res] ?? 0)) * $share);
        }

        $lists = [];
        if ($personality === 'passive') {
            $lists[] = self::DEFENCES['passive'];
        } elseif ($personality === 'turtle') {
            // Domes first (one each), then defence or ships by what the planet has less of
            $defenceValue = $this->defenderPower(array_intersect_key($planet, array_flip(array_filter(array_keys($planet), fn ($k) => str_starts_with((string) $k, 'defense_')))));
            $fleetValue = $this->calculateFleetStrength($this->getAvailableCombatShips($planet));
            $lists[] = [408, 407];
            $lists[] = $defenceValue <= $fleetValue * (self::TURTLE_DEFENCE_SHARE / (1 - self::TURTLE_DEFENCE_SHARE))
                ? self::DEFENCES['turtle'] : self::COMBAT_SHIPS['turtle'];
        } else {
            $lists[] = $this->withThreatCounters(self::COMBAT_SHIPS[$personality], $planet);
        }

        foreach ($lists as $list) {
            foreach ($list as $id) {
                if (isset(self::SHIP_CAPS[$id]) && $have($id) >= self::SHIP_CAPS[$id]) {
                    continue;
                }
                if (!$this->allowed($id, $planet, $user)) {
                    continue;
                }

                $cost = $this->price($id);
                $count = self::MAX_ORDER;
                foreach (['metal', 'crystal', 'deuterium'] as $res) {
                    if ($cost[$res] > 0) {
                        $count = min($count, (int) floor($budget[$res] / $cost[$res]));
                    }
                }
                if (isset(self::SHIP_CAPS[$id])) {
                    $count = min($count, self::SHIP_CAPS[$id] - $have($id));
                }

                if ($count >= 1) {
                    return ['ship_id' => $id, 'count' => $count, 'cost' => $cost];
                }
            }
        }

        return null;
    }

    /**
     * When the neighbourhood reads as dangerous, move ThreatAnalyzer's counter ships to the front
     * of the list — they are RANKS (1 = best counter), not shipyard levels as the old code read them.
     *
     * @param  list<int>  $list
     * @return list<int>
     */
    private function withThreatCounters(array $list, array $planet): array
    {
        try {
            $threats = $this->threatAnalyzer->analyzeThreats($planet);
        } catch (\Throwable) {
            return $list;
        }

        if (($threats['threat_level'] ?? 'low') === 'low') {
            return $list;
        }

        $ranks = $threats['ship_priority'] ?? [];
        asort($ranks);
        $counters = array_values(array_filter(array_map('intval', array_keys($ranks)), fn ($id) => in_array($id, $list, true)));

        return array_values(array_unique(array_merge($counters, $list)));
    }

    /**
     * Up to $wanted of $id if the game allows it and the planet can pay (above $reserve).
     *
     * @return array{ship_id: int, count: int, cost: array{metal: float, crystal: float, deuterium: float}}|null
     */
    private function affordableOrder(int $id, int $wanted, array $planet, array $user, array $reserve): ?array
    {
        if ($wanted < 1 || !$this->allowed($id, $planet, $user)) {
            return null;
        }

        $cost = $this->price($id);
        $count = $wanted;
        foreach (['metal', 'crystal', 'deuterium'] as $res) {
            if ($cost[$res] > 0) {
                $spendable = max(0.0, (float) ($planet["planet_{$res}"] ?? 0) - (float) ($reserve[$res] ?? 0));
                $count = min($count, (int) floor($spendable / $cost[$res]));
            }
        }

        return $count >= 1 ? ['ship_id' => $id, 'count' => $count, 'cost' => $cost] : null;
    }

    /**
     * Parse the hangar queue string (e.g. "210,20;202,5;") into ship_id => count.
     *
     * @return array<int, int>
     */
    public function parseHangarQueue(string $queue): array
    {
        $result = [];

        foreach (explode(';', $queue) as $entry) {
            $parts = explode(',', trim($entry));
            if (count($parts) === 2) {
                $result[(int) $parts[0]] = ($result[(int) $parts[0]] ?? 0) + (int) $parts[1];
            }
        }

        return $result;
    }

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
            401 => 'defense_rocket_launcher', 402 => 'defense_light_laser',
            403 => 'defense_heavy_laser', 404 => 'defense_gauss_cannon',
            405 => 'defense_ion_cannon', 406 => 'defense_plasma_turret',
            407 => 'defense_small_shield_dome', 408 => 'defense_large_shield_dome',
        ];

        return $columns[$shipId] ?? "ship_{$shipId}";
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Attack planning (unchanged in Phase 2)
    // ═════════════════════════════════════════════════════════════════════════

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

        $this->lastAttackDebug = [];

        $availableShips = $this->getAvailableCombatShips($botPlanet);

        if (empty($availableShips)) {
            return null;
        }

        $defenderPlanet = $target['planet_data'] ?? [];

        if (empty($defenderPlanet)) {
            return null;
        }

        $defenderUser = [
            'research_weapons_technology' => (int) ($defenderPlanet['research_weapons_technology'] ?? 0),
            'research_shielding_technology' => (int) ($defenderPlanet['research_shielding_technology'] ?? 0),
            'research_armour_technology' => (int) ($defenderPlanet['research_armour_technology'] ?? 0),
        ];

        $maxLossRate = match ($personality) {
            'raider' => 0.7,
            'turtle' => 0.3,
            'balanced' => 0.5,
            default => 0.5,
        };

        if ($this->isUnderResourcePressure($botPlanet)) {
            $maxLossRate = min($maxLossRate + 0.2, 0.95);
        }

        // Cargo: the game hands over up to half of what is on the planet, capped by hold space.
        $cargo = $this->pickCargo($availableShips, (int) ceil(((int) ($target['resources'] ?? 0)) / 2));
        $defenderPower = $this->defenderPower($defenderPlanet);

        // Nothing at all on the planet (the intel shows no ship and no defence): cargo alone takes it, as
        // a player raids an empty planet. Until 7 Oct a raid needed combat ships, and no x1 bot had one.
        $freshIntel = time() - (int) ($target['scanned_at'] ?? 0) <= BotSpeed::seconds(self::EMPTY_PLANET_INTEL_SECONDS);
        if ($defenderPower === 0 && !empty($cargo) && $freshIntel && $this->isUndefended($defenderPlanet)) {
            $this->lastAttackDebug = ['tier' => 0, 'winner' => 'attacker', 'undefended' => true];
            return $cargo;
        }

        // Try growing shares of the combat fleet and send the smallest one the battle engine says
        // wins within the loss limit (6 Sep). Tier 0 = the cargo alone.
        $lastFleet = null;

        foreach (self::ATTACK_TIERS as $share) {
            $fleet = $cargo;

            foreach (self::ATTACK_SHIP_ORDER as $shipId) {
                $count = (int) floor(($availableShips[$shipId] ?? 0) * $share);
                if ($count > 0) {
                    $fleet[$shipId] = $count;
                }
            }

            if (empty($fleet) || $fleet === $lastFleet) {
                continue;
            }
            $lastFleet = $fleet;

            $ownPower = $this->calculateFleetStrength($fleet);
            if ($ownPower < $defenderPower * 0.5) {
                $this->lastAttackDebug = ['tier' => $share, 'skipped' => 'power', 'own' => $ownPower, 'def' => $defenderPower];
                continue;
            }

            $fuel = $this->estimateFuel($fleet, $botPlanet, $botUser, $target);
            if ($fuel > 0 && (float) ($botPlanet['planet_deuterium'] ?? 0) < $fuel * 2) {
                $this->lastAttackDebug = ['tier' => $share, 'skipped' => 'fuel', 'fuel' => $fuel];
                break;
            }

            try {
                $simResult = $this->simulator->simulate($fleet, $defenderPlanet, $botUser, $defenderUser);
            } catch (\Throwable $e) {
                return null;
            }

            $attackerInitial = array_sum($fleet);
            $lossRate = $attackerInitial > 0 ? $simResult['attacker_losses'] / $attackerInitial : 1;

            $lostValue = 0;
            $holdSpace = 0;
            foreach ($simResult['attacker_ships_detail'] ?? [] as $shipId => $detail) {
                $initial = (int) ($detail['initial'] ?? 0);
                $final = (int) ($detail['final'] ?? 0);
                $lost = max(0, $initial - $final);
                if ($lost > 0) {
                    $cost = $this->price((int) $shipId);
                    $lostValue += $lost * ($cost['metal'] + $cost['crystal'] + $cost['deuterium']);
                }
                $holdSpace += max(0, $final) * (self::CARGO_CAPACITY[(int) $shipId] ?? 0);
            }
            $lootValue = min($holdSpace, intdiv((int) ($target['resources'] ?? 0), 2));

            $this->lastAttackDebug = [
                'tier' => $share, 'winner' => $simResult['winner'], 'loss_rate' => round($lossRate, 2),
                'loot' => $lootValue, 'lost' => $lostValue, 'own' => $ownPower, 'def' => $defenderPower,
            ];

            if ($simResult['winner'] === 'attacker' && $lossRate <= $maxLossRate && $lootValue >= $lostValue * self::LOOT_MARGIN) {
                return $fleet;
            }
        }

        return null;
    }

    /** Attack fleet sizes to try, as a share of the combat ships on the origin planet (0 = cargo only). */
    private const ATTACK_TIERS = [0.0, 0.34, 0.67, 1.0];

    /** "Nothing on it" is trusted for a cargo-only raid only from a report this young (x5 terms: 2.5 h at x1). */
    private const EMPTY_PLANET_INTEL_SECONDS = 1800;

    /** No ship and no defence of any kind in the defender array. */
    private function isUndefended(array $defenderPlanet): bool
    {
        foreach ($defenderPlanet as $key => $value) {
            if ((str_starts_with((string) $key, 'ship_') || str_starts_with((string) $key, 'defense_'))
                && !str_ends_with((string) $key, '_id') && (int) $value > 0
            ) {
                return false;
            }
        }

        return true;
    }

    /** Combat ships that go on raids. */
    private const ATTACK_SHIP_ORDER = [204, 205, 206, 207, 211, 213, 215];

    /** Estimated loot must be at least this many times the value of the ships expected lost. */
    private const LOOT_MARGIN = 1.25;

    /** Hold space per ship (game values) for the loot estimate in planAttack(). */
    private const CARGO_CAPACITY = [
        202 => 5000, 203 => 25000, 204 => 50, 205 => 100, 206 => 800, 207 => 1500,
        211 => 500, 213 => 2000, 214 => 1000000, 215 => 10000,
    ];

    /** What the last planAttack() decided, for dry-run output. */
    public array $lastAttackDebug = [];

    /**
     * Cargo ships for a raid: Large Cargos first (25K each), then Small Cargos (5K each).
     *
     * @param  array<int, int>  $available
     * @return array<int, int>
     */
    private function pickCargo(array $available, int $capacityNeeded): array
    {
        $fleet = [];

        if ($capacityNeeded <= 0) {
            return $fleet;
        }

        $large = min((int) ($available[203] ?? 0), (int) ceil($capacityNeeded / 25000));
        if ($large > 0) {
            $fleet[203] = $large;
            $capacityNeeded -= $large * 25000;
        }

        if ($capacityNeeded > 0) {
            $small = min((int) ($available[202] ?? 0), (int) ceil($capacityNeeded / 5000));
            if ($small > 0) {
                $fleet[202] = $small;
            }
        }

        return $fleet;
    }

    /**
     * Rough combat power of a planet array (ships + defences), same scale as
     * calculateFleetStrength(). Used to skip hopeless simulations.
     */
    private function defenderPower(array $planet): int
    {
        $ships = [];
        foreach ([202, 203, 204, 205, 206, 207, 208, 209, 210, 211, 212, 213, 214, 215] as $id) {
            $ships[$id] = (int) ($planet[$this->getShipColumn($id)] ?? 0);
        }
        $power = $this->calculateFleetStrength($ships);

        $defences = [
            'defense_rocket_launcher' => 80, 'defense_light_laser' => 100, 'defense_heavy_laser' => 250,
            'defense_gauss_cannon' => 1100, 'defense_ion_cannon' => 500, 'defense_plasma_turret' => 3000,
            'defense_small_shield_dome' => 2000, 'defense_large_shield_dome' => 10000,
        ];
        foreach ($defences as $column => $unitPower) {
            $power += (int) ($planet[$column] ?? 0) * $unitPower;
        }

        return $power;
    }

    /** Under pressure = holds less than 5 hours of its own production; takes riskier fights. */
    private function isUnderResourcePressure(array $planet): bool
    {
        $total = (float) ($planet['planet_metal'] ?? 0) + (float) ($planet['planet_crystal'] ?? 0) + (float) ($planet['planet_deuterium'] ?? 0);
        $hourly = (float) ($planet['planet_metal_perhour'] ?? 0) + (float) ($planet['planet_crystal_perhour'] ?? 0) + (float) ($planet['planet_deuterium_perhour'] ?? 0);

        return $hourly > 0 && $total < $hourly * BotSpeed::x1Hours(5);
    }

    /** @return array<int, int> */
    private function getAvailableCombatShips(array $planet): array
    {
        $ships = [];
        foreach ([202, 203, 204, 205, 206, 207, 211, 213, 214, 215] as $id) {
            $count = (int) ($planet[$this->getShipColumn($id)] ?? 0);
            if ($count > 0) {
                $ships[$id] = $count;
            }
        }

        return $ships;
    }

    /** @param  array<int, int>  $ships */
    private function calculateFleetStrength(array $ships): int
    {
        $power = [
            202 => 5, 203 => 5, 204 => 50, 205 => 150, 206 => 400, 207 => 1000, 208 => 50,
            209 => 1, 210 => 0, 211 => 1000, 213 => 2000, 214 => 200000, 215 => 2800,
        ];

        $total = 0;
        foreach ($ships as $shipId => $count) {
            $total += ($power[$shipId] ?? 0) * $count;
        }

        return $total;
    }

    /** Rough fuel estimate for an attack fleet. */
    private function estimateFuel(array $ships, array $planet, array $user, array $target): int
    {
        $consumption = [202 => 10, 203 => 50, 204 => 20, 205 => 75, 206 => 300, 207 => 500, 210 => 1];

        $total = 0;
        foreach ($ships as $shipId => $count) {
            $total += ($consumption[$shipId] ?? 50) * $count;
        }

        return (int) ($total * (int) ($target['distance'] ?? 0) / 35000 + 1);
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Levels
    // ═════════════════════════════════════════════════════════════════════════

    /** Current level of a building on a planet array. */
    public function getBuildingLevel(int $buildingId, array $planet): int
    {
        $columns = [
            Buildings::BUILDING_METAL_MINE           => 'building_metal_mine',
            Buildings::BUILDING_CRYSTAL_MINE         => 'building_crystal_mine',
            Buildings::BUILDING_DEUTERIUM_SINTETIZER => 'building_deuterium_sintetizer',
            Buildings::BUILDING_SOLAR_PLANT          => 'building_solar_plant',
            Buildings::BUILDING_FUSION_REACTOR       => 'building_fusion_reactor',
            Buildings::BUILDING_ROBOT_FACTORY        => 'building_robot_factory',
            Buildings::BUILDING_NANO_FACTORY         => 'building_nano_factory',
            Buildings::BUILDING_HANGAR               => 'building_hangar',
            Buildings::BUILDING_METAL_STORE          => 'building_metal_store',
            Buildings::BUILDING_CRYSTAL_STORE        => 'building_crystal_store',
            Buildings::BUILDING_DEUTERIUM_TANK       => 'building_deuterium_tank',
            Buildings::BUILDING_LABORATORY           => 'building_laboratory',
            Buildings::BUILDING_TERRAFORMER          => 'building_terraformer',
            Buildings::BUILDING_ALLY_DEPOSIT         => 'building_ally_deposit',
            Buildings::BUILDING_MISSILE_SILO         => 'building_missile_silo',
            Buildings::BUILDING_MONDBASIS            => 'building_mondbasis',
            Buildings::BUILDING_PHALANX              => 'building_phalanx',
            Buildings::BUILDING_JUMP_GATE            => 'building_jump_gate',
        ];

        $column = $columns[$buildingId] ?? null;

        return $column ? (int) ($planet[$column] ?? 0) : 0;
    }
}

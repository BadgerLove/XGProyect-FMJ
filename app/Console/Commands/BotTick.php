<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Planets;
use App\Models\User;
use App\Services\Bot\BotBrain;
use App\Services\Bot\AllianceCoordinator;
use App\Services\Bot\BattleSimulator;
use App\Services\Bot\ColonizationService;
use App\Services\Bot\HarvestService;
use App\Services\Bot\CombatChatService;
use App\Services\Bot\FleetDispatcher;
use App\Services\Bot\FleetProtector;
use App\Services\Bot\GrudgeService;
use App\Services\Bot\IntelService;
use App\Services\Bot\PlanetLadder;
use App\Services\Bot\ResourceTrader;
use App\Services\Bot\TargetScanner;
use App\Services\Game\BuildingQueueService;
use App\Services\Game\ResearchQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Core\Enumerators\MissionsEnumerator as Missions;
use Xgp\App\Libraries\UpdatesLibrary;

/**
 * Process all bot accounts: tick resources, build, research, scout, attack.
 *
 * Run via: php artisan bot:tick
 * Schedule: every 15 minutes via Kernel.php
 */
class BotTick extends Command
{
    use PreparesLegacySql;

    /** Fleet slots to leave free for spying/attacking when sending expeditions. */
    private const EXPEDITION_KEEP_FREE_SLOTS = 2;

    /** Don't re-probe a planet scanned more recently than this (seconds). */
    private const SPY_REFRESH_SECONDS = 1800;

    /** Intel older than this is re-spied instead of attacked on (seconds). */
    private const INTEL_MAX_AGE = 7200;

    /** How many intel targets (richest first) to run through the battle engine per bot per tick. */
    private const ATTACK_CANDIDATES = 8;

    /** Ignore intel targets holding less than this much in total — not worth the fuel or the risk. */
    private const ATTACK_MIN_RESOURCES = 50_000;

    protected $signature = 'bot:tick
        {--dry-run : Show what would happen without saving}
        {--ladder-assume-idle=0 : DRY RUN ONLY — pretend every idle planet has already been idle this many ticks, to preview the ladder}';

    protected $description = 'Process bot accounts: tick resources, build, research, attack';

    public function __construct(
        private readonly BotBrain $brain,
        private readonly BuildingQueueService $queueService,
        private readonly ResearchQueueService $researchQueueService,
        private readonly TargetScanner $scanner,
        private readonly FleetDispatcher $dispatcher,
        private readonly FleetProtector $protector,
        private readonly BattleSimulator $simulator,
        private readonly IntelService $intel,
        private readonly ResourceTrader $trader,
        private readonly AllianceCoordinator $alliance,
        private readonly CombatChatService $chat,
        private readonly GrudgeService $grudge,
        private readonly ColonizationService $colonizer,
        private readonly HarvestService $harvester,
        private readonly PlanetLadder $ladder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Load legacy constants (table names, game mechanics)
        $legacyConstants = base_path('config/legacy/constants.php');
        if (file_exists($legacyConstants)) {
            require_once $legacyConstants;
        }

        // Load OPBE battle engine constants and functions
        if (!defined('OPBEPATH')) {
            define('OPBEPATH', base_path('legacy/app/Libraries/BattleEngine') . DIRECTORY_SEPARATOR);
        }
        $battleConstants = OPBEPATH . 'Constants' . DIRECTORY_SEPARATOR . 'BattleConstants.php';
        if (file_exists($battleConstants) && !defined('COST_TO_ARMOUR')) {
            require_once $battleConstants;
        }
        $battleFunctions = OPBEPATH . 'Utils' . DIRECTORY_SEPARATOR . 'Functions.php';
        if (file_exists($battleFunctions) && !function_exists('log_var')) {
            require_once $battleFunctions;
        }

        // Define LIB_PATH for mission handlers (Attack.php needs it)
        if (!defined('LIB_PATH')) {
            define('LIB_PATH', base_path('legacy/app/Libraries') . DIRECTORY_SEPARATOR);
        }

        $dryRun = $this->option('dry-run');
        $startTime = microtime(true);

        // Safety: clamp ALL negative ship counts to 0 (battle engine crashes on negatives)
        if (!$dryRun) {
            $shipColumns = [
                'ship_small_cargo_ship', 'ship_big_cargo_ship', 'ship_light_fighter',
                'ship_heavy_fighter', 'ship_cruiser', 'ship_battleship',
                'ship_colony_ship', 'ship_recycler', 'ship_espionage_probe',
                'ship_bomber', 'ship_solar_satellite', 'ship_destroyer',
                'ship_deathstar', 'ship_reaper',
            ];
            foreach ($shipColumns as $col) {
                \Illuminate\Support\Facades\DB::table('ships')
                    ->where($col, '<', 0)
                    ->update([$col => 0]);
            }
        }

        // Process arriving and returning fleets BEFORE bot loop
        // This ensures spy reports are generated, battles resolved, etc.
        // Wrapped in try-catch so one bad battle doesn't kill the entire tick
        $missionControl = app(\Xgp\App\Libraries\MissionControlLib::class);
        try {
            $missionControl->arrivingFleets();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('BotTick: arrivingFleets crashed: ' . $e->getMessage());
            $this->warn('  WARNING: arrivingFleets error: ' . $e->getMessage());
        }
        try {
            $missionControl->returningFleets();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('BotTick: returningFleets crashed: ' . $e->getMessage());
            $this->warn('  WARNING: returningFleets error: ' . $e->getMessage());
        }

        // Record grudges: check recent combat log for bots that got attacked
        $botIds = User::whereNotNull('bot_profile')->pluck('id')->toArray();
        $recentCombats = DB::table('bot_combat_log')
            ->where('created_at', '>', now()->subMinutes(5))
            ->whereIn('defender_id', $botIds)
            ->get();
        foreach ($recentCombats as $combat) {
            $this->grudge->recordGrudge((int) $combat->defender_id, (int) $combat->attacker_id);
        }
        // Cleanup expired grudges
        $this->grudge->cleanup();

        $bots = User::where('email', 'LIKE', '%@bots.local')
            ->where('authlevel', '!=', 3)
            ->get();

        if ($bots->isEmpty()) {
            $this->info('No bots found.');
            return self::SUCCESS;
        }

        $this->info("Processing {$bots->count()} bots..." . ($dryRun ? ' (DRY RUN)' : ''));

        $stats = ['processed' => 0, 'built' => 0, 'ships' => 0, 'researches' => 0, 'attacks' => 0, 'spies' => 0, 'fleet_saves' => 0, 'expeditions' => 0, 'harvests' => 0, 'skipped' => 0, 'errors' => 0, 'idle_planets' => 0, 'escalated' => 0, 'stuck' => 0, 'reserved' => 0];

        if ($this->ladder->isEnabled()) {
            $assume = (int) $this->option('ladder-assume-idle');
            if ($assume > 0 && !$dryRun) {
                $this->error('--ladder-assume-idle is only allowed with --dry-run');
                return self::FAILURE;
            }
            if ($assume > 0) {
                $this->warn("  Ladder preview: treating idle planets as already {$assume} ticks idle");
            }
        } else {
            $this->warn('  Idle ladder disabled: table ' . PlanetLadder::TABLE . ' missing (run migrations)');
        }

        foreach ($bots as $bot) {
            try {
                // Check if bot is in active hours
                if (!$this->isBotActive($bot)) {
                    $stats['skipped']++;
                    continue;
                }

                // Update onlinetime so site shows bot as active
                if (!$dryRun) {
                    User::where('id', $bot->id)->update(['onlinetime' => time()]);
                }

                $result = $this->processBot($bot, $dryRun);
                $stats['processed']++;

                if ($result['fleet_saved']) {
                    $stats['fleet_saves']++;
                }

                if ($result['expeditions'] > 0) {
                    $stats['expeditions'] += $result['expeditions'];
                }
                $stats['harvests'] += $result['harvests'];

                $stats['idle_planets'] += $result['idle_planets'];
                $stats['escalated'] += $result['escalated'];
                $stats['stuck'] += $result['stuck'];
                $stats['reserved'] += $result['reserved'];

                if ($result['built']) {
                    $stats['built']++;
                }

                if ($result['ship_built']) {
                    $stats['ships']++;
                }

                if ($result['research']) {
                    $stats['researches']++;
                }

                if ($result['attacked']) {
                    $stats['attacks']++;
                }

                if ($result['spied']) {
                    $stats['spies']++;
                }

                // Log interesting actions
                $actions = array_filter([
                    $result['building'] ? "build:{$result['building']}" : null,
                    $result['ship'] ? "ship:{$result['ship']}" : null,
                    $result['research'] ? "research:{$result['research']}" : null,
                    $result['attack_target'] ? "attack:{$result['attack_target']}" : null,
                    $result['spy_target'] ? "spy:{$result['spy_target']}" : null,
                ]);

                if (!empty($actions)) {
                    $this->line("  [{$bot->id}] {$bot->name}: " . implode(', ', $actions));
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->warn("  [{$bot->id}] {$bot->name}: ERROR - {$e->getMessage()}");
            }
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        // Cleanup expired intel
        $cleaned = $this->intel->cleanup();

        $this->newLine();
        $this->info("Done in {$elapsed}s.");
        $this->info("  Processed: {$stats['processed']}");
        $this->info("  Buildings queued: {$stats['built']}");
        $this->info("  Ships queued: {$stats['ships']}");
        $this->info("  Research queued: {$stats['researches']}");
        $this->info("  Attacks sent: {$stats['attacks']}");
        $this->info("  Spy missions: {$stats['spies']}");
        $this->info("  Fleet saves: {$stats['fleet_saves']}");
        $this->info("  Expeditions sent: {$stats['expeditions']}");
        $this->info("  Harvest missions: {$stats['harvests']}");
        $this->info("  Idle planets: {$stats['idle_planets']} (ladder fired: {$stats['escalated']}, stuck: {$stats['stuck']})");
        $this->info("  Planets saving for a building (ships held back): {$stats['reserved']}");
        $this->info("  Skipped (sleeping): {$stats['skipped']}");
        $this->info("  Errors: {$stats['errors']}");

        $this->appendTickLog($stats, $elapsed, (bool) $dryRun);

        return self::SUCCESS;
    }

    /**
     * Append a one-line summary of this tick to storage/logs/bot-tick-YYYY-MM.log.
     *
     * The scheduled task discards stdout, so until 2026-09-04 nobody could see that the
     * tick had been reporting 0 buildings / 0 ships / 0 research for a month. This line is
     * what `bot:health` reads. Dry runs are tagged DRY so the watchdog can ignore them.
     * Never throws — a logging failure must not fail the tick.
     */
    private function appendTickLog(array $stats, float $elapsed, bool $dryRun): void
    {
        try {
            $line = sprintf(
                "%s | %sprocessed=%d skipped=%d built=%d ships=%d research=%d attacks=%d spies=%d saves=%d expeditions=%d harvests=%d errors=%d | idle_planets=%d escalated=%d stuck=%d reserved=%d | %.1fs\n",
                date('Y-m-d H:i:s'),
                $dryRun ? 'DRY ' : '',
                $stats['processed'], $stats['skipped'], $stats['built'], $stats['ships'], $stats['researches'],
                $stats['attacks'], $stats['spies'], $stats['fleet_saves'], $stats['expeditions'], $stats['harvests'] ?? 0, $stats['errors'],
                $stats['idle_planets'] ?? 0, $stats['escalated'] ?? 0, $stats['stuck'] ?? 0, $stats['reserved'] ?? 0,
                $elapsed
            );
            file_put_contents(storage_path('logs/bot-tick-' . date('Y-m') . '.log'), $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            $this->warn('Tick log write failed: ' . $e->getMessage());
        }
    }

    /**
     * Process a single bot.
     *
     * @return array{built: bool, ship_built: bool, attacked: bool, spied: bool, building: string|null, ship: string|null, research: string|null, attack_target: string|null, spy_target: string|null}
     */
    private function processBot(User $bot, bool $dryRun): array
    {
        $result = [
            'built' => false, 'ship_built' => false, 'attacked' => false, 'spied' => false, 'fleet_saved' => false, 'expeditions' => 0, 'harvests' => 0,
            'building' => null, 'ship' => null, 'research' => null,
            'attack_target' => null, 'spy_target' => null,
            'idle_planets' => 0, 'escalated' => 0, 'stuck' => 0, 'reserved' => 0,
        ];

        // Load ALL planets for this bot (not just first)
        $planetRows = DB::select(
            $this->prepareSql(
                'SELECT p.*, b.*, d.*, s.*
                FROM ' . PLANETS . ' AS p
                INNER JOIN ' . BUILDINGS . ' AS b ON b.building_planet_id = p.`planet_id`
                INNER JOIN ' . DEFENSES . ' AS d ON d.defense_planet_id = p.`planet_id`
                INNER JOIN ' . SHIPS . ' AS s ON s.ship_planet_id = p.`planet_id`
                WHERE p.`planet_user_id` = ' . $bot->id . '
                AND p.`planet_destroyed` = 0
                ORDER BY p.`planet_id` ASC;'
            )
        );

        if (empty($planetRows)) {
            return $result;
        }

        // Load user data once (shared across all planets)
        $userRow = DB::selectOne(
            $this->prepareSql(
                'SELECT u.*, pre.*, pr.*, r.*
                FROM ' . USERS . ' AS u
                INNER JOIN ' . PREFERENCES . ' AS pr ON pr.preference_user_id = u.id
                INNER JOIN ' . PREMIUM . ' AS pre ON pre.premium_user_id = u.id
                INNER JOIN ' . RESEARCH . ' AS r ON r.research_user_id = u.id
                WHERE u.`id` = ' . $bot->id . '
                LIMIT 1;'
            )
        );

        $user = $userRow !== null ? (array) $userRow : [];

        // Add unprefixed research keys so levelsFromPlanet() can find them
        foreach ($user as $key => $value) {
            if (str_starts_with($key, 'research_') && !isset($user[substr($key, 9)])) {
                $user[substr($key, 9)] = $value;
            }
        }

        // Load bot profile
        $profile = json_decode((string) ($bot->bot_profile ?? '{}'), true);
        $personality = $profile['personality'] ?? 'raider';

        // Track best attack origin — prefer moons (harder for victims to trace)
        $attackPlanet = null;
        $attackMoon = null;
        $researchQueued = false;

        // --- PER-PLANET LOOP ---
        foreach ($planetRows as $planetRow) {
            $planet = (array) $planetRow;

            // --- Phase 0: Fleet save (reactive + nightly) ---
            if ($personality !== 'passive') {
                $attacks = $this->protector->getIncomingAttacks($planet);

                if (!empty($attacks)) {
                    $shouldSave = match ($personality) {
                        'turtle', 'raider' => true,
                        'balanced' => random_int(1, 100) <= 70,
                        default => false,
                    };

                    if ($shouldSave) {
                        $saved = $this->protector->attemptFleetSave($planet, $user, $attacks);

                        if ($saved) {
                            $result['fleet_saved'] = true;
                            $this->line("  [{$bot->id}] {$bot->name}: FLEET SAVE on {$planet['planet_galaxy']}:{$planet['planet_system']}:{$planet['planet_planet']} (incoming attack!)");
                            continue; // Skip this planet, process others
                        }
                    }
                }

                // Nightly fleet save: turtles save before sleep
                if (!empty($profile)) {
                    $nightlySaved = $this->protector->nightlyFleetSave($planet, $user, $profile);

                    if ($nightlySaved) {
                        $result['fleet_saved'] = true;
                        $this->line("  [{$bot->id}] {$bot->name}: nightly fleet save on {$planet['planet_galaxy']}:{$planet['planet_system']}:{$planet['planet_planet']}");
                        continue;
                    }
                }
            }

            // --- Phase 1: Tick resources ---
            $now = time();
            $lastUpdate = (int) ($planet['planet_last_update'] ?? 0);

            if ($lastUpdate > 0 && $now > $lastUpdate) {
                UpdatesLibrary::updatePlanetResources($user, $planet, $now);
            }

            // --- Phase 2: Process building completions ---
            $planetModel = Planets::with(['buildings'])->where('planet_id', $planet['planet_id'])->first();

            if ($planetModel) {
                $this->queueService->processCompletions($planetModel, $user);
                $this->syncPlanetFromModel($planet, $planetModel);
            }

            // --- Phase 3: Queue next building (reuse cached model) ---
            $buildingId = null;
            $buildingQueueEmpty = $planetModel && $planetModel->buildingQueue()->count() === 0;
            if ($buildingQueueEmpty) {
                $buildingId = $this->brain->nextBuilding($planet, $user);

                if ($buildingId !== null && !$dryRun) {
                    $success = $this->queueService->add($planetModel, $user, $buildingId, 'build');

                    if ($success) {
                        $result['built'] = true;
                        $result['building'] = $this->getBuildingName($buildingId);
                    }
                } elseif ($buildingId !== null) {
                    $result['built'] = true;
                    $result['building'] = $this->getBuildingName($buildingId);
                }
            }

            // Refresh planet data after building queue
            if ($planetModel) {
                $planetModel->refresh();
                $this->syncPlanetFromModel($planet, $planetModel);
            }

            // --- Phase 4: Queue ship/defense production ---
            // Energy handling lives in BotBrain::nextShip(): when the planet is in deficit it
            // returns Solar Satellites first (cheap crystal/deut, no metal). The old blanket
            // "skip all ships while energy is negative" gate (Aug 9) is gone — combined with the
            // inverted isEnergyNegative() sign it silenced ship production on every planet.
            //
            // Building reserve (6 Sep): if the building phase wanted something it could not
            // afford, the shipyard may only spend what is left above that building's cost.
            // Otherwise cruisers/fighters ate every spare crystal each tick and the 250-460K
            // crystal buildings were never reached (140-190 idle planets overnight). Capped at
            // two days of the planet's own production so an out-of-reach building (Nanites)
            // cannot freeze the hangar for a week; the merchant ladder does the rest.
            $reserve = [];
            if ($buildingQueueEmpty && $buildingId === null && ((int) ($planet['planet_type'] ?? 1)) !== 3) {
                $wanted = $this->brain->wantedBuildingCost($planet, $user);
                if ($wanted !== null) {
                    foreach (['metal', 'crystal', 'deuterium'] as $res) {
                        $cap = 48 * (float) ($planet["planet_{$res}_perhour"] ?? 0);
                        $reserve[$res] = min((float) $wanted[$res], max($cap, 100_000.0));
                    }
                    $result['reserved']++;
                    if ($dryRun) {
                        $this->line(sprintf(
                            "  [%d] %s: saving for %s on %d:%d:%d (reserve %dK/%dK/%dK, has %dK/%dK/%dK)",
                            $bot->id, $bot->name, $this->getBuildingName((int) $wanted['building_id']),
                            $planet['planet_galaxy'], $planet['planet_system'], $planet['planet_planet'],
                            $reserve['metal'] / 1000, $reserve['crystal'] / 1000, $reserve['deuterium'] / 1000,
                            ($planet['planet_metal'] ?? 0) / 1000, ($planet['planet_crystal'] ?? 0) / 1000, ($planet['planet_deuterium'] ?? 0) / 1000
                        ));
                    }
                }
            }

            $shipDecision = $this->brain->nextShip($planet, $user, $reserve);

            if ($shipDecision !== null) {
                $shipId = $shipDecision['ship_id'];
                $count = $shipDecision['count'];

                if (!$dryRun) {
                    $this->queueShipProduction($planet['planet_id'], $shipId, $count, $shipDecision['cost']);
                }

                $result['ship_built'] = true;
                $result['ship'] = $this->getShipName($shipId) . " x{$count}";

                if (!$dryRun) {
                    $planetModel->refresh();
                    $this->syncPlanetFromModel($planet, $planetModel);
                }
            }

            // Queue research (once per bot, from first planet with a lab)
            $isResearchPlanet = false;
            $researchId = null;
            if (!$researchQueued) {
                $labLevel = (int) ($planet['building_laboratory'] ?? 0);

                if ($labLevel >= 1 && $planetModel) {
                    $isResearchPlanet = true;
                    $researchId = $this->brain->nextResearch($user, $planet);

                    if ($researchId !== null && !$dryRun) {
                        $technocrateActive = (int) ($user['premium_officier_technocrat'] ?? 0) > time();
                        $success = $this->researchQueueService->add($bot, $planetModel, $user, $researchId, $technocrateActive);

                        if ($success) {
                            $result['research'] = 'research queued';
                        }
                    } elseif ($researchId !== null) {
                        $result['research'] = 'research queued';
                    }
                    $researchQueued = true;
                }
            }

            // --- Phase 3.5: Idle ladder (self-healing) ---
            // Idle = the economy is stuck: nothing building and the brain chose nothing, and on
            // the research planet nothing researching and nothing chosen. Ships deliberately
            // don't count — cheap fighters get queued every tick while the crystal wall stands.
            $ladderActive = $this->ladder->isEnabled() || ($dryRun && (int) $this->option('ladder-assume-idle') > 0);
            if ($planetModel && $ladderActive && ((int) ($planet['planet_type'] ?? 1)) !== 3) {
                $researchIdle = !$isResearchPlanet
                    || ((int) ($user['research_current_research'] ?? 0) === 0 && $researchId === null);
                $idle = $buildingQueueEmpty && $buildingId === null && $researchIdle;

                $state = $this->ladder->recordTick((int) $planet['planet_id'], $idle, time(), $dryRun);
                if ($idle && $dryRun) {
                    $state['idle_ticks'] = max($state['idle_ticks'], (int) $this->option('ladder-assume-idle'));
                }

                if ($idle) {
                    $result['idle_planets']++;
                    $rung = $this->ladder->dueRung($state);

                    if ($rung === 1) {
                        $trade = $this->ladder->planMerchantTrade($planet);
                        $coords = "{$planet['planet_galaxy']}:{$planet['planet_system']}:{$planet['planet_planet']}";

                        if ($trade === null) {
                            $this->line("  [{$bot->id}] {$bot->name}: ladder rung 1 on {$coords} — nothing to sell (idle {$state['idle_ticks']} ticks)");
                        } elseif ($dryRun) {
                            $after = $planet;
                            $after[$trade['sell_resource']] -= $trade['sell'];
                            $after['planet_crystal'] += $trade['crystal_gain'];
                            $wouldBuild = $this->brain->nextBuilding($after, $user);
                            $wouldResearch = $isResearchPlanet ? $this->brain->nextResearch($user, $after) : null;
                            $this->line("  [{$bot->id}] {$bot->name}: would {$trade['note']} on {$coords} → build:"
                                . ($wouldBuild !== null ? $this->getBuildingName($wouldBuild) : '-')
                                . ' research:' . ($wouldResearch !== null ? "#{$wouldResearch}" : '-'));
                            $result['escalated']++;
                        } else {
                            $this->ladder->applyMerchantTrade($planet, $trade, time());
                            $result['escalated']++;
                            $this->line("  [{$bot->id}] {$bot->name}: ladder {$trade['note']} on {$coords}");

                            // Spend it now: re-run building, then research, with the new balance
                            $planetModel->refresh();
                            $this->syncPlanetFromModel($planet, $planetModel);
                            $retryBuilding = $this->brain->nextBuilding($planet, $user);
                            if ($retryBuilding !== null && $this->queueService->add($planetModel, $user, $retryBuilding, 'build')) {
                                $result['built'] = true;
                                $result['building'] = $this->getBuildingName($retryBuilding);
                                $planetModel->refresh();
                                $this->syncPlanetFromModel($planet, $planetModel);
                            }
                            if ($isResearchPlanet && $result['research'] === null) {
                                $retryResearch = $this->brain->nextResearch($user, $planet);
                                if ($retryResearch !== null) {
                                    $technocrateActive = (int) ($user['premium_officier_technocrat'] ?? 0) > time();
                                    if ($this->researchQueueService->add($bot, $planetModel, $user, $retryResearch, $technocrateActive)) {
                                        $result['research'] = 'research queued';
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Resource trading
            if (!$this->dispatcher->hasActiveFleetFromPlanet($planet['planet_galaxy'], $planet['planet_system'], $planet['planet_planet'])) {
                $tradeOpportunity = $this->trader->findSharingOpportunity($planet, $user);

                if ($tradeOpportunity !== null) {
                    $destination = [
                        'galaxy' => $tradeOpportunity['target_galaxy'],
                        'system' => $tradeOpportunity['target_system'],
                        'planet' => $tradeOpportunity['target_planet'],
                    ];

                    if (!$dryRun) {
                        $fleetId = $this->dispatcher->sendTransport(
                            $planet, $user, $destination,
                            $tradeOpportunity['metal'],
                            $tradeOpportunity['crystal'],
                            $tradeOpportunity['deuterium']
                        );

                        if ($fleetId) {
                            $this->line("  [{$bot->id}] {$bot->name}: sharing resources from {$planet['planet_galaxy']}:{$planet['planet_system']}:{$planet['planet_planet']}");
                        }
                    }
                }
            }

            // Colonization
            if (!$this->dispatcher->hasActiveFleetFromPlanet($planet['planet_galaxy'], $planet['planet_system'], $planet['planet_planet'])
                && $this->colonizer->shouldColonize($user)
                && $this->colonizer->hasColonyShip($planet)
            ) {
                $colTarget = $this->colonizer->findColonizationTarget($planet, $bot->id);

                if ($colTarget !== null) {
                    $colonyFleet = [208 => 1];
                    if ((int) ($planet['ship_small_cargo_ship'] ?? 0) > 0) {
                        $colonyFleet[202] = 1;
                    }

                    if (!$dryRun) {
                        $fleetId = $this->dispatcher->sendColonize($planet, $user, $colTarget, $colonyFleet);
                        if ($fleetId) {
                            $this->line("  [{$bot->id}] {$bot->name}: colonizing from {$planet['planet_galaxy']}:{$planet['planet_system']}:{$planet['planet_planet']}");
                        }
                    } else {
                        $this->line("  [{$bot->id}] {$bot->name}: would colonize {$colTarget['galaxy']}:{$colTarget['system']}:{$colTarget['planet']}");
                    }
                }
            }

            // Debris field harvesting — the field on the bot's own doorstep first (every raid it
            // suffers leaves one), then fields it knows about from its own battles, both sized from
            // the LIVE planet debris. Only a recycle mission already out from here blocks it; until
            // 7 Sep ANY fleet out did — and no bot owned a recycler anyway (209 was in no build list).
            if ($this->harvester->hasRecyclers($planet)
                && !$this->dispatcher->hasActiveFleetFromPlanet(
                    (int) $planet['planet_galaxy'], (int) $planet['planet_system'], (int) $planet['planet_planet'],
                    (int) ($planet['planet_type'] ?? 1), [Missions::RECYCLE]
                )
            ) {
                $debrisTarget = $this->harvester->findOwnDebris($planet)
                    ?? $this->harvester->findHarvestTarget($planet, $bot->id);

                if ($debrisTarget !== null) {
                    $needed = $this->harvester->calcRecyclersNeeded($debrisTarget);
                    $available = (int) ($planet['ship_recycler'] ?? 0);
                    $toSend = min($needed, $available);
                    $where = "{$debrisTarget['galaxy']}:{$debrisTarget['system']}:{$debrisTarget['planet']}";
                    $what = number_format($debrisTarget['debris_metal']) . 'M/' . number_format($debrisTarget['debris_crystal']) . 'C';

                    if ($toSend > 0) {
                        if (!$dryRun) {
                            $fleetId = $this->dispatcher->sendRecycle($planet, $user, $debrisTarget, [209 => $toSend]);
                            if ($fleetId) {
                                if ($toSend >= $needed) {
                                    // Whole field covered — forget the log rows. A partial trip leaves them
                                    // so the bot comes back for the rest when the recyclers are home.
                                    $this->harvester->markHarvestedAt(
                                        $debrisTarget['galaxy'], $debrisTarget['system'], $debrisTarget['planet'], $bot->id
                                    );
                                }
                                $planet['ship_recycler'] = $available - $toSend;
                                $result['harvests']++;
                                $this->line("  [{$bot->id}] {$bot->name}: harvesting {$what} at {$where} ({$toSend} recyclers)");
                            }
                        } else {
                            $result['harvests']++;
                            $this->line("  [{$bot->id}] {$bot->name}: would harvest {$what} at {$where} ({$toSend} of {$needed} recyclers)");
                        }
                    }
                }
            }

            // Alliance coordination
            if (!$this->dispatcher->hasActiveFleetFromPlanet($planet['planet_galaxy'], $planet['planet_system'], $planet['planet_planet'])) {
                $supportTarget = $this->alliance->findDefensiveOpportunity($planet);

                if ($supportTarget !== null) {
                    $supportFleet = $this->alliance->getAvailableSupportFleet($planet);

                    if (!empty($supportFleet)) {
                        $destination = [
                            'galaxy' => $supportTarget['target_galaxy'],
                            'system' => $supportTarget['target_system'],
                            'planet' => $supportTarget['target_planet'],
                        ];

                        if (!$dryRun) {
                            $fleetId = $this->dispatcher->sendDeploy($planet, $user, $destination, $supportFleet, 3600);

                            if ($fleetId) {
                                $this->line("  [{$bot->id}] {$bot->name}: sending defensive support from {$planet['planet_galaxy']}:{$planet['planet_system']}:{$planet['planet_planet']}");
                            }
                        }
                    }
                }
            }

            // (Expedition dispatch moved after Phase 5 — see sendExpeditions(). Sending it here
            //  parked a fleet on the home planet before the spy/attack phase ever ran.)

            // Track best attack origin — moons preferred (stealthier)
            $combatShips = (int) ($planet['ship_light_fighter'] ?? 0)
                + (int) ($planet['ship_heavy_fighter'] ?? 0)
                + (int) ($planet['ship_cruiser'] ?? 0)
                + (int) ($planet['ship_battleship'] ?? 0);
            if ($combatShips > 0) {
                if (((int) ($planet['planet_type'] ?? 1)) === 3) {
                    $attackMoon = $planet; // Moon with ships — best origin
                } elseif ($attackPlanet === null) {
                    $attackPlanet = $planet; // Fallback: first planet with ships
                }
            }
        } // end planet loop

        // Process research completions (once per bot)
        $this->researchQueueService->processCompletions($bot);

        // Refresh user data after research completion
        $userRow2 = DB::selectOne(
            $this->prepareSql(
                'SELECT u.*, pre.*, pr.*, r.*
                FROM ' . USERS . ' AS u
                INNER JOIN ' . PREFERENCES . ' AS pr ON pr.preference_user_id = u.id
                INNER JOIN ' . PREMIUM . ' AS pre ON pre.premium_user_id = u.id
                INNER JOIN ' . RESEARCH . ' AS r ON r.research_user_id = u.id
                WHERE u.`id` = ' . $bot->id . '
                LIMIT 1;'
            )
        );
        $user = $userRow2 !== null ? (array) $userRow2 : $user;
        foreach ($user as $key => $value) {
            if (str_starts_with($key, 'research_') && !isset($user[substr($key, 9)])) {
                $user[substr($key, 9)] = $value;
            }
        }

        // Parse espionage reports (once per bot)
        $intelParsed = $this->intel->parseNewReports($bot->id);
        if ($intelParsed > 0) {
            $this->line("  [{$bot->id}] {$bot->name}: parsed {$intelParsed} spy reports");
        }

        // --- Phase 4.9: Moon supply (ship resources to moons) ---
        // Moons have zero resource production — they need supplies from planets.
        // Runs once per bot, before attack phase so cargos aren't away raiding.
        $moonRows = array_filter($planetRows, fn ($p) => ((int) ((array) $p)['planet_type'] ?? 1) === 3);
        $moonRows = array_values($moonRows); // Re-index after filter
        if (!empty($moonRows)) {
            $moonSupplyResult = $this->supplyMoons($bot, $user, $planetRows, $moonRows, $dryRun);
            if ($moonSupplyResult !== null) {
                $this->line("  [{$bot->id}] {$bot->name}: moon supply → {$moonSupplyResult}");
            }
        }

        // --- Phase 5: Scout and attack ---
        // Prefer moon as attack origin (harder for victim to trace back).
        // If no moon with ships, use first planet with combat ships.
        $planet = $attackMoon ?? $attackPlanet ?? (array) $planetRows[0];
        $personality = $this->brain->getPersonality($user);

        // Only a spy/attack already in flight from this origin blocks the phase. Expeditions,
        // transports etc. used to block it too, which kept raiders from ever probing.
        $originBusy = $this->dispatcher->hasActiveFleetFromPlanet(
            (int) $planet['planet_galaxy'], (int) $planet['planet_system'], (int) $planet['planet_planet'],
            (int) ($planet['planet_type'] ?? 1),
            [Missions::ATTACK, Missions::SPY]
        );

        if ($personality !== 'passive' && !$originBusy) {
            // Scan for nearby targets
            $targets = $this->scanner->scan($planet, 5);
            $probes = (int) ($planet['ship_espionage_probe'] ?? 0);

            // Intel we already hold (newest scan first per planet) — drives both phases below.
            $intelData = $this->intel->getAllIntel($bot->id);
            $latestScan = [];
            foreach ($intelData as $row) {
                $key = "{$row['galaxy']}:{$row['system']}:{$row['planet']}";
                $latestScan[$key] = max($latestScan[$key] ?? 0, (int) $row['scanned_at']);
            }

            // SPY PHASE: Send probes to multiple targets to gather intel
            $spiedCount = 0;
            $maxSpyPerTick = 3;
            // Probes per spy mission. legacy/app/Libraries/Missions/Spy.php shows a report section only when
            // probes_sent >= need - gap², need = 1 resources / 2 fleet / 3 defence / 5 buildings. All bots sit at
            // espionage 8-9 (gap 0-1), so a single probe reported resources only and the simulator saw an
            // undefended planet -> 85 % of raids lost (5 Sep). Three probes always show fleet + defence.
            $probesPerSpy = 3;

            if ($probes >= $probesPerSpy && !empty($targets)) {
                foreach ($targets as $spyTarget) {
                    if ($spiedCount >= $maxSpyPerTick) break;
                    if ($probes - $spiedCount * $probesPerSpy < $probesPerSpy) break;

                    // Don't re-probe a planet we scanned minutes ago: the same top-3 neighbours were
                    // being hit every tick, and about half of those probe flights get shot down
                    // (Spy.php: detection chance scales with the target's fleet) — 3K crystal a go.
                    $key = "{$spyTarget['galaxy']}:{$spyTarget['system']}:{$spyTarget['planet']}";
                    if (time() - ($latestScan[$key] ?? 0) < self::SPY_REFRESH_SECONDS) {
                        continue;
                    }

                    if (!$dryRun) {
                        $fleetId = $this->dispatcher->sendSpy($planet, $user, $spyTarget, $probesPerSpy);
                        if ($fleetId) {
                            $result['spied'] = true;
                            $result['spy_target'] = $key;
                            $spiedCount++;
                        }
                    } else {
                        $result['spied'] = true;
                        $result['spy_target'] = $key;
                        $spiedCount++;
                    }
                }
            }
            $probesLeft = $probes - $spiedCount * $probesPerSpy;

            // ATTACK PHASE: walk the intel from richest down and raid the first target the battle
            // engine says we beat. Until 6 Sep only the single richest entry was ever tried (with a
            // fixed 65-ship raid), so one fat neighbour blocked every raid → attacks=0 all night.
            $attackTarget = null;
            $attackFleet = null;
            $staleTarget = null;   // richest candidate whose intel is too old to trust → re-spy it
            $candidatesTried = 0;

            if ($dryRun) {
                $this->line("  [{$bot->id}] INTEL: " . count($intelData) . " entries");
            }

            if (!empty($intelData)) {
                $prefix = DB::getTablePrefix();
                $botStats = DB::selectOne("SELECT user_statistic_total_points FROM `{$prefix}users_statistics` WHERE `user_statistic_user_id` = ?", [$user['id']]);
                $botPoints = (int) ($botStats->user_statistic_total_points ?? 0);
                $noobLib = new \Xgp\App\Libraries\NoobsProtectionLib();

                $grudges = $this->grudge->getGrudges($bot->id);
                $grudgeTargets = [];
                foreach ($grudges as $g) {
                    $grudgeTargets[$g['attacker_id']] = $g;
                }

                // One entry per planet — the newest scan (getAllIntel is newest-first)
                $latest = [];
                foreach ($intelData as $row) {
                    $key = "{$row['galaxy']}:{$row['system']}:{$row['planet']}";
                    $latest[$key] ??= $row;
                }
                $candidates = array_values($latest);

                usort($candidates, function ($a, $b) use ($grudgeTargets) {
                    $aGrudge = $grudgeTargets[$a['user_id'] ?? 0] ?? null;
                    $bGrudge = $grudgeTargets[$b['user_id'] ?? 0] ?? null;
                    $aSeverity = $aGrudge ? ($aGrudge['attack_count'] ?? 0) : 0;
                    $bSeverity = $bGrudge ? ($bGrudge['attack_count'] ?? 0) : 0;

                    if ($aSeverity !== $bSeverity) {
                        return $bSeverity <=> $aSeverity;
                    }

                    return $b['total_resources'] <=> $a['total_resources'];
                });

                foreach ($candidates as $intelTarget) {
                    if ($candidatesTried >= self::ATTACK_CANDIDATES) break;

                    if ($intelTarget['total_resources'] < self::ATTACK_MIN_RESOURCES) continue; // not worth the fuel

                    $distance = \Xgp\App\Libraries\FleetsLib::targetDistance(
                        (int) $planet['planet_galaxy'],
                        $intelTarget['galaxy'],
                        (int) $planet['planet_system'],
                        $intelTarget['system'],
                        (int) $planet['planet_planet'],
                        $intelTarget['planet']
                    );

                    if ($distance > 5000) continue;

                    $defenderId = (int) ($intelTarget['user_id'] ?? 0);
                    if ($defenderId === (int) $user['id']) continue;

                    if ($defenderId > 0) {
                        $defStats = DB::selectOne("SELECT user_statistic_total_points FROM `{$prefix}users_statistics` WHERE `user_statistic_user_id` = ?", [$defenderId]);
                        $defPoints = (int) ($defStats->user_statistic_total_points ?? 0);

                        if ($noobLib->isWeak($botPoints, $defPoints) || $noobLib->isStrong($botPoints, $defPoints)) {
                            continue;
                        }

                        $recentLosses = DB::table('bot_combat_log')
                            ->where('attacker_id', $bot->id)
                            ->where('defender_id', $defenderId)
                            ->where('result', 'loss')
                            ->where('created_at', '>', now()->subDays(7))
                            ->count();

                        if ($recentLosses >= 3) {
                            if ($dryRun) {
                                $this->line("  [{$bot->id}] SKIP: {$defenderId} ({$recentLosses} losses in 7d)");
                            }
                            continue;
                        }
                    }

                    $intelTarget['distance'] = $distance;
                    $intelTarget['resources'] = $intelTarget['total_resources'];
                    $intelTarget['user_id'] = $defenderId;
                    $intelTarget['planet_data'] = $this->buildDefenderFromIntel($intelTarget);
                    $candidatesTried++;

                    $coords = "{$intelTarget['galaxy']}:{$intelTarget['system']}:{$intelTarget['planet']}";
                    $intelAge = time() - (int) ($intelTarget['scanned_at'] ?? 0);

                    if ($intelAge > self::INTEL_MAX_AGE) {
                        $staleTarget ??= $intelTarget;
                        if ($dryRun) {
                            $this->line("  [{$bot->id}] STALE: {$coords} res={$intelTarget['total_resources']} age=" . intdiv($intelAge, 60) . "m");
                        }
                        continue;
                    }

                    $fleet = $this->brain->planAttack($planet, $user, $intelTarget);

                    if ($dryRun) {
                        $grudgeInfo = isset($grudgeTargets[$defenderId]) ? " GRUDGE({$grudgeTargets[$defenderId]['attack_count']}x)" : '';
                        $this->line("  [{$bot->id}] TRY: {$coords} res={$intelTarget['total_resources']} dist={$distance} age=" . intdiv($intelAge, 60) . "m"
                            . $grudgeInfo . ' sim=' . json_encode($this->brain->lastAttackDebug)
                            . ($fleet !== null ? ' → ATTACK ' . json_encode($fleet) : ''));
                    }

                    if ($fleet !== null) {
                        $attackTarget = $intelTarget;
                        $attackFleet = $fleet;
                        break;
                    }
                }
            }

            if ($attackFleet !== null) {
                $coords = "{$attackTarget['galaxy']}:{$attackTarget['system']}:{$attackTarget['planet']}";
                if (!$dryRun) {
                    try {
                        $fleetId = $this->dispatcher->sendAttack($planet, $user, $attackTarget, $attackFleet);
                        if ($fleetId) {
                            $result['attacked'] = true;
                            $result['attack_target'] = $coords;
                            $this->chat->sendAttackWinMessage($user, (int) ($attackTarget['user_id'] ?? 0), $personality);
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error("BotTick: attack dispatch crashed for {$bot->name}: " . $e->getMessage());
                    }
                } else {
                    $result['attacked'] = true;
                    $result['attack_target'] = $coords;
                }
            } elseif ($staleTarget !== null && $probesLeft >= $probesPerSpy) {
                // Nothing fresh we can beat — refresh the richest stale entry so next tick can decide.
                $coords = "{$staleTarget['galaxy']}:{$staleTarget['system']}:{$staleTarget['planet']}";
                if (!$dryRun) {
                    if ($this->dispatcher->sendSpy($planet, $user, $staleTarget, $probesPerSpy)) {
                        $result['spied'] = true;
                        $result['spy_target'] = "{$coords} (refresh)";
                    }
                } else {
                    $result['spied'] = true;
                    $result['spy_target'] = "{$coords} (refresh)";
                }
            }
        }

        // --- Phase 6: Expeditions — every tick, whatever Phase 5 did ---
        // Until 7 Sep only a bot that neither spied nor attacked sent any, so raiders (most of the
        // universe) almost never did. Dale: expeditions are a massive thing for players, so they
        // should be for bots too. Raids keep first call — Phase 5 has already taken its ships, and
        // sendExpeditions() re-reads what is still at home.
        $result['expeditions'] = $this->sendExpeditions($bot, $user, $planetRows, $dryRun);

        return $result;
    }

    /**
     * Send expeditions from idle planets. Runs AFTER the spy/attack phase so raiding always
     * gets first call on the fleet. Respects the game's expedition and fleet-slot limits and
     * keeps 2 fleet slots free for spying/attacking.
     *
     * @param  array<int, object>  $planetRows
     * @return int  Expeditions sent (or that would be sent in dry-run)
     */
    private function sendExpeditions(User $bot, array $user, array $planetRows, bool $dryRun): int
    {
        $astroLevel = (int) ($user['research_astrophysics'] ?? 0);
        if ($astroLevel < 1) {
            return 0;
        }

        $maxExpeditions = \Xgp\App\Libraries\FleetsLib::getMaxExpeditions($astroLevel);
        $availableSlots = $maxExpeditions - $this->dispatcher->countActiveExpeditions((int) $bot->id);
        if ($availableSlots <= 0) {
            return 0;
        }

        $maxFleets = \Xgp\App\Libraries\FleetsLib::getMaxFleets(
            (int) ($user['research_computer_technology'] ?? 0),
            (int) ($user['premium_officier_admiral'] ?? 0)
        );
        $freeFleetSlots = $maxFleets - $this->dispatcher->countActiveFleets((int) $bot->id);

        $profile = json_decode((string) ($bot->bot_profile ?? '{}'), true);
        $isActive = $this->isInActiveWindow($profile);
        $sent = 0;

        foreach ($planetRows as $planetRow) {
            if ($availableSlots <= 0 || $freeFleetSlots < self::EXPEDITION_KEEP_FREE_SLOTS + 1) {
                break;
            }

            // Phase 5 and the harvest phase have already taken ships and fuel from this planet
            // this tick — re-read what is actually still at home. (A raid or spy flight out from
            // here no longer blocks an expedition; the slot counts above are the limit.)
            $planet = $this->refreshPlanetShips((array) $planetRow);

            // Duration first: it sizes the cargo (finds scale with the stay length).
            // Short during active hours, long overnight (fleet save).
            $stayDuration = $this->pickExpeditionDuration($profile);
            $hours = round($stayDuration / 3600, 1);

            $expFleet = $this->buildExpeditionFleet($planet, $user, $stayDuration, $isActive);
            if (empty($expFleet)) {
                continue;
            }

            // Pick a system to send expedition to — rotate around home system
            $expSystem = $this->pickExpeditionSystem((int) $planet['planet_galaxy'], (int) $planet['planet_system'], (int) $bot->id);

            if ($dryRun) {
                $this->line("  [{$bot->id}] {$bot->name}: would send expedition → {$planet['planet_galaxy']}:{$expSystem}:16 ({$hours}h) " . json_encode($expFleet));
                $sent++;
                $availableSlots--;
                $freeFleetSlots--;
                continue;
            }

            $fleetId = $this->dispatcher->sendExpedition(
                $planet,
                $user,
                (int) $planet['planet_galaxy'],
                $expSystem,
                $expFleet,
                $stayDuration
            );

            if ($fleetId) {
                $this->line("  [{$bot->id}] {$bot->name}: expedition → {$planet['planet_galaxy']}:{$expSystem}:16 ({$hours}h) " . json_encode($expFleet));
                $sent++;
                $availableSlots--;
                $freeFleetSlots--;
            }
        }

        return $sent;
    }
    /**
     * Queue ship/defense production via the hangar system.
     *
     * This inserts directly into the planet's hangar queue (planet_b_hangar_id).
     */
    private function queueShipProduction(int $planetId, int $shipId, int $count, array $costPerUnit): void
    {
        $totalMetal = $costPerUnit['metal'] * $count;
        $totalCrystal = $costPerUnit['crystal'] * $count;
        $totalDeuterium = $costPerUnit['deuterium'] * $count;

        // Add to hangar queue AND deduct resources
        // $shipId and $count are always ints — no injection risk, safe to embed directly
        $hangarEntry = $shipId . ',' . $count . ';';
        DB::table('planets')
            ->where('planet_id', $planetId)
            ->update([
                'planet_b_hangar_id' => DB::raw("CONCAT(IFNULL(planet_b_hangar_id, ''), '" . $hangarEntry . "')"),
                'planet_metal'       => DB::raw('planet_metal - ' . (int) $totalMetal),
                'planet_crystal'     => DB::raw('planet_crystal - ' . (int) $totalCrystal),
                'planet_deuterium'   => DB::raw('planet_deuterium - ' . (int) $totalDeuterium),
            ]);
    }

    /**
     * Supply moons with resources from parent planets.
     *
     * Moons have zero production — they need resources shipped from planets.
     * Checks what the moon could build next, calculates cost, and sends
     * a transport fleet from the nearest planet that shares coordinates.
     *
     * @param  array<int, array<string, mixed>>  $planetRows  All planets (including moons)
     * @param  array<int, array<string, mixed>>  $moonRows    Only moon rows
     */
    private function supplyMoons(User $bot, array $user, array $planetRows, array $moonRows, bool $dryRun): ?string
    {
        foreach ($moonRows as $moon) {
            $moon = (array) $moon;

            // Find the parent planet (same G:S:P, type 1)
            $parentPlanet = null;
            foreach ($planetRows as $p) {
                $p = (array) $p;
                if (((int) ($p['planet_type'] ?? 1)) === 1
                    && (int) $p['planet_galaxy'] === (int) $moon['planet_galaxy']
                    && (int) $p['planet_system'] === (int) $moon['planet_system']
                    && (int) $p['planet_planet'] === (int) $moon['planet_planet']
                ) {
                    $parentPlanet = $p;
                    break;
                }
            }

            if ($parentPlanet === null) {
                continue; // No parent planet found (shouldn't happen)
            }

            // Skip if parent planet already has a fleet out
            if ($this->dispatcher->hasActiveFleetFromPlanet(
                (int) $parentPlanet['planet_galaxy'],
                (int) $parentPlanet['planet_system'],
                (int) $parentPlanet['planet_planet']
            )) {
                continue;
            }

            // Find what the moon SHOULD build next (priority order)
            // Don't use nextBuilding() — it checks canAfford(), which fails
            // when the moon has 0 resources (chicken-and-egg deadlock).
            // Instead, walk the priority list manually and find the first
            // building that isn't maxed yet.
            $moonBuilding = null;
            $moonLevel = 0;
            foreach (\App\Services\Bot\BotBrain::MOON_BUILDING_PRIORITY as $bId => $config) {
                $lvl = $this->brain->getBuildingLevel($bId, $moon);
                if ($lvl < $config['cap']) {
                    // Check prerequisites
                    $needsLunarBase = in_array($bId, [42, 43, 44], true); // Phalanx, Jump Gate, Silo
                    $needsRF = ($bId === 43); // Jump Gate needs RF >= 1
                    $lunarBaseLevel = $this->brain->getBuildingLevel(41, $moon);
                    $rfLevel = $this->brain->getBuildingLevel(14, $moon);

                    if ($needsLunarBase && $lunarBaseLevel < 1) {
                        $moonBuilding = 41; // Force Lunar Base first
                        $moonLevel = $lunarBaseLevel;
                        break;
                    }
                    if ($needsRF && $rfLevel < 1) {
                        $moonBuilding = 14; // Force Robot Factory first
                        $moonLevel = $rfLevel;
                        break;
                    }

                    $moonBuilding = $bId;
                    $moonLevel = $lvl;
                    break;
                }
            }

            if ($moonBuilding === null) {
                continue; // All moon buildings maxed
            }

            // Calculate cost of next moon building
            $cost = $this->getMoonBuildingCost($moonBuilding, $moonLevel);

            // Check if moon already has enough resources
            $moonMetal = (float) ($moon['planet_metal'] ?? 0);
            $moonCrystal = (float) ($moon['planet_crystal'] ?? 0);
            $moonDeut = (float) ($moon['planet_deuterium'] ?? 0);

            $needMetal = max(0, $cost['metal'] - $moonMetal);
            $needCrystal = max(0, $cost['crystal'] - $moonCrystal);
            $needDeut = max(0, $cost['deuterium'] - $moonDeut);

            if ($needMetal <= 0 && $needCrystal <= 0 && $needDeut <= 0) {
                continue; // Moon already has enough
            }

            // Check if parent planet can afford to send
            $planetMetal = (float) ($parentPlanet['planet_metal'] ?? 0);
            $planetCrystal = (float) ($parentPlanet['planet_crystal'] ?? 0);
            $planetDeut = (float) ($parentPlanet['planet_deuterium'] ?? 0);

            // Keep a reserve on the planet — but be aggressive for early moons.
            // A moon with Sensor Phalanx is a game-changer for area domination.
            // Rush Lunar Base + Phalanx before worrying about planet reserves.
            $lunarBaseLevel = $this->brain->getBuildingLevel(41, $moon);
            $phalanxLevel = $this->brain->getBuildingLevel(42, $moon);

            if ($lunarBaseLevel < 3 || $phalanxLevel < 1) {
                // Early moon: be aggressive, send up to 70% of planet resources
                $reserveFraction = 0.15;
            } else {
                // Established moon: normal pace
                $reserveFraction = 0.3;
            }

            $sendMetal = min($needMetal, (int) ($planetMetal * (1 - $reserveFraction)));
            $sendCrystal = min($needCrystal, (int) ($planetCrystal * (1 - $reserveFraction)));
            $sendDeut = min($needDeut, (int) ($planetDeut * (1 - $reserveFraction)));

            // Need at least something worth sending
            $totalToSend = $sendMetal + $sendCrystal + $sendDeut;
            if ($totalToSend < 1000) {
                continue; // Not worth a trip
            }

            // Check if parent planet has cargo ships
            $bigCargo = (int) ($parentPlanet['ship_big_cargo_ship'] ?? 0);
            $smallCargo = (int) ($parentPlanet['ship_small_cargo_ship'] ?? 0);
            if ($bigCargo === 0 && $smallCargo === 0) {
                continue; // No cargo ships available
            }

            $destination = [
                'galaxy' => (int) $moon['planet_galaxy'],
                'system' => (int) $moon['planet_system'],
                'planet' => (int) $moon['planet_planet'],
            ];

            if (!$dryRun) {
                $fleetId = $this->dispatcher->sendTransport(
                    $parentPlanet, $user, $destination,
                    (int) $sendMetal, (int) $sendCrystal, (int) $sendDeut
                );

                if ($fleetId) {
                    return "G:{$moon['planet_galaxy']}:{$moon['planet_system']}:{$moon['planet_planet']} "
                        . "(Lunar Base {$moonLevel} → building #{$moonBuilding}, "
                        . "sending " . number_format($sendMetal) . "M/"
                        . number_format($sendCrystal) . "C/"
                        . number_format($sendDeut) . "D)";
                }
            } else {
                return "would supply G:{$moon['planet_galaxy']}:{$moon['planet_system']}:{$moon['planet_planet']} "
                    . "(Lunar Base {$moonLevel}, next: #{$moonBuilding}, "
                    . "need " . number_format($needMetal) . "M/"
                    . number_format($needCrystal) . "C/"
                    . number_format($needDeut) . "D)";
            }
        }

        return null;
    }

    /**
     * Get building cost for a moon building at a given level.
     * Mirrors BotBrain::getBuildingCost() for moon-specific buildings.
     *
     * @return array{metal: float, crystal: float, deuterium: float}
     */
    private function getMoonBuildingCost(int $buildingId, int $currentLevel): array
    {
        $baseCosts = [
            41 => ['metal' => 20000,  'crystal' => 40000,  'deuterium' => 20000,  'factor' => 2.0],  // Lunar Base
            14 => ['metal' => 400,    'crystal' => 120,    'deuterium' => 250,    'factor' => 2.0],  // Robot Factory
            42 => ['metal' => 20000,  'crystal' => 40000,  'deuterium' => 20000,  'factor' => 2.0],  // Sensor Phalanx
            44 => ['metal' => 20000,  'crystal' => 20000,  'deuterium' => 1000,   'factor' => 2.0],  // Missile Silo
            43 => ['metal' => 2000000, 'crystal' => 4000000, 'deuterium' => 2000000, 'factor' => 2.0],  // Jump Gate
        ];

        $base = $baseCosts[$buildingId] ?? ['metal' => 0, 'crystal' => 0, 'deuterium' => 0, 'factor' => 2.0];
        $factor = $base['factor'];

        return [
            'metal'     => round($base['metal'] * pow($factor, $currentLevel)),
            'crystal'   => round($base['crystal'] * pow($factor, $currentLevel)),
            'deuterium' => round($base['deuterium'] * pow($factor, $currentLevel)),
        ];
    }

    private function getBuildingName(int $buildingId): string
    {
        $names = [
            1 => 'Metal Mine', 2 => 'Crystal Mine', 3 => 'Deuterium Synthesizer',
            4 => 'Solar Plant', 12 => 'Fusion Reactor', 14 => 'Robot Factory',
            15 => 'Nanite Factory', 21 => 'Shipyard', 22 => 'Metal Storage',
            23 => 'Crystal Storage', 24 => 'Deuterium Tank', 31 => 'Research Lab',
            33 => 'Terraformer', 34 => 'Alliance Depot', 41 => 'Lunar Base',
            42 => 'Sensor Phalanx', 43 => 'Jump Gate', 44 => 'Missile Silo',
        ];

        return $names[$buildingId] ?? "Building #{$buildingId}";
    }

    /**
     * Sync a flat planet array from an Eloquent model after resource/building changes.
     */
    private function syncPlanetFromModel(array &$planet, Planets $model): void
    {
        $planet['planet_metal'] = $model->planet_metal;
        $planet['planet_crystal'] = $model->planet_crystal;
        $planet['planet_deuterium'] = $model->planet_deuterium;
        $planet['planet_field_current'] = $model->planet_field_current;
        $planet['planet_field_max'] = $model->planet_field_max;
        $planet['planet_b_building'] = $model->planet_b_building;

        if ($model->buildings) {
            foreach ($model->buildings->getAttributes() as $key => $value) {
                if (array_key_exists($key, $planet)) {
                    $planet[$key] = $value;
                }
            }
        }
    }

    /**
     * Check if a bot is currently in its active hours.
     *
     * Bots have a timezone offset and active window. Outside that window,
     * they're "sleeping" and won't process ticks.
     */
    private function isBotActive(User $bot): bool
    {
        $profile = json_decode((string) ($bot->bot_profile ?? '{}'), true);

        if (empty($profile) || !isset($profile['tz_offset'], $profile['active_start'], $profile['active_end'])) {
            return true; // No profile = always active (legacy bots)
        }

        // Current hour in bot's timezone
        $botHour = (int) gmdate('G', time() + ($profile['tz_offset'] * 3600));

        $start = (int) $profile['active_start'];
        $end = (int) $profile['active_end'];

        // Handle wrapping (e.g., active 22:00-06:00)
        if ($start > $end) {
            // Window wraps midnight: active if hour >= start OR hour < end
            return $botHour >= $start || $botHour < $end;
        }

        // Normal window: active if hour >= start AND hour < end
        return $botHour >= $start && $botHour < $end;
    }

    /**
     * Build a defender planet array from intel data for battle simulation.
     *
     * @param  array{metal: int, crystal: int, deuterium: int, fleet_data: array, defense_data: array}  $intel
     * @return array<string, mixed>
     */
    private function buildDefenderFromIntel(array $intel): array
    {
        $planet = [
            'planet_metal'     => $intel['metal'] ?? 0,
            'planet_crystal'   => $intel['crystal'] ?? 0,
            'planet_deuterium' => $intel['deuterium'] ?? 0,
        ];

        // Map fleet data to ship columns
        $shipMap = [
            202 => 'ship_small_cargo_ship', 203 => 'ship_big_cargo_ship',
            204 => 'ship_light_fighter', 205 => 'ship_heavy_fighter',
            206 => 'ship_cruiser', 207 => 'ship_battleship',
            208 => 'ship_colony_ship', 209 => 'ship_recycler',
            210 => 'ship_espionage_probe', 211 => 'ship_bomber',
            212 => 'ship_solar_satellite', 213 => 'ship_destroyer',
            214 => 'ship_deathstar', 215 => 'ship_reaper',
        ];

        foreach ($shipMap as $id => $column) {
            $planet[$column] = ($intel['fleet_data'][$id] ?? 0);
        }

        // Map defense data to defense columns
        $defenseMap = [
            401 => 'defense_rocket_launcher', 402 => 'defense_light_laser',
            403 => 'defense_heavy_laser', 404 => 'defense_gauss_cannon',
            405 => 'defense_ion_cannon', 406 => 'defense_plasma_turret',
            502 => 'defense_small_shield_dome', 503 => 'defense_large_shield_dome',
        ];

        foreach ($defenseMap as $id => $column) {
            $planet[$column] = ($intel['defense_data'][$id] ?? 0);
        }

        // Veto against stale fleet intel (6 Sep 09:30 results): raids of 4-26 ships were sent at
        // planets the report showed as empty and met 400-1,100 ships — the target's fleet was away
        // when probed and home when the raid landed. Take the LARGER of the report and the live
        // ships/defences per unit type, so the simulator can be surprised upwards never downwards.
        // (Dale's 5 Sep proposal. Resources for the loot estimate still come from the report.)
        $live = $this->liveDefender((int) ($intel['galaxy'] ?? 0), (int) ($intel['system'] ?? 0), (int) ($intel['planet'] ?? 0));
        foreach (array_merge($shipMap, $defenseMap) as $column) {
            $planet[$column] = max((int) $planet[$column], (int) ($live[$column] ?? 0));
        }

        // Defender tech. Spy reports don't carry it at 3 probes and the simulator was scoring
        // every defender at weapons/shield/armour 0 while bots average 11 / 9.5 / 11.7 — first
        // live tick of fix I (6 Sep): 41 wins but 11 total wipe-outs and 6 draws the sim had
        // approved. Read the real research row instead (one query per defender per tick).
        $targetUserId = (int) ($intel['user_id'] ?? 0);
        if ($targetUserId > 0) {
            if (!array_key_exists($targetUserId, $this->defenderTechCache)) {
                $prefix = DB::getTablePrefix();
                $row = DB::selectOne(
                    "SELECT research_weapons_technology, research_shielding_technology, research_armour_technology
                    FROM `{$prefix}research` WHERE `research_user_id` = ?",
                    [$targetUserId]
                );
                $this->defenderTechCache[$targetUserId] = $row ? [
                    'research_weapons_technology' => (int) $row->research_weapons_technology,
                    'research_shielding_technology' => (int) $row->research_shielding_technology,
                    'research_armour_technology' => (int) $row->research_armour_technology,
                ] : [];
            }
            $planet += $this->defenderTechCache[$targetUserId];
        }

        return $planet;
    }

    /** Defender research rows looked up this tick: user id => research_* columns. */
    private array $defenderTechCache = [];

    /** Live ships + defences rows looked up this tick: "g:s:p" => columns. */
    private array $liveDefenderCache = [];

    /**
     * Current ships + defences on a planet (empty array if the planet doesn't exist).
     *
     * @return array<string, mixed>
     */
    private function liveDefender(int $galaxy, int $system, int $planet): array
    {
        $key = "{$galaxy}:{$system}:{$planet}";

        if (!array_key_exists($key, $this->liveDefenderCache)) {
            $prefix = DB::getTablePrefix();
            $row = DB::selectOne(
                "SELECT s.*, d.*
                FROM `{$prefix}planets` AS p
                INNER JOIN `{$prefix}ships` AS s ON s.`ship_planet_id` = p.`planet_id`
                INNER JOIN `{$prefix}defenses` AS d ON d.`defense_planet_id` = p.`planet_id`
                WHERE p.`planet_galaxy` = ? AND p.`planet_system` = ? AND p.`planet_planet` = ? AND p.`planet_type` = 1
                LIMIT 1",
                [$galaxy, $system, $planet]
            );
            $this->liveDefenderCache[$key] = $row ? (array) $row : [];
        }

        return $this->liveDefenderCache[$key];
    }

    private function getShipName(int $shipId): string
    {
        $names = [
            202 => 'Small Cargo', 203 => 'Large Cargo', 204 => 'Light Fighter',
            205 => 'Heavy Fighter', 206 => 'Cruiser', 207 => 'Battleship',
            208 => 'Colony Ship', 209 => 'Recycler', 210 => 'Espionage Probe',
            211 => 'Bomber', 212 => 'Solar Satellite', 213 => 'Destroyer',
            214 => 'Deathstar', 215 => 'Reaper',
            401 => 'Rocket Launcher', 402 => 'Light Laser', 403 => 'Heavy Laser',
            404 => 'Gauss Cannon', 405 => 'Ion Cannon', 406 => 'Plasma Turret',
            502 => 'Small Shield Dome', 503 => 'Large Shield Dome',
        ];

        return $names[$shipId] ?? "Unit #{$shipId}";
    }

    // ─── Expedition Helpers ──────────────────────────────────

    /** Share of the combat ships at home that an expedition takes: awake vs about to sleep (fleet save). */
    private const EXPEDITION_SHARE_ACTIVE = 0.5;
    private const EXPEDITION_SHARE_SLEEP = 0.9;

    /** Don't bother below this many combat ships — pirates/aliens eat tiny fleets and finds scale with value. */
    private const EXPEDITION_MIN_COMBAT_SHIPS = 10;

    /** Game cost (metal + crystal + deuterium) per ship, for the find estimate (Expedition::resultResources). */
    private const EXPEDITION_SHIP_VALUE = [
        202 => 4000, 203 => 12000, 204 => 4000, 205 => 10000, 206 => 29000, 207 => 60000,
        210 => 1000, 211 => 90000, 213 => 125000, 215 => 160000,
    ];

    /** Base hold space per ship (before Hyperspace Tech, +5 %/level). */
    private const EXPEDITION_SHIP_HOLD = [
        202 => 5000, 203 => 25000, 204 => 50, 205 => 100, 206 => 800, 207 => 1500,
        211 => 500, 213 => 2000, 215 => 10000,
    ];

    /**
     * Build a fleet composition for an expedition.
     *
     * The engine (legacy Expedition::resultResources) finds fleetValue × 3-20 % (tier by value)
     * × a duration multiplier and keeps only what the fleet can CARRY, and ship finds scale with
     * the fleet's structural points — so value and hold space are what matter. Combat ships:
     * half of what is home while awake, 90 % before the sleep window (doubles as fleet save).
     * Cargo: enough hold space for the best-case find, Large Cargos first. Always 1 probe.
     *
     * @return array<int, int>  Ship ID => count, empty if nothing worth sending
     */
    private function buildExpeditionFleet(array $planet, array $user, int $stayDuration, bool $isActive): array
    {
        $fleet = [];
        $value = 0;
        $hyper = (int) ($user['research_hyperspace_technology'] ?? 0);
        $holdBonus = 1 + 0.05 * $hyper;
        $combatShare = $isActive ? self::EXPEDITION_SHARE_ACTIVE : self::EXPEDITION_SHARE_SLEEP;

        foreach ([204, 205, 206, 207, 211, 213, 215] as $shipId) {
            $count = (int) floor(((int) ($planet[$this->shipColumn($shipId)] ?? 0)) * $combatShare);
            if ($count > 0) {
                $fleet[$shipId] = $count;
                $value += $count * self::EXPEDITION_SHIP_VALUE[$shipId];
            }
        }

        if (array_sum($fleet) < self::EXPEDITION_MIN_COMBAT_SHIPS) {
            return [];
        }

        // Best-case find: 20 % of the fleet's value × the stay multiplier (1 + (h−1)·2/7)
        $hours = max(1, (int) round($stayDuration / 3600));
        $multiplier = 1.0 + ($hours - 1) * (2 / 7);
        $capacityNeeded = (int) ceil($value * 0.20 * $multiplier);

        foreach ($fleet as $shipId => $count) {
            $capacityNeeded -= (int) ($count * (self::EXPEDITION_SHIP_HOLD[$shipId] ?? 0) * $holdBonus);
        }

        // Cargo: Large Cargos first; keep some at home for raiding while awake, all out before sleep
        $cargoShare = $isActive ? 0.7 : 1.0;
        $bigHome = (int) floor(((int) ($planet['ship_big_cargo_ship'] ?? 0)) * $cargoShare);
        $smallHome = (int) floor(((int) ($planet['ship_small_cargo_ship'] ?? 0)) * $cargoShare);
        $bigHold = (int) (self::EXPEDITION_SHIP_HOLD[203] * $holdBonus);
        $smallHold = (int) (self::EXPEDITION_SHIP_HOLD[202] * $holdBonus);

        if ($capacityNeeded > 0 && $bigHome > 0) {
            $big = min($bigHome, (int) ceil($capacityNeeded / $bigHold));
            $fleet[203] = $big;
            $capacityNeeded -= $big * $bigHold;
        }
        if ($capacityNeeded > 0 && $smallHome > 0) {
            $fleet[202] = min($smallHome, (int) ceil($capacityNeeded / $smallHold));
        }

        // Always send 1 probe for depletion reports (if available)
        if ((int) ($planet['ship_espionage_probe'] ?? 0) >= 1) {
            $fleet[210] = 1;
        }

        return $fleet;
    }

    /**
     * Re-read the ships and resources of a planet after earlier phases spent them this tick.
     *
     * @param  array<string, mixed>  $planet
     * @return array<string, mixed>
     */
    private function refreshPlanetShips(array $planet): array
    {
        $prefix = DB::getTablePrefix();
        $row = DB::selectOne(
            "SELECT p.`planet_metal`, p.`planet_crystal`, p.`planet_deuterium`, s.*
            FROM `{$prefix}planets` AS p
            INNER JOIN `{$prefix}ships` AS s ON s.`ship_planet_id` = p.`planet_id`
            WHERE p.`planet_id` = ?
            LIMIT 1",
            [(int) $planet['planet_id']]
        );

        return $row ? array_merge($planet, (array) $row) : $planet;
    }

    private function shipColumn(int $shipId): string
    {
        return [
            202 => 'ship_small_cargo_ship', 203 => 'ship_big_cargo_ship', 204 => 'ship_light_fighter',
            205 => 'ship_heavy_fighter', 206 => 'ship_cruiser', 207 => 'ship_battleship',
            208 => 'ship_colony_ship', 209 => 'ship_recycler', 210 => 'ship_espionage_probe',
            211 => 'ship_bomber', 212 => 'ship_solar_satellite', 213 => 'ship_destroyer',
            214 => 'ship_deathstar', 215 => 'ship_reaper',
        ][$shipId];
    }

    /**
     * Pick a system for an expedition.
     * Rotates around the bot's home system to spread depletion.
     * Stays within the same galaxy.
     */
    private function pickExpeditionSystem(int $galaxy, int $homeSystem, int $botId): int
    {
        // Use bot ID + current hour to rotate systems
        $offset = ($botId + (int) (time() / 3600)) % 400;
        $system = ($homeSystem + $offset) % 499 + 1; // Systems 1-499
        return $system;
    }

    /**
     * Is the bot inside its active window right now (bot_profile active_start/active_end, tz_offset)?
     */
    private function isInActiveWindow(array $profile): bool
    {
        $activeStart = $profile['active_start'] ?? 8;
        $activeEnd = $profile['active_end'] ?? 22;
        $tzOffset = $profile['tz_offset'] ?? 0;

        // Current hour in bot's timezone
        $botHour = (int) gmdate('H', time() + ($tzOffset * 3600));

        if ($activeStart < $activeEnd) {
            return $botHour >= $activeStart && $botHour < $activeEnd;
        }

        // Wraps midnight (e.g., active 22-6)
        return $botHour >= $activeStart || $botHour < $activeEnd;
    }

    /**
     * Pick expedition stay duration based on bot's active hours.
     * Short (1-2h) during active hours, long (6-8h) overnight (fleet save).
     */
    private function pickExpeditionDuration(array $profile): int
    {
        if ($this->isInActiveWindow($profile)) {
            // Active hours: short expeditions (1-2 hours)
            return mt_rand(1, 2) * 3600;
        } else {
            // Inactive hours: long expeditions (6-8 hours = fleet save)
            return mt_rand(6, 8) * 3600;
        }
    }
}

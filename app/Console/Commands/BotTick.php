<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Planets;
use App\Models\User;
use App\Services\Bot\BotBrain;
use App\Services\Bot\BotSpeed;
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

    /** Expeditions go to a system within this many of home (was up to 399 away until 29 Sep). */
    private const EXPEDITION_RANGE = 20;

    /** Attack origin needs this much deuterium to be preferred (raids cost 3-9K fuel). */
    private const ORIGIN_MIN_DEUTERIUM = 10000;

    /** Don't re-probe a planet scanned more recently than this (seconds). */
    private const SPY_REFRESH_SECONDS = 1800;

    /** Intel older than this is re-spied instead of attacked on (seconds). */
    private const INTEL_MAX_AGE = 7200;

    /** How many intel targets (richest first) to run through the battle engine per bot per tick. */
    private const ATTACK_CANDIDATES = 8;

    /** Ignore intel targets holding less than this much in total — not worth the fuel or the risk. */
    private const ATTACK_MIN_RESOURCES = 50_000;

    /**
     * Human-owned planets: a scan by ANY bot newer than this is reused (cloned into the bot's own intel)
     * instead of sending another probe, and intel this old is still attacked on. Every probe lands as an
     * "Espionage action" message in the human's inbox — 53 in one day from four bots re-probing the same
     * six planets (13 Sep). The simulator merges live ships/defences anyway, so old intel only misjudges loot.
     */
    private const HUMAN_INTEL_SHARE_SECONDS = 21600;

    /**
     * After the battle engine rejects a human-owned target (can't win / not worth it) the bot leaves that
     * planet alone — no probes, no sims — for this long. Without it the scanner put the same rich,
     * unbeatable human planet at the top of the list every tick and the 30-min refresh sent another probe.
     * Table bot_target_skip; bot-vs-bot targets are not affected.
     */
    private const UNWINNABLE_SKIP_SECONDS = 43200;

    /** Probes avoided this tick by reusing another bot's scan of a human planet (tick line `shared=`). */
    private int $sharedIntelHits = 0;

    /** user id => true for human players, looked up once per tick. */
    private array $humanIds = [];

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

        $stats = ['processed' => 0, 'built' => 0, 'ships' => 0, 'researches' => 0, 'attacks' => 0, 'spies' => 0, 'fleet_saves' => 0, 'expeditions' => 0, 'harvests' => 0, 'skipped' => 0, 'errors' => 0, 'idle_planets' => 0, 'escalated' => 0, 'stuck' => 0, 'reserved' => 0, 'moon_returns' => 0, 'build_rejected' => 0, 'colonise' => 0, 'feeds' => 0];

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
                $stats['moon_returns'] += $result['moon_returns'];
                $stats['build_rejected'] += $result['build_rejected'];
                $stats['colonise'] += $result['colonise'];
                $stats['feeds'] += $result['feeds'];

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
                $this->logBotError($bot, $e, $dryRun);
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

        if (!$dryRun) {
            $this->updateStatistics();
        }

        return self::SUCCESS;
    }

    /**
     * Rebuild the player/alliance statistics here, every tick, instead of inside a page request.
     * The game's own trigger (UpdatesLibrary::updateStatistics, every `stat_update_time` minutes)
     * ran StatisticsLibrary::makeStats() — 1.6 s — in whichever player's page load came first;
     * that option is now 60 so the web path only fires if the tick has been dead for an hour.
     * Same named lock as UpdatesLibrary so the two never run at once. Never fails the tick.
     */
    private function updateStatistics(): void
    {
        if (!\Xgp\App\Libraries\MissionControlLib::acquireLock('xgp_updates')) {
            $this->warn('  Statistics: skipped, a page request holds the updates lock');

            return;
        }

        try {
            $t = microtime(true);
            $result = (new \Xgp\App\Libraries\StatisticsLibrary())->makeStats();
            app(\App\Services\SettingsService::class)->write('stat_last_update', $result['stats_time']);
            $this->info(sprintf('  Statistics rebuilt in %.1fs', microtime(true) - $t));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('BotTick: statistics update failed: ' . $e->getMessage());
            $this->warn('  WARNING: statistics update failed: ' . $e->getMessage());
        } finally {
            \Xgp\App\Libraries\MissionControlLib::releaseLock('xgp_updates');
        }
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
                "%s | %sprocessed=%d skipped=%d built=%d ships=%d research=%d attacks=%d spies=%d shared=%d saves=%d expeditions=%d harvests=%d moon_returns=%d colonise=%d feeds=%d build_rejected=%d errors=%d | idle_planets=%d escalated=%d stuck=%d reserved=%d | %.1fs\n",
                date('Y-m-d H:i:s'),
                $dryRun ? 'DRY ' : '',
                $stats['processed'], $stats['skipped'], $stats['built'], $stats['ships'], $stats['researches'],
                $stats['attacks'], $stats['spies'], $this->sharedIntelHits, $stats['fleet_saves'], $stats['expeditions'], $stats['harvests'] ?? 0, $stats['moon_returns'] ?? 0, $stats['colonise'] ?? 0, $stats['feeds'] ?? 0, $stats['build_rejected'] ?? 0, $stats['errors'],
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
            'idle_planets' => 0, 'escalated' => 0, 'stuck' => 0, 'reserved' => 0, 'moon_returns' => 0,
            'build_rejected' => 0, 'colonise' => 0, 'feeds' => 0,
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

        // Finish research that is done BEFORE anything is decided (was after the planet loop: every
        // finished research cost a tick and the lab looked busy on stale data)
        $this->researchQueueService->processCompletions($bot);

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

        $researchQueued = false;

        // --- Account plan (Phase 2): research planet, colony yard, colony need ---
        $account = $this->accountPlan($bot, $user, $planetRows);
        $labNeeded = 0;

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

            $planetId = (int) $planet['planet_id'];
            $isMoonRow = ((int) ($planet['planet_type'] ?? 1)) === 3;
            $isResearchPlanet = $planetId === $account['research_planet_id'];
            $isColonyYard = $planetId === $account['colony_yard_id'];

            // --- Phase 2.5: Research (research planet only) — decided BEFORE building and ships ---
            // Until 30 Sep research came last, from whatever the shipyard left, and only if it could be
            // paid on the spot: research fell from 1,400 to ~100 a day while fighters ate the crystal.
            $researchId = null;
            $researchPlan = null;
            if ($isResearchPlanet && !$researchQueued && $planetModel) {
                $researchPlan = $this->brain->researchPlan($user, $planet);
                $labNeeded = $this->brain->lastLabNeeded;

                if ($researchPlan !== null && $researchPlan['affordable']) {
                    $researchId = $researchPlan['id'];
                    if (!$dryRun) {
                        $technocrateActive = (int) ($user['premium_officier_technocrat'] ?? 0) > time();
                        if ($this->researchQueueService->add($bot, $planetModel, $user, $researchId, $technocrateActive)) {
                            $result['research'] = 'research queued';
                            $user['research_current_research'] = $planetId;
                            $planetModel->refresh();
                            $this->syncPlanetFromModel($planet, $planetModel);
                        }
                    } else {
                        $result['research'] = 'research queued';
                    }
                }
                $researchQueued = true;
            }

            // --- Phase 3: Queue next building (reuse cached model) ---
            $buildCtx = [
                'research_planet' => $isResearchPlanet,
                'lab_needed' => $isResearchPlanet ? $labNeeded : 0,
                'colony_push' => $account['colony_push'],
                'colony_yard' => $isColonyYard,
            ];
            if ($researchPlan !== null && !$researchPlan['affordable']) {
                foreach (['metal', 'crystal', 'deuterium'] as $res) {
                    $buildCtx["need_{$res}"] = $researchPlan['cost'][$res];
                }
            }
            if ($isColonyYard && $account['need_colony_ship']) {
                foreach ($this->brain->price(208) as $res => $amount) {
                    $buildCtx["need_{$res}"] = max((float) ($buildCtx["need_{$res}"] ?? 0), $amount);
                }
            }

            $buildingId = null;
            $buildingQueueEmpty = $planetModel && $planetModel->buildingQueue()->count() === 0;
            if ($buildingQueueEmpty) {
                $buildingId = $this->brain->nextBuilding($planet, $user, $buildCtx);

                // Full planet whose way out (Terraformer / Nanite) is allowed now: tear one level down
                if ($buildingId === null && $planetModel) {
                    $demolish = $this->brain->nextDemolition($planet, $user, $buildCtx);
                    if ($demolish !== null) {
                        $what = $this->getBuildingName($demolish);
                        $where = "{$planet['planet_galaxy']}:{$planet['planet_system']}:{$planet['planet_planet']}";
                        if ($dryRun) {
                            $this->line("  [{$bot->id}] {$bot->name}: would demolish a level of {$what} on {$where} (room for the terraformer)");
                        } elseif ($this->queueService->add($planetModel, $user, $demolish, 'destroy')) {
                            $this->line("  [{$bot->id}] {$bot->name}: demolishing a level of {$what} on {$where} (room for the terraformer)");
                            $result['built'] = true;
                            $result['building'] = "demolish {$what}";
                            $buildingQueueEmpty = false;
                        }
                    }
                }

                if ($buildingId !== null && !$dryRun) {
                    $success = $this->queueService->add($planetModel, $user, $buildingId, 'build');

                    if ($success) {
                        $result['built'] = true;
                        $result['building'] = $this->getBuildingName($buildingId);
                    } else {
                        // The game refused it (the brain checks fields + requirements, so this should
                        // be rare). Count it; the reserve below still saves for it.
                        $result['build_rejected']++;
                        $this->line("  [{$bot->id}] {$bot->name}: game refused {$this->getBuildingName($buildingId)} on "
                            . "{$planet['planet_galaxy']}:{$planet['planet_system']}:{$planet['planet_planet']} ({$this->brain->lastBuildingReason})");
                        $buildingId = null;
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

            // --- Phase 4: Shipyard, above the reserve ---
            // Reserve = what this planet is saving for: the building the brain wants (capped at two
            // days of the planet's own production), the research on the research planet (capped at a
            // day), and the colony ship on the colony yard. Floors (satellites, colony ship, probes)
            // ignore it; recyclers, cargo and combat only spend what is above it.
            $reserve = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];
            $saving = false;
            if ($buildingQueueEmpty && $buildingId === null && !$isMoonRow) {
                $wanted = $this->brain->wantedBuildingCost($planet, $user, $buildCtx);
                if ($wanted !== null) {
                    foreach (['metal', 'crystal', 'deuterium'] as $res) {
                        $cap = 48 * (float) ($planet["planet_{$res}_perhour"] ?? 0);
                        $reserve[$res] += min((float) $wanted[$res], max($cap, 100_000.0));
                    }
                    $saving = true;
                    if ($dryRun) {
                        $this->line(sprintf(
                            "  [%d] %s: saving for %s on %d:%d:%d (%s)",
                            $bot->id, $bot->name, $this->getBuildingName((int) $wanted['building_id']),
                            $planet['planet_galaxy'], $planet['planet_system'], $planet['planet_planet'],
                            $this->brain->lastBuildingReason
                        ));
                    }
                }
            }
            if ($researchPlan !== null && !$researchPlan['affordable']) {
                foreach (['metal', 'crystal', 'deuterium'] as $res) {
                    $cap = 48 * (float) ($planet["planet_{$res}_perhour"] ?? 0);
                    $reserve[$res] += min((float) $researchPlan['cost'][$res], max($cap, 50_000.0));
                }
                $saving = true;
            }
            $wantColonyShip = $isColonyYard && $account['need_colony_ship'];
            if ($wantColonyShip) {
                $colonyCost = $this->brain->price(208);
                foreach (['metal', 'crystal', 'deuterium'] as $res) {
                    $reserve[$res] += $colonyCost[$res];
                }
                $saving = true;
            }
            if ($saving) {
                $result['reserved']++;
            }

            $shipDecision = $this->brain->nextShip($planet, $user, $reserve, [
                'want_colony_ship' => $wantColonyShip,
                'economy_first' => $account['colonies'] < 1,
                'main_planet' => $isResearchPlanet || $isColonyYard,
            ]);

            if ($shipDecision !== null) {
                $shipId = $shipDecision['ship_id'];
                $count = $shipDecision['count'];

                if (!$dryRun) {
                    $this->queueShipProduction($planetId, $shipId, $count, $shipDecision['cost']);
                }

                if ($shipId === 208) {
                    $account['need_colony_ship'] = false;
                    $this->line("  [{$bot->id}] {$bot->name}: colony ship queued on {$planet['planet_galaxy']}:{$planet['planet_system']}:{$planet['planet_planet']}");
                }

                $result['ship_built'] = true;
                $result['ship'] = $this->getShipName($shipId) . " x{$count}";

                if (!$dryRun && $planetModel) {
                    $planetModel->refresh();
                    $this->syncPlanetFromModel($planet, $planetModel);
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

            // (Resource sharing with OTHER bots and the old in-loop colonisation are gone since 30 Sep:
            //  colonies are founded and fed once per bot after the loop — see colonise() / feedColonies().)

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

            // Alliance coordination — defensive support for an ALLY under attack: a hold (the ships
            // defend, then fly home), no cargo, and only if it lands before the attack. Until 29 Sep
            // this was a Deploy to any bot nearby carrying all the sender's resources — the ships
            // and ~315M resources a day were simply given away.
            if (!$this->dispatcher->hasActiveFleetFromPlanet(
                (int) $planet['planet_galaxy'], (int) $planet['planet_system'], (int) $planet['planet_planet'],
                (int) ($planet['planet_type'] ?? 1)
            )) {
                $supportTarget = $this->alliance->findDefensiveOpportunity($planet, (int) ($user['ally_id'] ?? 0));

                if ($supportTarget !== null) {
                    $supportFleet = $this->alliance->getAvailableSupportFleet($planet);

                    if (!empty($supportFleet)) {
                        $destination = [
                            'galaxy' => $supportTarget['target_galaxy'],
                            'system' => $supportTarget['target_system'],
                            'planet' => $supportTarget['target_planet'],
                            'type' => 1,
                        ];
                        $landsAt = time() + $this->dispatcher->flightSeconds($supportFleet, $planet, $user, $destination);

                        if ($landsAt < $supportTarget['arrival_time'] && !$dryRun) {
                            $stay = $supportTarget['arrival_time'] - $landsAt + 900;
                            $fleetId = $this->dispatcher->sendHold($planet, $user, $destination, $supportFleet, $stay, false);

                            if ($fleetId) {
                                $this->line("  [{$bot->id}] {$bot->name}: sending defensive support from {$planet['planet_galaxy']}:{$planet['planet_system']}:{$planet['planet_planet']}");
                            }
                        }
                    }
                }
            }

            // (Expedition dispatch moved after Phase 5 — see sendExpeditions(). Sending it here
            //  parked a fleet on the home planet before the spy/attack phase ever ran.)

            // (The attack origin is chosen after moon return + supply — see pickAttackOrigin().)
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

        // --- Phase 4.7: Colonise, then feed young colonies (once per bot) ---
        $result['colonise'] = $this->colonise($bot, $user, $planetRows, $dryRun);
        $result['feeds'] = $this->feedColonies($bot, $user, $planetRows, $dryRun);

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

        // --- Phase 4.8: Moon return (bring stranded ships + resources home) ---
        // Fleet saves were one-way Deploys to the moon until 29 Sep; 178M metal and a third of all
        // bot ships sat on moons where nothing could spend them. Saves are holds now (they come
        // back by themselves); this drains what is already there and anything left over.
        foreach ($moonRows as $moonRow) {
            $moonReturnResult = $this->returnMoonStock($user, (array) $moonRow, $planetRows, $dryRun);
            if ($moonReturnResult !== null) {
                $this->line("  [{$bot->id}] {$bot->name}: moon return → {$moonReturnResult}");
                $result['moon_returns']++;
            }
        }

        if (!empty($moonRows)) {
            $moonSupplyResult = $this->supplyMoons($bot, $user, $planetRows, $moonRows, $dryRun);
            if ($moonSupplyResult !== null) {
                $this->line("  [{$bot->id}] {$bot->name}: moon supply → {$moonSupplyResult}");
            }
        }

        // --- Phase 5: Scout and attack ---
        // Origin = the planet or moon where the real fleet is (see pickAttackOrigin()). It re-reads
        // ships and resources: $planetRows was loaded before Phase 0, and a fleet save or moon
        // return has since moved things. Spying/attacking from the stale row spent probes the
        // planet no longer had (2:401:7 went to -3 on 13 Sep) and OPBE throws on a negative
        // defender count.
        $planet = $this->pickAttackOrigin($planetRows);
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

            // Planets this bot has given up on for now (human targets the simulator rejected).
            $skips = $this->loadSkips($bot->id);
            $borrowed = false;

            if ($probes >= $probesPerSpy && !empty($targets)) {
                foreach ($targets as $spyTarget) {
                    if ($spiedCount >= $maxSpyPerTick) break;
                    if ($probes - $spiedCount * $probesPerSpy < $probesPerSpy) break;

                    // Don't re-probe a planet we scanned minutes ago: the same top-3 neighbours were
                    // being hit every tick, and about half of those probe flights get shot down
                    // (Spy.php: detection chance scales with the target's fleet) — 3K crystal a go.
                    $key = "{$spyTarget['galaxy']}:{$spyTarget['system']}:{$spyTarget['planet']}";
                    if (time() - ($latestScan[$key] ?? 0) < BotSpeed::seconds(self::SPY_REFRESH_SECONDS)) {
                        continue;
                    }

                    // Fix 1 (13 Sep): the simulator already said no to this human planet — leave it alone.
                    if (($skips[$key] ?? 0) > time()) {
                        continue;
                    }

                    // Fix 2 (13 Sep): human planet another bot looked at recently — reuse that scan, no probe.
                    if ($this->isHumanPlayer((int) ($spyTarget['user_id'] ?? 0))) {
                        $shared = $this->borrowHumanIntel($bot->id, $spyTarget['galaxy'], $spyTarget['system'], $spyTarget['planet'], (int) ($latestScan[$key] ?? 0), $dryRun);
                        if ($shared > 0) {
                            $latestScan[$key] = $shared;
                            $borrowed = true;
                            $this->sharedIntelHits++;
                            if ($dryRun) {
                                $this->line("  [{$bot->id}] SHARE: {$key} scanned " . intdiv(time() - $shared, 60) . "m ago by another bot — no probe");
                            }
                            continue;
                        }
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

            if ($borrowed && !$dryRun) {
                // Cloned rows must be visible to the attack phase below in the same tick.
                $intelData = $this->intel->getAllIntel($bot->id);
            }

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

                    if ($intelTarget['total_resources'] < BotSpeed::amount(self::ATTACK_MIN_RESOURCES)) continue; // not worth the fuel

                    // Fix 1 (13 Sep): rejected recently — no sim, no re-probe, doesn't use up a candidate slot.
                    $skipKey = "{$intelTarget['galaxy']}:{$intelTarget['system']}:{$intelTarget['planet']}";
                    if (($skips[$skipKey] ?? 0) > time()) {
                        if ($dryRun) {
                            $this->line("  [{$bot->id}] SKIP: {$skipKey} rejected earlier, until " . date('H:i', $skips[$skipKey]));
                        }
                        continue;
                    }

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
                    $intelTarget['planet_data'] = $this->buildDefenderFromIntel($intelTarget, (int) $bot->id, $user);
                    $candidatesTried++;

                    $coords = "{$intelTarget['galaxy']}:{$intelTarget['system']}:{$intelTarget['planet']}";
                    $intelAge = time() - (int) ($intelTarget['scanned_at'] ?? 0);
                    $isHumanTarget = $this->isHumanPlayer($defenderId);

                    // Humans run on the 6-hour shared cycle (fix 2); the sim merges live ships/defences anyway.
                    if ($intelAge > BotSpeed::seconds($isHumanTarget ? self::HUMAN_INTEL_SHARE_SECONDS : self::INTEL_MAX_AGE)) {
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

                    // Fix 1 (13 Sep): the engine said no to a human planet — don't come back for a while.
                    if ($isHumanTarget) {
                        $this->rememberUnwinnable($bot->id, $intelTarget, $dryRun);
                        $skips[$coords] = time() + BotSpeed::seconds(self::UNWINNABLE_SKIP_SECONDS);
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
                // Fix 2 (13 Sep): a human planet another bot scanned recently is reused, not re-probed.
                $shared = $this->isHumanPlayer((int) ($staleTarget['user_id'] ?? 0))
                    ? $this->borrowHumanIntel($bot->id, (int) $staleTarget['galaxy'], (int) $staleTarget['system'], (int) $staleTarget['planet'], (int) ($staleTarget['scanned_at'] ?? 0), $dryRun)
                    : 0;
                if ($shared > 0) {
                    $this->sharedIntelHits++;
                    if ($dryRun) {
                        $this->line("  [{$bot->id}] SHARE: {$coords} scanned " . intdiv(time() - $shared, 60) . "m ago by another bot — no refresh probe");
                    }
                } elseif (!$dryRun) {
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

            $next = $this->moonNextBuilding($moon, $user);

            if ($next === null) {
                continue; // All moon buildings maxed
            }

            ['id' => $moonBuilding, 'level' => $moonLevel, 'cost' => $cost] = $next;

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
                'type' => 3, // the moon (type 1 = the planet itself: supply shipped to itself until 29 Sep)
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
     * The next building a moon should get (first entry of MOON_BUILDING_PRIORITY below its cap,
     * with Lunar Base / Robot Factory forced first where needed) and its cost. Walks the list by
     * hand rather than nextBuilding(): that checks canAfford(), which fails on an empty moon.
     *
     * @param  array<string, mixed>  $moon
     * @return array{id: int, level: int, cost: array{metal: float, crystal: float, deuterium: float}}|null
     */
    private function moonNextBuilding(array $moon, array $user = []): ?array
    {
        foreach (\App\Services\Bot\BotBrain::MOON_BUILDING_PRIORITY as $bId => $config) {
            $lvl = $this->brain->getBuildingLevel($bId, $moon);

            if ($lvl >= $config['cap']) {
                continue;
            }

            // Skip what the moon can't have yet (Jump Gate before Hyperspace Tech 7) instead of
            // saving for it for ever; Phalanx/Jump Gate need the Lunar Base first.
            if (!empty($user) && !$this->brain->allowed($bId, $moon, $user)) {
                if ($this->brain->getBuildingLevel(41, $moon) < 1) {
                    [$bId, $lvl] = [41, 0];
                } else {
                    continue;
                }
            }

            return ['id' => $bId, 'level' => $lvl, 'cost' => $this->brain->price($bId, $lvl)];
        }

        return null;
    }

    /**
     * Bring a moon's ships and spare resources home to its planet.
     *
     * Leaves the cost of the moon's next building on the moon (moon supply sends that there on
     * purpose), and does nothing while an attack is heading for the moon or the planet, or while
     * an earlier return/shuttle from this moon is still out. If the ships can't carry the lot in
     * one go they shuttle it (Transport — the ships come back to the moon); once the rest fits
     * they Deploy home for good.
     *
     * @param  array<string, mixed>  $moonRow
     * @param  array<int, object|array<string, mixed>>  $planetRows
     */
    private function returnMoonStock(array $user, array $moonRow, array $planetRows, bool $dryRun): ?string
    {
        $moon = $this->refreshPlanetShips($moonRow);
        $coords = "{$moon['planet_galaxy']}:{$moon['planet_system']}:{$moon['planet_planet']}";

        $parent = null;
        foreach ($planetRows as $row) {
            $row = (array) $row;
            if ((int) ($row['planet_type'] ?? 1) === 1
                && (int) $row['planet_galaxy'] === (int) $moon['planet_galaxy']
                && (int) $row['planet_system'] === (int) $moon['planet_system']
                && (int) $row['planet_planet'] === (int) $moon['planet_planet']
            ) {
                $parent = $row;
                break;
            }
        }

        if ($parent === null) {
            return null;
        }

        // Not into or out of a fight
        if (!empty($this->protector->getIncomingAttacks($moon)) || !empty($this->protector->getIncomingAttacks($parent))) {
            return null;
        }

        // One return at a time per moon (a shuttle is still on its way back)
        $busy = DB::table('fleets')
            ->where('fleet_owner', (int) $moon['planet_user_id'])
            ->where('fleet_start_galaxy', (int) $moon['planet_galaxy'])
            ->where('fleet_start_system', (int) $moon['planet_system'])
            ->where('fleet_start_planet', (int) $moon['planet_planet'])
            ->where('fleet_start_type', 3)
            ->whereIn('fleet_mission', [Missions::TRANSPORT, Missions::DEPLOY])
            ->exists();
        if ($busy) {
            return null;
        }

        $ships = [];
        foreach ([202, 203, 204, 205, 206, 207, 208, 209, 210, 211, 213, 214, 215] as $shipId) { // not 212: satellites can't fly
            $count = (int) ($moon[$this->shipColumn($shipId)] ?? 0);
            if ($count > 0) {
                $ships[$shipId] = $count;
            }
        }

        if (empty($ships)) {
            return null; // Nothing that can fly; the moon spends what it holds on lunar buildings
        }

        $keep = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];
        $next = $this->moonNextBuilding($moon, $user);
        if ($next !== null) {
            $keep = $next['cost'];
        }

        $metal = max(0, (int) floor((float) $moon['planet_metal'] - $keep['metal']));
        $crystal = max(0, (int) floor((float) $moon['planet_crystal'] - $keep['crystal']));
        // Fuel comes out of the moon's deuterium too; a short-hop fuel bill is tiny, keep 1K spare
        $deut = max(0, (int) floor((float) $moon['planet_deuterium'] - $keep['deuterium']) - 1000);

        $destination = [
            'galaxy' => (int) $moon['planet_galaxy'],
            'system' => (int) $moon['planet_system'],
            'planet' => (int) $moon['planet_planet'],
            'type' => 1,
        ];
        $capacity = $this->dispatcher->cargoCapacity($ships, $user);
        $load = $metal + $crystal + $deut;
        $shipCount = array_sum($ships);
        $what = number_format($metal) . 'M/' . number_format($crystal) . 'C/' . number_format($deut) . 'D';

        if ($load > $capacity) {
            // Shuttle a full hold; the ships come back and take the next load next tick
            if ($dryRun) {
                return "would shuttle {$coords} moon → planet (load {$what}, hold " . number_format($capacity) . ", {$shipCount} ships)";
            }

            $fleetId = $this->dispatcher->sendTransport($moon, $user, $destination, $metal, $crystal, $deut, $ships);

            return $fleetId ? "shuttle {$coords} moon → planet ({$shipCount} ships, hold " . number_format($capacity) . ')' : null;
        }

        if ($dryRun) {
            return "would deploy {$coords} moon → planet ({$shipCount} ships + {$what})";
        }

        $fleetId = $this->dispatcher->sendDeploy($moon, $user, $destination, $ships, 0, [
            'metal' => $metal, 'crystal' => $crystal, 'deuterium' => $deut,
        ]);

        return $fleetId ? "deploy {$coords} moon → planet ({$shipCount} ships + {$what})" : null;
    }

    /**
     * Where to spy and attack from: re-read every planet and moon, prefer ones with >= 3 probes and
     * enough deuterium for a raid, and among those the most combat power. Until 29 Sep any moon with
     * a single fighter won: 73 bots raided from moons with ~15 fighters, no cargo and rarely the 3
     * probes a spy needs, while their real fleet sat on the planet (2391 attacks Sep 9-11 → 86).
     *
     * @param  array<int, object|array<string, mixed>>  $planetRows
     * @return array<string, mixed>
     */
    private function pickAttackOrigin(array $planetRows): array
    {
        $power = [204 => 50, 205 => 150, 206 => 400, 207 => 1000, 211 => 1000, 213 => 2000, 214 => 200000, 215 => 2800];
        $best = null;
        $bestKey = null;

        foreach ($planetRows as $row) {
            $candidate = $this->refreshPlanetShips((array) $row);
            $strength = 0;
            foreach ($power as $shipId => $value) {
                $strength += (int) ($candidate[$this->shipColumn($shipId)] ?? 0) * $value;
            }

            $ready = (int) ($candidate['ship_espionage_probe'] ?? 0) >= 3
                && (float) ($candidate['planet_deuterium'] ?? 0) >= self::ORIGIN_MIN_DEUTERIUM;
            $key = [$ready ? 1 : 0, $strength];

            if ($bestKey === null || $key > $bestKey) {
                $best = $candidate;
                $bestKey = $key;
            }
        }

        return $best ?? $this->refreshPlanetShips((array) $planetRows[0]);
    }

    /**
     * Per-bot plan for this tick: which planet researches, which builds colony ships, and whether
     * the account still wants colonies.
     *
     * @param  array<int, object|array<string, mixed>>  $planetRows
     * @return array{research_planet_id: int, colony_yard_id: int, colony_push: bool, need_colony_ship: bool, colony_slots: int, colonies: int}
     */
    private function accountPlan(User $bot, array $user, array $planetRows): array
    {
        $researchPlanetId = 0;
        $bestLab = -1;
        $yardId = 0;
        $bestYard = -1;
        $colonyShips = 0;

        foreach ($planetRows as $row) {
            $row = (array) $row;
            $colonyShips += (int) ($row['ship_colony_ship'] ?? 0)
                + ($this->brain->parseHangarQueue((string) ($row['planet_b_hangar_id'] ?? ''))[208] ?? 0);

            if ((int) ($row['planet_type'] ?? 1) !== 1) {
                continue;
            }

            $lab = (int) ($row['building_laboratory'] ?? 0);
            if ($lab > $bestLab) {
                [$bestLab, $researchPlanetId] = [$lab, (int) $row['planet_id']];
            }

            $yard = (int) ($row['building_hangar'] ?? 0) * 100 + (int) ($row['building_metal_mine'] ?? 0);
            if ($yard > $bestYard) {
                [$bestYard, $yardId] = [$yard, (int) $row['planet_id']];
            }
        }

        $astro = (int) ($user['research_astrophysics'] ?? 0);
        $slots = $this->colonizer->colonySlotsFree((int) $bot->id, $astro);
        $colonies = count(array_filter($planetRows, fn ($r) => (int) (((array) $r)['planet_type'] ?? 1) === 1)) - 1;

        return [
            'research_planet_id' => $researchPlanetId,
            'colony_yard_id' => $yardId,
            // Get Shipyard 4 + Robot 2 ready from the start, and keep going while colonies are allowed
            'colony_push' => $colonies < \App\Services\Bot\ColonizationService::MAX_COLONIES && ($astro < 1 || $slots > 0),
            'need_colony_ship' => $astro >= 1 && $slots > $colonyShips,
            'colony_slots' => $slots,
            'colonies' => $colonies,
        ];
    }

    /**
     * Send every colony ship the bot owns to the best free slot, while the account may still found
     * colonies. Not blocked by other fleets being out (it was: any fleet out from the planet did).
     *
     * @param  array<int, object|array<string, mixed>>  $planetRows
     */
    private function colonise(User $bot, array $user, array $planetRows, bool $dryRun): int
    {
        $astro = (int) ($user['research_astrophysics'] ?? 0);
        if ($astro < 1) {
            return 0;
        }

        $sent = 0;
        foreach ($planetRows as $row) {
            $planet = $this->refreshPlanetShips((array) $row);
            if ((int) ($planet['ship_colony_ship'] ?? 0) < 1) {
                continue;
            }
            if ($this->colonizer->colonySlotsFree((int) $bot->id, $astro) < 1) {
                break;
            }

            $target = $this->colonizer->findColonizationTarget($planet, (int) $bot->id, $astro);
            if ($target === null) {
                $this->line("  [{$bot->id}] {$bot->name}: no free colony slot found near {$planet['planet_galaxy']}:{$planet['planet_system']}");
                continue;
            }

            $ships = [208 => 1];
            if ((int) ($planet['ship_big_cargo_ship'] ?? 0) > 0) {
                $ships[203] = 1;
            } elseif ((int) ($planet['ship_small_cargo_ship'] ?? 0) > 0) {
                $ships[202] = 1;
            }
            $where = "{$target['galaxy']}:{$target['system']}:{$target['planet']}";

            if ($dryRun) {
                $this->line("  [{$bot->id}] {$bot->name}: would colonise {$where}");
                $sent++;
                continue;
            }

            if ($this->dispatcher->sendColonize($planet, $user, $target, $ships)) {
                $this->line("  [{$bot->id}] {$bot->name}: colonising {$where}");
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Young colonies (Metal Mine < 12) get a share of the richest planet's stock, one transport per
     * colony at a time. Replaces ResourceTrader, which gave balanced/turtle bots' resources to OTHER
     * bots and ignored what the giver was saving for.
     *
     * @param  array<int, object|array<string, mixed>>  $planetRows
     */
    private function feedColonies(User $bot, array $user, array $planetRows, bool $dryRun): int
    {
        $planets = array_values(array_filter(
            array_map(fn ($r) => (array) $r, $planetRows),
            fn ($p) => (int) ($p['planet_type'] ?? 1) === 1
        ));
        if (count($planets) < 2) {
            return 0;
        }

        $donor = null;
        $donorStock = 0.0;
        foreach ($planets as $p) {
            $p = $this->refreshPlanetShips($p);
            $stock = (float) $p['planet_metal'] + (float) $p['planet_crystal'] + (float) $p['planet_deuterium'];
            if ((int) ($p['building_metal_mine'] ?? 0) >= 12 && $stock > $donorStock) {
                [$donor, $donorStock] = [$p, $stock];
            }
        }
        if ($donor === null || $donorStock < 60_000) {
            return 0;
        }

        $fed = 0;
        foreach ($planets as $colony) {
            if ((int) $colony['planet_id'] === (int) $donor['planet_id'] || (int) ($colony['building_metal_mine'] ?? 0) >= 12) {
                continue;
            }

            $inbound = DB::table('fleets')
                ->where('fleet_owner', (int) $bot->id)
                ->where('fleet_mission', Missions::TRANSPORT)
                ->where('fleet_mess', 0)
                ->where('fleet_end_galaxy', (int) $colony['planet_galaxy'])
                ->where('fleet_end_system', (int) $colony['planet_system'])
                ->where('fleet_end_planet', (int) $colony['planet_planet'])
                ->where('fleet_end_type', 1)
                ->exists();
            if ($inbound) {
                continue;
            }

            // A fifth of the donor's stock per young colony, at most ~150K, in a 3:2:1 mix
            $metal = (int) min((float) $donor['planet_metal'] * 0.2, 75_000);
            $crystal = (int) min((float) $donor['planet_crystal'] * 0.2, 50_000);
            $deut = (int) min((float) $donor['planet_deuterium'] * 0.2, 25_000);
            if ($metal + $crystal + $deut < 10_000) {
                break;
            }

            $dest = [
                'galaxy' => (int) $colony['planet_galaxy'],
                'system' => (int) $colony['planet_system'],
                'planet' => (int) $colony['planet_planet'],
                'type' => 1,
            ];
            $where = "{$dest['galaxy']}:{$dest['system']}:{$dest['planet']}";

            if ($dryRun) {
                $this->line("  [{$bot->id}] {$bot->name}: would feed colony {$where}");
                $fed++;
                continue;
            }

            if ($this->dispatcher->sendTransport($donor, $user, $dest, $metal, $crystal, $deut)) {
                $this->line("  [{$bot->id}] {$bot->name}: feeding colony {$where}");
                $fed++;
                $donor = $this->refreshPlanetShips($donor);
            }
        }

        return $fed;
    }

    /** Max expedition points (Expedition.php), from the top player's score; cached per tick. */
    private function maxExpeditionPoints(): int
    {
        if ($this->expeditionPointsCache === null) {
            $top = (float) (DB::table('users_statistics')->max('user_statistic_total_points') ?? 0);
            $this->expeditionPointsCache = app(\App\Services\Game\Formulas\ExpeditionService::class)->getMaxExpeditionPoints($top);
        }

        return $this->expeditionPointsCache;
    }

    private ?int $expeditionPointsCache = null;

    /** Per-bot exceptions go to storage/logs/bot-tick-errors-YYYY-MM.log (not only the console). */
    private function logBotError(User $bot, \Throwable $e, bool $dryRun): void
    {
        try {
            $line = sprintf(
                "%s | %sbot %d %s | %s: %s @ %s:%d\n",
                date('Y-m-d H:i:s'),
                $dryRun ? 'DRY ' : '',
                $bot->id,
                $bot->name,
                get_class($e),
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine()
            );
            file_put_contents(storage_path('logs/bot-tick-errors-' . date('Y-m') . '.log'), $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // never let logging break the tick
        }
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
    private function buildDefenderFromIntel(array $intel, int $botId = 0, array $ownUser = []): array
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

        // Dale's rule (2026-10-01): bots only use what a player could see. This used to take the
        // larger of the report and the target's LIVE ships/defences (6 Sep fix for fleets that were
        // away when probed), and read the defender's real research. Now, like a player: the report,
        // raised to whatever this bot met at those coordinates in its own last battle there (its
        // own battle report), and its OWN tech as the guess for the defender's tech.
        foreach ($this->lastMetDefender($botId, (int) ($intel['galaxy'] ?? 0), (int) ($intel['system'] ?? 0), (int) ($intel['planet'] ?? 0)) as $id => $count) {
            $column = $shipMap[$id] ?? $defenseMap[$id] ?? null;
            if ($column !== null) {
                $planet[$column] = max((int) $planet[$column], (int) $count);
            }
        }

        foreach (['research_weapons_technology', 'research_shielding_technology', 'research_armour_technology'] as $tech) {
            $planet[$tech] = (int) ($ownUser[$tech] ?? 0);
        }

        return $planet;
    }

    /** What this bot met in its last battle at a target, this tick: "bot:g:s:p" => [unit id => count]. */
    private array $lastMetCache = [];

    /** True for a human player (users.bot_profile IS NULL). Cached for the tick. */
    private function isHumanPlayer(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        if (!array_key_exists($userId, $this->humanIds)) {
            $this->humanIds[$userId] = DB::table('users')->where('id', $userId)->whereNull('bot_profile')->exists();
        }

        return $this->humanIds[$userId];
    }

    /**
     * Planets this bot has given up on (fix 1): "g:s:p" => unix time the skip ends.
     *
     * @return array<string, int>
     */
    private function loadSkips(int $botId): array
    {
        $out = [];
        $rows = DB::table('bot_target_skip')
            ->where('bot_user_id', $botId)
            ->where('until', '>', time())
            ->get(['galaxy', 'system', 'planet', 'until']);
        foreach ($rows as $row) {
            $out["{$row->galaxy}:{$row->system}:{$row->planet}"] = (int) $row->until;
        }

        return $out;
    }

    /**
     * Record that the battle engine rejected this target (fix 1). Only called for planets not already on
     * the list, so an active skip is never extended tick after tick.
     *
     * @param  array<string, mixed>  $target  intel row (galaxy/system/planet)
     */
    private function rememberUnwinnable(int $botId, array $target, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        DB::table('bot_target_skip')->updateOrInsert(
            ['bot_user_id' => $botId, 'galaxy' => (int) $target['galaxy'], 'system' => (int) $target['system'], 'planet' => (int) $target['planet']],
            ['until' => time() + BotSpeed::seconds(self::UNWINNABLE_SKIP_SECONDS), 'set_at' => time(), 'reason' => 'sim-rejected']
        );
    }

    /**
     * Freshest usable scan of a human-owned planet by ANY bot inside HUMAN_INTEL_SHARE_SECONDS (fix 2).
     * If it is another bot's and newer than this bot's own latest scan it is cloned into this bot's intel
     * so the attack phase can use it. Returns that scanned_at, or 0 when nobody has looked recently
     * (=> go ahead and probe).
     */
    private function borrowHumanIntel(int $botId, int $galaxy, int $system, int $planet, int $ownLatest, bool $dryRun): int
    {
        // Dale's rule (2026-10-01): a player only sees spy reports his alliance shares. Bots without
        // an alliance never borrow; inside one they only borrow from alliance members.
        $allyId = (int) DB::table('users')->where('id', $botId)->value('ally_id');
        if ($allyId <= 0) {
            return 0;
        }
        $members = DB::table('users')->where('ally_id', $allyId)->pluck('id')->all();

        $row = DB::table('bot_intel')
            ->whereIn('bot_user_id', $members)
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->where('planet', $planet)
            ->where('scanned_at', '>', time() - BotSpeed::seconds(self::HUMAN_INTEL_SHARE_SECONDS))
            ->where('expires_at', '>', time())
            ->orderByDesc('scanned_at')
            ->first();
        if ($row === null) {
            return 0;
        }

        $scannedAt = (int) $row->scanned_at;
        if ((int) $row->bot_user_id !== $botId && $scannedAt > $ownLatest && !$dryRun) {
            $copy = (array) $row;
            unset($copy['id']);
            $copy['bot_user_id'] = $botId;
            DB::table('bot_intel')->insert($copy);
        }

        return $scannedAt;
    }

    /**
     * The defender's units in this bot's own most recent battle at those coordinates (its own battle
     * report; empty if it never fought there).
     *
     * @return array<int, int> unit id => count
     */
    private function lastMetDefender(int $botId, int $galaxy, int $system, int $planet): array
    {
        $key = "{$botId}:{$galaxy}:{$system}:{$planet}";

        if (!array_key_exists($key, $this->lastMetCache)) {
            $json = $botId > 0 ? DB::table('bot_combat_log')
                ->where('attacker_id', $botId)
                ->where('target_coords', "{$galaxy}:{$system}:{$planet}")
                ->orderByDesc('id')
                ->value('defender_fleet') : null;
            $decoded = json_decode((string) $json, true);
            $this->lastMetCache[$key] = is_array($decoded) ? array_map('intval', $decoded) : [];
        }

        return $this->lastMetCache[$key];
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
            407 => 'Small Shield Dome', 408 => 'Large Shield Dome',
            502 => 'Anti-Ballistic Missile', 503 => 'Interplanetary Missile',
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

        // Finds stop growing at the game's max expedition points (Expedition.php:
        // (metal + crystal) x 5 / 1000 per ship, capped by the top player's score). Ships past the
        // cap only burn fuel — after Phase 1 cut the fuel, expedition fleets grew 3.5x (122 -> 433).
        $maxPoints = $this->maxExpeditionPoints();
        $points = 0;
        foreach ($fleet as $shipId => $count) {
            $points += self::EXPEDITION_SHIP_VALUE[$shipId] * 5 / 1000 * $count;
        }
        if ($points > $maxPoints) {
            $scale = $maxPoints / $points;
            $value = 0;
            foreach ($fleet as $shipId => $count) {
                $fleet[$shipId] = max(0, (int) floor($count * $scale));
                if ($fleet[$shipId] === 0) {
                    unset($fleet[$shipId]);
                    continue;
                }
                $value += $fleet[$shipId] * self::EXPEDITION_SHIP_VALUE[$shipId];
            }
            if (array_sum($fleet) < self::EXPEDITION_MIN_COMBAT_SHIPS) {
                return [];
            }
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
     * Pick a system for an expedition: within ±EXPEDITION_RANGE of home, rotating by bot and hour
     * to spread depletion (ExpeditionService: 20 expeditions deplete a system to a 50 % floor, it
     * recovers 2 an hour). Never the home system. Until 29 Sep this reached up to 399 systems
     * away — ~19K deuterium per trip, about half of all bot deuterium income.
     */
    private function pickExpeditionSystem(int $galaxy, int $homeSystem, int $botId): int
    {
        // x1: flights take 5x longer, so expeditions stay closer to home (20 systems at x5, 4 at x1)
        $range = max(4, (int) round(self::EXPEDITION_RANGE / max(1.0, BotSpeed::factor())));
        $span = 2 * $range; // offsets -RANGE..-1, 1..RANGE
        $step = ($botId + (int) (time() / 3600)) % $span;
        $offset = $step < $range ? $step - $range : $step - $range + 1;
        $system = $homeSystem + $offset;

        if ($system < 1 || $system > MAX_SYSTEM_IN_GALAXY) {
            $system = $homeSystem - $offset; // reflect off the edge of the galaxy
        }

        return max(1, min(MAX_SYSTEM_IN_GALAXY, $system));
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

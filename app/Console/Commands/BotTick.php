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
use App\Services\Bot\ResourceTrader;
use App\Services\Bot\TargetScanner;
use App\Services\Game\BuildingQueueService;
use App\Services\Game\ResearchQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
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

    protected $signature = 'bot:tick {--dry-run : Show what would happen without saving}';

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

        $stats = ['processed' => 0, 'built' => 0, 'ships' => 0, 'researches' => 0, 'attacks' => 0, 'spies' => 0, 'fleet_saves' => 0, 'skipped' => 0, 'errors' => 0];

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
        $this->info("  Skipped (sleeping): {$stats['skipped']}");
        $this->info("  Errors: {$stats['errors']}");

        return self::SUCCESS;
    }

    /**
     * Process a single bot.
     *
     * @return array{built: bool, ship_built: bool, attacked: bool, spied: bool, building: string|null, ship: string|null, research: string|null, attack_target: string|null, spy_target: string|null}
     */
    private function processBot(User $bot, bool $dryRun): array
    {
        $result = [
            'built' => false, 'ship_built' => false, 'attacked' => false, 'spied' => false, 'fleet_saved' => false,
            'building' => null, 'ship' => null, 'research' => null,
            'attack_target' => null, 'spy_target' => null,
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

        // Track which planet we can launch attacks from (first with combat ships)
        $attackPlanet = null;
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
            if ($planetModel && $planetModel->buildingQueue()->count() === 0) {
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
            $shipDecision = $this->brain->nextShip($planet, $user);

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
            if (!$researchQueued) {
                $labLevel = (int) ($planet['building_laboratory'] ?? 0);

                if ($labLevel >= 1 && $planetModel) {
                    $researchId = $this->brain->nextResearch($user);

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

            // Debris field harvesting
            if (!$this->dispatcher->hasActiveFleetFromPlanet($planet['planet_galaxy'], $planet['planet_system'], $planet['planet_planet'])
                && $this->harvester->hasRecyclers($planet)
            ) {
                $debrisTarget = $this->harvester->findHarvestTarget($planet, $bot->id);

                if ($debrisTarget !== null) {
                    $needed = $this->harvester->calcRecyclersNeeded($debrisTarget);
                    $available = (int) ($planet['ship_recycler'] ?? 0);
                    $toSend = min($needed, $available);

                    if ($toSend > 0) {
                        $harvestFleet = [209 => $toSend];

                        if (!$dryRun) {
                            $fleetId = $this->dispatcher->sendRecycle($planet, $user, $debrisTarget, $harvestFleet);
                            if ($fleetId) {
                                $this->harvester->markHarvested($debrisTarget['combat_id'], $bot->id);
                                $this->line("  [{$bot->id}] {$bot->name}: harvesting {$debrisTarget['debris_metal']}+{$debrisTarget['debris_crystal']} debris at {$debrisTarget['galaxy']}:{$debrisTarget['system']}:{$debrisTarget['planet']}");
                            }
                        } else {
                            $this->line("  [{$bot->id}] {$bot->name}: would harvest {$debrisTarget['debris_metal']}+{$debrisTarget['debris_crystal']} debris at {$debrisTarget['galaxy']}:{$debrisTarget['system']}:{$debrisTarget['planet']}");
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

            // Track first planet with combat ships for attack phase
            if ($attackPlanet === null) {
                $combatShips = (int) ($planet['ship_light_fighter'] ?? 0)
                    + (int) ($planet['ship_heavy_fighter'] ?? 0)
                    + (int) ($planet['ship_cruiser'] ?? 0)
                    + (int) ($planet['ship_battleship'] ?? 0);
                if ($combatShips > 0) {
                    $attackPlanet = $planet;
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

        // --- Phase 5: Scout and attack (from best planet) ---
        // Use first planet with combat ships, or fall back to first planet
        $planet = $attackPlanet ?? (array) $planetRows[0];
        $personality = $this->brain->getPersonality($user);

        if ($personality !== 'passive' && !$this->dispatcher->hasActiveFleetFromPlanet($planet['planet_galaxy'], $planet['planet_system'], $planet['planet_planet'])) {
            // Scan for nearby targets
            $targets = $this->scanner->scan($planet, 5);
            $probes = (int) ($planet['ship_espionage_probe'] ?? 0);

            // SPY PHASE: Send probes to multiple targets to gather intel
            $spiedCount = 0;
            $maxSpyPerTick = 3;

            if ($probes > 0 && !empty($targets)) {
                foreach ($targets as $spyTarget) {
                    if ($spiedCount >= $maxSpyPerTick) break;

                    if (!$dryRun) {
                        $fleetId = $this->dispatcher->sendSpy($planet, $user, $spyTarget, 1);
                        if ($fleetId) {
                            $result['spied'] = true;
                            $result['spy_target'] = "{$spyTarget['galaxy']}:{$spyTarget['system']}:{$spyTarget['planet']}";
                            $spiedCount++;
                        }
                    } else {
                        $result['spied'] = true;
                        $result['spy_target'] = "{$spyTarget['galaxy']}:{$spyTarget['system']}:{$spyTarget['planet']}";
                        $spiedCount++;
                    }
                }
            }

            // ATTACK PHASE: Use intel to pick best target and raid
            $intelData = $this->intel->getAllIntel($bot->id);
            $bestTarget = null;
            $useIntel = false;

            if ($dryRun && !empty($intelData)) {
                $this->line("  [{$bot->id}] INTEL: " . count($intelData) . " entries, first_res=" . ($intelData[0]['total_resources'] ?? 0));
            } elseif ($dryRun) {
                $this->line("  [{$bot->id}] INTEL: 0 entries");
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

                usort($intelData, function ($a, $b) use ($grudgeTargets) {
                    $aGrudge = $grudgeTargets[$a['user_id'] ?? 0] ?? null;
                    $bGrudge = $grudgeTargets[$b['user_id'] ?? 0] ?? null;
                    $aSeverity = $aGrudge ? ($aGrudge['attack_count'] ?? 0) : 0;
                    $bSeverity = $bGrudge ? ($bGrudge['attack_count'] ?? 0) : 0;

                    if ($aSeverity !== $bSeverity) {
                        return $bSeverity <=> $aSeverity;
                    }

                    return $b['total_resources'] <=> $a['total_resources'];
                });

                foreach ($intelData as $intelTarget) {
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
                    if ($defenderId > 0 && $defenderId != $user['id']) {
                        $defStats = DB::selectOne("SELECT user_statistic_total_points FROM `{$prefix}users_statistics` WHERE `user_statistic_user_id` = ?", [$defenderId]);
                        $defPoints = (int) ($defStats->user_statistic_total_points ?? 0);

                        if ($noobLib->isWeak($botPoints, $defPoints) || $noobLib->isStrong($botPoints, $defPoints)) {
                            continue;
                        }
                    }

                    if ($defenderId > 0) {
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
                    $intelTarget['user_id'] = (int) ($intelTarget['user_id'] ?? 0);
                    $intelTarget['planet_data'] = $this->buildDefenderFromIntel($intelTarget);

                    $bestTarget = $intelTarget;
                    $useIntel = true;

                    if ($dryRun) {
                        $grudgeInfo = '';
                        if (isset($grudgeTargets[$intelTarget['user_id']])) {
                            $g = $grudgeTargets[$intelTarget['user_id']];
                            $grudgeInfo = " GRUDGE:{$g['severity']}({$g['attack_count']}x)";
                        }
                        $this->line("  [{$bot->id}] BEST: G:{$intelTarget['galaxy']}:{$intelTarget['system']}:{$intelTarget['planet']} res={$intelTarget['total_resources']} dist=$distance fleet_def=" . (empty($intelTarget['planet_data']) ? 'EMPTY' : 'OK') . $grudgeInfo);
                    }

                    break;
                }
            }

            if ($bestTarget !== null) {
                $intelAge = time() - ($bestTarget['scanned_at'] ?? 0);
                if ($intelAge > 1800 && !$dryRun) {
                    $this->dispatcher->sendSpy($planet, $user, $bestTarget);
                    $result['spied'] = true;
                    $result['spy_target'] = "{$bestTarget['galaxy']}:{$bestTarget['system']}:{$bestTarget['planet']} (refresh)";
                } else {
                    $attackFleet = $this->brain->planAttack($planet, $user, $bestTarget);

                    if ($attackFleet === null && $dryRun) {
                        $availableShips = array_filter([
                            204 => (int) ($planet['ship_light_fighter'] ?? 0),
                            205 => (int) ($planet['ship_heavy_fighter'] ?? 0),
                            206 => (int) ($planet['ship_cruiser'] ?? 0),
                            207 => (int) ($planet['ship_battleship'] ?? 0),
                        ], fn ($c) => $c > 0);

                        $fleet = [];
                        if (isset($availableShips[204]) && $availableShips[204] > 0) $fleet[204] = min($availableShips[204], 50);
                        if (isset($planet['ship_small_cargo_ship']) && $planet['ship_small_cargo_ship'] > 0) {
                            $fleet[202] = min((int)$planet['ship_small_cargo_ship'], (int)ceil($bestTarget['resources'] / 5000), 20);
                        }

                        $defenderPlanet = $bestTarget['planet_data'] ?? [];
                        $defenderUser = ['research_weapons_technology' => 0, 'research_shielding_technology' => 0, 'research_armour_technology' => 0];

                        try {
                            $simResult = $this->simulator->simulate($fleet, $defenderPlanet, $user, $defenderUser);
                            $lossRate = array_sum($fleet) > 0 ? $simResult['attacker_losses'] / array_sum($fleet) : 1;
                            $this->line("  [{$bot->id}] SIM: winner={$simResult['winner']} losses={$simResult['attacker_losses']} rate=" . round($lossRate * 100) . "% fleet=" . json_encode($fleet));
                        } catch (\Throwable $e) {
                            $this->line("  [{$bot->id}] SIM ERROR: " . $e->getMessage());
                        }
                    }

                    if ($attackFleet !== null) {
                        try {
                        $defenderPlanet = $useIntel
                            ? $this->buildDefenderFromIntel($bestTarget)
                            : ($bestTarget['planet_data'] ?? []);

                        $defenderUser = [
                            'research_weapons_technology' => (int) ($defenderPlanet['research_weapons_technology'] ?? 0),
                            'research_shielding_technology' => (int) ($defenderPlanet['research_shielding_technology'] ?? 0),
                            'research_armour_technology' => (int) ($defenderPlanet['research_armour_technology'] ?? 0),
                        ];

                        $simResult = $this->simulator->simulate($attackFleet, $defenderPlanet, $user, $defenderUser);

                        $attackerInitial = array_sum($attackFleet);
                        $lossRate = $attackerInitial > 0 ? $simResult['attacker_losses'] / $attackerInitial : 1;

                        if ($simResult['winner'] === 'attacker' && $lossRate < 0.8) {
                            if (!$dryRun) {
                                $fleetId = $this->dispatcher->sendAttack($planet, $user, $bestTarget, $attackFleet);
                                if ($fleetId) {
                                    $result['attacked'] = true;
                                    $result['attack_target'] = "{$bestTarget['galaxy']}:{$bestTarget['system']}:{$bestTarget['planet']}";

                                    $this->chat->sendAttackWinMessage($user, (int) ($bestTarget['user_id'] ?? 0), $personality);
                                }
                            } else {
                                $result['attacked'] = true;
                                $result['attack_target'] = "{$bestTarget['galaxy']}:{$bestTarget['system']}:{$bestTarget['planet']}";
                            }
                        }
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::error("BotTick: attack sim crashed for {$bot->name}: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        return $result;
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

        // Add to hangar queue AND deduct resources (parameterized to prevent injection)
        $hangarEntry = $shipId . ',' . $count . ';';
        DB::table('planets')
            ->where('planet_id', $planetId)
            ->update([
                'planet_b_hangar_id' => DB::raw("CONCAT(IFNULL(planet_b_hangar_id, ''), '" . DB::connection()->getPdo()->quote($hangarEntry) . "')"),
                'planet_metal'       => DB::raw('planet_metal - ' . (int) $totalMetal),
                'planet_crystal'     => DB::raw('planet_crystal - ' . (int) $totalCrystal),
                'planet_deuterium'   => DB::raw('planet_deuterium - ' . (int) $totalDeuterium),
            ]);
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

        return $planet;
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
}

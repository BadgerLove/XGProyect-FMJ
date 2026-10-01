<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Xgp\App\Libraries\MissionControlLib;

/**
 * Process every fleet that has arrived or come home, exactly as a page load does
 * (MissionControlLib, same `xgp_fleet_processor` lock, so it never runs twice at once).
 *
 * Why (2026-10-01): fleets were only processed on page loads and by the 15-minute bot tick. Bots
 * land ~900 fleets an hour, so the first player to open a page after a quiet spell processed the
 * whole backlog (800+ fleets) before seeing anything: logins "hung" for 5-24 s and some players
 * gave up. Run every minute by the scheduled task "OGame Fleet Processor"
 * (xgproyect/fleets-process-hidden.vbs), pages only ever find a minute's worth.
 */
class ProcessFleets extends Command
{
    protected $signature = 'fleets:process';

    protected $description = 'Process arrived and returning fleets (what a page load does), so players never wait for the backlog';

    public function handle(SettingsService $settings): int
    {
        $this->bootLegacy($settings);

        $before = $this->pending();
        $start = microtime(true);
        $missionControl = app(MissionControlLib::class);

        try {
            $missionControl->arrivingFleets();
            $missionControl->returningFleets();
        } catch (\Throwable $e) {
            Log::error('fleets:process crashed: ' . $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $after = $this->pending();
        $seconds = microtime(true) - $start;

        if ($before > 0) {
            $line = sprintf('%s | pending %d -> %d | %.1fs', now()->format('Y-m-d H:i:s'), $before, $after, $seconds);
            file_put_contents(storage_path('logs/fleets-' . now()->format('Y-m') . '.log'), $line . PHP_EOL, FILE_APPEND);
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /** Fleets waiting to be processed (held expeditions excluded: they only wait out their stay). */
    private function pending(): int
    {
        $now = time();

        return DB::table('fleets')
            ->where(function ($q) use ($now) {
                $q->where(function ($a) use ($now) {
                    $a->where('fleet_mess', 0)
                        ->where('fleet_start_time', '<=', $now)
                        ->whereRaw('NOT (fleet_mission = 15 AND fleet_end_stay > ?)', [$now]);
                })->orWhere(function ($r) use ($now) {
                    $r->where('fleet_mess', '<>', 0)->where('fleet_end_time', '<=', $now);
                });
            })
            ->count();
    }

    /** The constants a page load gets from legacy Common.php / the bot tick, for the mission handlers. */
    private function bootLegacy(SettingsService $settings): void
    {
        $legacyConstants = base_path('config/legacy/constants.php');
        if (file_exists($legacyConstants)) {
            require_once $legacyConstants;
        }

        // same clock as a page load (Common::setSystemTimezone): report texts carry formatted times
        date_default_timezone_set($settings->getString('date_time_zone'));

        // same debris factors as a page load (Common::setUpdates), before the battle engine's defaults
        if (!defined('SHIP_DEBRIS_FACTOR')) {
            define('SHIP_DEBRIS_FACTOR', $settings->getInt('fleet_cdr') / 100);
        }
        if (!defined('DEFENSE_DEBRIS_FACTOR')) {
            define('DEFENSE_DEBRIS_FACTOR', $settings->getInt('defs_cdr') / 100);
        }

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

        if (!defined('LIB_PATH')) {
            define('LIB_PATH', base_path('legacy/app/Libraries') . DIRECTORY_SEPARATOR);
        }
    }
}

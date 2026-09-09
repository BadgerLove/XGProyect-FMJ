<?php

declare(strict_types=1);

namespace Xgp\App\Libraries;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Xgp\App\Core\Concerns\PreparesLegacySql;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class MissionControlLib
{
    use PreparesLegacySql;

    public function arrivingFleets(): void
    {
        $this->processMissions($this->getArrivingFleets());
    }

    public function returningFleets(): void
    {
        $this->processMissions($this->getReturningFleets());
    }

    private function getArrivingFleets(): array
    {
        return array_map(
            fn ($row) => (array) $row,
            DB::select(
                $this->prepareSql(
                    'SELECT
                        f.*,
                        sp.`planet_name` AS `planet_start_name`,
                        ep.`planet_name` AS `planet_end_name`,
                        sr.`research_hyperspace_technology`
                    FROM `' . FLEETS . '` f
                    LEFT JOIN `' . PLANETS . '` sp
                        ON (sp.`planet_galaxy` = f.`fleet_start_galaxy` AND
                            sp.`planet_system` = f.`fleet_start_system` AND
                            sp.`planet_planet` = f.`fleet_start_planet` AND
                            sp.`planet_type` = f.`fleet_start_type`
                    )
                    LEFT JOIN `' . RESEARCH . '` sr
                        ON sr.`research_user_id` = f.`fleet_owner`
                    LEFT JOIN `' . PLANETS . "` ep
                        ON (ep.`planet_galaxy` = f.`fleet_end_galaxy` AND
                            ep.`planet_system` = f.`fleet_end_system` AND
                            ep.`planet_planet` = f.`fleet_end_planet` AND
                            ep.`planet_type` = f.`fleet_end_type`
                    )
                    WHERE f.`fleet_start_time` <= '" . time() . "'
                        AND f.`fleet_mess` = '0'
                    GROUP BY f.`fleet_id`, sp.`planet_name`, ep.`planet_name`
                    ORDER BY f.`fleet_id` ASC"
                )
            )
        );
    }

    private function getReturningFleets(): array
    {
        return array_map(
            fn ($row) => (array) $row,
            DB::select(
                $this->prepareSql(
                    'SELECT
                        f.*,
                        sp.`planet_name` AS `planet_start_name`,
                        ep.`planet_name` AS `planet_end_name`,
                        sr.`research_hyperspace_technology`
                    FROM `' . FLEETS . '` f
                    LEFT JOIN `' . PLANETS . '` sp
                        ON (sp.`planet_galaxy` = f.`fleet_start_galaxy` AND
                            sp.`planet_system` = f.`fleet_start_system` AND
                            sp.`planet_planet` = f.`fleet_start_planet` AND
                            sp.`planet_type` = f.`fleet_start_type`
                    )
                    LEFT JOIN `' . RESEARCH . '` sr
                        ON sr.`research_user_id` = f.`fleet_owner`
                    LEFT JOIN `' . PLANETS . "` ep
                        ON (ep.`planet_galaxy` = f.`fleet_end_galaxy` AND
                            ep.`planet_system` = f.`fleet_end_system` AND
                            ep.`planet_planet` = f.`fleet_end_planet` AND
                            ep.`planet_type` = f.`fleet_end_type`
                    )
                    WHERE f.`fleet_end_time` <= '" . time() . "'
                        AND f.`fleet_mess` <> '0'
                    GROUP BY f.`fleet_id`, sp.`planet_name`, ep.`planet_name`
                    ORDER BY f.`fleet_id` ASC"
                )
            )
        );
    }

    private function processMissions(array $allFleets = []): void
    {
        // validate
        if (!is_array($allFleets) or empty($allFleets)) {
            return;
        }

        // missions list
        $missions = [
            1 => 'Attack',
            2 => 'Acs',
            3 => 'Transport',
            4 => 'Deploy',
            5 => 'Stay',
            6 => 'Spy',
            7 => 'Colonize',
            8 => 'Recycle',
            9 => 'Destroy',
            10 => 'Missile',
            15 => 'Expedition',
        ];

        // Process missions — each fleet on its own. This runs on every page load, so one fleet
        // whose handler throws must never stop the rest of the batch (2026-09-09: a single recycle
        // fleet aimed at a deleted player's planet took the site down for every logged-in human).
        foreach ($allFleets as $fleet) {
            $name = $missions[$fleet['fleet_mission']] ?? null;

            if ($name === null) {
                $this->quarantineFleet($fleet, new \RuntimeException('unknown mission ' . $fleet['fleet_mission']));
                continue;
            }

            try {
                $mission_name = $name . 'Mission';
                $class_name = 'Xgp\App\Libraries\Missions\\' . $name;

                $mission = app($class_name);
                $mission->$mission_name($fleet);
            } catch (\Throwable $e) {
                $this->quarantineFleet($fleet, $e);
            }
        }
    }

    /**
     * A fleet whose handler threw. First failure: turn it around so it flies home and the
     * handler's return branch restores the ships. Second failure (it threw again on the way
     * home): delete it — the ships are lost, and the log says so. Either way the next batch
     * is not blocked by it.
     */
    private function quarantineFleet(array $fleet, \Throwable $e): void
    {
        $fleetId = (int) ($fleet['fleet_id'] ?? 0);
        $where = sprintf(
            'fleet %d (mission %s, owner %s, %s:%s:%s -> %s:%s:%s)',
            $fleetId,
            $fleet['fleet_mission'] ?? '?',
            $fleet['fleet_owner'] ?? '?',
            $fleet['fleet_start_galaxy'] ?? '?',
            $fleet['fleet_start_system'] ?? '?',
            $fleet['fleet_start_planet'] ?? '?',
            $fleet['fleet_end_galaxy'] ?? '?',
            $fleet['fleet_end_system'] ?? '?',
            $fleet['fleet_end_planet'] ?? '?'
        );
        $reason = $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();

        try {
            if ($fleetId <= 0) {
                Log::error("MissionControl: {$where} failed and has no id, skipped: {$reason}");
            } elseif ((int) ($fleet['fleet_mess'] ?? 0) === 0) {
                DB::table('fleets')->where('fleet_id', $fleetId)->update(['fleet_mess' => 1]);
                Log::error("MissionControl: {$where} failed on arrival, fleet recalled: {$reason}");
            } else {
                DB::table('fleets')->where('fleet_id', $fleetId)->delete();
                Log::error("MissionControl: {$where} failed on return, fleet REMOVED (ships lost): {$reason}");
            }
        } catch (\Throwable $inner) {
            Log::critical("MissionControl: could not quarantine {$where}: " . $inner->getMessage() . " (original: {$reason})");
        }
    }

    /**
     * Turn around every other player's fleet still flying to one of this user's planets.
     * Call BEFORE the planets are deleted, or those fleets arrive at coordinates that no
     * longer exist. Returns the number of fleets recalled.
     */
    public static function recallFleetsTargetingUser(int $userId): int
    {
        $p = DB::getTablePrefix();

        $byPlanet = DB::update(
            "UPDATE `{$p}fleets` AS f
                INNER JOIN `{$p}planets` AS pl
                    ON pl.`planet_galaxy` = f.`fleet_end_galaxy`
                    AND pl.`planet_system` = f.`fleet_end_system`
                    AND pl.`planet_planet` = f.`fleet_end_planet`
                    AND pl.`planet_type` = f.`fleet_end_type`
            SET f.`fleet_mess` = 1
            WHERE pl.`planet_user_id` = ?
                AND f.`fleet_mess` = 0
                AND f.`fleet_owner` <> ?",
            [$userId, $userId]
        );

        $byOwner = DB::update(
            "UPDATE `{$p}fleets`
            SET `fleet_mess` = 1
            WHERE `fleet_target_owner` = ?
                AND `fleet_mess` = 0
                AND `fleet_owner` <> ?",
            [$userId, $userId]
        );

        $count = $byPlanet + $byOwner;

        if ($count > 0) {
            Log::info("MissionControl: recalled {$count} fleet(s) targeting user {$userId} before deleting the user");
        }

        return $count;
    }

    /**
     * Same, for planets that are about to be purged because `planet_destroyed` is older than
     * the given timestamp. Returns the number of fleets recalled.
     */
    public static function recallFleetsTargetingDestroyedPlanets(int $destroyedBefore): int
    {
        $p = DB::getTablePrefix();

        $count = DB::update(
            "UPDATE `{$p}fleets` AS f
                INNER JOIN `{$p}planets` AS pl
                    ON pl.`planet_galaxy` = f.`fleet_end_galaxy`
                    AND pl.`planet_system` = f.`fleet_end_system`
                    AND pl.`planet_planet` = f.`fleet_end_planet`
                    AND pl.`planet_type` = f.`fleet_end_type`
            SET f.`fleet_mess` = 1
            WHERE pl.`planet_destroyed` <> 0
                AND pl.`planet_destroyed` < ?
                AND f.`fleet_mess` = 0",
            [$destroyedBefore]
        );

        if ($count > 0) {
            Log::info("MissionControl: recalled {$count} fleet(s) targeting destroyed planets before purging them");
        }

        return $count;
    }
}

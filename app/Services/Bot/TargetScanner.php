<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Libraries\FleetsLib;

/**
 * Picks which neighbouring planets a bot should spy on next.
 *
 * Dale's rule (2026-10-01): bots only use what a player could see. This used to rank planets by
 * their LIVE resources, ships, defences and research and the owner's exact last-online time.
 * Now it uses what a player has: the galaxy view (who is where, the admin/vacation markers, the
 * i/I inactive flags, noob protection by highscore points) and the bot's OWN spy reports.
 * A planet the bot has a report on scores by that report (resources minus strength, older
 * reports count for less); a planet it has never looked at gets a modest "worth a look" score.
 */
class TargetScanner
{
    use PreparesLegacySql;

    /** What an unscanned planet is worth looking at, before distance and inactivity. */
    private const UNSCANNED_SCORE = 1.0;

    /** Galaxy-view inactive flags: i = 7 days, I = 28 days (GalaxyLib). */
    private const INACTIVE_DAYS = 7;
    private const LONG_INACTIVE_DAYS = 28;

    /**
     * @param  array<string, mixed>  $botPlanet
     * @return list<array{planet_id: int, user_id: int, galaxy: int, system: int, planet: int, resources: int, defense_strength: int, distance: int, score: float, last_active: int}>
     */
    public function scan(array $botPlanet, int $range = 5): array
    {
        $botGalaxy = (int) $botPlanet['planet_galaxy'];
        $botSystem = (int) $botPlanet['planet_system'];
        $botPlanetNum = (int) $botPlanet['planet_planet'];
        $botUserId = (int) $botPlanet['planet_user_id'];

        $systemMin = max(1, $botSystem - $range);
        $systemMax = $botSystem + $range;
        $prefix = DB::getTablePrefix();

        // galaxy-view facts only: position, owner, admin/vacation markers, how long inactive
        $rows = DB::select(
            "SELECT p.`planet_id`, p.`planet_user_id`, p.`planet_galaxy`, p.`planet_system`, p.`planet_planet`,
                u.`onlinetime`, u.`authlevel`, pr.`preference_vacation_mode`
            FROM `{$prefix}planets` AS p
            INNER JOIN `{$prefix}users` AS u ON u.`id` = p.`planet_user_id`
            LEFT JOIN `{$prefix}preferences` AS pr ON pr.`preference_user_id` = p.`planet_user_id`
            WHERE p.`planet_galaxy` = ?
                AND p.`planet_system` BETWEEN ? AND ?
                AND p.`planet_type` = 1
                AND p.`planet_destroyed` = 0
                AND p.`planet_user_id` != ?
            ORDER BY p.`planet_system` ASC",
            [$botGalaxy, $systemMin, $systemMax, $botUserId]
        );

        $reports = $this->ownLatestReports($botUserId, $botGalaxy, $systemMin, $systemMax);
        $reportAge = BotSpeed::seconds(7200); // a report this old (2 h at x5, 10 h at x1) counts half

        $targets = [];
        $now = time();

        foreach ($rows as $row) {
            $row = (array) $row;

            if ((int) ($row['authlevel'] ?? 0) > 0) {          // admin marker
                continue;
            }
            if ((int) ($row['preference_vacation_mode'] ?? 0) > 0) { // vacation marker
                continue;
            }
            if ($this->isNoobProtected($botUserId, (int) $row['planet_user_id'])) { // highscore points
                continue;
            }

            $distance = FleetsLib::targetDistance(
                $botGalaxy,
                (int) $row['planet_galaxy'],
                $botSystem,
                (int) $row['planet_system'],
                $botPlanetNum,
                (int) $row['planet_planet']
            );

            // only what the galaxy view shows: nothing, "i" (7+ days) or "I" (28+ days)
            $daysAway = ($now - (int) ($row['onlinetime'] ?? $now)) / 86400;
            $inactivityBonus = $daysAway >= self::LONG_INACTIVE_DAYS ? 10.0 : ($daysAway >= self::INACTIVE_DAYS ? 7.0 : 0.0);

            $key = "{$row['planet_system']}:{$row['planet_planet']}";
            $report = $reports[$key] ?? null;

            if ($report !== null) {
                $resources = (int) $report['metal'] + (int) $report['crystal'] + (int) $report['deuterium'];
                if ($resources < BotSpeed::amount(1000)) {
                    continue;
                }
                $strength = $this->strength($report['fleet'], $report['defense']);
                $freshness = ($now - (int) $report['scanned_at']) > $reportAge ? 0.5 : 1.0;
                $score = ($resources / BotSpeed::amount(10000)) * $freshness - $strength / 5000;
            } else {
                $resources = 0;
                $strength = 0;
                $score = self::UNSCANNED_SCORE;
            }

            $score += $inactivityBonus - $distance / 5000;

            if ($score > 0) {
                $targets[] = [
                    'planet_id'        => (int) $row['planet_id'],
                    'user_id'          => (int) $row['planet_user_id'],
                    'galaxy'           => (int) $row['planet_galaxy'],
                    'system'           => (int) $row['planet_system'],
                    'planet'           => (int) $row['planet_planet'],
                    'resources'        => $resources,
                    'defense_strength' => $strength,
                    'distance'         => $distance,
                    'score'            => $score,
                    'last_active'      => 0, // exact online time is not visible to a player
                    'planet_data'      => $row,
                ];
            }
        }

        usort($targets, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $targets;
    }

    /**
     * The bot's own newest, unexpired spy report per planet in range: "system:planet" => report.
     *
     * @return array<string, array{metal: int, crystal: int, deuterium: int, fleet: array<int, int>, defense: array<int, int>, scanned_at: int}>
     */
    private function ownLatestReports(int $botUserId, int $galaxy, int $systemMin, int $systemMax): array
    {
        $rows = DB::table('bot_intel')
            ->where('bot_user_id', $botUserId)
            ->where('galaxy', $galaxy)
            ->whereBetween('system', [$systemMin, $systemMax])
            ->where('expires_at', '>', time())
            ->orderByDesc('scanned_at')
            ->get(['system', 'planet', 'metal', 'crystal', 'deuterium', 'fleet_data', 'defense_data', 'scanned_at']);

        $reports = [];
        foreach ($rows as $row) {
            $key = "{$row->system}:{$row->planet}";
            if (isset($reports[$key])) {
                continue; // newest first
            }
            $reports[$key] = [
                'metal' => (int) $row->metal,
                'crystal' => (int) $row->crystal,
                'deuterium' => (int) $row->deuterium,
                'fleet' => array_map('intval', json_decode((string) $row->fleet_data, true) ?: []),
                'defense' => array_map('intval', json_decode((string) $row->defense_data, true) ?: []),
                'scanned_at' => (int) $row->scanned_at,
            ];
        }

        return $reports;
    }

    private function isNoobProtected(int $attackerId, int $defenderId): bool
    {
        $prefix = DB::getTablePrefix();

        $attackerStats = DB::selectOne(
            "SELECT `user_statistic_total_points`
            FROM `{$prefix}users_statistics`
            WHERE `user_statistic_user_id` = ?",
            [$attackerId]
        );

        $defenderStats = DB::selectOne(
            "SELECT `user_statistic_total_points`
            FROM `{$prefix}users_statistics`
            WHERE `user_statistic_user_id` = ?",
            [$defenderId]
        );

        $attackerPoints = (int) ($attackerStats->user_statistic_total_points ?? 0);
        $defenderPoints = (int) ($defenderStats->user_statistic_total_points ?? 0);

        $noob = new \Xgp\App\Libraries\NoobsProtectionLib();

        return $noob->isWeak($attackerPoints, $defenderPoints) || $noob->isStrong($attackerPoints, $defenderPoints);
    }

    /**
     * Rough combat strength of what a report showed (unit id => count).
     *
     * @param  array<int, int>  $fleet
     * @param  array<int, int>  $defense
     */
    private function strength(array $fleet, array $defense): int
    {
        $power = [
            202 => 5, 203 => 5, 204 => 50, 205 => 150, 206 => 400, 207 => 1000, 210 => 0,
            213 => 2000, 214 => 200000, 215 => 2800,
            401 => 80, 402 => 100, 403 => 250, 404 => 1100, 405 => 500, 406 => 3000,
            502 => 2000, 503 => 10000,
        ];

        $total = 0;
        foreach ($fleet + $defense as $id => $count) {
            $total += ($power[$id] ?? 0) * $count;
        }

        return $total;
    }
}

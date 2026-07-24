<?php

declare(strict_types=1);

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;

/**
 * Detects suspicious players who may be using bots or scripts.
 *
 * Analyzes activity patterns (onlinetime updates) to identify
 * players with inhuman consistency or zero sleep periods.
 */
class BotDetectionService
{
    /**
     * Minimum hours of activity to be considered suspicious.
     */
    private const SUSPICIOUS_HOURS = 20;

    /**
     * Maximum inactivity gap (minutes) for a player to be considered "always on".
     */
    private const MAX_GAP_MINUTES = 30;

    /**
     * Analyze all players and return suspicion scores.
     *
     * @return list<array{id: int, name: string, email: string, onlinetime: int, suspicion_score: int, flags: list<string>, hours_active_24h: int, longest_gap_minutes: int, activity_count: int}>
     */
    public function analyzePlayers(): array
    {
        $prefix = DB::getTablePrefix();
        $now = time();
        $dayAgo = $now - 86400;

        // Get all non-bot, non-admin players with recent activity
        $players = DB::select(
            "SELECT
                u.`id`,
                u.`name`,
                u.`email`,
                u.`onlinetime`,
                u.`authlevel`,
                u.`register_time`,
                u.`lastip`,
                p.`planet_metal`,
                p.`planet_crystal`,
                p.`planet_deuterium`,
                b.`building_metal_mine`,
                b.`building_robot_factory`
            FROM `{$prefix}users` AS u
            LEFT JOIN `{$prefix}planets` AS p ON p.`planet_user_id` = u.`id` AND p.`planet_type` = 1
            LEFT JOIN `{$prefix}buildings` AS b ON b.`building_planet_id` = p.`planet_id`
            WHERE u.`email` NOT LIKE '%@bots.local'
                AND u.`authlevel` = 0
                AND u.`onlinetime` > ?
            ORDER BY u.`onlinetime` DESC",
            [$dayAgo]
        );

        $results = [];

        foreach ($players as $player) {
            $analysis = $this->analyzePlayer((array) $player, $now);

            if ($analysis['suspicion_score'] > 0) {
                $results[] = $analysis;
            }
        }

        // Sort by suspicion score descending
        usort($results, fn ($a, $b) => $b['suspicion_score'] <=> $a['suspicion_score']);

        return $results;
    }

    /**
     * Analyze a single player's activity pattern.
     *
     * @param  array<string, mixed>  $player
     * @return array{id: int, name: string, email: string, onlinetime: int, suspicion_score: int, flags: list<string>, hours_active_24h: int, longest_gap_minutes: int, activity_count: int}
     */
    private function analyzePlayer(array $player, int $now): array
    {
        $flags = [];
        $score = 0;
        $onlinetime = (int) ($player['onlinetime'] ?? 0);

        // How recently was the player active?
        $minutesSinceActive = ($now - $onlinetime) / 60;

        // Flag 1: Very recently active (within 5 min) and has been for a long time
        if ($minutesSinceActive < 5) {
            // Check how long they've been consistently active
            $registrationAge = $now - (int) ($player['register_time'] ?? $now);
            $daysSinceRegistration = $registrationAge / 86400;

            if ($daysSinceRegistration > 1) {
                // Check activity in the last 24 hours by looking at onlinetime patterns
                $activityHours = $this->estimateActiveHours((int) $player['id'], $now);

                if ($activityHours >= self::SUSPICIOUS_HOURS) {
                    $flags[] = "Active {$activityHours}h in last 24h (suspicious: >" . self::SUSPICIOUS_HOURS . "h)";
                    $score += 40;
                }

                if ($activityHours >= 23) {
                    $flags[] = 'Near-continuous activity (possible bot)';
                    $score += 30;
                }
            }
        }

        // Flag 2: Check session history for pattern regularity
        $sessionPattern = $this->analyzeSessionPattern((int) $player['id'], $now);

        if ($sessionPattern['regularity_score'] > 70) {
            $flags[] = "Highly regular activity pattern ({$sessionPattern['regularity_score']}% regular)";
            $score += 25;
        }

        // Flag 3: Resource accumulation rate (too fast = botting)
        $resourceScore = $this->checkResourceAnomalies($player, $now);

        if ($resourceScore > 0) {
            $flags[] = 'Abnormal resource accumulation';
            $score += $resourceScore;
        }

        // Flag 4: IP reputation (multiple accounts from same IP)
        $ipFlag = $this->checkIpReputation((string) ($player['lastip'] ?? ''), (int) $player['id']);

        if ($ipFlag) {
            $flags[] = $ipFlag;
            $score += 15;
        }

        // Cap score at 100
        $score = min(100, $score);

        return [
            'id'                    => (int) $player['id'],
            'name'                  => (string) ($player['name'] ?? ''),
            'email'                 => (string) ($player['email'] ?? ''),
            'onlinetime'            => $onlinetime,
            'suspicion_score'       => $score,
            'flags'                 => $flags,
            'hours_active_24h'      => $sessionPattern['hours_active'] ?? 0,
            'longest_gap_minutes'   => $sessionPattern['longest_gap'] ?? 0,
            'activity_count'       => $sessionPattern['activity_count'] ?? 0,
        ];
    }

    /**
     * Estimate how many hours a player has been active in the last 24 hours.
     *
     * Uses session data if available, falls back to onlinetime estimation.
     */
    private function estimateActiveHours(int $userId, int $now): int
    {
        $prefix = DB::getTablePrefix();
        $dayAgo = $now - 86400;

        // Count unique session starts in the last 24 hours
        $sessions = DB::selectOne(
            "SELECT COUNT(*) AS session_count,
                    MIN(`last_activity`) AS earliest,
                    MAX(`last_activity`) AS latest
            FROM `{$prefix}sessions`
            WHERE `user_id` = ?
                AND `last_activity` > ?",
            [$userId, $dayAgo]
        );

        if ($sessions && (int) $sessions->session_count > 0) {
            $span = (int) $sessions->latest - (int) $sessions->earliest;

            return (int) round($span / 3600);
        }

        return 0;
    }

    /**
     * Analyze session patterns for regularity.
     *
     * Bots tend to have perfectly regular intervals between actions.
     * Real humans have irregular patterns.
     *
     * @return array{regularity_score: int, hours_active: int, longest_gap: int, activity_count: int}
     */
    private function analyzeSessionPattern(int $userId, int $now): array
    {
        $prefix = DB::getTablePrefix();
        $dayAgo = $now - 86400;

        // Get session activity timestamps
        $sessions = DB::select(
            "SELECT `last_activity`
            FROM `{$prefix}sessions`
            WHERE `user_id` = ?
                AND `last_activity` > ?
            ORDER BY `last_activity` ASC",
            [$userId, $dayAgo]
        );

        if (count($sessions) < 5) {
            return ['regularity_score' => 0, 'hours_active' => 0, 'longest_gap' => 0, 'activity_count' => count($sessions)];
        }

        $timestamps = array_map(fn ($s) => (int) $s->last_activity, $sessions);
        $gaps = [];

        for ($i = 1; $i < count($timestamps); $i++) {
            $gap = $timestamps[$i] - $timestamps[$i - 1];

            if ($gap > 0 && $gap < 3600) { // Only count gaps under 1 hour
                $gaps[] = $gap;
            }
        }

        if (empty($gaps)) {
            return ['regularity_score' => 0, 'hours_active' => 0, 'longest_gap' => 0, 'activity_count' => count($sessions)];
        }

        // Calculate regularity: low standard deviation = suspicious
        $mean = array_sum($gaps) / count($gaps);
        $variance = 0;

        foreach ($gaps as $gap) {
            $variance += pow($gap - $mean, 2);
        }

        $variance /= count($gaps);
        $stdDev = sqrt($variance);

        // Coefficient of variation: lower = more regular
        $cv = $mean > 0 ? ($stdDev / $mean) * 100 : 100;

        // Regularity score: 100 = perfectly regular, 0 = completely random
        $regularityScore = max(0, (int) (100 - $cv));

        // Calculate hours active
        $span = end($timestamps) - $timestamps[0];
        $hoursActive = (int) round($span / 3600);

        // Longest gap
        $longestGap = (int) (max($gaps) / 60);

        return [
            'regularity_score' => $regularityScore,
            'hours_active'     => $hoursActive,
            'longest_gap'      => $longestGap,
            'activity_count'   => count($sessions),
        ];
    }

    /**
     * Check for abnormal resource accumulation.
     *
     * @param  array<string, mixed>  $player
     */
    private function checkResourceAnomalies(array $player, int $now): int
    {
        $metal = (int) ($player['planet_metal'] ?? 0);
        $crystal = (int) ($player['planet_crystal'] ?? 0);
        $deuterium = (int) ($player['planet_deuterium'] ?? 0);
        $totalResources = $metal + $crystal + $deuterium;

        $mineLevel = (int) ($player['building_metal_mine'] ?? 0);
        $registrationAge = $now - (int) ($player['register_time'] ?? $now);
        $daysSinceRegistration = max(1, $registrationAge / 86400);

        // Expected production: roughly 1000 * mine_level * days * game_speed
        $expectedResources = $mineLevel * $daysSinceRegistration * 5000;

        if ($totalResources > $expectedResources * 5 && $totalResources > 1000000) {
            return 20; // Suspicious: 5x more resources than expected
        }

        return 0;
    }

    /**
     * Check if multiple accounts share the same IP.
     */
    private function checkIpReputation(string $ip, int $userId): ?string
    {
        if (empty($ip)) {
            return null;
        }

        $prefix = DB::getTablePrefix();

        $count = DB::selectOne(
            "SELECT COUNT(*) AS account_count
            FROM `{$prefix}users`
            WHERE `lastip` = ?
                AND `email` NOT LIKE '%@bots.local'
                AND `id` != ?",
            [$ip, $userId]
        );

        if ($count && (int) $count->account_count > 0) {
            return "Shared IP with {$count->account_count} other account(s)";
        }

        return null;
    }
}

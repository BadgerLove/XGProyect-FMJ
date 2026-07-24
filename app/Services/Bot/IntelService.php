<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\DB;

/**
 * Manages bot intelligence — parses espionage reports and stores
 * structured data for attack planning.
 *
 * When a bot spies on a target, the spy mission sends an HTML message
 * with the spy report. This service extracts that data and stores it
 * in the bot_intel table for quick lookup during attack decisions.
 */
class IntelService
{
    /**
     * Intel expires after 24 hours (stale data is dangerous).
     */
    private const INTEL_TTL = 86400;

    /**
     * Parse new espionage reports for a bot and store the intel.
     *
     * @return int Number of reports parsed
     */
    public function parseNewReports(int $botUserId): int
    {
        $prefix = DB::getTablePrefix();
        $now = time();

        // Find unread spy report messages for this bot
        // Spy reports are stored as message_type = 5 (GENERAL) via Functions::sendMessage
        $messages = DB::select(
            "SELECT `message_id`, `message_subject`, `message_text`, `message_time`
            FROM `{$prefix}messages`
            WHERE `message_receiver` = ?
                AND `message_type` = 5
                AND `message_read` = 0
                AND (`message_from` = 'Fleet Command' OR `message_text` LIKE '%Resources%')
            ORDER BY `message_time` DESC
            LIMIT 50",
            [$botUserId]
        );

        $parsed = 0;

        foreach ($messages as $msg) {
            $text = (string) $msg->message_text;

            // Only process spy reports — check for spy report HTML markers
            if (!str_contains($text, 'Resources') && !str_contains($text, 'spy_report') && !str_contains($text, 'Spy') && !str_contains($text, 'espionage') && !str_contains($text, 'Fleets')) {
                continue;
            }

            $intel = $this->extractSpyData((string) $msg->message_subject . ' ' . $text);

            if ($intel === null) {
                continue;
            }

            // Store intel
            $this->storeIntel($botUserId, $intel, (int) $msg->message_time, $now);

            // Mark message as read
            DB::statement(
                "UPDATE `{$prefix}messages` SET `message_read` = 1 WHERE `message_id` = ?",
                [(int) $msg->message_id]
            );

            $parsed++;
        }

        return $parsed;
    }

    /**
     * Get the best intel on a specific target.
     *
     * @return array{metal: int, crystal: int, deuterium: int, fleet_data: array, defense_data: array, scanned_at: int}|null
     */
    public function getIntel(int $botUserId, int $galaxy, int $system, int $planet): ?array
    {
        $prefix = DB::getTablePrefix();
        $now = time();

        $row = DB::selectOne(
            "SELECT *
            FROM `{$prefix}bot_intel`
            WHERE `bot_user_id` = ?
                AND `galaxy` = ?
                AND `system` = ?
                AND `planet` = ?
                AND `expires_at` > ?
            ORDER BY `scanned_at` DESC
            LIMIT 1",
            [$botUserId, $galaxy, $system, $planet, $now]
        );

        if (!$row) {
            return null;
        }

        return [
            'metal'        => (int) $row->metal,
            'crystal'      => (int) $row->crystal,
            'deuterium'    => (int) $row->deuterium,
            'fleet_data'   => json_decode((string) $row->fleet_data, true) ?? [],
            'defense_data' => json_decode((string) $row->defense_data, true) ?? [],
            'scanned_at'   => (int) $row->scanned_at,
        ];
    }

    /**
     * Get all intel for a bot (for target selection).
     *
     * @return list<array{galaxy: int, system: int, planet: int, metal: int, crystal: int, deuterium: int, total_resources: int, fleet_strength: int, defense_strength: int, scanned_at: int}>
     */
    public function getAllIntel(int $botUserId): array
    {
        $prefix = DB::getTablePrefix();
        $now = time();

        $rows = DB::select(
            "SELECT *
            FROM `{$prefix}bot_intel`
            WHERE `bot_user_id` = ?
                AND `expires_at` > ?
            ORDER BY `scanned_at` DESC",
            [$botUserId, $now]
        );

        $intel = [];

        foreach ($rows as $row) {
            $fleetData = json_decode((string) $row->fleet_data, true) ?? [];
            $defenseData = json_decode((string) $row->defense_data, true) ?? [];

            $intel[] = [
                'user_id'          => (int) ($row->target_user_id ?? 0),
                'galaxy'           => (int) $row->galaxy,
                'system'           => (int) $row->system,
                'planet'           => (int) $row->planet,
                'metal'            => (int) $row->metal,
                'crystal'          => (int) $row->crystal,
                'deuterium'        => (int) $row->deuterium,
                'total_resources'  => (int) $row->metal + (int) $row->crystal + (int) $row->deuterium,
                'fleet_strength'   => $this->calculateFleetStrength($fleetData),
                'fleet_data'       => $fleetData,
                'defense_strength' => $this->calculateDefenseStrength($defenseData),
                'defense_data'     => $defenseData,
                'scanned_at'       => (int) $row->scanned_at,
            ];
        }

        return $intel;
    }

    /**
     * Clean up expired intel.
     */
    public function cleanup(): int
    {
        $prefix = DB::getTablePrefix();
        $now = time();

        return DB::delete(
            "DELETE FROM `{$prefix}bot_intel` WHERE `expires_at` < ?",
            [$now]
        );
    }

    /**
     * Extract spy data from a message's HTML content.
     *
     * Spy reports contain tables with resource/fleet/defense/building/research data.
     * The format varies by espionage tech level, but generally follows this pattern:
     * - Resource rows: metal, crystal, deuterium values
     * - Fleet rows: ship names and counts
     * - Defense rows: defense names and counts
     *
     * @return array{galaxy: int, system: int, planet: int, metal: int, crystal: int, deuterium: int, fleet: array, defense: array}|null
     */
    private function extractSpyData(string $html): ?array
    {
        // Try to extract coordinates from the message
        $coordsMatch = null;

        if (preg_match('/(\d+):(\d+):(\d+)/', $html, $coordsMatch)) {
            $galaxy = (int) $coordsMatch[1];
            $system = (int) $coordsMatch[2];
            $planet = (int) $coordsMatch[3];
        } else {
            return null;
        }

        // Extract resources — look for numbers after resource keywords
        $metal = $this->extractResource($html, ['Metal', 'metal', 'Metall']);
        $crystal = $this->extractResource($html, ['Crystal', 'crystal', 'Kristall', 'Cristal']);
        $deuterium = $this->extractResource($html, ['Deuterium', 'deuterium', 'Deuterium']);

        // Extract fleet data
        $fleet = $this->extractUnits($html, $this->getShipPatterns());

        // Extract defense data
        $defense = $this->extractUnits($html, $this->getDefensePatterns());

        return [
            'galaxy'   => $galaxy,
            'system'   => $system,
            'planet'   => $planet,
            'metal'    => $metal,
            'crystal'  => $crystal,
            'deuterium' => $deuterium,
            'fleet'    => $fleet,
            'defense'  => $defense,
        ];
    }

    /**
     * Extract a resource value from HTML.
     *
     * @param  list<string>  $keywords
     */
    private function extractResource(string $html, array $keywords): int
    {
        foreach ($keywords as $keyword) {
            // Pattern: keyword in one cell, value in the NEXT cell with align=right
            // HTML: <td>Metal</td><td ... align=right>21.533</td>
            if (preg_match('/' . preg_quote($keyword, '/') . '<\/td>\s*<td[^>]*align=right>([\d.,]+)/i', $html, $match)) {
                $value = str_replace([',', '.'], ['', ''], $match[1]);

                if (is_numeric($value)) {
                    return (int) $value;
                }
            }

            // Fallback: keyword directly followed by number (no HTML)
            if (preg_match('/' . preg_quote($keyword, '/') . '\s+([\d.,]+)/i', $html, $match)) {
                $value = str_replace([',', '.'], ['', ''], $match[1]);

                if (is_numeric($value)) {
                    return (int) $value;
                }
            }
        }

        return 0;
    }

    /**
     * Extract unit counts from HTML.
     *
     * @param  array<string, int>  $patterns  Name pattern => unit ID
     * @return array<int, int>  Unit ID => count
     */
    private function extractUnits(string $html, array $patterns): array
    {
        $units = [];

        foreach ($patterns as $namePattern => $unitId) {
            // Look for "Ship Name ... number" pattern
            if (preg_match('/' . $namePattern . '.*?([\d.,]+)/i', $html, $match)) {
                $value = str_replace([',', '.'], ['', ''], $match[1]);

                if (is_numeric($value) && (int) $value > 0) {
                    $units[$unitId] = (int) $value;
                }
            }
        }

        return $units;
    }

    /**
     * Get ship name patterns for extraction.
     *
     * @return array<string, int>
     */
    private function getShipPatterns(): array
    {
        return [
            'Small Cargo'     => 202,
            'Kleiner Transporter' => 202,
            'Big Cargo'       => 203,
            'Großer Transporter' => 203,
            'Light Fighter'   => 204,
            'Leichter Jäger'  => 204,
            'Heavy Fighter'   => 205,
            'Schwerer Jäger'  => 205,
            'Cruiser'         => 206,
            'Kreuzer'         => 206,
            'Battleship'      => 207,
            'Schlachtschiff'  => 207,
            'Colony Ship'     => 208,
            'Kolonieschiff'   => 208,
            'Recycler'        => 209,
            'Recycler'        => 209,
            'Espionage Probe' => 210,
            'Spionagesonde'   => 210,
            'Bomber'          => 211,
            'Solar Satellite' => 212,
            'Solarsatellit'   => 212,
            'Destroyer'       => 213,
            'Zerstörer'       => 213,
            'Deathstar'       => 214,
            'Todesstern'      => 214,
            'Reaper'          => 215,
            'Schnitter'       => 215,
        ];
    }

    /**
     * Get defense name patterns for extraction.
     *
     * @return array<string, int>
     */
    private function getDefensePatterns(): array
    {
        return [
            'Rocket Launcher'          => 401,
            'Raketenwerfer'            => 401,
            'Light Laser'              => 402,
            'Leichtes Lasergeschütz'   => 402,
            'Heavy Laser'              => 403,
            'Schweres Lasergeschütz'   => 403,
            'Gauss Cannon'             => 404,
            'Gausskanone'              => 404,
            'Ion Cannon'               => 405,
            'Ionengeschütz'            => 405,
            'Plasma Turret'            => 406,
            'Plasmawerfer'             => 406,
            'Small Shield Dome'        => 502,
            'Kleine Schutzkuppel'      => 502,
            'Large Shield Dome'        => 503,
            'Große Schutzkuppel'       => 503,
        ];
    }

    /**
     * Store intel in the database.
     *
     * @param  array{galaxy: int, system: int, planet: int, metal: int, crystal: int, deuterium: int, fleet: array, defense: array}  $intel
     */
    private function storeIntel(int $botUserId, array $intel, int $messageTime, int $now): void
    {
        $prefix = DB::getTablePrefix();

        // Try to find the target user_id from the planet coordinates
        $targetPlanet = DB::selectOne(
            "SELECT `planet_id`, `planet_user_id`
            FROM `{$prefix}planets`
            WHERE `planet_galaxy` = ?
                AND `planet_system` = ?
                AND `planet_planet` = ?
                AND `planet_type` = 1
            LIMIT 1",
            [$intel['galaxy'], $intel['system'], $intel['planet']]
        );

        $targetUserId = $targetPlanet ? (int) $targetPlanet->planet_user_id : 0;
        $targetPlanetId = $targetPlanet ? (int) $targetPlanet->planet_id : 0;

        DB::table('bot_intel')->insert([
            'bot_user_id'     => $botUserId,
            'target_user_id'  => $targetUserId,
            'target_planet_id' => $targetPlanetId,
            'galaxy'          => $intel['galaxy'],
            'system'          => $intel['system'],
            'planet'          => $intel['planet'],
            'metal'           => $intel['metal'],
            'crystal'         => $intel['crystal'],
            'deuterium'       => $intel['deuterium'],
            'fleet_data'      => json_encode($intel['fleet']),
            'defense_data'    => json_encode($intel['defense']),
            'scanned_at'      => $messageTime,
            'expires_at'      => $messageTime + self::INTEL_TTL,
        ]);
    }

    /**
     * Calculate fleet strength from parsed fleet data.
     *
     * @param  array<int, int>  $fleet
     */
    private function calculateFleetStrength(array $fleet): int
    {
        $power = [
            202 => 5, 203 => 5, 204 => 50, 205 => 150, 206 => 400,
            207 => 1000, 208 => 50, 209 => 1, 210 => 0, 211 => 1000,
            212 => 1, 213 => 2000, 214 => 200000, 215 => 2800,
        ];

        $total = 0;

        foreach ($fleet as $shipId => $count) {
            $total += ($power[$shipId] ?? 0) * $count;
        }

        return $total;
    }

    /**
     * Calculate defense strength from parsed defense data.
     *
     * @param  array<int, int>  $defense
     */
    private function calculateDefenseStrength(array $defense): int
    {
        $power = [
            401 => 80, 402 => 100, 403 => 250, 404 => 1100,
            405 => 500, 406 => 3000, 502 => 2000, 503 => 10000,
        ];

        $total = 0;

        foreach ($defense as $unitId => $count) {
            $total += ($power[$unitId] ?? 0) * $count;
        }

        return $total;
    }
}

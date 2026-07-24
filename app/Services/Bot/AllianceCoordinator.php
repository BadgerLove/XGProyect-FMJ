<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\DB;
use Xgp\App\Libraries\FleetsLib;

/**
 * Coordinates bot alliances — mutual defense and coordinated attacks.
 *
 * When a bot detects incoming attacks, nearby bots can send fleet support.
 * Creates a cooperative defense network that makes bot alliances formidable.
 */
class AllianceCoordinator
{
    /**
     * Maximum distance to send defensive support (systems).
     */
    private const MAX_SUPPORT_DISTANCE = 20;

    /**
     * Minimum fleet strength to bother sending support.
     */
    private const MIN_SUPPORT_STRENGTH = 500;

    /**
     * Find bots under attack that need defensive support.
     *
     * @param  array<string, mixed>  $botPlanet
     * @return array{target_galaxy: int, target_system: int, target_planet: int, attacker_strength: int}|null
     */
    public function findDefensiveOpportunity(array $botPlanet): ?array
    {
        $prefix = DB::getTablePrefix();
        $botGalaxy = (int) $botPlanet['planet_galaxy'];
        $botSystem = (int) $botPlanet['planet_system'];
        $botUserId = (int) $botPlanet['planet_user_id'];
        $now = time();

        $systemMin = max(1, $botSystem - self::MAX_SUPPORT_DISTANCE);
        $systemMax = $botSystem + self::MAX_SUPPORT_DISTANCE;

        // Find nearby bots that are under attack
        $attacks = DB::select(
            "SELECT
                f.`fleet_end_galaxy`,
                f.`fleet_end_system`,
                f.`fleet_end_planet`,
                f.`fleet_array`,
                f.`fleet_amount`,
                f.`fleet_end_time`,
                p.`planet_user_id`
            FROM `{$prefix}fleets` AS f
            INNER JOIN `{$prefix}planets` AS p
                ON p.`planet_galaxy` = f.`fleet_end_galaxy`
                AND p.`planet_system` = f.`fleet_end_system`
                AND p.`planet_planet` = f.`fleet_end_planet`
                AND p.`planet_type` = 1
            INNER JOIN `{$prefix}users` AS u
                ON u.`id` = p.`planet_user_id`
            WHERE f.`fleet_mission` = 1
                AND f.`fleet_mess` = 0
                AND f.`fleet_end_time` > ?
                AND f.`fleet_end_galaxy` = ?
                AND f.`fleet_end_system` BETWEEN ? AND ?
                AND p.`planet_user_id` != ?
                AND u.`email` LIKE '%@bots.local'
            ORDER BY f.`fleet_end_time` ASC
            LIMIT 10",
            [$now, $botGalaxy, $systemMin, $systemMax, $botUserId]
        );

        foreach ($attacks as $attack) {
            $attack = (array) $attack;

            // Calculate attacker strength
            $ships = FleetsLib::getFleetShipsArray((string) $attack['fleet_array']);
            $attackerStrength = $this->calculateFleetStrength($ships);

            // Only help if the attack is significant
            if ($attackerStrength < self::MIN_SUPPORT_STRENGTH) {
                continue;
            }

            // Check if the attack arrives soon (within 30 min)
            $arrivalTime = (int) $attack['fleet_end_time'];

            if ($arrivalTime - $now > 1800) {
                continue; // Too far away, skip
            }

            return [
                'target_galaxy'    => (int) $attack['fleet_end_galaxy'],
                'target_system'    => (int) $attack['fleet_end_system'],
                'target_planet'    => (int) $attack['fleet_end_planet'],
                'attacker_strength' => $attackerStrength,
            ];
        }

        return null;
    }

    /**
     * Get the combat ships available for defensive support.
     *
     * @param  array<string, mixed>  $botPlanet
     * @return array<int, int>  Ship ID => count
     */
    public function getAvailableSupportFleet(array $botPlanet): array
    {
        $combatShips = [
            204 => (int) ($botPlanet['ship_light_fighter'] ?? 0),
            205 => (int) ($botPlanet['ship_heavy_fighter'] ?? 0),
            206 => (int) ($botPlanet['ship_cruiser'] ?? 0),
            207 => (int) ($botPlanet['ship_battleship'] ?? 0),
            213 => (int) ($botPlanet['ship_destroyer'] ?? 0),
        ];

        // Keep half the fleet for self-defense, send the other half
        $supportFleet = [];

        foreach ($combatShips as $shipId => $count) {
            if ($count >= 2) {
                $supportFleet[$shipId] = (int) floor($count / 2);
            }
        }

        return $supportFleet;
    }

    /**
     * Calculate fleet combat strength.
     *
     * @param  array<int, int>  $ships
     */
    private function calculateFleetStrength(array $ships): int
    {
        $power = [
            202 => 5, 203 => 5, 204 => 50, 205 => 150, 206 => 400,
            207 => 1000, 208 => 50, 209 => 1, 210 => 0, 211 => 1000,
            212 => 1, 213 => 2000, 214 => 200000, 215 => 2800,
        ];

        $total = 0;

        foreach ($ships as $shipId => $count) {
            $total += ($power[$shipId] ?? 0) * $count;
        }

        return $total;
    }
}

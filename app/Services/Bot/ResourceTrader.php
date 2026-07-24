<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\DB;

/**
 * Manages resource sharing between bots.
 *
 * When a bot has excess resources and a nearby bot needs them,
 * transports resources via deploy missions. Creates a cooperative
 * bot economy where bots help each other grow.
 */
class ResourceTrader
{
    /**
     * Minimum resources to consider sharing (don't transport tiny amounts).
     */
    private const MIN_SHARE_AMOUNT = 50000;

    /**
     * Maximum distance to share resources (same galaxy, nearby systems).
     */
    private const MAX_SHARE_DISTANCE = 50;

    /**
     * Check if a bot should share resources with neighbors.
     *
     * @param  array<string, mixed>  $botPlanet
     * @param  array<string, mixed>  $botUser
     * @return array{target_galaxy: int, target_system: int, target_planet: int, metal: int, crystal: int, deuterium: int}|null
     */
    public function findSharingOpportunity(array $botPlanet, array $botUser): ?array
    {
        $personality = json_decode((string) ($botUser['bot_profile'] ?? '{}'), true)['personality'] ?? 'raider';

        // Only balanced and turtle bots share resources
        if (!in_array($personality, ['balanced', 'turtle'])) {
            return null;
        }

        $metal = (int) ($botPlanet['planet_metal'] ?? 0);
        $crystal = (int) ($botPlanet['planet_crystal'] ?? 0);
        $deuterium = (int) ($botPlanet['planet_deuterium'] ?? 0);

        // Check if bot has excess resources (above 80% storage)
        $hasExcess = $metal > self::MIN_SHARE_AMOUNT || $crystal > self::MIN_SHARE_AMOUNT || $deuterium > self::MIN_SHARE_AMOUNT;

        if (!$hasExcess) {
            return null;
        }

        // Find a nearby bot that needs resources
        $prefix = DB::getTablePrefix();
        $botGalaxy = (int) $botPlanet['planet_galaxy'];
        $botSystem = (int) $botPlanet['planet_system'];
        $botUserId = (int) $botPlanet['planet_user_id'];

        $systemMin = max(1, $botSystem - self::MAX_SHARE_DISTANCE);
        $systemMax = $botSystem + self::MAX_SHARE_DISTANCE;

        // Find nearby bots with low resources
        $needyBots = DB::select(
            "SELECT
                p.`planet_id`,
                p.`planet_user_id`,
                p.`planet_galaxy`,
                p.`planet_system`,
                p.`planet_planet`,
                p.`planet_metal`,
                p.`planet_crystal`,
                p.`planet_deuterium`,
                b.`building_metal_store`,
                b.`building_crystal_store`,
                b.`building_deuterium_tank`
            FROM `{$prefix}planets` AS p
            INNER JOIN `{$prefix}buildings` AS b ON b.`building_planet_id` = p.`planet_id`
            INNER JOIN `{$prefix}users` AS u ON u.`id` = p.`planet_user_id`
            WHERE p.`planet_galaxy` = ?
                AND p.`planet_system` BETWEEN ? AND ?
                AND p.`planet_type` = 1
                AND p.`planet_destroyed` = 0
                AND p.`planet_user_id` != ?
                AND u.`email` LIKE '%@bots.local'
            ORDER BY (p.`planet_metal` + p.`planet_crystal` + p.`planet_deuterium`) ASC
            LIMIT 5",
            [$botGalaxy, $systemMin, $systemMax, $botUserId]
        );

        foreach ($needyBots as $needy) {
            $needy = (array) $needy;
            $needyTotal = (int) ($needy['planet_metal'] ?? 0)
                + (int) ($needy['planet_crystal'] ?? 0)
                + (int) ($needy['planet_deuterium'] ?? 0);

            // Only share with bots that have less than 20% of our resources
            $ourTotal = $metal + $crystal + $deuterium;

            if ($needyTotal < $ourTotal * 0.2) {
                // Calculate what to send (up to 30% of our excess)
                $sendMetal = min((int) ($metal * 0.3), $metal - self::MIN_SHARE_AMOUNT);
                $sendCrystal = min((int) ($crystal * 0.3), $crystal - self::MIN_SHARE_AMOUNT);
                $sendDeuterium = min((int) ($deuterium * 0.3), $deuterium - self::MIN_SHARE_AMOUNT);

                $sendMetal = max(0, $sendMetal);
                $sendCrystal = max(0, $sendCrystal);
                $sendDeuterium = max(0, $sendDeuterium);

                if ($sendMetal + $sendCrystal + $sendDeuterium >= self::MIN_SHARE_AMOUNT) {
                    return [
                        'target_galaxy'  => (int) $needy['planet_galaxy'],
                        'target_system'  => (int) $needy['planet_system'],
                        'target_planet'  => (int) $needy['planet_planet'],
                        'metal'          => $sendMetal,
                        'crystal'        => $sendCrystal,
                        'deuterium'      => $sendDeuterium,
                    ];
                }
            }
        }

        return null;
    }
}

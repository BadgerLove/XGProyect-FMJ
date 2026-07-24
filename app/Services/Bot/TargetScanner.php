<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Libraries\FleetsLib;

/**
 * Scans the galaxy neighborhood for profitable attack targets.
 */
class TargetScanner
{
    use PreparesLegacySql;

    /**
     * Scan for attack targets near a bot's planet.
     *
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

        $rows = DB::select(
            "SELECT
                p.`planet_id`,
                p.`planet_user_id`,
                p.`planet_galaxy`,
                p.`planet_system`,
                p.`planet_planet`,
                p.`planet_metal`,
                p.`planet_crystal`,
                p.`planet_deuterium`,
                u.`onlinetime`,
                u.`authlevel`,
                u.`ally_id`,
                pr.`preference_vacation_mode`,
                s.`ship_small_cargo_ship`,
                s.`ship_big_cargo_ship`,
                s.`ship_light_fighter`,
                s.`ship_heavy_fighter`,
                s.`ship_cruiser`,
                s.`ship_battleship`,
                s.`ship_espionage_probe`,
                s.`ship_destroyer`,
                s.`ship_deathstar`,
                s.`ship_reaper`,
                d.`defense_rocket_launcher`,
                d.`defense_light_laser`,
                d.`defense_heavy_laser`,
                d.`defense_ion_cannon`,
                d.`defense_gauss_cannon`,
                d.`defense_plasma_turret`,
                d.`defense_small_shield_dome`,
                d.`defense_large_shield_dome`,
                r.`research_weapons_technology`,
                r.`research_shielding_technology`,
                r.`research_armour_technology`
            FROM `{$prefix}planets` AS p
            INNER JOIN `{$prefix}users` AS u ON u.`id` = p.`planet_user_id`
            LEFT JOIN `{$prefix}preferences` AS pr ON pr.`preference_user_id` = p.`planet_user_id`
            INNER JOIN `{$prefix}ships` AS s ON s.`ship_planet_id` = p.`planet_id`
            INNER JOIN `{$prefix}defenses` AS d ON d.`defense_planet_id` = p.`planet_id`
            INNER JOIN `{$prefix}research` AS r ON r.`research_user_id` = p.`planet_user_id`
            WHERE p.`planet_galaxy` = ?
                AND p.`planet_system` BETWEEN ? AND ?
                AND p.`planet_type` = 1
                AND p.`planet_destroyed` = 0
                AND p.`planet_user_id` != ?
            ORDER BY p.`planet_system` ASC",
            [$botGalaxy, $systemMin, $systemMax, $botUserId]
        );

        $targets = [];
        $now = time();

        foreach ($rows as $row) {
            $row = (array) $row;

            // Skip admins
            if ((int) ($row['authlevel'] ?? 0) > 0) {
                continue;
            }

            // Skip vacation mode
            if ((int) ($row['preference_vacation_mode'] ?? 0) > 0) {
                continue;
            }

            // Skip noob protection using real game logic
            if ($this->isNoobProtected($botUserId, (int) $row['planet_user_id'])) {
                continue;
            }

            // Calculate resources
            $resources = (int) ($row['planet_metal'] ?? 0)
                + (int) ($row['planet_crystal'] ?? 0)
                + (int) ($row['planet_deuterium'] ?? 0);

            if ($resources < 1000) {
                continue;
            }

            // Calculate defense strength
            $defenseStrength = $this->calculateDefenseStrength($row);
            $shipStrength = $this->calculateShipStrength($row);
            $totalDefense = $defenseStrength + $shipStrength;

            // Calculate distance
            $distance = FleetsLib::targetDistance(
                $botGalaxy,
                (int) $row['planet_galaxy'],
                $botSystem,
                (int) $row['planet_system'],
                $botPlanetNum,
                (int) $row['planet_planet']
            );

            // Score target
            $resourceScore = $resources / 10000;
            $defensePenalty = $totalDefense / 5000;
            $distancePenalty = $distance / 5000;

            $lastActive = (int) ($row['onlinetime'] ?? 0);
            $hoursInactive = ($now - $lastActive) / 3600;
            $inactivityBonus = min($hoursInactive / 24, 10);

            $score = $resourceScore - $defensePenalty - $distancePenalty + $inactivityBonus;

            if ($score > 0) {
                $targets[] = [
                    'planet_id'        => (int) $row['planet_id'],
                    'user_id'          => (int) $row['planet_user_id'],
                    'galaxy'           => (int) $row['planet_galaxy'],
                    'system'           => (int) $row['planet_system'],
                    'planet'           => (int) $row['planet_planet'],
                    'resources'        => $resources,
                    'defense_strength' => $totalDefense,
                    'distance'         => $distance,
                    'score'            => $score,
                    'last_active'      => $lastActive,
                    // Raw data for battle simulation
                    'planet_data'      => $row,
                ];
            }
        }

        usort($targets, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $targets;
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
     * @param  array<string, mixed>  $planet
     */
    private function calculateDefenseStrength(array $planet): int
    {
        $defensePower = [
            'defense_rocket_launcher'  => 80,
            'defense_light_laser'      => 100,
            'defense_heavy_laser'      => 250,
            'defense_ion_cannon'       => 500,
            'defense_gauss_cannon'     => 1100,
            'defense_plasma_turret'    => 3000,
        ];

        $total = 0;

        foreach ($defensePower as $column => $power) {
            $total += (int) ($planet[$column] ?? 0) * $power;
        }

        // Shield domes
        $total += (int) ($planet['defense_small_shield_dome'] ?? 0) * 2000;
        $total += (int) ($planet['defense_large_shield_dome'] ?? 0) * 10000;

        return $total;
    }

    /**
     * @param  array<string, mixed>  $planet
     */
    private function calculateShipStrength(array $planet): int
    {
        $shipPower = [
            'ship_small_cargo_ship'  => 5,
            'ship_big_cargo_ship'    => 5,
            'ship_light_fighter'     => 50,
            'ship_heavy_fighter'     => 150,
            'ship_cruiser'           => 400,
            'ship_battleship'        => 1000,
            'ship_espionage_probe'   => 0,
            'ship_destroyer'         => 2000,
            'ship_deathstar'         => 200000,
            'ship_reaper'            => 2800,
        ];

        $total = 0;

        foreach ($shipPower as $column => $power) {
            $total += (int) ($planet[$column] ?? 0) * $power;
        }

        return $total;
    }
}

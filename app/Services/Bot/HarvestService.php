<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\DB;

/**
 * Debris Field Harvesting Service.
 *
 * Scans bot_combat_log for unharvested debris fields (debris_metal > 0
 * and not yet claimed), calculates recycler requirements, and queues
 * harvest missions.
 *
 * Recycler capacity: 20,000 units per ship.
 * Maximum 3 recyclers built per planet (configurable cap).
 */
class HarvestService
{
    /** Recycler cargo capacity */
    private const RECYCLER_CAPACITY = 20000;

    /** Max harvest distance (systems) */
    private const MAX_HARVEST_DISTANCE = 30;

    /** Minimum debris value to bother harvesting */
    private const MIN_DEBRIS_VALUE = 5000;

    /**
     * Find unharvested debris fields near a bot's planet.
     *
     * Bots only know about debris from battles they PARTICIPATED IN
     * or from systems they recently probed. No omniscient galaxy knowledge.
     *
     * @param  array<string, mixed>  $planet  Bot's planet data
     * @param  int                   $botId   Bot's user ID
     * @return array{combat_id: int, galaxy: int, system: int, planet: int, debris_metal: int, debris_crystal: int, distance: int}|null
     */
    public function findHarvestTarget(array $planet, int $botId): ?array
    {
        $botGalaxy = (int) $planet['planet_galaxy'];
        $botSystem = (int) $planet['planet_system'];
        $botPlanet = (int) $planet['planet_planet'];

        // Only see debris from battles this bot was involved in:
        // - Was the attacker
        // - Was the defender
        // - Same alliance as attacker (shared intel)
        $allyId = DB::table('users')->where('id', $botId)->value('ally_id') ?? 0;

        $prefix = DB::getTablePrefix();

        $debris = DB::select(
            "SELECT c.id, c.target_coords, c.debris_metal, c.debris_crystal
            FROM `{$prefix}bot_combat_log` AS c
            LEFT JOIN `{$prefix}users` AS u ON u.id = c.attacker_id
            WHERE (c.debris_metal + c.debris_crystal) >= ?
                AND c.harvested_by IS NULL
                AND c.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND (
                    c.attacker_id = ?
                    OR c.defender_id = ?
                    " . ($allyId > 0 ? "OR u.ally_id = ?" : "") . "
                )
            ORDER BY (c.debris_metal + c.debris_crystal) DESC, c.id DESC",
            $allyId > 0
                ? [self::MIN_DEBRIS_VALUE, $botId, $botId, $allyId]
                : [self::MIN_DEBRIS_VALUE, $botId, $botId]
        );

        $bestTarget = null;
        $bestValue = 0;

        foreach ($debris as $row) {
            // Parse coordinates "G:S:P"
            $coords = explode(':', $row->target_coords);
            if (count($coords) < 3) continue;

            $debrisGalaxy = (int) $coords[0];
            $debrisSystem = (int) $coords[1];
            $debrisPlanet = (int) $coords[2];

            // Same galaxy only
            if ($debrisGalaxy !== $botGalaxy) continue;

            // Calculate distance
            $systemDist = abs($debrisSystem - $botSystem);
            if ($systemDist > self::MAX_HARVEST_DISTANCE) continue;

            $distance = $systemDist + abs($debrisPlanet - $botPlanet);
            $value = (int) $row->debris_metal + (int) $row->debris_crystal;

            // Score: value minus distance penalty
            $score = $value - ($distance * 1000);

            if ($score > $bestValue) {
                $bestValue = $score;
                $bestTarget = [
                    'combat_id'      => (int) $row->id,
                    'galaxy'         => $debrisGalaxy,
                    'system'         => $debrisSystem,
                    'planet'         => $debrisPlanet,
                    'debris_metal'   => (int) $row->debris_metal,
                    'debris_crystal' => (int) $row->debris_crystal,
                    'distance'       => $distance,
                ];
            }
        }

        return $bestTarget;
    }

    /**
     * Calculate how many recyclers are needed to collect a debris field.
     *
     * @param  array{debris_metal: int, debris_crystal: int}  $debris
     * @return int  Number of recyclers needed
     */
    public function calcRecyclersNeeded(array $debris): int
    {
        $totalDebris = (int) $debris['debris_metal'] + (int) $debris['debris_crystal'];
        return (int) ceil($totalDebris / self::RECYCLER_CAPACITY);
    }

    /**
     * Mark a debris field as harvested.
     */
    public function markHarvested(int $combatId, int $botId): void
    {
        DB::table('bot_combat_log')
            ->where('id', $combatId)
            ->update([
                'harvested_by' => $botId,
                'harvested_at' => DB::raw('NOW()'),
            ]);
    }

    /**
     * Check if bot has recyclers available (not already deployed).
     *
     * @param  array<string, mixed>  $planet
     * @return bool
     */
    public function hasRecyclers(array $planet): bool
    {
        return (int) ($planet['ship_recycler'] ?? 0) > 0;
    }

    /**
     * Get total available recycler capacity for this planet.
     */
    public function getRecyclerCapacity(array $planet): int
    {
        $count = (int) ($planet['ship_recycler'] ?? 0);
        return $count * self::RECYCLER_CAPACITY;
    }
}

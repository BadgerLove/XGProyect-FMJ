<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\DB;

/**
 * Debris Field Harvesting Service.
 *
 * Bots learn about debris from the battles they took part in (bot_combat_log) and — since
 * 7 Sep 2026 — from the field lying at their own planet (every raid they suffer leaves one).
 * Every trip is sized from the LIVE planet_debris_* columns: several battles pile into the
 * same field and the log rows only know their own share.
 *
 * Recycler capacity: 20,000 units per ship.
 */
class HarvestService
{
    /** Recycler cargo capacity */
    private const RECYCLER_CAPACITY = 20000;

    /** Max harvest distance (systems) */
    private const MAX_HARVEST_DISTANCE = 30;

    /** Minimum debris value to bother harvesting */
    private const MIN_DEBRIS_VALUE = 5000;

    /** Live debris looked up this tick: "g:s:p" => [metal, crystal]. */
    private array $liveCache = [];

    /**
     * The debris field at the bot's own planet, if worth collecting.
     * No knowledge or distance needed — it is on the doorstep.
     *
     * @param  array<string, mixed>  $planet  Bot's planet row (planets.* included)
     * @return array{combat_id: int, galaxy: int, system: int, planet: int, debris_metal: int, debris_crystal: int, distance: int}|null
     */
    public function findOwnDebris(array $planet): ?array
    {
        if ((int) ($planet['planet_type'] ?? 1) !== 1) {
            return null; // moons carry no debris field
        }

        $metal = (int) ($planet['planet_debris_metal'] ?? 0);
        $crystal = (int) ($planet['planet_debris_crystal'] ?? 0);

        if ($metal + $crystal < self::MIN_DEBRIS_VALUE) {
            return null;
        }

        return [
            'combat_id'      => 0,
            'galaxy'         => (int) $planet['planet_galaxy'],
            'system'         => (int) $planet['planet_system'],
            'planet'         => (int) $planet['planet_planet'],
            'debris_metal'   => $metal,
            'debris_crystal' => $crystal,
            'distance'       => 0,
        ];
    }

    /**
     * Find the best unharvested debris field this bot knows about near one of its planets.
     *
     * Bots only know about debris from battles they PARTICIPATED IN (attacker or defender)
     * or that an alliance member fought. No omniscient galaxy knowledge. The amounts returned
     * are the live field, not the logged share.
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

        $allyId = DB::table('users')->where('id', $botId)->value('ally_id') ?? 0;

        $prefix = DB::getTablePrefix();

        $debris = DB::select(
            "SELECT c.id, c.target_coords
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
            ORDER BY c.id DESC",
            $allyId > 0
                ? [self::MIN_DEBRIS_VALUE, $botId, $botId, $allyId]
                : [self::MIN_DEBRIS_VALUE, $botId, $botId]
        );

        // One candidate per set of coordinates — the field is shared by every battle fought there
        $fields = [];

        foreach ($debris as $row) {
            $coords = explode(':', $row->target_coords);
            if (count($coords) < 3) continue;

            $debrisGalaxy = (int) $coords[0];
            $debrisSystem = (int) $coords[1];
            $debrisPlanet = (int) $coords[2];

            // Same galaxy only
            if ($debrisGalaxy !== $botGalaxy) continue;

            $systemDist = abs($debrisSystem - $botSystem);
            if ($systemDist > self::MAX_HARVEST_DISTANCE) continue;

            $key = "{$debrisGalaxy}:{$debrisSystem}:{$debrisPlanet}";
            $fields[$key] ??= [
                'galaxy'   => $debrisGalaxy,
                'system'   => $debrisSystem,
                'planet'   => $debrisPlanet,
                'distance' => $systemDist + abs($debrisPlanet - $botPlanet),
                'ids'      => [],
            ];
            $fields[$key]['ids'][] = (int) $row->id;
        }

        $bestTarget = null;
        $bestScore = 0;

        foreach ($fields as $field) {
            [$metal, $crystal] = $this->liveDebris($field['galaxy'], $field['system'], $field['planet']);
            $value = $metal + $crystal;

            if ($value < self::MIN_DEBRIS_VALUE) {
                // Somebody else got there first — stop re-checking these rows
                $this->markRowsHarvested($field['ids'], 0);
                continue;
            }

            // Score: value minus distance penalty
            $score = $value - ($field['distance'] * 1000);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTarget = [
                    'combat_id'      => $field['ids'][0],
                    'galaxy'         => $field['galaxy'],
                    'system'         => $field['system'],
                    'planet'         => $field['planet'],
                    'debris_metal'   => $metal,
                    'debris_crystal' => $crystal,
                    'distance'       => $field['distance'],
                ];
            }
        }

        return $bestTarget;
    }

    /**
     * Current debris field at a planet position (the amount a recycle mission would collect).
     *
     * @return array{0: int, 1: int}  [metal, crystal]
     */
    public function liveDebris(int $galaxy, int $system, int $planet): array
    {
        $key = "{$galaxy}:{$system}:{$planet}";

        if (!array_key_exists($key, $this->liveCache)) {
            $row = DB::table('planets')
                ->where('planet_galaxy', $galaxy)
                ->where('planet_system', $system)
                ->where('planet_planet', $planet)
                ->where('planet_type', 1)
                ->first(['planet_debris_metal', 'planet_debris_crystal']);

            $this->liveCache[$key] = $row
                ? [(int) $row->planet_debris_metal, (int) $row->planet_debris_crystal]
                : [0, 0];
        }

        return $this->liveCache[$key];
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
     * Mark a single logged battle's debris as harvested.
     */
    public function markHarvested(int $combatId, int $botId): void
    {
        $this->markRowsHarvested([$combatId], $botId);
    }

    /**
     * Mark every unharvested logged battle at these coordinates as harvested — the field is one
     * pile, so one trip that covers it clears them all.
     */
    public function markHarvestedAt(int $galaxy, int $system, int $planet, int $botId): void
    {
        DB::table('bot_combat_log')
            ->where('target_coords', "{$galaxy}:{$system}:{$planet}")
            ->whereNull('harvested_by')
            ->update([
                'harvested_by' => $botId,
                'harvested_at' => DB::raw('NOW()'),
            ]);
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function markRowsHarvested(array $ids, int $botId): void
    {
        $ids = array_filter($ids, fn ($id) => $id > 0);
        if (empty($ids)) {
            return;
        }

        DB::table('bot_combat_log')
            ->whereIn('id', $ids)
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

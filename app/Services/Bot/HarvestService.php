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

    /** Recycler capacity already on its way to each field this tick (recycle fleets not yet turned round): "g:s:p" => units. */
    private ?array $inFlight = null;

    /**
     * Recycler capacity other fleets are already bringing to this field: every recycle mission (8) that has not
     * collected yet (fleet_mess 0: flying there, or landed and waiting for the fleet processor), plus the trips
     * sent earlier this tick (claim()). On 2 Nov 2026 741 recycle fleets were flying to 190 fields, 429 of them to
     * a field already empty, and 52 % of all recycle trips since 22 Oct came home with nothing: every finder sized
     * its trip from the live field and nobody looked at who was already on the way.
     */
    public function inFlightCapacity(int $galaxy, int $system, int $planet): int
    {
        if ($this->inFlight === null) {
            $this->inFlight = [];
            $rows = DB::table('fleets')
                ->where('fleet_mission', 8)
                ->where('fleet_mess', 0)
                ->where('fleet_end_type', 1)
                ->get(['fleet_end_galaxy', 'fleet_end_system', 'fleet_end_planet', 'fleet_array']);
            foreach ($rows as $f) {
                $ships = @unserialize((string) $f->fleet_array, ['allowed_classes' => false]);
                $n = is_array($ships) ? (int) ($ships[209] ?? 0) : 0;
                $key = "{$f->fleet_end_galaxy}:{$f->fleet_end_system}:{$f->fleet_end_planet}";
                $this->inFlight[$key] = ($this->inFlight[$key] ?? 0) + $n * self::RECYCLER_CAPACITY;
            }
        }

        return $this->inFlight["{$galaxy}:{$system}:{$planet}"] ?? 0;
    }

    /**
     * The part of a field nobody is already flying to collect, or null when what is left is not worth a trip.
     *
     * @param  array{galaxy: int, system: int, planet: int, debris_metal: int, debris_crystal: int}|null  $target
     */
    public function lessInFlight(?array $target): ?array
    {
        if ($target === null) {
            return null;
        }
        $claimed = $this->inFlightCapacity((int) $target['galaxy'], (int) $target['system'], (int) $target['planet']);
        if ($claimed <= 0) {
            return $target;
        }
        $total = (int) $target['debris_metal'] + (int) $target['debris_crystal'];
        $left = $total - $claimed;
        if ($left < BotSpeed::amount(self::MIN_DEBRIS_VALUE)) {
            return null;
        }
        $share = $left / max(1, $total);
        $target['debris_metal'] = (int) floor((int) $target['debris_metal'] * $share);
        $target['debris_crystal'] = (int) floor((int) $target['debris_crystal'] * $share);

        return $target;
    }

    /** A trip was just sent: later bots in this tick see that much of the field as spoken for. */
    public function claim(array $target, int $recyclers): void
    {
        $this->inFlightCapacity((int) $target['galaxy'], (int) $target['system'], (int) $target['planet']);
        $key = "{$target['galaxy']}:{$target['system']}:{$target['planet']}";
        $this->inFlight[$key] = ($this->inFlight[$key] ?? 0) + $recyclers * self::RECYCLER_CAPACITY;
    }

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

        if ($metal + $crystal < BotSpeed::amount(self::MIN_DEBRIS_VALUE)) {
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
                ? [BotSpeed::amount(self::MIN_DEBRIS_VALUE), $botId, $botId, $allyId]
                : [BotSpeed::amount(self::MIN_DEBRIS_VALUE), $botId, $botId]
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

            if ($value < BotSpeed::amount(self::MIN_DEBRIS_VALUE)) {
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

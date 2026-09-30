<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\DB;
use Xgp\App\Libraries\FleetsLib;

/**
 * Bot colonisation: how many colonies a bot may still found, and where.
 *
 * Game rules (legacy Colonize.php, checked 30 Sep 2026):
 *  - colonies allowed = ceil(astrophysics / 2), counting planet_type 1 that are not destroyed,
 *    home excluded; the colony ship (208) is consumed;
 *  - slots 4-12 are always allowed; 3/13 need astro 4, 2/14 astro 6, 1/15 astro 8
 *    (Colonize::positionAllowed) — a colony ship sent to a slot the bot may not have bounces home;
 *  - planet size by slot (Formulas::getPlanetSize): 8 > 6 > 9 > 7 > 5 > 10 > 4 > 11 > 12 > 3/13 > 2/14 > 1/15.
 *
 * Until 30 Sep no bot ever colonised: no colony ship was built, moons counted as planets, slot
 * 15 (astro 8) was offered first and bounced for ever, and each search ran up to 700 queries.
 */
class ColonizationService
{
    /** Astrophysics 13 = 7 colonies + home = 8 planets. */
    public const MAX_COLONIES = 7;

    /** Slots by planet size, then the edge slots as astrophysics unlocks them. */
    private const SLOT_ORDER = [8, 6, 9, 7, 5, 10, 4, 11, 12];
    private const EDGE_SLOTS = [4 => [3, 13], 6 => [2, 14], 8 => [1, 15]];

    /** Systems searched either side of the launching planet. */
    private const SEARCH_RADIUS = 40;

    /**
     * Colonies this bot may still found now (allowed minus owned minus colony ships on their way).
     */
    public function colonySlotsFree(int $botId, int $astrophysics): int
    {
        $allowed = min(self::MAX_COLONIES, FleetsLib::getMaxColonies($astrophysics));

        $planets = (int) DB::table('planets')
            ->where('planet_user_id', $botId)
            ->where('planet_type', 1)
            ->where('planet_destroyed', 0)
            ->count();

        $flying = (int) DB::table('fleets')
            ->where('fleet_owner', $botId)
            ->where('fleet_mission', 7)
            ->where('fleet_mess', 0)
            ->count();

        return max(0, $allowed - ($planets - 1) - $flying);
    }

    /**
     * Kept for older callers: may this bot found another colony now?
     *
     * @param  array<string, mixed>  $bot  user row (needs id + research_astrophysics)
     */
    public function shouldColonize(array $bot): bool
    {
        return $this->colonySlotsFree((int) $bot['id'], (int) ($bot['research_astrophysics'] ?? 0)) > 0;
    }

    /**
     * Best free slot for a colony: nearest systems first, biggest slots first, only slots the
     * bot's astrophysics allows, never a slot another colony ship is already heading to.
     *
     * @param  array<string, mixed>  $planet  launching planet
     * @return array{galaxy: int, system: int, planet: int}|null
     */
    public function findColonizationTarget(array $planet, int $botId, int $astrophysics = 1): ?array
    {
        $galaxy = (int) $planet['planet_galaxy'];
        $home = (int) $planet['planet_system'];
        $slots = $this->allowedSlots($astrophysics);

        $from = max(1, $home - self::SEARCH_RADIUS);
        $to = min(MAX_SYSTEM_IN_GALAXY, $home + self::SEARCH_RADIUS);
        $taken = $this->takenSlots($galaxy, $from, $to);

        for ($offset = 0; $offset <= self::SEARCH_RADIUS; $offset++) {
            foreach ($offset === 0 ? [0] : [-$offset, $offset] as $delta) {
                $system = $home + $delta;
                if ($system < 1 || $system > MAX_SYSTEM_IN_GALAXY) {
                    continue;
                }

                foreach ($slots as $slot) {
                    if (!isset($taken["{$system}:{$slot}"])) {
                        return ['galaxy' => $galaxy, 'system' => $system, 'planet' => $slot];
                    }
                }
            }
        }

        // Nothing near home: a few random systems in the other galaxies
        for ($g = 1; $g <= MAX_GALAXY_IN_WORLD; $g++) {
            if ($g === $galaxy) {
                continue;
            }

            $system = random_int(1, MAX_SYSTEM_IN_GALAXY);
            $taken = $this->takenSlots($g, $system, $system);
            foreach ($slots as $slot) {
                if (!isset($taken["{$system}:{$slot}"])) {
                    return ['galaxy' => $g, 'system' => $system, 'planet' => $slot];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $planet
     */
    public function hasColonyShip(array $planet): bool
    {
        return (int) ($planet['ship_colony_ship'] ?? 0) > 0;
    }

    /** @return list<int> */
    private function allowedSlots(int $astrophysics): array
    {
        $slots = self::SLOT_ORDER;
        foreach (self::EDGE_SLOTS as $astro => $edge) {
            if ($astrophysics >= $astro) {
                $slots = array_merge($slots, $edge);
            }
        }

        return $slots;
    }

    /**
     * "system:slot" => true for every planet in the range and every slot a colony ship is flying to.
     *
     * @return array<string, true>
     */
    private function takenSlots(int $galaxy, int $from, int $to): array
    {
        $taken = [];

        $rows = DB::table('planets')
            ->where('planet_galaxy', $galaxy)
            ->whereBetween('planet_system', [$from, $to])
            ->where('planet_type', 1)
            ->get(['planet_system', 'planet_planet']);
        foreach ($rows as $row) {
            $taken["{$row->planet_system}:{$row->planet_planet}"] = true;
        }

        $flying = DB::table('fleets')
            ->where('fleet_mission', 7)
            ->where('fleet_mess', 0)
            ->where('fleet_end_galaxy', $galaxy)
            ->whereBetween('fleet_end_system', [$from, $to])
            ->get(['fleet_end_system', 'fleet_end_planet']);
        foreach ($flying as $row) {
            $taken["{$row->fleet_end_system}:{$row->fleet_end_planet}"] = true;
        }

        return $taken;
    }
}

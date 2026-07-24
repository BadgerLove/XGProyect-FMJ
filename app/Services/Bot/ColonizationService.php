<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\DB;

/**
 * Colonization Service — bots colonize new planets to expand their empire.
 *
 * Strategy:
 * - First colony: middle slots (7-9) for biggest planet size
 * - Second colony: outer slots (13-15) for deuterium bonus
 * - Bots aim for 3 total planets (1 home + 2 colonies)
 *
 * Planet slot sizes (standard OGame):
 *   Slots 1-3:   Small (~80-100 fields)
 *   Slots 4-6:   Medium (~100-130 fields)
 *   Slots 7-9:   Large (~130-170 fields) ← best for first colony
 *   Slots 10-12: Medium (~100-130 fields)
 *   Slots 13-15: Small (~80-100 fields) ← best for deuterium
 *
 * Deuterium bonus: outer slots (13-15) produce more deuterium.
 */
class ColonizationService
{
    /** Maximum planets per bot */
    private const MAX_PLANETS = 3;

    /** Preferred slots for first colony (biggest planets) */
    private const FIRST_COLONY_SLOTS = [7, 8, 9, 6, 10, 5, 11];

    /** Preferred slots for second colony (deuterium focus) */
    private const SECOND_COLONY_SLOTS = [15, 14, 13, 12, 1, 2, 3];

    /**
     * Check if a bot should colonize.
     *
     * @param  array<string, mixed>  $bot  The bot user
     * @return bool
     */
    public function shouldColonize(array $bot): bool
    {
        $botId = (int) $bot['id'];
        $planetCount = DB::table('planets')->where('planet_user_id', $botId)->count();

        return $planetCount < self::MAX_PLANETS;
    }

    /**
     * Find the best empty slot for colonization.
     *
     * @param  array<string, mixed>  $planet  The bot's current planet
     * @param  int                   $botId   The bot's user ID
     * @return array{galaxy: int, system: int, planet: int}|null
     */
    public function findColonizationTarget(array $planet, int $botId): ?array
    {
        $currentGalaxy = (int) $planet['planet_galaxy'];
        $currentSystem = (int) $planet['planet_system'];
        $planetCount = DB::table('planets')->where('planet_user_id', $botId)->count();

        // Choose preferred slots based on how many colonies we have
        $preferredSlots = $planetCount === 1
            ? self::FIRST_COLONY_SLOTS   // First colony: big planet
            : self::SECOND_COLONY_SLOTS; // Second colony: deuterium

        // Search nearby systems first (±50 systems)
        for ($offset = 0; $offset <= 50; $offset++) {
            foreach ([-1, 1] as $direction) {
                $system = $currentSystem + ($offset * $direction);
                if ($system < 1 || $system > 499) continue;

                foreach ($preferredSlots as $slot) {
                    // Check if this slot is occupied
                    $occupied = DB::table('planets')
                        ->where('planet_galaxy', $currentGalaxy)
                        ->where('planet_system', $system)
                        ->where('planet_planet', $slot)
                        ->exists();

                    if (!$occupied) {
                        return [
                            'galaxy' => $currentGalaxy,
                            'system' => $system,
                            'planet' => $slot,
                        ];
                    }
                }
            }
        }

        // If nothing found nearby, try other galaxies
        for ($galaxy = 1; $galaxy <= 6; $galaxy++) {
            if ($galaxy === $currentGalaxy) continue;

            foreach ($preferredSlots as $slot) {
                // Pick a random system in this galaxy
                $system = rand(1, 499);

                $occupied = DB::table('planets')
                    ->where('planet_galaxy', $galaxy)
                    ->where('planet_system', $system)
                    ->where('planet_planet', $slot)
                    ->exists();

                if (!$occupied) {
                    return [
                        'galaxy' => $galaxy,
                        'system' => $system,
                        'planet' => $slot,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Check if a bot has a colony ship available.
     *
     * @param  array<string, mixed>  $planet  The bot's planet
     * @return bool
     */
    public function hasColonyShip(array $planet): bool
    {
        return (int) ($planet['ship_colony_ship'] ?? 0) > 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Models\Planets;
use App\Services\SettingsService;

class PlanetService
{
    public function __construct(private SettingsService $settingsService)
    {
    }

    /**
     * Where new players start (Dale, 2026-10-01): a busy spawn area, galaxy 1 systems 1-100, with the
     * bots packed in around them (universe:reset puts ~3 bots in every spawn system). Players go 5
     * systems apart first (5, 10, ... 100), then the gaps fill (3, 8 ... / 1, 6 ... / 4, 9 ... /
     * 2, 7 ...), so they are near each other but always have bots in between. A system that
     * already has a player is skipped while any spawn system has none. Slot = a random free one
     * of 4-12. Only when the spawn area is full does it carry on through galaxy 1 the old way.
     */
    public const SPAWN_GALAXY = 1;
    public const SPAWN_SYSTEMS = 100;
    public const SPAWN_STEP = 5;
    private const SPAWN_PASS_OFFSETS = [0, 2, 4, 1, 3]; // pass k starts at system STEP - offset
    private const SPAWN_SLOTS = [4, 5, 6, 7, 8, 9, 10, 11, 12];

    /** @return array{galaxy: int, system: int, planet: int} */
    public function calculateNewPlanetPosition(): array
    {
        $playersPerSystem = Planets::query()
            ->join('users', 'users.id', '=', 'planets.planet_user_id')
            ->whereNull('users.bot_profile')
            ->where('planets.planet_galaxy', self::SPAWN_GALAXY)
            ->where('planets.planet_system', '<=', self::SPAWN_SYSTEMS)
            ->groupBy('planets.planet_system')
            ->pluck(\Illuminate\Support\Facades\DB::raw('COUNT(*)'), 'planets.planet_system')
            ->all();

        // fewest players first; within the same count, the spread-out order above
        for ($players = 0; $players < count(self::SPAWN_SLOTS); $players++) {
            foreach ($this->spawnSystemsInOrder() as $system) {
                if (($playersPerSystem[$system] ?? 0) !== $players) {
                    continue;
                }

                $slot = $this->randomFreeSlot(self::SPAWN_GALAXY, $system);
                if ($slot !== null) {
                    return ['galaxy' => self::SPAWN_GALAXY, 'system' => $system, 'planet' => $slot];
                }
            }
        }

        // spawn area completely full: the old sequential search after it
        return $this->isPlanetFree(self::SPAWN_GALAXY, self::SPAWN_SYSTEMS + 1, 4);
    }

    /** @return list<int> 5, 10 ... 100, then 3, 8 ... 98, then 1, 6 ..., 4, 9 ..., 2, 7 ... */
    public function spawnSystemsInOrder(): array
    {
        $order = [];
        foreach (self::SPAWN_PASS_OFFSETS as $offset) {
            for ($system = self::SPAWN_STEP - $offset; $system <= self::SPAWN_SYSTEMS; $system += self::SPAWN_STEP) {
                $order[] = $system;
            }
        }

        return $order;
    }

    private function randomFreeSlot(int $galaxy, int $system): ?int
    {
        $taken = Planets::where(['planet_galaxy' => $galaxy, 'planet_system' => $system])->pluck('planet_planet')->all();
        $free = array_values(array_diff(self::SPAWN_SLOTS, $taken));

        return $free === [] ? null : $free[array_rand($free)];
    }

    /** @return array{galaxy: int, system: int, planet: int} */
    public function isPlanetFree(int $galaxy, int $system, int $position): array
    {
        // Check if the planet is free
        $isFree = Planets::where(['planet_galaxy' => $galaxy, 'planet_system' => $system, 'planet_planet' => $position])->first();

        if ($isFree === null) {
            $this->settingsService->write('lastsettedgalaxypos', $galaxy);
            $this->settingsService->write('lastsettedsystempos', $system);
            $this->settingsService->write('lastsettedplanetpos', $position);

            return [
                'galaxy' => $galaxy,
                'system' => $system,
                'planet' => $position,
            ];
        }

        // If the planet is not free, try the next position
        if ($position < 12) {
            return $this->isPlanetFree($galaxy, $system, $position + PLANET_SEPARATION_FACTOR);
        }

        // If we've tried all positions in this system, try the next system
        if ($system < MAX_SYSTEM_IN_GALAXY) {
            return $this->isPlanetFree($galaxy, $system + SYSTEM_SEPARATION_FACTOR, 4);
        }

        // If we've tried all systems in this galaxy, try the next galaxy
        if ($galaxy < MAX_GALAXY_IN_WORLD) {
            return $this->isPlanetFree($galaxy + GALAXY_SEPARATION_FACTOR, 1, 4);
        }

        // If we've tried all galaxies and haven't found a free planet, restart the search
        return $this->isPlanetFree(1, 1, 4);
    }
}

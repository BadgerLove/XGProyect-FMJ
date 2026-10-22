<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind;

/**
 * What one bot knows about itself this tick, built by SituationReader from things its player could see:
 * its own planets, stock, income, queues and fleets, its own battle reports, the debris fields in the
 * galaxy view around its planets and the public highscore. Nothing about another player's planets.
 */
final class Situation
{
    public const RES = ['metal', 'crystal', 'deuterium'];

    public int $botId = 0;

    /** @var array<string, mixed> user + research row */
    public array $user = [];

    /** @var array<int, array<string, mixed>> planet_id => planet row (planets only, no moons) */
    public array $planets = [];

    public int $mainId = 0;

    /** @var array<string, float> per real hour, all planets */
    public array $income = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];

    /** @var array<string, float> all planets */
    public array $stock = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];

    /** @var array<string, float> what one unit of each resource is worth to this bot now, in metal */
    public array $worth = ['metal' => 1.0, 'crystal' => 1.5, 'deuterium' => 3.0];

    /** @var array<int, int> ship id => owned (home + hangar queues + flying) */
    public array $owned = [];

    public float $totalPoints = 0.0;

    /** Warships + defences at their cost in metal-worth. */
    public float $militaryWorth = 0.0;

    public float $militaryRatio = 0.0;

    /** Own battle reports as defender inside the window. */
    public int $hits = 0;

    /** What those battles cost us (loot taken + ships lost), metal-worth. */
    public float $lostWorth = 0.0;

    /** 0 (left alone) .. 1 (hit every few hours). */
    public float $threat = 0.0;

    /** Own raids/attacks inside the window. */
    public int $raidsSent = 0;

    public int $raidWins = 0;

    public float $lootWorth = 0.0;

    /** @var list<array{galaxy: int, system: int, planet: int, metal: int, crystal: int, own: bool, distance: int}> */
    public array $debris = [];

    public float $debrisWorth = 0.0;

    /** Debris landing on our own planets per real hour (from our battle reports), metal-worth. */
    public float $debrisRate = 0.0;

    public int $colonies = 0;

    public int $colonySlots = 0;

    public int $colonyFlying = 0;

    public int $fleetSlots = 1;

    public int $fleetsOut = 0;

    /**
     * The income engine (Dale, 21 Oct: every bot must master its most profitable way to make resources):
     * what each way of making money actually brought in, per real hour over the window, in metal-worth.
     * Mining is the planets' own income; the rest comes from the bot's own reports and returning fleets.
     */
    public float $raidIncome = 0.0;

    public float $attackIncome = 0.0;

    public float $expeditionIncome = 0.0;

    public float $harvestIncome = 0.0;

    /** Debris our own attacks leave at their targets, per real hour (ours to collect). */
    public float $attackDebrisRate = 0.0;

    /** @var array<int, bool> planet_id => an attack is coming and the fleet can't leave: spend everything now */
    public array $panic = [];

    /** What raids on us cost per real hour (loot taken + ships lost). */
    public float $lossRate = 0.0;

    /** Goal-tree stage (0 start .. 5 end) and the milestone names still open in it. */
    public int $stage = 0;

    /** @var list<string> */
    public array $openMilestones = [];

    /** @var array<string, mixed> the bot's notebook (Memory) */
    public array $memory = [];

    /** @var list<array<string, mixed>> incoming attacks on any of our planets (fleets table, as the overview shows them) */
    public array $incoming = [];

    /** @var array{mode: string, bans: array<string, int>, boost: array<string, float>} the doctor's orders this tick */
    public array $rx = ['mode' => '', 'bans' => [], 'boost' => []];

    /** The merchant was used this tick. */
    public bool $merchantUsed = false;

    /** Real seconds of the history window (48 x1 hours). */
    public float $windowSeconds = 0.0;

    /** Real hours per x1 hour (1 live, 1/70 in the x70 simulator). */
    public float $h1 = 1.0;

    /** Worth of income per real hour across all planets. */
    public function incomeWorth(): float
    {
        $sum = 0.0;
        foreach (self::RES as $r) {
            $sum += $this->income[$r] * $this->worth[$r];
        }

        return $sum;
    }

    /** @param array<string, float> $cost */
    public function worthOf(array $cost): float
    {
        $sum = 0.0;
        foreach (self::RES as $r) {
            $sum += (float) ($cost[$r] ?? 0) * $this->worth[$r];
        }

        return $sum;
    }

    public function mainPlanet(): array
    {
        return $this->planets[$this->mainId] ?? (reset($this->planets) ?: []);
    }
}

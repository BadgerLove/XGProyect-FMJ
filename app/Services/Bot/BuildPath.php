<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Core\GameObjects\GameObjectRegistry;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\Game\Formulas\ProductionService;

/**
 * Build paths (Dale, 6 Oct 2026): every bot follows one of six ordered goal lists, so the bots play
 * differently and none of them sits idle. The paths and the planner were raced in a simulator built on
 * the game's own formulas first (ogame-vault/bot-ai/Bot Build Paths Sim 2026-10-06.md); the old brain
 * saved for Robot Factory 2 / Shipyard 4 with full metal and crystal stores and left 600 of 1,000 bots
 * on deuterium mine 1.
 *
 * Goal forms (in order; each lane takes the first goal of its kind that is not done):
 *   ['b', id, level]        building on the main planet
 *   ['r', id, level]        research
 *   ['s', id, count]        ships owned (home + queued + flying)
 *   ['mines', m, c, d]      raise the three mines towards these levels, cheapest first
 *   ['grow']                keep raising mines (never done)
 *   ['ships', id, share]    keep building this ship from $share of what is left after every other goal
 *   ['colonies', n]         n colonies: colony ships while Astrophysics allows, else the next Astrophysics
 *
 * Missing prerequisites turn into the item that unlocks them, in the right lane. A lane only spends what
 * earlier goals have not reserved. While the building lane has nothing it can pay for, a store that is
 * 95 % full pays for a mine or that store (the old brain wasted whole days of production this way), and
 * a mine that would push energy below 100 % gets a Solar Plant first.
 */
final class BuildPath
{
    private const METAL = 1;
    private const CRYSTAL = 2;
    private const DEUT = 3;
    private const SOLAR = 4;
    private const ROBOT = 14;
    private const YARD = 21;
    private const MSTORE = 22;
    private const CSTORE = 23;
    private const DTANK = 24;
    private const LAB = 31;
    private const TERRAFORMER = 33;
    private const ESPIONAGE = 106;
    private const ENERGY = 113;
    private const COMBUSTION = 115;
    private const IMPULSE = 117;
    private const ASTRO = 124;

    public const SMALL_CARGO = 202;
    public const LIGHT_FIGHTER = 204;
    public const COLONY_SHIP = 208;
    public const PROBE = 210;

    /** Ships the goals count (home + hangar queue + flying). */
    public const COUNTED_SHIPS = [self::SMALL_CARGO, self::LIGHT_FIGHTER, self::COLONY_SHIP, self::PROBE];

    /** Share of bots per path, in percent (Dale's mix, 6 Oct 2026). */
    public const MIX = ['warlord' => 20, 'raider' => 25, 'pirate' => 15, 'conqueror' => 15, 'miner' => 20, 'emperor' => 5];

    /** Paths that raid with small cargos. */
    public const RAIDS = ['warlord' => true, 'raider' => true, 'pirate' => true, 'conqueror' => true, 'emperor' => true, 'miner' => false];

    /** Paths that send their cargos on expeditions. */
    public const EXPEDITIONS = ['warlord' => true, 'pirate' => true, 'emperor' => true, 'raider' => false, 'conqueror' => false, 'miner' => false];

    private const STORE_FOR = ['metal' => self::MSTORE, 'crystal' => self::CSTORE, 'deuterium' => self::DTANK];

    public string $lastReason = '';

    /** @var array<int, string> id => planet/user column */
    private array $columns = [];

    public function __construct(
        private readonly GameObjectRegistry $registry,
        private readonly DevelopmentsService $developments,
        private readonly ProductionService $production,
    ) {
        foreach ($this->registry->all() as $id => $object) {
            $this->columns[(int) $id] = $object->getName();
        }
    }

    /** The path a bot follows: bot_profile 'path' if set, else a fixed spread over MIX by bot id. */
    public static function forBot(int $botId, array $profile = []): string
    {
        $set = (string) ($profile['path'] ?? '');
        if (isset(self::MIX[$set])) {
            return $set;
        }

        $roll = crc32('build-path:' . $botId) % 100;
        foreach (self::MIX as $path => $share) {
            if ($roll < $share) {
                return $path;
            }
            $roll -= $share;
        }

        return 'raider';
    }

    /** @return array<int, array<int, int|float|string>> */
    public function goals(string $path): array
    {
        $cargoOpener = [
            ['mines', 3, 1, 2],
            ['b', self::ROBOT, 2], ['b', self::YARD, 2], ['b', self::LAB, 1],
            ['r', self::ENERGY, 1], ['r', self::COMBUSTION, 2],
        ];
        $astroRoad = [['b', self::LAB, 3], ['r', self::IMPULSE, 3], ['r', self::ESPIONAGE, 4], ['r', self::ASTRO, 1]];
        // Probes for the spy/attack phase (two missions of 3), light fighters so a raid can beat a planet
        // with ships at home: on 9 Oct the live universe had 0 warships and no human could be attacked
        $spies = ['s', self::PROBE, 6];

        return match ($path) {
            'miner' => [
                ['mines', 4, 2, 1], ['mines', 8, 6, 4], ['b', self::ROBOT, 2], ['mines', 12, 9, 6],
                ['b', self::LAB, 1], ['r', self::ENERGY, 1], ['b', self::ROBOT, 4], ['grow'],
            ],
            'raider' => array_merge($cargoOpener, [
                ['s', self::SMALL_CARGO, 3], ['mines', 6, 4, 4], ['s', self::SMALL_CARGO, 8], $spies, ['s', self::LIGHT_FIGHTER, 10],
                ['mines', 10, 7, 6], ['s', self::LIGHT_FIGHTER, 25], ['ships', self::SMALL_CARGO, 0.3], ['grow'],
            ]),
            'pirate' => array_merge($cargoOpener, [['s', self::SMALL_CARGO, 4], ['mines', 6, 4, 4], ['s', self::SMALL_CARGO, 8], $spies], $astroRoad, [
                ['s', self::LIGHT_FIGHTER, 10], ['r', self::ASTRO, 3], ['ships', self::SMALL_CARGO, 0.4], ['grow'],
            ]),
            'conqueror' => array_merge($cargoOpener, [['s', self::SMALL_CARGO, 4], ['mines', 6, 4, 4], ['s', self::SMALL_CARGO, 8], $spies], $astroRoad, [
                ['b', self::YARD, 4], ['colonies', 1], ['r', self::ASTRO, 3], ['colonies', 2], ['r', self::ASTRO, 5], ['colonies', 3],
                ['ships', self::SMALL_CARGO, 0.2], ['grow'],
            ]),
            'emperor' => array_merge($cargoOpener, [['s', self::SMALL_CARGO, 4], ['mines', 6, 4, 4], ['s', self::SMALL_CARGO, 8], $spies], $astroRoad, [
                ['b', self::YARD, 4], ['colonies', 1], ['s', self::LIGHT_FIGHTER, 10], ['r', self::ASTRO, 3], ['colonies', 2], ['ships', self::SMALL_CARGO, 0.3],
                ['r', self::ASTRO, 5], ['colonies', 3], ['grow'],
            ]),
            // warlord: mines first, then everything (the simulator's best path)
            default => array_merge([['mines', 8, 6, 5]], $cargoOpener, [['s', self::SMALL_CARGO, 4], ['mines', 10, 8, 6], ['s', self::SMALL_CARGO, 8], $spies], $astroRoad, [
                ['b', self::YARD, 4], ['colonies', 1], ['s', self::LIGHT_FIGHTER, 10], ['r', self::ASTRO, 3], ['colonies', 2], ['ships', self::SMALL_CARGO, 0.3], ['grow'],
            ]),
        };
    }

    /**
     * What the main planet should start now.
     *
     * @param  array<string, mixed>  $planet  planet + buildings + ships row (fresh)
     * @param  array<string, mixed>  $user    user + research row
     * @param  array{build_idle: bool, lab_idle: bool, yard_idle: bool, ships_total: array<int, int>, colonies: int, colony_flying: int, colony_slots: int}  $ctx
     * research_claimed: a path goal holds the research lane (else the lab is free for other research);
     * reserve: what the waiting goals keep back (mine growth left out: an idle lab outranks one more mine level).
     *
     * @return array{building: int|null, research: int|null, ships: array<int, int>, research_claimed: bool, reserve: array<string, float>}
     */
    public function plan(string $path, array $planet, array $user, array $ctx): array
    {
        $this->lastReason = '';
        $out = ['building' => null, 'research' => null, 'ships' => [], 'research_claimed' => false, 'reserve' => []];
        $reserve = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];
        $firm = $reserve; // without mine-growth goals: what other research may not touch
        $lanes = ['b' => $ctx['build_idle'], 'r' => $ctx['lab_idle'], 's' => $ctx['yard_idle']];
        $have = $this->stock($planet);

        $goals = $this->goals($path);
        // share goals spend last, from what is left once every other goal is reserved
        $ordered = array_merge(
            array_values(array_filter($goals, fn ($g) => $g[0] !== 'ships')),
            array_values(array_filter($goals, fn ($g) => $g[0] === 'ships'))
        );

        foreach ($ordered as $goal) {
            if ($this->done($goal, $planet, $user, $ctx)) {
                continue;
            }
            $item = $this->nextItem($goal, $planet, $user, $ctx);
            if ($item === null) {
                continue;
            }
            [$lane, $id, $level] = $item;
            $cost = $lane === 's' ? $this->unitPrice($id) : $this->price($id, $level - 1);

            if ($lane === 'b' && $lanes['b']) {
                if ($this->fieldsFree($planet) && $this->canPay($have, $cost, $reserve)) {
                    $out['building'] = $id;
                    $this->lastReason = $this->describe($goal);
                    $have = $this->minus($have, $cost);
                } elseif ($this->fieldsFree($planet) && ($mine = $this->bottleneckMine($cost, $reserve, $have, $planet, $user)) !== null) {
                    // Waiting hours for one resource: build its mine first (the simulator: +60-170 % by day 4
                    // for the cargo paths on hot planets, whose openers need ~3,300 deuterium)
                    $out['building'] = $mine;
                    $this->lastReason = $this->describe($goal) . ' -> its bottleneck mine first';
                    $have = $this->minus($have, $this->price($mine, $this->level($mine, $planet, $user)));
                }
                $lanes['b'] = false;
            } elseif ($lane === 'r' && $lanes['r']) {
                if ($this->canPay($have, $cost, $reserve)) {
                    $out['research'] = $id;
                    $have = $this->minus($have, $cost);
                }
                $lanes['r'] = false;
                $out['research_claimed'] = true;
            } elseif ($lane === 's' && $lanes['s']) {
                $isShare = $goal[0] === 'ships';
                $want = match (true) {
                    $id === self::COLONY_SHIP => 1,
                    $goal[0] === 's' => (int) $goal[2] - (int) ($ctx['ships_total'][$id] ?? 0),
                    default => PHP_INT_MAX,
                };
                $n = $want;
                foreach ($cost as $res => $amount) {
                    if ($amount > 0) {
                        $avail = max(0.0, $have[$res] - $reserve[$res]);
                        $n = min($n, (int) floor(($isShare ? $avail * (float) $goal[2] : $avail) / $amount));
                    }
                }
                if ($n > 0) {
                    $out['ships'][$id] = $n;
                    $have = $this->minus($have, $cost, $n);
                }
                $lanes['s'] = false;
            } elseif ($goal[0] !== 'grow') {
                continue;
            }

            if ($goal[0] !== 'ships') {
                foreach ($cost as $res => $amount) {
                    $reserve[$res] += $amount;
                    if ($goal[0] !== 'mines' && $goal[0] !== 'grow') {
                        $firm[$res] += $amount;
                    }
                }
            }
        }

        $out['reserve'] = $firm;

        // Never waste a full store: while the building lane found nothing it could pay for, the overflow
        // pays for a mine (deuterium first: it is what every early goal waits on) or for that store.
        if ($ctx['build_idle'] && $out['building'] === null) {
            $out['building'] = $this->spendOverflow($planet, $user, $have);
            if ($out['building'] !== null) {
                $this->lastReason = 'store full';
            }
        }

        return $out;
    }

    /** Colonies (and any planet that is not the main one): grow the mines, never idle on a full store. */
    public function growColony(array $planet, array $user): ?int
    {
        $item = $this->nextItem(['grow'], $planet, $user, []);
        $have = $this->stock($planet);
        if ($item !== null && $item[0] === 'b' && $this->fieldsFree($planet) && $this->canPay($have, $this->price($item[1], $item[2] - 1))) {
            $this->lastReason = 'colony mines';
            return $item[1];
        }

        $overflow = $this->spendOverflow($planet, $user, $have);
        if ($overflow !== null) {
            $this->lastReason = 'colony store full';
        }

        return $overflow;
    }

    /**
     * @param  array<int, int|float|string>  $goal
     * @param  array<string, mixed>  $ctx
     */
    private function done(array $goal, array $planet, array $user, array $ctx): bool
    {
        return match ($goal[0]) {
            'b' => $this->level((int) $goal[1], $planet, $user) >= (int) $goal[2],
            'r' => $this->level((int) $goal[1], $planet, $user) >= (int) $goal[2],
            's' => (int) ($ctx['ships_total'][(int) $goal[1]] ?? 0) >= (int) $goal[2],
            'mines' => $this->level(self::METAL, $planet, $user) >= (int) $goal[1]
                && $this->level(self::CRYSTAL, $planet, $user) >= (int) $goal[2]
                && $this->level(self::DEUT, $planet, $user) >= (int) $goal[3],
            'colonies' => (int) $ctx['colonies'] + (int) $ctx['colony_flying'] >= min((int) $goal[1], ColonizationService::MAX_COLONIES),
            default => false,
        };
    }

    /**
     * The concrete next item for a goal: [lane, id, level-to-reach], prerequisites resolved.
     *
     * @param  array<int, int|float|string>  $goal
     * @param  array<string, mixed>  $ctx
     * @return array{0: string, 1: int, 2: int}|null
     */
    private function nextItem(array $goal, array $planet, array $user, array $ctx): ?array
    {
        $want = match ($goal[0]) {
            'b', 'r' => [(int) $goal[1], $this->level((int) $goal[1], $planet, $user) + 1],
            's', 'ships' => [(int) $goal[1], 0],
            'mines', 'grow' => $this->pickMine($goal, $planet, $user),
            'colonies' => (int) $ctx['colony_slots'] > (int) ($ctx['ships_total'][self::COLONY_SHIP] ?? 0)
                ? [self::COLONY_SHIP, 0]
                : ((int) ($ctx['ships_total'][self::COLONY_SHIP] ?? 0) > 0 ? null : [self::ASTRO, $this->level(self::ASTRO, $planet, $user) + 1]),
            default => null,
        };
        if ($want === null) {
            return null;
        }

        $missing = $this->missing($want[0], $planet, $user);
        if ($missing !== null) {
            $want = $missing;
        }

        // A mine that would take energy below 100 %: Solar Plant first
        if (in_array($want[0], [self::METAL, self::CRYSTAL, self::DEUT], true)) {
            $surplus = (float) ($planet['planet_energy_max'] ?? 0) + (float) ($planet['planet_energy_used'] ?? 0);
            if ($surplus < $this->mineEnergy($want[0], $want[1], $planet, $user) - $this->mineEnergy($want[0], $want[1] - 1, $planet, $user)) {
                $want = [self::SOLAR, $this->level(self::SOLAR, $planet, $user) + 1];
            }
        }

        $lane = $this->registry->research()->has($want[0]) ? 'r'
            : ($this->registry->ships()->has($want[0]) || $this->registry->defenses()->has($want[0]) ? 's' : 'b');

        return [$lane, $want[0], $want[1]];
    }

    /** Deepest missing prerequisite of $id as [id, level], or null when $id is allowed. */
    private function missing(int $id, array $planet, array $user): ?array
    {
        foreach ($this->registry->get($id)->getRequirements() as $reqId => $reqLevel) {
            $have = $this->level((int) $reqId, $planet, $user);
            if ($have < (int) $reqLevel) {
                return $this->missing((int) $reqId, $planet, $user) ?? [(int) $reqId, $have + 1];
            }
        }

        return null;
    }

    /**
     * Cheapest next mine level towards the goal ('grow': weighted towards metal 1 : crystal 0.75 : deut 0.55).
     *
     * @param  array<int, int|float|string>  $goal
     * @return array{0: int, 1: int}|null
     */
    private function pickMine(array $goal, array $planet, array $user): ?array
    {
        $targets = $goal[0] === 'mines'
            ? [self::METAL => (int) $goal[1], self::CRYSTAL => (int) $goal[2], self::DEUT => (int) $goal[3]]
            : [self::METAL => 40, self::CRYSTAL => 36, self::DEUT => 36];
        $ratio = [self::METAL => 1.0, self::CRYSTAL => 0.75, self::DEUT => 0.55];

        $best = null;
        $bestScore = PHP_FLOAT_MAX;
        foreach ($targets as $id => $target) {
            $level = $this->level($id, $planet, $user);
            if ($level >= $target) {
                continue;
            }
            $cost = $this->price($id, $level);
            $score = $cost['metal'] + 1.5 * $cost['crystal'] + 2 * $cost['deuterium'];
            if ($goal[0] === 'grow') {
                $score /= $ratio[$id];
            }
            if ($score < $bestScore) {
                [$best, $bestScore] = [[$id, $level + 1], $score];
            }
        }

        return $best;
    }

    /** Hours (at x1) a goal may wait for one resource before that resource's mine is built first. */
    private const BOTTLENECK_HOURS = 2.0;

    /**
     * The goal can't be paid: if one resource is more than BOTTLENECK_HOURS of income away, the next level
     * of its mine (or the Solar Plant that mine needs), when that can be paid now. Deuterium first.
     */
    private function bottleneckMine(array $cost, array $reserve, array $have, array $planet, array $user): ?int
    {
        $hours = self::BOTTLENECK_HOURS / BotSpeed::multiplier(); // the sim runs x90: an hour there is 90 x1 hours
        foreach (['deuterium' => self::DEUT, 'crystal' => self::CRYSTAL, 'metal' => self::METAL] as $res => $mine) {
            $short = $cost[$res] + $reserve[$res] - $have[$res];
            $perHour = max(1.0, (float) ($planet["planet_{$res}_perhour"] ?? 0));
            if ($short <= 0 || $short / $perHour <= $hours) {
                continue;
            }
            $item = $this->nextItem(['b', $mine, $this->level($mine, $planet, $user) + 1], $planet, $user, []);
            if ($item !== null && $item[0] === 'b' && $this->canPay($have, $this->price($item[1], $item[2] - 1))) {
                return $item[1];
            }
        }

        return null;
    }

    /** A building the overflow of a full store can pay for: deuterium / crystal / metal mine, else that store. */
    private function spendOverflow(array $planet, array $user, array $have): ?int
    {
        if (!$this->fieldsFree($planet)) {
            return null;
        }

        foreach (self::STORE_FOR as $res => $store) {
            $capacity = (float) $this->production->maxStorable($this->level($store, $planet, $user));
            if ($have[$res] < 0.95 * $capacity) {
                continue;
            }
            foreach ([self::DEUT, self::CRYSTAL, self::METAL, $store] as $id) {
                $item = $this->nextItem(['b', $id, $this->level($id, $planet, $user) + 1], $planet, $user, []);
                if ($item !== null && $item[0] === 'b' && $this->canPay($have, $this->price($item[1], $item[2] - 1))) {
                    return $item[1];
                }
            }
        }

        return null;
    }

    private function mineEnergy(int $id, int $level, array $planet, array $user): float
    {
        if ($level <= 0) {
            return 0.0;
        }
        $formula = $this->registry->get($id)->getProduction();

        return abs($formula->calculateEnergy($level, 10, (float) ($planet['planet_temp_max'] ?? 0), (int) ($user['research_energy_technology'] ?? 0)));
    }

    private function fieldsFree(array $planet): bool
    {
        $max = $this->developments->maxFields((int) ($planet['planet_field_max'] ?? 0), $this->level(self::TERRAFORMER, $planet, []));

        return (int) ($planet['planet_field_current'] ?? 0) < $max;
    }

    public function level(int $id, array $planet, array $user): int
    {
        $column = $this->columns[$id] ?? '';
        if ($this->registry->research()->has($id)) {
            return (int) ($user[$column] ?? 0);
        }

        return (int) ($planet[$column] ?? 0);
    }

    /** @return array{metal: float, crystal: float, deuterium: float} */
    public function price(int $id, int $level): array
    {
        $p = $this->developments->developmentPrice($id, $level);

        return ['metal' => (float) ($p['metal'] ?? 0), 'crystal' => (float) ($p['crystal'] ?? 0), 'deuterium' => (float) ($p['deuterium'] ?? 0)];
    }

    /** @return array{metal: float, crystal: float, deuterium: float} */
    public function unitPrice(int $id): array
    {
        $p = $this->registry->get($id)->getPrice();

        return ['metal' => (float) $p->getMetal(), 'crystal' => (float) $p->getCrystal(), 'deuterium' => (float) $p->getDeuterium()];
    }

    /** @return array{metal: float, crystal: float, deuterium: float} */
    private function stock(array $planet): array
    {
        return ['metal' => (float) ($planet['planet_metal'] ?? 0), 'crystal' => (float) ($planet['planet_crystal'] ?? 0), 'deuterium' => (float) ($planet['planet_deuterium'] ?? 0)];
    }

    private function canPay(array $have, array $cost, array $reserve = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0]): bool
    {
        foreach ($cost as $res => $amount) {
            if ($amount > 0 && $have[$res] - $reserve[$res] < $amount) {
                return false;
            }
        }

        return true;
    }

    private function minus(array $have, array $cost, int $n = 1): array
    {
        foreach ($cost as $res => $amount) {
            $have[$res] -= $amount * $n;
        }

        return $have;
    }

    /** @param array<int, int|float|string> $goal */
    private function describe(array $goal): string
    {
        return match ($goal[0]) {
            'b', 'r' => "goal {$this->columns[(int) $goal[1]]} {$goal[2]}",
            'mines' => "mines {$goal[1]}/{$goal[2]}/{$goal[3]}",
            'colonies' => "colonies {$goal[1]}",
            default => (string) $goal[0],
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind\Advisors;

use App\Services\Bot\Mind\Advisor;
use App\Services\Bot\Mind\Kit;
use App\Services\Bot\Mind\Memory;
use App\Services\Bot\Mind\Personality;
use App\Services\Bot\Mind\Situation;

/**
 * Keeping what we have. Two jobs:
 *  - propose defence, valued by what raids have been costing this bot (its own battle reports) plus a
 *    standing share for guarded characters;
 *  - react to incoming attacks (react()): notice them (a chance per tick: the vigilance trait, Dale 21 Oct
 *    "make it a percentage"), decide whether the fleet should leave, and where it can go. Until 21 Oct a
 *    bot could only fleet-save to its own moon or another of its planets - 89 % of live bots had neither,
 *    so they never saved. Now: moon, colony, an expedition, or a recycle run to a debris field.
 */
final class GuardAdvisor implements Advisor
{
    private const DEFENCES = [401, 402, 403, 404, 405, 406];

    /** Combat power per unit (FleetProtector's scale). */
    private const POWER = [202 => 5, 203 => 5, 204 => 50, 205 => 150, 206 => 400, 207 => 1000, 211 => 1000, 213 => 2000, 214 => 200000, 215 => 2800,
        401 => 80, 402 => 100, 403 => 250, 404 => 1100, 405 => 500, 406 => 3000, 407 => 2000, 408 => 10000];

    public function __construct(private readonly Kit $kit)
    {
    }

    public function name(): string
    {
        return 'guard';
    }

    public function propose(Situation $s, Personality $p, array $planet, array $ctx): array
    {
        $k = $this->kit;
        $income = $k->planetIncomeWorth($s, $planet);
        $have = 0.0;
        foreach (self::DEFENCES as $id) {
            $have += (int) ($planet[$k->col[$id]] ?? 0) * $s->worthOf($k->unitPrice($id));
        }
        // a few hours of this planet's income in guns, more for guarded characters and when hit often
        $target = $income * (2.0 + 10.0 * $p->guard) * (0.3 + 1.5 * $s->threat) * $s->h1;
        // an attack is coming and the stock can't leave: every unit of it in guns beats handing it over
        $panic = !empty($s->panic[(int) $planet['planet_id']]);
        if ($have >= $target && !$panic) {
            return [];
        }
        $share = 1.0 / max(1, count($s->planets));
        $value = 0.7 * $s->lossRate * $share + 0.03 * $p->guard * $income + 0.05 * $s->threat * $income;
        if ($panic) {
            $value += 0.5 * $s->worthOf(['metal' => (float) $planet['planet_metal'], 'crystal' => (float) $planet['planet_crystal'], 'deuterium' => (float) $planet['planet_deuterium']]) / max(0.001, $s->h1);
            $target = PHP_FLOAT_MAX;
        }

        $eff = [];
        foreach (self::DEFENCES as $id) {
            if ($k->allowed($id, $planet, $s->user)) {
                $eff[$id] = $k->efficiency($s, $id);
            }
        }
        if ($eff === []) {
            return [];
        }
        arsort($eff);
        // in a panic the next best guns too, so crystal and deuterium get spent as well as metal
        $out = [];
        foreach (array_slice(array_keys($eff), 0, $panic ? 3 : 1) as $i => $id) {
            $n = $panic ? 2000 : max(1, (int) ceil(($target - $have) / max(1.0, $s->worthOf($k->unitPrice($id))) * 0.5));
            if (($c = $k->resolveUnit($s, $planet, $id, $n)) !== null) {
                $out[] = Kit::proposal($c, 'guard', ($panic ? 'attack coming, spend it: ' : 'defence: ') . $k->name($id), $value * (1.0 - 0.2 * $i));
            }
        }

        return $out;
    }

    /** Chance (0.3 .. 0.92) that the bot notices an incoming fleet on a given tick. */
    public function vigilance(Personality $p): float
    {
        return max(0.3, min(0.92, 0.35 + 0.45 * $p->caution + 0.25 * $p->guard));
    }

    /**
     * An attack is coming at this planet: noticed this tick? worth leaving? where to?
     *
     * @param  list<array{fleet_id: int, strength: int, fleet_end_time: int}>  $attacks
     * @param  array{moon: array|null, colony: array|null, expedition: bool, debris: array|null}  $exits  what this planet could fly to
     * @return array{save: bool, how: string, why: string}
     */
    public function react(Situation $s, Personality $p, array $planet, array $attacks, array $exits): array
    {
        $noticed = $s->memory['noticed'] ?? [];
        $seen = false;
        foreach ($attacks as $a) {
            $id = (string) $a['fleet_id'];
            if (!($noticed[$id] ?? false)) {
                // a player checks the overview now and then: each tick is another chance to spot it
                $noticed[$id] = mt_rand() / mt_getrandmax() < $this->vigilance($p);
            }
            $seen = $seen || $noticed[$id];
        }
        // forget fleets that have landed
        $live = array_flip(array_map(fn ($a) => (string) $a['fleet_id'], $attacks));
        foreach ($noticed as $id => $v) {
            if (!isset($live[$id]) && count($noticed) > 50) {
                unset($noticed[$id]);
            }
        }
        $s->memory['noticed'] = $noticed;
        if (!$seen) {
            return ['save' => false, 'how' => '', 'why' => 'did not notice the attack in time'];
        }

        $threat = array_sum(array_map(fn ($a) => (int) $a['strength'], $attacks));
        $mine = 0;
        $fleetWorth = 0.0;
        foreach (self::POWER as $id => $power) {
            $n = (int) ($planet[$this->kit->col[$id]] ?? 0);
            $mine += $n * $power;
            if ($id < 400) {
                $fleetWorth += $n * $s->worthOf($this->kit->unitPrice($id));
            }
        }
        foreach ([209, 210, 208] as $id) {
            $fleetWorth += (int) ($planet[$this->kit->col[$id]] ?? 0) * $s->worthOf($this->kit->unitPrice($id));
        }
        $stock = $s->worthOf(['metal' => (float) $planet['planet_metal'], 'crystal' => (float) $planet['planet_crystal'], 'deuterium' => (float) $planet['planet_deuterium']]);
        // stand and fight if we are clearly stronger (cautious bots want a bigger margin)
        if ($mine > $threat * (1.2 + $p->caution)) {
            return ['save' => false, 'how' => '', 'why' => "we are stronger ({$mine} vs {$threat})"];
        }
        if ($fleetWorth < 1000 && $stock < 20000) {
            return ['save' => false, 'how' => '', 'why' => 'nothing worth moving'];
        }
        // last way out, as players do it: fly at somewhere far away and recall the fleet once the attack has landed
        foreach (['moon', 'colony', 'expedition', 'debris', 'recall'] as $how) {
            if (!empty($exits[$how])) {
                return ['save' => true, 'how' => $how, 'why' => "attack seen ({$threat} vs our {$mine}): fleet away via {$how}"];
            }
        }

        return ['save' => false, 'how' => 'spend', 'why' => 'attack seen but nowhere to fly: spend what we can'];
    }
}

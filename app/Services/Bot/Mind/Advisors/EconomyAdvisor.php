<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind\Advisors;

use App\Services\Bot\BotSpeed;
use App\Services\Bot\Mind\Advisor;
use App\Services\Bot\Mind\Kit;
use App\Services\Bot\Mind\Personality;
use App\Services\Bot\Mind\Situation;

/**
 * Mines, power, stores, factories: the planet's own income. Every value is the game's production formula
 * times what that resource is worth to this bot now, so a crystal-starved bot sees its crystal mine shine.
 */
final class EconomyAdvisor implements Advisor
{
    /** Best mine value of the last propose() call (the factories are measured against it). */
    public float $bestMine = 0.0;

    public function __construct(private readonly Kit $kit)
    {
    }

    public function name(): string
    {
        return 'economy';
    }

    public function propose(Situation $s, Personality $p, array $planet, array $ctx): array
    {
        $out = [];
        $k = $this->kit;
        $mult = BotSpeed::multiplier();
        $pct = $k->productionPercent($planet) / 100.0;
        $greed = 0.8 + 0.4 * min(1.0, $p->greed);
        $this->bestMine = 0.0;

        // mines (with the power plant first when the next level would run short of energy)
        foreach ([Kit::METAL => 'metal', Kit::CRYSTAL => 'crystal', Kit::DEUT => 'deuterium'] as $id => $res) {
            $level = $k->level($id, $planet, $s->user);
            $gain = ($k->prod($id, $level + 1, $planet) - $k->prod($id, $level, $planet)) * $mult * max(0.3, $pct);
            $value = $gain * $s->worth[$res] * $greed;
            if ($value <= 0) {
                continue;
            }
            $this->bestMine = max($this->bestMine, $value);
            $mine = $k->resolve($s, $planet, $id, $level + 1);
            if ($mine === null) {
                continue;
            }
            $need = $k->energy($id, $level + 1, $planet, $s->user) - $k->energy($id, $level, $planet, $s->user);
            $surplus = (float) ($planet['planet_energy_max'] ?? 0) + (float) ($planet['planet_energy_used'] ?? 0);
            if ($surplus < $need) {
                $power = $k->bestPower($s, $planet);
                if ($power === null) {
                    continue;
                }
                $mine['chain'] = $k->add($mine['chain'], $power['chain']);
                $mine = array_merge($mine, ['lane' => $power['lane'], 'id' => $power['id'], 'level' => $power['level'], 'cost' => $power['cost'], 'unit_cost' => $power['cost']]);
            }
            $out[] = Kit::proposal($mine, 'economy', "{$res} mine " . ($level + 1), $value);
        }

        // running below 100 %: the power plant that gives production back
        $now = $k->productionPercent($planet);
        if ($now < 100 && ($power = $k->bestPower($s, $planet)) !== null) {
            $after = $k->production->maxProductionPercentage((int) ((float) $planet['planet_energy_max'] + $power['energy']), (int) $planet['planet_energy_used']);
            $value = 0.0;
            foreach (Situation::RES as $r) {
                $full = (float) ($planet["planet_{$r}_perhour"] ?? 0) / max(0.01, $now / 100.0);
                $value += $full * ($after - $now) / 100.0 * $s->worth[$r];
            }
            if ($value > 0) {
                $out[] = Kit::proposal($power, 'economy', "power (running at {$now} %)", $value);
            }
        }

        // a store about to throw production away
        foreach (Kit::STORE as $res => $id) {
            $fill = (float) $planet["planet_{$res}"] / max(1.0, $k->storeCap($res, $planet));
            $perHour = (float) ($planet["planet_{$res}_perhour"] ?? 0);
            if ($fill >= 0.8 && $perHour > 0 && ($c = $k->resolve($s, $planet, $id, $k->level($id, $planet, $s->user) + 1)) !== null) {
                $out[] = Kit::proposal($c, 'economy', "{$res} store " . round($fill * 100) . ' % full', $perHour * $s->worth[$res] * ($fill - 0.6) * 2.0);
            }
        }

        // faster building: Robot Factory with the mines, Nanites later
        $maxMine = max($k->level(Kit::METAL, $planet, $s->user), $k->level(Kit::CRYSTAL, $planet, $s->user));
        $robot = $k->level(Kit::ROBOT, $planet, $s->user);
        if ($robot < max(2, min(10, intdiv($maxMine, 3))) && ($c = $k->resolve($s, $planet, Kit::ROBOT, $robot + 1)) !== null) {
            $out[] = Kit::proposal($c, 'economy', 'faster building', 0.35 * $this->bestMine);
        }
        if ($robot >= 10 && ($c = $k->resolve($s, $planet, Kit::NANITE, $k->level(Kit::NANITE, $planet, $s->user) + 1)) !== null) {
            $out[] = Kit::proposal($c, 'economy', 'nanites', 0.6 * $this->bestMine);
        }

        // fields nearly gone: the Terraformer keeps the planet growing
        $max = $k->developments->maxFields((int) $planet['planet_field_max'], (int) ($planet['building_terraformer'] ?? 0));
        if ($max - (int) $planet['planet_field_current'] <= 3 && ($c = $k->resolve($s, $planet, Kit::TERRAFORMER, $k->level(Kit::TERRAFORMER, $planet, $s->user) + 1)) !== null) {
            $out[] = Kit::proposal($c, 'economy', 'more fields', 1.5 * $this->bestMine);
        }

        return $out;
    }
}

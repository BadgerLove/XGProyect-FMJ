<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind\Advisors;

use App\Services\Bot\Mind\Advisor;
use App\Services\Bot\Mind\Kit;
use App\Services\Bot\Mind\Personality;
use App\Services\Bot\Mind\Situation;

/**
 * Warships, valued as income (Dale, 21 Oct): what the fleet's attacks bring home (the bot's own battle
 * reports, losses taken off), plus what a fleet at home protects from the raids the bot has been
 * suffering, plus a small ambition share for warlike characters so a bot with no history still tries.
 * The fleet share of points is a ceiling, not a target, and the best hull per worth is built; a better
 * hull it can't build yet becomes a research goal.
 */
final class WarAdvisor implements Advisor
{
    private const WARSHIPS = [204, 205, 206, 207, 211, 213, 215];

    public function __construct(private readonly Kit $kit)
    {
    }

    public function name(): string
    {
        return 'war';
    }

    public function propose(Situation $s, Personality $p, array $planet, array $ctx): array
    {
        if ($p->war < 0.1) {
            return [];
        }
        $k = $this->kit;
        $ceiling = 0.05 + 0.35 * $p->war + 0.1 * $s->threat;
        if ($s->militaryRatio >= $ceiling) {
            return [];
        }
        $income = $s->incomeWorth();
        $value = 0.8 * $s->attackIncome + 0.3 * $s->lossRate * $p->guard + 0.04 * $p->war * $income;
        // a fleet that has been losing its attacks earns less than the reports say: trust a bad record
        if ($s->raidsSent >= 4 && $s->raidWins / $s->raidsSent < 0.4) {
            $value *= 0.5;
        }

        $best = null;
        $bestEff = 0.0;
        $locked = [];
        foreach (self::WARSHIPS as $id) {
            $eff = $k->efficiency($s, $id);
            if ($k->allowed($id, $planet, $s->user)) {
                if ($eff > $bestEff) {
                    [$best, $bestEff] = [$id, $eff];
                }
            } else {
                $locked[$id] = $eff;
            }
        }

        $out = [];
        if ($best !== null) {
            $need = ($ceiling - $s->militaryRatio) * max(50.0, $s->totalPoints) * 1000;
            $n = max(1, (int) ceil($need / max(1.0, $s->worthOf($k->unitPrice($best))) * 0.3));
            if (($c = $k->resolveUnit($s, $planet, $best, $n)) !== null) {
                $out[] = Kit::proposal($c, 'war', 'fleet: ' . $k->name($best), $value * ($ctx['main'] ? 1.0 : 0.5));
            }
        }
        if ($ctx['main']) {
            foreach ($locked as $id => $eff) {
                if ($eff > 1.15 * max($bestEff, 0.0001) && ($c = $k->resolveUnit($s, $planet, $id, 1)) !== null) {
                    $gain = $bestEff > 0 ? min(2.0, $eff / $bestEff - 1.0) : 1.0;
                    $out[] = Kit::proposal($c, 'war', 'better hull: ' . $k->name($id), 0.5 * $gain * $value);
                }
            }
        }

        return $out;
    }
}

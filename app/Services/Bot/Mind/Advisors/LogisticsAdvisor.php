<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind\Advisors;

use App\Services\Bot\Mind\Advisor;
use App\Services\Bot\Mind\Kit;
use App\Services\Bot\Mind\Personality;
use App\Services\Bot\Mind\Situation;

/**
 * Hauling: recyclers for the debris the bot can see, cargo for raids and expeditions. Values come from
 * what these trips actually brought home (raid loot, expedition and recycler returns in the notebook),
 * with a modest prior while the bot has no record yet.
 */
final class LogisticsAdvisor implements Advisor
{
    private const SMALL_CARGO = 202;
    private const LARGE_CARGO = 203;
    private const RECYCLER = 209;

    /** One-off gains are spread over this many x1 hours to compare with lasting ones. */
    private const ONE_OFF_HOURS = 72.0;

    /** Debris in reach is worth this many x1 hours of collecting. */
    private const DEBRIS_HOURS = 36.0;

    public function __construct(private readonly Kit $kit)
    {
    }

    public function name(): string
    {
        return 'logistics';
    }

    public function propose(Situation $s, Personality $p, array $planet, array $ctx): array
    {
        $out = [];
        $k = $this->kit;

        // recyclers: the debris in the galaxy view (our own fields fully, others' at 40 %) + what lands on us
        $tons = 0.0;
        $ownHere = false;
        foreach ($s->debris as $d) {
            $tons += ($d['own'] ? 1.0 : 0.4) * ($d['metal'] + $d['crystal']);
            $ownHere = $ownHere || ($d['own'] && $d['system'] === (int) $planet['planet_system'] && $d['planet'] === (int) $planet['planet_planet']);
        }
        $cap = (float) $k->registry->get(self::RECYCLER)->getCapacity();
        // a bot that fights makes debris at its targets every day: that is its to collect too
        $want = (int) min(15, ceil($tons / $cap)) + ($s->debrisRate > 0 ? 1 : 0) + ($s->attackDebrisRate > 0 ? 2 : 0);
        $have = (int) ($s->owned[self::RECYCLER] ?? 0);
        if ($want > $have && ($ctx['main'] || $ownHere)) {
            $n = $want - $have;
            $perTon = $s->debrisWorth / max(1.0, $tons);
            // debris is collected within a day or two, not spread over three like a one-off research gain
            $value = min($tons, $n * $cap) * $perTon / (self::DEBRIS_HOURS * $s->h1) + 0.8 * $s->debrisRate + 0.8 * $s->attackDebrisRate + 0.5 * $s->harvestIncome;
            if (($c = $k->resolveUnit($s, $planet, self::RECYCLER, $n)) !== null) {
                $out[] = Kit::proposal($c, 'logistics', 'debris to collect', $value * (0.7 + 0.3 * $p->greed));
            }
        }

        // cargo for raids and expeditions
        if (!$ctx['main'] || ($p->raid < 0.3 && $p->explore < 0.4)) {
            return $out;
        }
        $windowHours = max(0.001, $s->windowSeconds / 3600.0);
        $capacity = (int) ($s->owned[self::SMALL_CARGO] ?? 0) * 5000 + (int) ($s->owned[self::LARGE_CARGO] ?? 0) * 25000;
        $slots = max(1, $s->fleetSlots - 1);
        $wantCap = 0;
        $perTonHour = 0.0;
        if ($p->raid >= 0.3) {
            $wantCap += (int) round(min($slots, 2 + 4 * $p->raid) * 4 * 5000);
            $perTonHour = $s->raidsSent >= 3
                ? $s->raidIncome / max(5000.0, (float) $capacity)
                : 0.03 * $p->raid / $s->h1; // prior: a cargo brings home ~3 % of its hold in worth per x1 hour
        }
        if ($p->explore >= 0.4 && (int) ($s->user['research_astrophysics'] ?? 0) >= 1) {
            $wantCap += (int) round((5 + 15 * $p->explore) * 5000);
            $exp = $s->expeditionIncome > 0 ? $s->expeditionIncome / max(5000.0, (float) $capacity) : 0.02 * $p->explore / $s->h1;
            $perTonHour = max($perTonHour, $exp);
        }
        if ($capacity >= $wantCap || $perTonHour <= 0) {
            return $out;
        }
        $id = $k->allowed(self::LARGE_CARGO, $planet, $s->user) ? self::LARGE_CARGO : self::SMALL_CARGO;
        $hold = (float) $k->registry->get($id)->getCapacity();
        $n = (int) ceil(($wantCap - $capacity) / $hold);
        if (($c = $k->resolveUnit($s, $planet, $id, $n)) !== null) {
            $out[] = Kit::proposal($c, 'logistics', 'cargo hold', $perTonHour * $hold * $n);
        }
        if ($id === self::SMALL_CARGO && ($c = $k->resolveUnit($s, $planet, self::LARGE_CARGO, 1)) !== null) {
            $out[] = Kit::proposal($c, 'logistics', 'bigger cargos', 0.4 * $perTonHour * max(25000.0, (float) $capacity));
        }

        return $out;
    }
}

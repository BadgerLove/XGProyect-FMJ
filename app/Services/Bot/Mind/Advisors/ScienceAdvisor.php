<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind\Advisors;

use App\Services\Bot\Mind\Advisor;
use App\Services\Bot\Mind\GoalTree;
use App\Services\Bot\Mind\Kit;
use App\Services\Bot\Mind\Personality;
use App\Services\Bot\Mind\Situation;

/**
 * Research worth having for what it does right now (prerequisites are pulled in by the other advisors'
 * goals and by the goal tree). Main planet only.
 */
final class ScienceAdvisor implements Advisor
{
    private const ESPIONAGE = 106;
    private const COMPUTER = 108;
    private const WEAPONS = 109;
    private const SHIELDING = 110;
    private const ARMOUR = 111;
    private const PLASMA = 122;
    private const ASTRO = 124;

    /** One-off gains are spread over this many x1 hours to compare with lasting ones. */
    public const ONE_OFF_HOURS = 72.0;

    /** Each combat-tech level past what the stage allows keeps this share of its value (it fades, never stops). */
    public const COMBAT_FADE = 0.2;

    /**
     * Combat-tech level the bot's stage allows: the stage's milestone (or stage + 1) plus 2, and 2 more per stage
     * past 'mid'. Bots averaged Weapons 6.6 / Shielding 5.5 / Armour 7.3 against a milestone of 4 while Impulse
     * (3.2) and Ion (1.1) lagged behind (review 6 Nov, Dale 8 Nov): the crystal belongs to the road ahead.
     */
    public static function combatAllowed(int $tech, int $stage): int
    {
        return max(GoalTree::milestoneLevel($tech, $stage), $stage + 1) + 2 + 2 * max(0, $stage - 3);
    }

    public function __construct(private readonly Kit $kit)
    {
    }

    public function name(): string
    {
        return 'science';
    }

    public function propose(Situation $s, Personality $p, array $planet, array $ctx): array
    {
        if (!$ctx['main']) {
            return [];
        }
        $out = [];
        $k = $this->kit;
        $f = 0.6 + 0.6 * $p->science;
        $income = $s->incomeWorth();
        $push = function (int $id, string $why, float $value) use (&$out, $k, $s, $planet, $f): void {
            if ($value > 0 && ($c = $k->resolve($s, $planet, $id, $k->level($id, $planet, $s->user) + 1)) !== null) {
                $out[] = Kit::proposal($c, 'science', $why, $value * $f);
            }
        };

        // Research Lab: research goes faster
        $lab = $k->level(Kit::LAB, $planet, $s->user);
        if ($lab < 2 + (int) round(8 * $p->science) && ($c = $k->resolve($s, $planet, Kit::LAB, $lab + 1)) !== null) {
            $out[] = Kit::proposal($c, 'science', 'faster research', 0.06 * $p->science * $income);
        }

        // Plasma: +1 % metal, +0.66 % crystal, +0.33 % deuterium per level
        $push(self::PLASMA, 'plasma output', $s->income['metal'] * 0.01 * $s->worth['metal'] + $s->income['crystal'] * 0.0066 * $s->worth['crystal']
            + $s->income['deuterium'] * 0.0033 * $s->worth['deuterium']);

        // Computer: one more fleet slot, only when the slots are full and the fleets earn
        if ($s->fleetsOut >= max(1, $s->fleetSlots - 1)) {
            $missions = $s->raidIncome + $s->attackIncome + $s->expeditionIncome + $s->harvestIncome;
            $push(self::COMPUTER, 'another fleet slot', $missions / max(1, $s->fleetSlots) + 0.01 * $income);
        }

        // Espionage: probes that come back and see fleets
        $spy = max($p->raid, $p->war);
        if ($spy >= 0.35 && $k->level(self::ESPIONAGE, $planet, $s->user) < 2 + (int) round(4 * $spy)) {
            $push(self::ESPIONAGE, 'better probes', 0.02 * $spy * $income + 0.1 * $s->attackIncome);
        }

        // Weapons / shields / armour: 10 % on the fleet we have and on what it earns and protects
        if ($s->militaryWorth > 0) {
            $earned = $s->attackIncome + 0.5 * $s->lossRate;
            foreach ([self::WEAPONS => 'weapons', self::SHIELDING => 'shields', self::ARMOUR => 'armour'] as $tech => $n) {
                $over = $k->level($tech, $planet, $s->user) + 1 - self::combatAllowed($tech, $s->stage);
                $fade = $over > 0 ? self::COMBAT_FADE ** $over : 1.0;
                $push($tech, "stronger fleet ({$n})", (0.06 * $s->militaryWorth / (self::ONE_OFF_HOURS * $s->h1) + 0.1 * $earned) * $fade);
            }
        }

        // Astrophysics for expedition slots: what expeditions earn, or a prior for explorers
        $astro = $k->level(self::ASTRO, $planet, $s->user);
        if ($p->explore >= 0.4 && $astro < 1 + (int) round(5 * $p->explore)) {
            $push(self::ASTRO, 'more expeditions', max(0.3 * $s->expeditionIncome, 0.03 * $p->explore * $income));
        }

        return $out;
    }
}

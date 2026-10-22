<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind;

use App\Services\Bot\Mind\Advisors\EconomyAdvisor;
use App\Services\Bot\Mind\Advisors\ExpansionAdvisor;
use App\Services\Bot\Mind\Advisors\GuardAdvisor;
use App\Services\Bot\Mind\Advisors\LogisticsAdvisor;
use App\Services\Bot\Mind\Advisors\ScienceAdvisor;
use App\Services\Bot\Mind\Advisors\ScoutAdvisor;
use App\Services\Bot\Mind\Advisors\WarAdvisor;

/**
 * The General: chairs each bot's council (Dale, 21 Oct 2026: "multiple thinking bots rather than one big
 * thing that doesn't know why it goes wrong"). Every advisor proposes what it would do on this planet,
 * with a reason and a value in metal-worth per hour (what it earns or protects - Dale: every bot must
 * master its most profitable way to make resources); the goal tree adds the open milestones of the bot's
 * stage so it always has a direction; the doctor's prescription bans dead ends and boosts what is
 * needed. Then: value per worth spent (every missing prerequisite included), discounted by how long the
 * next step waits for its resources against the bot's patience. Do the best affordable thing per lane
 * (building / research / up to three unit orders), save for the best one it can't pay yet if it is
 * within patience, spend what is left above that saving. Each decision goes in the bot's diary.
 */
final class BotMind
{
    private const MAX_ORDER = 2000;

    private const MAX_UNIT_ORDERS = 3;

    public string $lastReason = '';

    /** @var list<string> */
    public array $lastTop = [];

    /** @var list<Advisor> */
    private array $council;

    public function __construct(
        private readonly Kit $kit,
        private readonly GoalTree $tree,
        private readonly Doctor $doctor,
        private readonly EconomyAdvisor $economy,
        ScienceAdvisor $science,
        ExpansionAdvisor $expansion,
        WarAdvisor $war,
        public readonly GuardAdvisor $guard,
        ScoutAdvisor $scout,
        LogisticsAdvisor $logistics,
    ) {
        $this->council = [$science, $expansion, $war, $guard, $scout, $logistics];
    }

    /** Once per bot per tick, before its planets: stage + doctor. Returns the prescription. */
    public function beginTurn(Situation $s, Personality $p): array
    {
        $this->tree->locate($s);
        $s->rx = $this->doctor->examine($s, $p);

        return $s->rx;
    }

    /**
     * @param  array<string, mixed>  $planet  fresh planet row (buildings, defences, ships)
     * @param  array{build: bool, lab: bool, yard: bool}  $lanes  what is free on this planet
     * @return array{building: int|null, research: int|null, units: array<int, int>, reason: string}
     */
    public function plan(Situation $s, Personality $p, array $planet, array $lanes): array
    {
        $isMain = (int) $planet['planet_id'] === $s->mainId;
        $lanes['lab'] = $lanes['lab'] && $isMain;
        $out = ['building' => null, 'research' => null, 'units' => [], 'reason' => ''];
        $rx = $s->rx ?? ['mode' => '', 'bans' => [], 'boost' => []];

        $ctx = ['main' => $isMain, 'best_mine' => 0.0];
        $props = $this->economy->propose($s, $p, $planet, $ctx);
        $ctx['best_mine'] = $this->economy->bestMine;
        foreach ($this->council as $advisor) {
            array_push($props, ...$advisor->propose($s, $p, $planet, $ctx));
        }
        if ($isMain) {
            array_push($props, ...$this->tree->propose($s, $p, $planet));
        }
        $pushColonies = $this->tree->wantsColonies($s);

        // an attack is coming and the stock can't leave: spend it all now (no saving, defence first)
        $panic = !empty($s->panic[(int) $planet['planet_id']]);
        if ($panic) {
            $rx['boost']['guard'] = 2.0 * ($rx['boost']['guard'] ?? 1.0);
        }

        // the doctor's orders + score
        $patience = $panic ? 0.0001 : $p->patienceHours() * $s->h1;
        $income = $this->kit->planetIncome($planet);
        foreach ($props as $i => $c) {
            $key = "{$c['lane']}:{$c['id']}";
            if (isset($rx['bans'][$key])) {
                unset($props[$i]);
                continue;
            }
            $v = $c['value'] * ($rx['boost'][$c['advisor']] ?? 1.0);
            if ($c['advisor'] === 'expansion' && $pushColonies) {
                $v *= 1.5;
            }
            if (isset($rx['boost']['power']) && in_array($c['id'], [Kit::SOLAR, Kit::FUSION], true)) {
                $v *= $rx['boost']['power'];
            }
            if (isset($rx['boost']['fields']) && $c['id'] === Kit::TERRAFORMER) {
                $v *= $rx['boost']['fields'];
            }
            $wait = 0.0;
            foreach (Situation::RES as $r) {
                $short = $c['cost'][$r] - (float) $planet["planet_{$r}"];
                if ($short > 0) {
                    $wait = max($wait, $short / max(1.0, $income[$r]));
                }
            }
            $props[$i]['wait'] = $wait;
            $props[$i]['score'] = $v / max(1.0, $s->worthOf($c['chain'])) / (1.0 + $wait / max(0.001, $patience));
        }
        $props = array_values($props);
        usort($props, fn ($a, $b) => $b['score'] <=> $a['score']);

        $this->lastTop = [];
        foreach (array_slice($props, 0, 4) as $c) {
            $this->lastTop[] = sprintf('%s/%s->%s %.3g', $c['advisor'], $c['label'], $this->kit->name($c['id']), $c['score'] * 1000);
        }

        // spend
        $have = ['metal' => (float) $planet['planet_metal'], 'crystal' => (float) $planet['planet_crystal'], 'deuterium' => (float) $planet['planet_deuterium']];
        $reserve = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];
        $saving = null;
        $unitOrders = 0;
        $reasons = [];
        $chosenBy = [];
        foreach ($props as $c) {
            $lane = $c['lane'];
            if (($lane === 'b' && (!$lanes['build'] || $out['building'] !== null))
                || ($lane === 'r' && (!$lanes['lab'] || $out['research'] !== null))
                || ($lane === 's' && (!$lanes['yard'] || $unitOrders >= self::MAX_UNIT_ORDERS || isset($out['units'][$c['id']])))
            ) {
                continue;
            }
            if ($lane === 's') {
                $n = min($c['count'], self::MAX_ORDER);
                foreach (Situation::RES as $r) {
                    if ($c['unit_cost'][$r] > 0) {
                        $n = min($n, (int) floor(max(0.0, $have[$r] - $reserve[$r]) / $c['unit_cost'][$r]));
                    }
                }
                if ($n > 0) {
                    $out['units'][$c['id']] = $n;
                    $unitOrders++;
                    foreach (Situation::RES as $r) {
                        $have[$r] -= $c['unit_cost'][$r] * $n;
                    }
                    $reasons[] = "{$c['advisor']}/{$c['label']}: " . $this->kit->name($c['id']) . " x{$n}";
                    $chosenBy[] = $c['advisor'];
                    continue;
                }
            } elseif ($this->canPay($have, $reserve, $c['cost'])) {
                $out[$lane === 'b' ? 'building' : 'research'] = $c['id'];
                foreach (Situation::RES as $r) {
                    $have[$r] -= $c['cost'][$r];
                }
                $reasons[] = "{$c['advisor']}/{$c['label']}: " . $this->kit->name($c['id']);
                $chosenBy[] = $c['advisor'];
                continue;
            }
            // can't pay: save for the best thing, if it is within reach of this bot's patience
            if ($saving === null && !$panic && $c['wait'] <= 3.0 * $patience) {
                $saving = $c;
                foreach (Situation::RES as $r) {
                    $reserve[$r] += min($have[$r], $lane === 's' ? $c['unit_cost'][$r] : $c['cost'][$r]);
                }
                $reasons[] = "saving for {$c['advisor']}/{$c['label']}: " . $this->kit->name($c['id']);
            }
        }

        // notebook: what was chosen (the doctor pushes advisors that never win), what we save for, the diary
        foreach ($chosenBy as $a) {
            $s->memory['chosen'][$a] = ($s->memory['chosen'][$a] ?? 0) + 1;
        }
        if ($isMain) {
            $key = $saving !== null ? "{$saving['lane']}:{$saving['id']}" : null;
            $prev = $s->memory['saving'] ?? null;
            $s->memory['saving'] = $key === null ? null : [$key, ($prev !== null && $prev[0] === $key) ? $prev[1] + 1 : 1];
        }
        $out['reason'] = implode('; ', $reasons);
        $this->lastReason = $out['reason'];
        $s->memory['diary'][] = [time(), "{$planet['planet_galaxy']}:{$planet['planet_system']}:{$planet['planet_planet']}",
            'stage ' . GoalTree::STAGES[$s->stage]['name'] . ($rx['mode'] ? " ({$rx['mode']})" : ''), $out['reason'] ?: 'nothing', implode(' | ', array_slice($this->lastTop, 0, 3))];

        return $out;
    }

    /** @return array<string, float> */
    public function unitPrice(int $id): array
    {
        return $this->kit->unitPrice($id);
    }

    /** @param array<string, float> $have @param array<string, float> $reserve @param array<string, float> $cost */
    private function canPay(array $have, array $reserve, array $cost): bool
    {
        foreach (Situation::RES as $r) {
            if ($cost[$r] > 0 && $have[$r] - $reserve[$r] < $cost[$r]) {
                return false;
            }
        }

        return true;
    }
}

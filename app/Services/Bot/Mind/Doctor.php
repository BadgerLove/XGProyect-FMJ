<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind;

/**
 * The stuck-bot doctor (Dale, 21 Oct: "we always get stuck, it needs to be looking for progress").
 * Once per bot per tick it writes the bot's points in the notebook. If they haven't grown for
 * STALL_X1_HOURS it works out why and prescribes something for the next STALL_X1_HOURS:
 *   - saving for the same thing longer than its patience and the stock is NOT rising -> ban that goal for a while
 *   - running short of energy                  -> power counts triple
 *   - out of fields                            -> Terraformer counts triple
 *   - wiped out (no fleet, no stock, being hit) -> rebuild mode: economy and defence up, no raids/cargo/fleet
 *   - bleeding (ships lost at home worth more than the fleet earns) -> economy and defence up, warships down
 *   - none of those                            -> economy first (income is the answer to "nothing happens")
 * Saving counts as progress while the stock keeps rising towards the goal (Dale, 21 Oct: "always looking for
 * progress"); until 2 Nov 2026 twelve hours of honest saving read as a stall, the saving ban fired after 12
 * TICKS (3 h live; it was tuned in the simulator where a tick was 17-75 game-minutes), and "push the least
 * chosen advisor" was always expansion, which proposes once per colony slot: 447 of 1,000 bots were under a
 * prescription at once and colony ships / Astrophysics were the most banned things in the universe.
 * Every visit is written to the notebook ('doctor') so a trace shows what was wrong and what was done.
 */
final class Doctor
{
    private const STALL_X1_HOURS = 12.0;

    private const GROWTH_MIN = 0.005;

    /** Stock rising by this many x1 hours of income over the stall window counts as progress while saving. */
    private const SAVING_PROGRESS_HOURS = 2.0;

    /** Losses at home above this share of the window's income = bleeding. */
    private const BLEED_SHARE = 0.25;

    public function __construct(private readonly Kit $kit)
    {
    }

    /** @return array{mode: string, bans: array<string, int>, boost: array<string, float>} what the General must apply now */
    public function examine(Situation $s, Personality $p): array
    {
        $m = &$s->memory;
        $now = time();
        $stall = (int) (self::STALL_X1_HOURS * $s->h1 * 3600);
        $stockNow = round($s->worthOf($s->stock));
        $m['pts'] = Memory::prune(array_merge($m['pts'] ?? [], [[$now, round($s->totalPoints, 1), $stockNow]]), 6 * $stall);

        // the current prescription, while it lasts
        $rx = $m['rx'] ?? null;
        if ($rx !== null && (int) $rx['until'] < $now) {
            $rx = null;
            unset($m['rx']);
        }
        $bans = array_filter($m['bans'] ?? [], fn ($until) => (int) $until > $now);
        $m['bans'] = $bans;

        // has it grown over the last STALL hours? (points, or the stock it is saving up)
        $old = null;
        $oldStock = null;
        foreach ($m['pts'] as $row) {
            if ($row[0] <= $now - $stall) {
                $old = $row[1];
                $oldStock = $row[2] ?? null;
            }
        }
        $savingProgress = !empty($m['saving']) && $oldStock !== null
            && $stockNow - $oldStock >= self::SAVING_PROGRESS_HOURS * $s->incomeWorth() * $s->h1;
        if ($rx === null && $old !== null && $old > 0 && ($s->totalPoints - $old) / $old < self::GROWTH_MIN && !$savingProgress) {
            $rx = $this->diagnose($s, $p, $now, $stall);
            $m['rx'] = $rx;
            $m['doctor'] = array_slice(array_merge($m['doctor'] ?? [], [[$now, $rx['why']]]), -10);
            if (isset($rx['ban'])) {
                $m['bans'][$rx['ban']] = $now + 2 * $stall;
                $bans = $m['bans'];
            }
        }

        return ['mode' => $rx['mode'] ?? '', 'bans' => $bans, 'boost' => $rx['boost'] ?? []];
    }

    private function diagnose(Situation $s, Personality $p, int $now, int $stall): array
    {
        $main = $s->mainPlanet();
        $income = max(1.0, $s->incomeWorth());
        $stock = $s->worthOf($s->stock);
        $rx = ['until' => $now + $stall, 'mode' => '', 'boost' => []];

        $saving = $s->memory['saving'] ?? null;
        $savingHours = $saving !== null ? ($now - (int) ($saving[2] ?? $now)) / 3600.0 / max(0.001, $s->h1) : 0.0;
        if ($saving !== null && $savingHours >= $p->patienceHours()) {
            return $rx + ['ban' => (string) $saving[0], 'why' => sprintf('stuck saving for %s for %.0f x1 h (patience %.0f) with the stock not rising: banned, next best instead', $saving[0], $savingHours, $p->patienceHours())];
        }
        if ($this->kit->productionPercent($main) < 70) {
            return array_merge($rx, ['boost' => ['power' => 3.0], 'why' => 'energy short (' . $this->kit->productionPercent($main) . ' %): power first']);
        }
        $max = $this->kit->developments->maxFields((int) $main['planet_field_max'], (int) ($main['building_terraformer'] ?? 0));
        if ($max - (int) $main['planet_field_current'] <= 1) {
            return array_merge($rx, ['boost' => ['fields' => 3.0], 'why' => 'out of fields: Terraformer']);
        }
        if ($s->hits > 0 && $s->militaryWorth < 2 * $income * $s->h1 && $stock < 2 * $income * $s->h1) {
            return array_merge($rx, ['mode' => 'rebuild', 'boost' => ['economy' => 1.3, 'guard' => 1.5, 'war' => 0.3, 'logistics' => 0.3],
                'why' => "wiped out ({$s->hits} hits, nothing left): rebuild mode"]);
        }
        $windowHours = $s->windowSeconds / 3600.0;
        if ($s->hits > 0 && $s->lostWorth > self::BLEED_SHARE * $income * $windowHours) {
            return array_merge($rx, ['mode' => 'bleeding', 'boost' => ['economy' => 1.3, 'guard' => 1.5, 'war' => 0.3],
                'why' => sprintf('bleeding (%d hits cost %.0f, %.0f %% of the window\'s income): economy and defence, no more warships for now', $s->hits, $s->lostWorth, 100 * $s->lostWorth / max(1.0, $income * $windowHours))]);
        }

        return array_merge($rx, ['boost' => ['economy' => 1.5], 'why' => 'no growth for ' . self::STALL_X1_HOURS . ' x1 h, no clear cause: economy first']);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind;

/**
 * The stuck-bot doctor (Dale, 21 Oct: "we always get stuck, it needs to be looking for progress").
 * Once per bot per tick it writes the bot's points in the notebook. If they haven't grown for
 * STALL_X1_HOURS it works out why and prescribes something for the next STALL_X1_HOURS:
 *   - saving for the same thing for ages      -> ban that goal for a while (take the next best)
 *   - running short of energy                  -> power counts triple
 *   - out of fields                            -> Terraformer counts triple
 *   - wiped out (no fleet, no stock, being hit) -> rebuild mode: economy and defence up, no raids/cargo/fleet
 *   - none of those                            -> shake: whatever the bot has chosen least gets a push
 * Every visit is written to the notebook ('doctor') so a trace shows what was wrong and what was done.
 */
final class Doctor
{
    private const STALL_X1_HOURS = 12.0;

    private const GROWTH_MIN = 0.005;

    /** Ticks of saving for the same thing before the doctor calls it a dead end. */
    private const SAVING_TICKS = 12;

    public function __construct(private readonly Kit $kit)
    {
    }

    /** @return array{mode: string, bans: array<string, int>, boost: array<string, float>} what the General must apply now */
    public function examine(Situation $s, Personality $p): array
    {
        $m = &$s->memory;
        $now = time();
        $stall = (int) (self::STALL_X1_HOURS * $s->h1 * 3600);
        $m['pts'] = Memory::prune(array_merge($m['pts'] ?? [], [[$now, round($s->totalPoints, 1)]]), 6 * $stall);

        // the current prescription, while it lasts
        $rx = $m['rx'] ?? null;
        if ($rx !== null && (int) $rx['until'] < $now) {
            $rx = null;
            unset($m['rx']);
        }
        $bans = array_filter($m['bans'] ?? [], fn ($until) => (int) $until > $now);
        $m['bans'] = $bans;

        // has it grown over the last STALL hours?
        $old = null;
        foreach ($m['pts'] as [$t, $pts]) {
            if ($t <= $now - $stall) {
                $old = $pts;
            }
        }
        if ($rx === null && $old !== null && $old > 0 && ($s->totalPoints - $old) / $old < self::GROWTH_MIN) {
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
        if ($saving !== null && (int) $saving[1] >= self::SAVING_TICKS) {
            return $rx + ['ban' => (string) $saving[0], 'why' => "stuck saving for {$saving[0]} for {$saving[1]} ticks: banned, next best instead"];
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
        $chosen = $s->memory['chosen'] ?? [];
        $least = 'economy';
        $min = PHP_INT_MAX;
        foreach (['economy', 'science', 'expansion', 'war', 'guard', 'logistics', 'goal'] as $a) {
            if (($chosen[$a] ?? 0) < $min) {
                [$least, $min] = [$a, (int) ($chosen[$a] ?? 0)];
            }
        }

        return array_merge($rx, ['boost' => [$least => 2.0], 'why' => "no growth for " . self::STALL_X1_HOURS . " x1 h, no clear cause: push {$least}"]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Game\Formulas;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Expedition slots (Dale, 30 Sep 2026).
 *
 * Every system has a hidden, random number of "good" expeditions per 6-hour window (00/06/12/18 UK
 * time), rolled 1-15 but mostly 1-6. An expedition claims a slot when it ARRIVES: under 100 % it gets
 * one and draws its normal card from the deck; at 100 % it comes back empty ("picked clean"). Fleet
 * size does not matter. Players (and bots — they get no extra information) only see the % used.
 *
 * Replaces the old wear in ExpeditionService (20-count threshold, 2/hour recovery that re-applied
 * itself on every read, and at most a ~9 % "nothing" nudge — no system ever got past 5).
 */
class ExpeditionSlotService
{
    public const WINDOW_HOURS = 6;
    public const TIMEZONE = 'Europe/London';

    /** Slots => weight (out of 1000): 84 % of windows are 1-6, 15 comes up ~1 in 330. */
    public const SLOT_WEIGHTS = [
        1 => 140, 2 => 170, 3 => 170, 4 => 150, 5 => 120, 6 => 90,
        7 => 50, 8 => 35, 9 => 25, 10 => 15, 11 => 12, 12 => 9, 13 => 7, 14 => 4, 15 => 3,
    ];

    /**
     * fleet_id => granted, for claims already decided. Bound as a singleton (AppServiceProvider)
     * so it lives for one page request, or one bot tick.
     *
     * @var array<int, bool|int>|null
     */
    private ?array $claims = null;

    /** Start (unix) of the 6-hour window $ts falls in. */
    public function windowStart(int $ts): int
    {
        $local = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(self::TIMEZONE));
        $hour = intdiv((int) $local->format('G'), self::WINDOW_HOURS) * self::WINDOW_HOURS;

        return $local->setTime($hour, 0, 0)->getTimestamp();
    }

    /** Start (unix) of the next window after the one $ts falls in. */
    public function nextWindowStart(int $ts): int
    {
        $local = (new DateTimeImmutable('@' . $this->windowStart($ts)))->setTimezone(new DateTimeZone(self::TIMEZONE));

        return $local->modify('+' . self::WINDOW_HOURS . ' hours')->getTimestamp();
    }

    /** "13:00" — when the current window ends, UK time. */
    public function resetsAt(?int $ts = null): string
    {
        return (new DateTimeImmutable('@' . $this->nextWindowStart($ts ?? time())))
            ->setTimezone(new DateTimeZone(self::TIMEZONE))
            ->format('H:i');
    }

    /**
     * The system's window row (rolled on first touch).
     *
     * @return array{capacity: int, used: int}
     */
    public function window(int $galaxy, int $system, int $windowStart): array
    {
        $row = $this->row($galaxy, $system, $windowStart);

        if ($row === null) {
            DB::table('expedition_slots')->insertOrIgnore([
                'galaxy' => $galaxy,
                'system' => $system,
                'window_start' => $windowStart,
                'capacity' => $this->roll(),
                'used' => 0,
            ]);
            $this->pruneSometimes();
            $row = $this->row($galaxy, $system, $windowStart);
        }

        return ['capacity' => max(1, (int) $row->capacity), 'used' => (int) $row->used];
    }

    /** % of this window's slots used in a system right now (0-100). */
    public function usedPercent(int $galaxy, int $system, ?int $now = null): int
    {
        $w = $this->window($galaxy, $system, $this->windowStart($now ?? time()));

        return (int) min(100, floor($w['used'] * 100 / $w['capacity']));
    }

    /**
     * Claim a slot for an expedition fleet that has arrived. Idempotent: the first call decides,
     * later calls return the same answer. Safe when the page-load processor and the bot tick
     * handle the same fleet at once (the claim row is inserted first; only its inserter decides).
     */
    public function claim(int $fleetId, int $galaxy, int $system, int $arrivalTs): bool
    {
        // Every held expedition comes through here on every page load until its hold ends (211 of
        // them = 214 queries a page, 2026-09-30), so read all decided claims once. A decided claim
        // never changes until forget(); anything not in the list falls through to the database.
        $this->claims ??= DB::table('expedition_claims')->pluck('granted', 'fleet_id')->all();
        if (array_key_exists($fleetId, $this->claims)) {
            return (bool) $this->claims[$fleetId];
        }

        $existing = DB::table('expedition_claims')->where('fleet_id', $fleetId)->value('granted');
        if ($existing !== null) {
            return $this->claims[$fleetId] = (bool) $existing;
        }

        $windowStart = $this->windowStart($arrivalTs);
        $this->window($galaxy, $system, $windowStart);

        return $this->claims[$fleetId] = (bool) DB::transaction(function () use ($fleetId, $galaxy, $system, $windowStart): bool {
            $inserted = DB::table('expedition_claims')->insertOrIgnore([
                'fleet_id' => $fleetId,
                'galaxy' => $galaxy,
                'system' => $system,
                'window_start' => $windowStart,
                'granted' => false,
                'claimed_at' => time(),
            ]);

            if ($inserted === 0) {
                return (bool) DB::table('expedition_claims')->where('fleet_id', $fleetId)->value('granted');
            }

            $slot = DB::table('expedition_slots')
                ->where(['galaxy' => $galaxy, 'system' => $system, 'window_start' => $windowStart])
                ->lockForUpdate()
                ->first();

            $granted = $slot !== null && (int) $slot->used < (int) $slot->capacity;

            if ($granted) {
                DB::table('expedition_slots')
                    ->where(['galaxy' => $galaxy, 'system' => $system, 'window_start' => $windowStart])
                    ->increment('used');
                DB::table('expedition_claims')->where('fleet_id', $fleetId)->update(['granted' => true]);
            }

            return $granted;
        });
    }

    /** Drop a fleet's claim once its expedition is resolved. */
    public function forget(int $fleetId): void
    {
        unset($this->claims[$fleetId]);
        DB::table('expedition_claims')->where('fleet_id', $fleetId)->delete();
    }

    /** Weighted 1-15. */
    public function roll(): int
    {
        $pick = mt_rand(1, array_sum(self::SLOT_WEIGHTS));
        foreach (self::SLOT_WEIGHTS as $slots => $weight) {
            $pick -= $weight;
            if ($pick <= 0) {
                return $slots;
            }
        }

        return 1;
    }

    private function row(int $galaxy, int $system, int $windowStart): ?object
    {
        return DB::table('expedition_slots')
            ->where(['galaxy' => $galaxy, 'system' => $system, 'window_start' => $windowStart])
            ->first();
    }

    /** Now and then, drop windows older than two days and claims orphaned for three. */
    private function pruneSometimes(): void
    {
        if (mt_rand(1, 200) !== 1) {
            return;
        }

        DB::table('expedition_slots')->where('window_start', '<', time() - 2 * 86400)->delete();
        DB::table('expedition_claims')->where('claimed_at', '<', time() - 3 * 86400)->delete();
    }
}

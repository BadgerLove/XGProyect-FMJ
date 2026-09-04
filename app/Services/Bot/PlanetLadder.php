<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Services\Game\Formulas\ProductionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idle-escalation ladder for bot planets (self-healing, Part 2 of the plan).
 *
 * A planet whose economy is stuck — nothing to build, nothing to research — climbs a
 * ladder of increasingly blunt fixes, one rung per tick. This class owns the per-planet
 * counter (table `bot_planet_state`) and the rung rules. BotTick decides *when* a planet
 * is idle and calls in here.
 *
 * Rung 1 (this version): the merchant. Sell surplus metal for crystal at 2:1 (or deut
 * for crystal at 1:1) when crystal is the blocker. Direct DB adjustment — bots have no
 * Dark Matter for the real trader. Never overflows the crystal store, always keeps a
 * metal/deut reserve.
 *
 * Rungs 2–4 (storage, relaxed ROI, STUCK) are in the plan; not implemented yet.
 *
 * If the table is missing (migration not run) the ladder disables itself and the tick
 * carries on exactly as before.
 */
class PlanetLadder
{
    public const TABLE = 'bot_planet_state';

    /** Idle ticks before rung 1 fires (~1 h of the bot's own day). */
    public const RUNG1_TICKS = 4;

    /** Merchant rules. */
    public const MERCHANT_MIN_METAL = 500_000;   // don't bother below this
    public const MERCHANT_KEEP_METAL = 300_000;  // always leave this much metal
    public const MERCHANT_KEEP_DEUT = 300_000;   // fuel reserve
    public const MERCHANT_RATIO = 3;             // resource must be > RATIO × crystal
    public const METAL_PER_CRYSTAL = 2;          // 2 metal → 1 crystal
    public const DEUT_PER_CRYSTAL = 1;           // 1 deut → 1 crystal

    private ?bool $enabled = null;

    public function __construct(
        private readonly ProductionService $productionService,
    ) {
    }

    /**
     * True once we've confirmed the state table exists.
     */
    public function isEnabled(): bool
    {
        if ($this->enabled === null) {
            $this->enabled = Schema::hasTable(self::TABLE);
        }

        return $this->enabled;
    }

    /**
     * Record this tick for a planet and return its updated state.
     *
     * @return array{idle_ticks: int, rung: int}
     */
    public function recordTick(int $planetId, bool $idle, int $now, bool $dryRun): array
    {
        // Dry-run preview may run before the migration exists — then there is no history to read.
        $row = $this->isEnabled() ? DB::table(self::TABLE)->where('planet_id', $planetId)->first() : null;
        $idleTicks = (int) ($row->idle_ticks ?? 0);
        $rung = (int) ($row->rung ?? 0);

        if ($idle) {
            $idleTicks++;
        } else {
            $idleTicks = 0;
            $rung = 0;
        }

        if (!$dryRun) {
            $values = [
                'idle_ticks' => $idleTicks,
                'rung' => $rung,
                'updated_at' => now(),
            ];
            if (!$idle) {
                $values['last_action_at'] = $now;
            }
            if ($row === null) {
                $values['planet_id'] = $planetId;
                $values['created_at'] = now();
                DB::table(self::TABLE)->insert($values);
            } else {
                DB::table(self::TABLE)->where('planet_id', $planetId)->update($values);
            }
        }

        return ['idle_ticks' => $idleTicks, 'rung' => $rung];
    }

    /**
     * Which rung should fire now, given the state — or 0 for none.
     * A rung fires once per idle streak (rung column = highest applied).
     */
    public function dueRung(array $state): int
    {
        if ($state['idle_ticks'] >= self::RUNG1_TICKS && $state['rung'] < 1) {
            return 1;
        }

        return 0;
    }

    /**
     * Work out the merchant trade for a planet without touching anything.
     *
     * @param  array<string, mixed>  $planet
     * @return array{sell_resource: string, sell: int, crystal_gain: int, note: string}|null
     */
    public function planMerchantTrade(array $planet): ?array
    {
        $metal = (float) ($planet['planet_metal'] ?? 0);
        $crystal = (float) ($planet['planet_crystal'] ?? 0);
        $deut = (float) ($planet['planet_deuterium'] ?? 0);

        $cap = $this->productionService->maxStorable((int) ($planet['building_crystal_store'] ?? 0));
        $room = (int) floor($cap - $crystal);
        if ($room <= 0) {
            return null; // crystal store full — that's rung 2's job
        }

        // Metal → crystal at 2:1
        if ($metal > self::MERCHANT_RATIO * $crystal && $metal > self::MERCHANT_MIN_METAL) {
            $sell = (int) min(
                floor(($metal - self::MERCHANT_KEEP_METAL) / self::METAL_PER_CRYSTAL) * self::METAL_PER_CRYSTAL,
                $room * self::METAL_PER_CRYSTAL
            );
            if ($sell >= self::METAL_PER_CRYSTAL) {
                $gain = intdiv($sell, self::METAL_PER_CRYSTAL);

                return [
                    'sell_resource' => 'planet_metal',
                    'sell' => $sell,
                    'crystal_gain' => $gain,
                    'note' => sprintf('sold %dK metal → %dK crystal', intdiv($sell, 1000), intdiv($gain, 1000)),
                ];
            }
        }

        // Deuterium → crystal at 1:1 (secondary)
        if ($deut > self::MERCHANT_RATIO * $crystal && $deut > self::MERCHANT_MIN_METAL) {
            $sell = (int) min(floor($deut - self::MERCHANT_KEEP_DEUT), $room * self::DEUT_PER_CRYSTAL);
            if ($sell >= 1) {
                $gain = intdiv($sell, self::DEUT_PER_CRYSTAL);

                return [
                    'sell_resource' => 'planet_deuterium',
                    'sell' => $sell,
                    'crystal_gain' => $gain,
                    'note' => sprintf('sold %dK deut → %dK crystal', intdiv($sell, 1000), intdiv($gain, 1000)),
                ];
            }
        }

        return null;
    }

    /**
     * Apply a planned trade: DB update + in-memory planet array, and mark the rung.
     * Not called under dry-run.
     *
     * @param  array<string, mixed>  $planet  updated in place
     * @param  array{sell_resource: string, sell: int, crystal_gain: int, note: string}  $trade
     */
    public function applyMerchantTrade(array &$planet, array $trade, int $now): void
    {
        DB::table('planets')
            ->where('planet_id', (int) $planet['planet_id'])
            ->update([
                $trade['sell_resource'] => DB::raw($trade['sell_resource'] . ' - ' . $trade['sell']),
                'planet_crystal' => DB::raw('planet_crystal + ' . $trade['crystal_gain']),
            ]);

        $planet[$trade['sell_resource']] = (float) $planet[$trade['sell_resource']] - $trade['sell'];
        $planet['planet_crystal'] = (float) $planet['planet_crystal'] + $trade['crystal_gain'];

        $this->markRung((int) $planet['planet_id'], 1, $trade['note'], $now);
    }

    /**
     * Remember that a rung fired on this planet.
     */
    public function markRung(int $planetId, int $rung, string $note, int $now): void
    {
        DB::table(self::TABLE)
            ->where('planet_id', $planetId)
            ->update([
                'rung' => $rung,
                'last_rung_at' => $now,
                'note' => mb_substr($note, 0, 120),
                'updated_at' => now(),
            ]);
    }
}

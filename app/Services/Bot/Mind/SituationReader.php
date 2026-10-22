<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind;

use App\Core\GameObjects\GameObjectRegistry;
use App\Services\Bot\BotBrain;
use App\Services\Bot\BotSpeed;
use App\Services\Bot\ColonizationService;
use App\Services\Bot\FleetDispatcher;
use App\Services\Game\Formulas\ProductionService;
use Illuminate\Support\Facades\DB;

/**
 * Builds a bot's Situation from what its player could see (Dale's rule, 30 Sep 2026: bots can't read
 * players or each other). Own rows, own battle reports (bot_combat_log rows where the bot fought), the
 * galaxy view's debris fields near its planets, and its own public highscore line.
 */
final class SituationReader
{
    /** Systems either side of each planet the bot looks at in the galaxy view for debris (x1). */
    private const DEBRIS_RANGE = 8;

    /** Fields worth less than this (metal + crystal) are ignored. */
    private const MIN_DEBRIS = 3000;

    /** History window in x1 hours. */
    private const WINDOW_X1_HOURS = 48.0;

    /** Warships and defences counted as military. */
    public const MILITARY = [204, 205, 206, 207, 211, 213, 214, 215, 401, 402, 403, 404, 405, 406, 407, 408];

    public function __construct(
        private readonly GameObjectRegistry $registry,
        private readonly ProductionService $production,
        private readonly ColonizationService $colonizer,
        private readonly FleetDispatcher $dispatcher,
        private readonly BotBrain $brain,
        private readonly Memory $memory,
    ) {
    }

    /**
     * What our expeditions and recyclers bring home: each returning fleet (fleet_mess 1) carrying resources
     * is written in the notebook once, then the window's total becomes an income per real hour.
     */
    private function returns(Situation $s): void
    {
        $seen = $s->memory['returns'] ?? [];
        $ids = array_flip(array_map(fn ($r) => (int) $r[2], $seen));
        foreach (DB::table('fleets')->where('fleet_owner', $s->botId)->whereIn('fleet_mission', [8, 15])->where('fleet_mess', 1)
            ->get(['fleet_id', 'fleet_mission', 'fleet_resource_metal', 'fleet_resource_crystal', 'fleet_resource_deuterium']) as $f) {
            if (isset($ids[(int) $f->fleet_id])) {
                continue;
            }
            $worth = $s->worthOf(['metal' => (float) $f->fleet_resource_metal, 'crystal' => (float) $f->fleet_resource_crystal, 'deuterium' => (float) $f->fleet_resource_deuterium]);
            $seen[] = [time(), (int) $f->fleet_mission, (int) $f->fleet_id, round($worth)];
        }
        $seen = Memory::prune($seen, (int) $s->windowSeconds);
        $s->memory['returns'] = $seen;
        $windowHours = max(0.001, $s->windowSeconds / 3600.0);
        foreach ($seen as [$t, $mission, $id, $worth]) {
            if ($mission === 15) {
                $s->expeditionIncome += $worth / $windowHours;
            } else {
                $s->harvestIncome += $worth / $windowHours;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $user
     * @param  array<int, object|array<string, mixed>>  $planetRows
     * @param  array<int, int>  $flying  ship id => count in flight
     */
    public function read(int $botId, array $user, array $planetRows, int $mainId, array $flying): Situation
    {
        $s = new Situation();
        $s->botId = $botId;
        $s->user = $user;
        $s->mainId = $mainId;
        $s->h1 = BotSpeed::x1Hours(1.0);
        $s->windowSeconds = self::WINDOW_X1_HOURS * $s->h1 * 3600.0;

        $caps = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];
        $s->owned = $flying;
        foreach ($planetRows as $row) {
            $p = (array) $row;
            foreach ($this->registry->ships() as $id => $ship) {
                $s->owned[(int) $id] = ($s->owned[(int) $id] ?? 0) + (int) ($p[$ship->getName()] ?? 0);
            }
            foreach ($this->brain->parseHangarQueue((string) ($p['planet_b_hangar_id'] ?? '')) as $id => $n) {
                $s->owned[$id] = ($s->owned[$id] ?? 0) + $n;
            }
            if ((int) ($p['planet_type'] ?? 1) !== 1) {
                continue;
            }
            $s->planets[(int) $p['planet_id']] = $p;
            foreach (Situation::RES as $r) {
                $s->income[$r] += max(0.0, (float) ($p["planet_{$r}_perhour"] ?? 0));
                $s->stock[$r] += (float) ($p["planet_{$r}"] ?? 0);
            }
            $caps['metal'] += $this->production->maxStorable((int) ($p['building_metal_store'] ?? 0));
            $caps['crystal'] += $this->production->maxStorable((int) ($p['building_crystal_store'] ?? 0));
            $caps['deuterium'] += $this->production->maxStorable((int) ($p['building_deuterium_tank'] ?? 0));
        }
        if (!isset($s->planets[$mainId])) {
            $s->mainId = (int) array_key_first($s->planets);
        }
        $s->colonies = max(0, count($s->planets) - 1);

        self::worth($s, $caps);
        $s->memory = $this->memory->load($botId);
        $this->military($s, $planetRows, $flying);
        $this->history($s);
        $this->returns($s);
        $this->debris($s);

        $astro = (int) ($user['research_astrophysics'] ?? 0);
        $s->colonySlots = $astro >= 1 ? $this->colonizer->colonySlotsFree($botId, $astro) : 0;
        $s->colonyFlying = (int) DB::table('fleets')->where('fleet_owner', $botId)->where('fleet_mission', 7)->where('fleet_mess', 0)->count();
        $s->fleetSlots = \Xgp\App\Libraries\FleetsLib::getMaxFleets(
            (int) ($user['research_computer_technology'] ?? 0),
            (int) ($user['premium_officier_admiral'] ?? 0)
        );
        $s->fleetsOut = $this->dispatcher->countActiveFleets($botId);

        return $s;
    }

    /**
     * What a resource is worth to this bot right now: the usual 3:2:1 trade value, raised for a resource
     * the bot holds few hours of income of and lowered for one it is drowning in (a full store is worth
     * little: every extra unit is thrown away). This is what makes a crystal-starved bot build crystal.
     *
     * @param  array<string, float>  $caps
     */
    public static function worth(Situation $s, array $caps): void
    {
        $base = ['metal' => 1.0, 'crystal' => 1.5, 'deuterium' => 3.0];
        $hours = [];
        foreach (Situation::RES as $r) {
            $hours[$r] = $s->stock[$r] / max(1.0, $s->income[$r]);
        }
        $sorted = array_values($hours);
        sort($sorted);
        $median = max(0.01, $sorted[1]);
        foreach (Situation::RES as $r) {
            $scarcity = sqrt($median / max(0.01, $hours[$r]));
            $scarcity = max(0.6, min(2.0, $scarcity));
            if ($caps[$r] > 0 && $s->stock[$r] >= 0.9 * $caps[$r]) {
                $scarcity *= 0.6;
            }
            $s->worth[$r] = $base[$r] * $scarcity;
        }
    }

    /**
     * @param  array<int, object|array<string, mixed>>  $planetRows
     * @param  array<int, int>  $flying
     */
    private function military(Situation $s, array $planetRows, array $flying): void
    {
        $worth = 0.0;
        foreach (self::MILITARY as $id) {
            if (!$this->registry->has($id)) {
                continue;
            }
            $object = $this->registry->get($id);
            $count = (int) ($flying[$id] ?? 0);
            foreach ($planetRows as $row) {
                $count += (int) (((array) $row)[$object->getName()] ?? 0);
            }
            if ($count > 0) {
                $p = $object->getPrice();
                $worth += $count * $s->worthOf(['metal' => $p->getMetal(), 'crystal' => $p->getCrystal(), 'deuterium' => $p->getDeuterium()]);
            }
        }
        $s->militaryWorth = $worth;
        $s->totalPoints = (float) (DB::table('users_statistics')->where('user_statistic_user_id', $s->botId)->value('user_statistic_total_points') ?? 0);
        $s->militaryRatio = $s->totalPoints > 0 ? ($worth / 1000) / $s->totalPoints : 0.0;
    }

    /** The bot's own battle reports inside the window. */
    private function history(Situation $s): void
    {
        $since = date('Y-m-d H:i:s', time() - (int) $s->windowSeconds);
        $rows = DB::table('bot_combat_log')
            ->where('created_at', '>', $since)
            ->where(fn ($q) => $q->where('attacker_id', $s->botId)->orWhere('defender_id', $s->botId))
            ->get(['attacker_id', 'defender_id', 'result', 'defender_ships_lost', 'defender_fleet', 'attacker_fleet', 'attacker_ships_lost', 'loot_metal', 'loot_crystal', 'loot_deuterium', 'debris_metal', 'debris_crystal']);

        $debris = 0.0;
        $raidLoot = 0.0;
        $attackLoot = 0.0;
        $attackDebris = 0.0;
        foreach ($rows as $row) {
            $loot = $s->worthOf(['metal' => (float) $row->loot_metal, 'crystal' => (float) $row->loot_crystal, 'deuterium' => (float) $row->loot_deuterium]);
            if ((int) $row->defender_id === $s->botId) {
                $s->hits++;
                $s->lostWorth += $loot + (int) $row->defender_ships_lost * $this->avgUnitWorth($s, (string) $row->defender_fleet);
                $debris += (float) $row->debris_metal * $s->worth['metal'] + (float) $row->debris_crystal * $s->worth['crystal'];
            } else {
                $s->raidsSent++;
                if ($row->result === 'win') {
                    $s->raidWins++;
                }
                $s->lootWorth += $loot;
                // a fleet with warships in it is an attack, cargos alone a raid; what we lost counts against it
                $fleet = json_decode((string) $row->attacker_fleet, true) ?: [];
                $warships = array_intersect(array_map('intval', array_keys($fleet)), [204, 205, 206, 207, 211, 213, 214, 215]);
                $lost = (int) $row->attacker_ships_lost * $this->avgUnitWorth($s, (string) $row->attacker_fleet);
                if ($warships !== []) {
                    $attackLoot += $loot - $lost;
                } else {
                    $raidLoot += $loot - $lost;
                }
                // the debris our own attacks leave at the target is ours to collect if we bring recyclers
                $attackDebris += (float) $row->debris_metal * $s->worth['metal'] + (float) $row->debris_crystal * $s->worth['crystal'];
            }
        }
        $windowHours = $s->windowSeconds / 3600.0;
        $s->debrisRate = $windowHours > 0 ? $debris / $windowHours : 0.0;
        $s->raidIncome = $windowHours > 0 ? max(0.0, $raidLoot) / $windowHours : 0.0;
        $s->attackIncome = $windowHours > 0 ? max(0.0, $attackLoot) / $windowHours : 0.0;
        $s->lossRate = $windowHours > 0 ? $s->lostWorth / $windowHours : 0.0;
        $s->attackDebrisRate = $windowHours > 0 ? $attackDebris / $windowHours : 0.0;
        // one hit every 8 x1 hours (6 in the window) = fully threatened
        $s->threat = min(1.0, $s->hits / 6.0);
    }

    private function avgUnitWorth(Situation $s, string $fleetJson): float
    {
        $fleet = json_decode($fleetJson, true);
        $n = 0;
        $sum = 0.0;
        foreach (is_array($fleet) ? $fleet : [] as $id => $count) {
            if (!$this->registry->has((int) $id)) {
                continue;
            }
            $p = $this->registry->get((int) $id)->getPrice();
            $sum += (int) $count * $s->worthOf(['metal' => $p->getMetal(), 'crystal' => $p->getCrystal(), 'deuterium' => $p->getDeuterium()]);
            $n += (int) $count;
        }

        return $n > 0 ? $sum / $n : 2500.0;
    }

    /** Debris fields in the galaxy view around the bot's planets (and on them). */
    private function debris(Situation $s): void
    {
        $seen = [];
        $range = self::DEBRIS_RANGE;
        foreach ($s->planets as $p) {
            $g = (int) $p['planet_galaxy'];
            $sys = (int) $p['planet_system'];
            $rows = DB::select(
                'SELECT planet_galaxy g, planet_system s, planet_planet p, planet_user_id u, planet_debris_metal m, planet_debris_crystal c
                 FROM planets WHERE planet_galaxy = ? AND planet_system BETWEEN ? AND ? AND planet_type = 1
                   AND planet_debris_metal + planet_debris_crystal >= ?',
                [$g, $sys - $range, $sys + $range, self::MIN_DEBRIS]
            );
            foreach ($rows as $r) {
                $key = "{$r->g}:{$r->s}:{$r->p}";
                $distance = abs((int) $r->s - $sys);
                if (isset($seen[$key]) && $seen[$key]['distance'] <= $distance) {
                    continue;
                }
                $seen[$key] = [
                    'galaxy' => (int) $r->g, 'system' => (int) $r->s, 'planet' => (int) $r->p,
                    'metal' => (int) $r->m, 'crystal' => (int) $r->c,
                    'own' => (int) $r->u === $s->botId, 'distance' => $distance,
                ];
            }
        }
        // fields our own recyclers are already flying to are spoken for
        foreach (DB::table('fleets')->where('fleet_owner', $s->botId)->where('fleet_mission', 8)->where('fleet_mess', 0)
            ->get(['fleet_end_galaxy', 'fleet_end_system', 'fleet_end_planet']) as $f) {
            unset($seen["{$f->fleet_end_galaxy}:{$f->fleet_end_system}:{$f->fleet_end_planet}"]);
        }

        $s->debris = array_values($seen);
        usort($s->debris, fn ($a, $b) => [$b['own'], $b['metal'] + $b['crystal']] <=> [$a['own'], $a['metal'] + $a['crystal']]);
        foreach ($s->debris as $d) {
            // someone else's field may be gone before we get there
            $s->debrisWorth += ($d['own'] ? 1.0 : 0.4) * ($d['metal'] * $s->worth['metal'] + $d['crystal'] * $s->worth['crystal']);
        }
    }
}

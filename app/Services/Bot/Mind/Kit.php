<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind;

use App\Core\GameObjects\GameObjectRegistry;
use App\Services\Bot\BotSpeed;
use App\Services\Game\Formulas\DevelopmentsService;
use App\Services\Game\Formulas\ProductionService;

/**
 * The game knowledge every advisor shares: levels, prices, requirements, production and energy formulas,
 * and the chain resolver ("I want X: what don't I have yet, and what is the next step?").
 */
final class Kit
{
    public const METAL = 1;
    public const CRYSTAL = 2;
    public const DEUT = 3;
    public const SOLAR = 4;
    public const FUSION = 12;
    public const ROBOT = 14;
    public const NANITE = 15;
    public const YARD = 21;
    public const STORE = ['metal' => 22, 'crystal' => 23, 'deuterium' => 24];
    public const LAB = 31;
    public const TERRAFORMER = 33;

    /** @var array<int, string> */
    public array $col = [];

    public function __construct(
        public readonly GameObjectRegistry $registry,
        public readonly DevelopmentsService $developments,
        public readonly ProductionService $production,
    ) {
        foreach ($this->registry->all() as $id => $o) {
            $this->col[(int) $id] = $o->getName();
        }
    }

    public function name(int $id): string
    {
        return preg_replace('/^(building|research|ship|defense)_/', '', $this->col[$id] ?? (string) $id);
    }

    public function level(int $id, array $planet, array $user): int
    {
        $column = $this->col[$id] ?? '';

        return $this->registry->research()->has($id) ? (int) ($user[$column] ?? 0) : (int) ($planet[$column] ?? 0);
    }

    public function isResearch(int $id): bool
    {
        return $this->registry->research()->has($id);
    }

    public function isUnit(int $id): bool
    {
        return $this->registry->ships()->has($id) || $this->registry->defenses()->has($id);
    }

    /** @return array<string, float> */
    public function price(int $id, int $level): array
    {
        $p = $this->developments->developmentPrice($id, $level);

        return ['metal' => (float) ($p['metal'] ?? 0), 'crystal' => (float) ($p['crystal'] ?? 0), 'deuterium' => (float) ($p['deuterium'] ?? 0)];
    }

    /** @return array<string, float> */
    public function unitPrice(int $id): array
    {
        $p = $this->registry->get($id)->getPrice();

        return ['metal' => (float) $p->getMetal(), 'crystal' => (float) $p->getCrystal(), 'deuterium' => (float) $p->getDeuterium()];
    }

    public function allowed(int $id, array $planet, array $user): bool
    {
        foreach ($this->registry->get($id)->getRequirements() as $reqId => $reqLevel) {
            if ($this->level((int) $reqId, $planet, $user) < (int) $reqLevel) {
                return false;
            }
        }

        return true;
    }

    public function fieldsFree(array $planet): bool
    {
        $max = $this->developments->maxFields((int) ($planet['planet_field_max'] ?? 0), (int) ($planet['building_terraformer'] ?? 0));

        return (int) ($planet['planet_field_current'] ?? 0) < $max;
    }

    public function storeCap(string $res, array $planet): float
    {
        return (float) $this->production->maxStorable((int) ($planet[$this->col[self::STORE[$res]]] ?? 0));
    }

    /** Production of a mine at a level, per x1 hour at 100 %. */
    public function prod(int $id, int $level, array $planet): float
    {
        if ($level <= 0) {
            return 0.0;
        }
        $f = $this->registry->get($id)->getProduction();

        return match ($id) {
            self::METAL => $f->calculateMetal($level, 10),
            self::CRYSTAL => $f->calculateCrystal($level, 10),
            default => $f->calculateDeuterium($level, 10, (float) ($planet['planet_temp_max'] ?? 0)),
        };
    }

    public function energy(int $id, int $level, array $planet, array $user): float
    {
        if ($level <= 0) {
            return 0.0;
        }

        return abs($this->registry->get($id)->getProduction()->calculateEnergy($level, 10, (float) ($planet['planet_temp_max'] ?? 0), (int) ($user['research_energy_technology'] ?? 0)));
    }

    public function productionPercent(array $planet): int
    {
        return $this->production->maxProductionPercentage((int) ($planet['planet_energy_max'] ?? 0), (int) ($planet['planet_energy_used'] ?? 0));
    }

    /** @return array<string, float> per real hour */
    public function planetIncome(array $planet): array
    {
        return [
            'metal' => max(1.0, (float) ($planet['planet_metal_perhour'] ?? 0)),
            'crystal' => max(1.0, (float) ($planet['planet_crystal_perhour'] ?? 0)),
            'deuterium' => max(1.0, (float) ($planet['planet_deuterium_perhour'] ?? 0)),
        ];
    }

    public function planetIncomeWorth(Situation $s, array $planet): float
    {
        $w = 0.0;
        foreach ($this->planetIncome($planet) as $r => $v) {
            $w += $v * $s->worth[$r];
        }

        return $w;
    }

    /** Combat worth per metal-worth spent: sqrt(attack x (shield + hull)), hull = (metal + crystal) / 10. */
    public function efficiency(Situation $s, int $id): float
    {
        $o = $this->registry->get($id);
        $p = $o->getPrice();
        $hull = ($p->getMetal() + $p->getCrystal()) / 10;

        return sqrt(max(0.0, $o->getAttack()) * (max(0.0, $o->getShield()) + $hull)) / max(1.0, $s->worthOf($this->unitPrice($id)));
    }

    /**
     * Solar Plant or (when allowed) Fusion: whichever buys energy cheaper, fusion's deuterium burn included.
     *
     * @return array<string, mixed>|null resolved step + 'energy'
     */
    public function bestPower(Situation $s, array $planet): ?array
    {
        $best = null;
        $bestPerEnergy = PHP_FLOAT_MAX;
        foreach ([self::SOLAR, self::FUSION] as $id) {
            if ($id === self::FUSION && !$this->allowed($id, $planet, $s->user)) {
                continue;
            }
            $level = $this->level($id, $planet, $s->user);
            $c = $this->resolve($s, $planet, $id, $level + 1);
            if ($c === null) {
                continue;
            }
            $f = $this->registry->get($id)->getProduction();
            $temp = (float) ($planet['planet_temp_max'] ?? 0);
            $tech = (int) ($s->user['research_energy_technology'] ?? 0);
            $gain = $f->calculateEnergy($level + 1, 10, $temp, $tech) - $f->calculateEnergy($level, 10, $temp, $tech);
            if ($gain <= 0) {
                continue;
            }
            $worth = $s->worthOf($c['chain']);
            if ($id === self::FUSION) {
                // deuterium burnt over ~5 days counts against it
                $burn = abs($f->calculateDeuterium($level + 1, 10, $temp) - $f->calculateDeuterium($level, 10, $temp)) * BotSpeed::multiplier();
                $worth += $burn * $s->worth['deuterium'] * 120 * $s->h1;
            }
            if ($worth / $gain < $bestPerEnergy) {
                $bestPerEnergy = $worth / $gain;
                $best = $c + ['energy' => $gain];
            }
        }

        return $best;
    }

    // ─── Chains ─────────────────────────────────────────────────────────────

    /**
     * Next concrete step towards $id at $level on this planet, with the full chain cost (every missing
     * prerequisite, every level in between, and the store a step needs to fit).
     *
     * @return array{lane: string, id: int, level: int, cost: array<string, float>, chain: array<string, float>, count: int, unit_cost: array<string, float>}|null
     */
    public function resolve(Situation $s, array $planet, int $id, int $level): ?array
    {
        $steps = [];
        if (!$this->collect($s, $planet, $id, $level, $steps, 0) || $steps === []) {
            return null;
        }
        $c = $this->finish($s, $planet, $steps);
        if ($c !== null) {
            $c['count'] = 1;
            $c['unit_cost'] = $c['cost'];
        }

        return $c;
    }

    /** Units: the chain to unlock it plus $count of it; the next step is the unit itself once allowed. */
    public function resolveUnit(Situation $s, array $planet, int $id, int $count): ?array
    {
        if ($count <= 0) {
            return null;
        }
        $steps = [];
        foreach ($this->registry->get($id)->getRequirements() as $reqId => $reqLevel) {
            if (!$this->collect($s, $planet, (int) $reqId, (int) $reqLevel, $steps, 0)) {
                return null;
            }
        }
        $unit = $this->unitPrice($id);
        $steps[] = ['lane' => 's', 'id' => $id, 'level' => 0, 'cost' => $unit, 'count' => $count];
        $c = $this->finish($s, $planet, $steps);
        if ($c === null) {
            return null;
        }
        $c['count'] = $c['lane'] === 's' ? $count : 1;
        $c['unit_cost'] = $c['lane'] === 's' ? $unit : $c['cost'];

        return $c;
    }

    /** @param list<array<string, mixed>> $steps */
    private function finish(Situation $s, array $planet, array $steps): ?array
    {
        $chain = ['metal' => 0.0, 'crystal' => 0.0, 'deuterium' => 0.0];
        foreach ($steps as $st) {
            $chain = $this->add($chain, $st['cost'], $st['count'] ?? 1);
        }
        $first = $steps[0];
        if ($first['lane'] === 'r' && (int) $planet['planet_id'] !== $s->mainId) {
            return null; // research happens on the research planet
        }
        if ($first['lane'] === 'b' && !$this->fieldsFree($planet)) {
            return null;
        }
        // a step costing more than its store holds: that store first (one level per tick)
        foreach (self::STORE as $res => $storeId) {
            if ($first['cost'][$res] > $this->storeCap($res, $planet)) {
                if (!$this->fieldsFree($planet)) {
                    return null;
                }
                $storeLevel = $this->level($storeId, $planet, $s->user) + 1;
                $storeCost = $this->price($storeId, $storeLevel - 1);
                $chain = $this->add($chain, $storeCost);
                $first = ['lane' => 'b', 'id' => $storeId, 'level' => $storeLevel, 'cost' => $storeCost];
                break;
            }
        }

        return ['lane' => $first['lane'], 'id' => $first['id'], 'level' => $first['level'], 'cost' => $first['cost'], 'chain' => $chain];
    }

    /** @param list<array<string, mixed>> $steps */
    private function collect(Situation $s, array $planet, int $id, int $level, array &$steps, int $depth): bool
    {
        if ($depth > 12 || !$this->registry->has($id)) {
            return false;
        }
        $have = $this->level($id, $planet, $s->user);
        foreach ($steps as $st) {
            if ($st['id'] === $id) {
                $have = max($have, $st['level']);
            }
        }
        if ($have >= $level) {
            return true;
        }
        foreach ($this->registry->get($id)->getRequirements() as $reqId => $reqLevel) {
            if (!$this->collect($s, $planet, (int) $reqId, (int) $reqLevel, $steps, $depth + 1)) {
                return false;
            }
        }
        $lane = $this->isResearch($id) ? 'r' : 'b';
        for ($l = $have + 1; $l <= $level; $l++) {
            $steps[] = ['lane' => $lane, 'id' => $id, 'level' => $l, 'cost' => $this->price($id, $l - 1)];
        }

        return true;
    }

    /**
     * @param  array<string, float>  $a
     * @param  array<string, float>  $b
     * @return array<string, float>
     */
    public function add(array $a, array $b, int $n = 1): array
    {
        foreach (Situation::RES as $r) {
            $a[$r] = (float) ($a[$r] ?? 0) + (float) ($b[$r] ?? 0) * $n;
        }

        return $a;
    }

    /** A proposal: a resolved step plus who proposes it, why, and what it is worth per real hour. */
    public static function proposal(array $resolved, string $advisor, string $why, float $value): array
    {
        return $resolved + ['advisor' => $advisor, 'label' => $why, 'value' => $value];
    }
}

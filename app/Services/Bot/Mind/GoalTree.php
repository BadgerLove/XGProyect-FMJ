<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind;

/**
 * Where a bot is in its game and what comes next (Dale, 21 Oct: "Right, I'm at this stage. I can go this
 * way or this way" - and never "I got to my goal, I'm done"). Six stages, each a set of milestones. The
 * bot's stage is the first one with milestones still open; every open milestone of that stage becomes a
 * proposal (resolved backwards through everything it still lacks), so there is always a direction. When
 * the last stage is done the bot keeps growing on the advisors' own values.
 *
 * Milestone forms: ['b', id, level] building on the main planet, ['r', id, level] research,
 * ['colonies', n], ['u', id, count] units owned. Each carries the trait it appeals to.
 */
final class GoalTree
{
    public const STAGES = [
        0 => ['name' => 'start', 'goals' => [
            ['b', 1, 8, 'greed'], ['b', 2, 6, 'greed'], ['b', 3, 4, 'greed'], ['b', 14, 2, 'greed'], ['b', 21, 2, 'war'],
            ['b', 31, 1, 'science'], ['r', 113, 1, 'science'], ['r', 115, 2, 'raid'], ['u', 202, 2, 'raid'], ['u', 210, 1, 'raid'],
        ]],
        1 => ['name' => 'early', 'goals' => [
            ['b', 1, 12, 'greed'], ['b', 2, 10, 'greed'], ['b', 3, 8, 'greed'], ['b', 23, 2, 'greed'], ['r', 117, 3, 'expand'],
            ['r', 106, 4, 'raid'], ['r', 124, 1, 'expand'], ['colonies', 1, 'expand'], ['u', 210, 3, 'raid'], ['u', 401, 5, 'guard'],
            // the first recycler (Lab 6, Energy 3, Shielding 2, Combustion 6): debris is income (Dale, 21 Oct)
            ['u', 209, 1, 'greed'],
        ]],
        2 => ['name' => 'growing', 'goals' => [
            ['r', 115, 6, 'raid'], ['r', 110, 2, 'science'], ['u', 209, 2, 'greed'], ['u', 203, 3, 'raid'], ['b', 14, 5, 'greed'],
            ['r', 124, 3, 'expand'], ['colonies', 2, 'expand'], ['r', 108, 4, 'raid'], ['b', 31, 6, 'science'], ['u', 204, 20, 'war'],
            ['b', 23, 4, 'greed'],
        ]],
        3 => ['name' => 'mid', 'goals' => [
            ['b', 1, 18, 'greed'], ['b', 2, 15, 'greed'], ['b', 3, 13, 'greed'], ['r', 117, 4, 'war'], ['r', 121, 2, 'war'],
            ['u', 206, 10, 'war'], ['r', 124, 5, 'expand'], ['colonies', 3, 'expand'], ['r', 109, 4, 'war'], ['r', 110, 4, 'guard'],
            ['r', 111, 4, 'war'], ['b', 21, 7, 'war'], ['b', 12, 1, 'science'], ['u', 402, 20, 'guard'],
        ]],
        4 => ['name' => 'late', 'goals' => [
            ['r', 118, 4, 'war'], ['u', 207, 10, 'war'], ['r', 124, 7, 'expand'], ['colonies', 4, 'expand'], ['b', 15, 1, 'greed'],
            ['r', 122, 4, 'science'], ['b', 1, 24, 'greed'], ['b', 2, 20, 'greed'], ['b', 3, 18, 'greed'], ['u', 405, 10, 'guard'],
            ['r', 108, 8, 'raid'],
        ]],
        5 => ['name' => 'end', 'goals' => [
            ['r', 114, 8, 'science'], ['u', 213, 10, 'war'], ['r', 124, 11, 'expand'], ['colonies', 6, 'expand'], ['b', 33, 3, 'greed'],
            ['u', 215, 10, 'war'], ['r', 122, 8, 'science'], // Graviton/Deathstar need energy, not resources: later
        ]],
    ];

    /** Value of a milestone, as a share of the bot's whole income per hour (rises with the stage). */
    private const WEIGHT = 0.03;

    public function __construct(private readonly Kit $kit)
    {
    }

    /** Sets $s->stage and $s->openMilestones from the main planet. */
    public function locate(Situation $s): void
    {
        $main = $s->mainPlanet();
        foreach (self::STAGES as $i => $stage) {
            $open = [];
            foreach ($stage['goals'] as $g) {
                if (!$this->done($g, $s, $main)) {
                    $open[] = $this->label($g);
                }
            }
            if ($open !== []) {
                $s->stage = $i;
                $s->openMilestones = $open;

                return;
            }
        }
        $s->stage = count(self::STAGES) - 1;
        $s->openMilestones = [];
    }

    /** Proposals for the open milestones of the bot's stage (main planet). */
    public function propose(Situation $s, Personality $p, array $planet): array
    {
        $out = [];
        $k = $this->kit;
        $income = $s->incomeWorth();
        foreach (self::STAGES[$s->stage]['goals'] as $g) {
            if ($this->done($g, $s, $planet)) {
                continue;
            }
            $trait = (string) end($g);
            $value = self::WEIGHT * (1.0 + 0.25 * $s->stage) * $income * (0.5 + $p->$trait);
            $c = match ($g[0]) {
                'b', 'r' => $k->resolve($s, $planet, (int) $g[1], (int) $g[2]),
                'u' => $k->resolveUnit($s, $planet, (int) $g[1], max(1, (int) $g[2] - (int) ($s->owned[(int) $g[1]] ?? 0) - $this->defenceCount($s, (int) $g[1]))),
                default => null, // colonies come from the expansion advisor; the milestone only pushes its value up
            };
            if ($c !== null) {
                $out[] = Kit::proposal($c, 'goal', 'stage ' . self::STAGES[$s->stage]['name'] . ': ' . $this->label($g), $value);
            }
        }

        return $out;
    }

    /** True when the stage's colonies milestone is still open (the expansion advisor gets a push). */
    public function wantsColonies(Situation $s): bool
    {
        foreach (self::STAGES[$s->stage]['goals'] as $g) {
            if ($g[0] === 'colonies' && $s->colonies < (int) $g[1]) {
                return true;
            }
        }

        return false;
    }

    private function done(array $g, Situation $s, array $planet): bool
    {
        return match ($g[0]) {
            'b', 'r' => $this->kit->level((int) $g[1], $planet, $s->user) >= (int) $g[2],
            'colonies' => $s->colonies + $s->colonyFlying >= (int) $g[1],
            'u' => (int) ($s->owned[(int) $g[1]] ?? 0) + $this->defenceCount($s, (int) $g[1]) >= (int) $g[2],
            default => true,
        };
    }

    private function defenceCount(Situation $s, int $id): int
    {
        if ($id < 400) {
            return 0;
        }
        $n = 0;
        foreach ($s->planets as $pl) {
            $n += (int) ($pl[$this->kit->col[$id]] ?? 0);
        }

        return $n;
    }

    private function label(array $g): string
    {
        return match ($g[0]) {
            'b', 'r' => $this->kit->name((int) $g[1]) . ' ' . $g[2],
            'colonies' => "{$g[1]} colonies",
            'u' => "{$g[2]} " . $this->kit->name((int) $g[1]),
            default => '?',
        };
    }
}

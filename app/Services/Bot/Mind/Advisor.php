<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind;

/**
 * One member of a bot's council. It looks at one area of the game and proposes what to do there, each
 * proposal with a reason and a value in metal-worth per real hour (what it earns or protects). The
 * General compares every advisor's proposals on that one scale.
 */
interface Advisor
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $planet  fresh planet row
     * @param  array{main: bool, best_mine: float}  $ctx
     * @return list<array<string, mixed>>  Kit::proposal() rows
     */
    public function propose(Situation $s, Personality $p, array $planet, array $ctx): array;
}

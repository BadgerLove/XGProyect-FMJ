<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind\Advisors;

use App\Services\Bot\ColonizationService;
use App\Services\Bot\Mind\Advisor;
use App\Services\Bot\Mind\Kit;
use App\Services\Bot\Mind\Personality;
use App\Services\Bot\Mind\Situation;
use Xgp\App\Libraries\FleetsLib;

/**
 * Colonies: a second (third, ...) economy. A young colony is worth roughly half of the main planet's
 * income once it grows. Colony ship while Astrophysics allows another colony, else the Astrophysics
 * level that does. Main planet only (the colony ship is built where the shipyard is).
 */
final class ExpansionAdvisor implements Advisor
{
    private const ASTRO = 124;
    private const COLONY_SHIP = 208;

    public function __construct(private readonly Kit $kit)
    {
    }

    public function name(): string
    {
        return 'expansion';
    }

    public function propose(Situation $s, Personality $p, array $planet, array $ctx): array
    {
        if (!$ctx['main'] || $s->colonies + $s->colonyFlying >= ColonizationService::MAX_COLONIES) {
            return [];
        }
        $k = $this->kit;
        $value = (0.25 + 0.5 * $p->expand) * 0.5 * $k->planetIncomeWorth($s, $planet);
        $ships = (int) ($s->owned[self::COLONY_SHIP] ?? 0);

        if ($s->colonySlots > $ships) {
            $c = $k->resolveUnit($s, $planet, self::COLONY_SHIP, 1);

            return $c !== null ? [Kit::proposal($c, 'expansion', 'new colony', $value)] : [];
        }
        if ($ships > 0) {
            return []; // one is waiting to fly
        }
        $astro = $k->level(self::ASTRO, $planet, $s->user);
        $next = $astro + 1;
        while ($next <= 15 && FleetsLib::getMaxColonies($next) <= FleetsLib::getMaxColonies($astro)) {
            $next++;
        }
        $c = $k->resolve($s, $planet, self::ASTRO, $next);
        if ($c === null) {
            return [];
        }
        $c['chain'] = $k->add($c['chain'], $k->unitPrice(self::COLONY_SHIP));

        return [Kit::proposal($c, 'expansion', 'new colony (Astrophysics ' . $next . ')', $value)];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind\Advisors;

use App\Services\Bot\Mind\Advisor;
use App\Services\Bot\Mind\Kit;
use App\Services\Bot\Mind\Personality;
use App\Services\Bot\Mind\Situation;

/**
 * Eyes: espionage probes for the attack phase (three per spy mission), more for bots that attack or
 * are being attacked (who is hitting us, and with what).
 */
final class ScoutAdvisor implements Advisor
{
    private const PROBE = 210;

    public function __construct(private readonly Kit $kit)
    {
    }

    public function name(): string
    {
        return 'scout';
    }

    public function propose(Situation $s, Personality $p, array $planet, array $ctx): array
    {
        if (!$ctx['main']) {
            return [];
        }
        $spy = max($p->raid, $p->war, 0.5 * $s->threat);
        $want = $spy >= 0.3 ? 3 + (int) round(6 * $spy) : 1;
        $have = (int) ($s->owned[self::PROBE] ?? 0);
        if ($have >= $want) {
            return [];
        }
        $value = 0.02 * $spy * $s->incomeWorth() + 0.1 * $s->attackIncome;
        $c = $this->kit->resolveUnit($s, $planet, self::PROBE, $want - $have);

        return $c !== null ? [Kit::proposal($c, 'scout', 'probes', $value)] : [];
    }
}

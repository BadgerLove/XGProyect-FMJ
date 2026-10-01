<?php

declare(strict_types=1);

namespace App\Services\Game\Formulas;

class OfficerService
{
    /**
     * Officers "for ever" (Dale, 2026-10-01: every player and bot has all officers, no expiry).
     * The expiry columns are signed INT, so this is the latest time they can hold (2038-01-19).
     */
    public const PERMANENT = 2147483647;

    public function isPermanent(int $expireTime): bool
    {
        return $expireTime >= self::PERMANENT;
    }

    public function isOfficerActive(int $expireTime, int $currentTime): bool
    {
        return $expireTime > $currentTime;
    }

    public function getMaxEspionage(int $espionageTech, bool $technocrateActive): int
    {
        return $espionageTech + ($technocrateActive ? TECHNOCRATE_SPY : 0);
    }

    public function getMaxComputer(int $computerTech, bool $admiralActive): int
    {
        return 1 + $computerTech + ($admiralActive ? AMIRAL : 0);
    }

    public function getDaysLeft(int $expireTime, int $currentTime): float
    {
        return ($expireTime - $currentTime) / 86400;
    }
}

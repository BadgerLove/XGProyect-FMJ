<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Services\SettingsService;

/**
 * The bots were tuned on the x5 universe (resource_multiplier 5). From the 2 Oct 2026 reset the
 * universe runs at x1, where everything takes 5x longer. Fixed hours/seconds in the bot rules are
 * multiplied by factor() and fixed resource thresholds divided by it, so at x5 nothing changes and
 * at x1 "within 96 hours" means 480 hours, "intel is stale after 2 h" means 10 h, and so on.
 * (In the simulator, which runs x200 faster, the factor is tiny and time compresses accordingly.)
 */
final class BotSpeed
{
    private const TUNED_AT = 5;

    private static ?float $multiplier = null;

    /** The universe's resource_multiplier (1 at x1). */
    public static function multiplier(): float
    {
        return self::$multiplier ??= (float) max(1, app(SettingsService::class)->getInt('resource_multiplier'));
    }

    /** 1 at x5, 5 at x1. */
    public static function factor(): float
    {
        return self::TUNED_AT / self::multiplier();
    }

    public static function hours(float $hours): float
    {
        return $hours * self::factor();
    }

    public static function seconds(int $seconds): int
    {
        return (int) round($seconds * self::factor());
    }

    /** A resource amount that mattered at x5, in this universe's terms. */
    public static function amount(int $amount): int
    {
        return (int) round($amount / self::factor());
    }
}

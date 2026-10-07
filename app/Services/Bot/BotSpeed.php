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

    /**
     * Hours of the planet's OWN production (perhour columns include resource_multiplier): unchanged on
     * the live x1 universe; in the simulator, which raises resource_multiplier only to compress time,
     * the same number of x1 production hours. (Storage sized for "12 h of production" became 6,000 h
     * there, and storage ate the crystal research needed.)
     */
    public static function x1Hours(float $hours): float
    {
        return $hours / self::multiplier();
    }

    public static function seconds(int $seconds): int
    {
        return (int) round($seconds * self::factor());
    }

    /** A resource amount that mattered at x5, in this universe's terms. */
    public static function amount(int $amount): int
    {
        return (int) round($amount / self::amountFactor());
    }

    /**
     * factor() for resource amounts. The simulator raises resource_multiplier only to compress time
     * (storage and prices stay x1), so it sets BOT_AMOUNT_FACTOR=5 to keep amounts at their x1 values.
     * Unset on the live server.
     */
    private static function amountFactor(): float
    {
        $override = (float) (getenv('BOT_AMOUNT_FACTOR') ?: 0);

        return $override > 0 ? $override : self::factor();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Bot;

/**
 * The clock bots sleep and wake by. Live: the wall clock. In the simulator, where the economy runs N times
 * faster, the wall clock would keep every bot on the same side of its sleep window for the whole run, so
 * SIM_CLOCK_START (unix time) + SIM_CLOCK_SPEED (N) make a day pass N times faster for sleep too.
 */
final class BotClock
{
    public static function now(): int
    {
        $speed = (float) (getenv('SIM_CLOCK_SPEED') ?: 0);
        $start = (int) (getenv('SIM_CLOCK_START') ?: 0);
        if ($speed <= 1.0 || $start <= 0) {
            return time();
        }

        return $start + (int) ((time() - $start) * $speed);
    }

    /** The hour (0-23) in a bot's own time zone. */
    public static function hour(int $tzOffset): int
    {
        return (int) gmdate('G', self::now() + $tzOffset * 3600);
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Open (or close) the game and sign-ups in one go, e.g. from a one-off scheduled task at launch
 * time (2 Oct 2026 18:00 UK: "OGame Launch"). Same two switches as Admin > Server / Registration.
 *
 *   php artisan game:open            # game open + registration open
 *   php artisan game:open --close    # both closed (close_reason is shown to players)
 */
class GameOpen extends Command
{
    protected $signature = 'game:open {--close : close the game and sign-ups instead}';

    protected $description = 'Open (or --close) the game and registration together';

    public function handle(SettingsService $settings): int
    {
        $open = !$this->option('close');

        $settings->write('game_enable', $open ? 1 : 0);
        $settings->write('reg_enable', $open ? 1 : 0);

        $line = now()->format('Y-m-d H:i:s') . ' | game ' . ($open ? 'OPENED' : 'CLOSED') . ' (game_enable + reg_enable = ' . ($open ? 1 : 0) . ')';
        file_put_contents(storage_path('logs/game-open.log'), $line . PHP_EOL, FILE_APPEND);
        $this->info($line);

        return self::SUCCESS;
    }
}

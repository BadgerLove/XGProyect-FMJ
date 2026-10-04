<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\GameObjects\GameObjectRegistry;
use App\Services\Game\Formulas\ExpeditionSlotService;
use App\Services\SettingsService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GameObjectRegistry::class);

        // One instance per request so their in-memory copies are read once (2026-09-30):
        // app(SettingsService::class) used to re-read `options` 8-13 times a page, and each held
        // expedition re-queried its slot claim.
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(ExpeditionSlotService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The game's clock = the admin "date_time_zone" setting (Europe/London). Only legacy game.php pages
        // applied it (Common::setSystemTimezone); pages moved to Laravel controllers (supplies, facilities,
        // research, overview...) showed UTC, an hour behind in summer (4 Oct 2026). Unix times are unaffected.
        try {
            $zone = $this->app->make(SettingsService::class)->getString('date_time_zone');
            if (in_array($zone, timezone_identifiers_list(), true)) {
                date_default_timezone_set($zone);
                config(['app.timezone' => $zone]);
            }
        } catch (\Throwable) {
            // no database yet (install, package discovery): keep config/app.php's zone
        }
    }
}

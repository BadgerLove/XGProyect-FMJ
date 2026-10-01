<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Planets;
use App\Models\User;
use App\Services\Admin\BotService;
use App\Services\Game\Formulas\OfficerService;
use App\Services\Game\PlanetService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Enumerators\UserRanksEnumerator;
use Xgp\App\Libraries\PlanetLib;

/**
 * Start a brand-new universe (Dale, 2026-10-01, for the 2 Oct launch).
 *
 * Deletes every account except the admins (players AND bots), wipes everything they owned and
 * every bot/expedition table, sets x1 speed and 0 starting dark matter, gives the admins a fresh
 * home planet, then creates the bots: a busy spawn area (galaxy 1 systems 1-100, where players
 * start - see PlanetService) and the rest spread evenly over the other systems of galaxies 1-N.
 * Everyone, players and bots, has all officers permanently.
 *
 * Refuses to run while the game is open, and only with --confirm=<database name>.
 * Take a database backup first: there is no undo.
 */
class UniverseReset extends Command
{
    protected $signature = 'universe:reset
        {--confirm= : must be the database name, as a safety catch}
        {--spawn-bots=300 : bots in the spawn area (galaxy 1, systems 1-' . PlanetService::SPAWN_SYSTEMS . ')}
        {--other-bots=700 : bots spread over the rest of galaxies 1..--galaxies}
        {--galaxies=3 : how many galaxies the bots fill}';

    protected $description = 'Wipe the universe (all players and bots, keeps admins) and create fresh bots in the spawn layout';

    /** Everything a player or bot owned, bot memory and expedition state: emptied completely. */
    private const WIPE = [
        'acs', 'acs_members', 'alliance', 'alliance_statistics', 'bans', 'buddys', 'notes', 'messages',
        'reports', 'fleets', 'sessions', 'password_reset_tokens', 'failed_jobs',
        'planets', 'buildings', 'defenses', 'ships', 'building_queues', 'research_queues',
        'research', 'premium', 'preferences', 'users_statistics',
        'bot_combat_log', 'bot_grudges', 'bot_intel', 'bot_planet_state', 'bot_target_skip',
        'expedition_activity', 'expedition_claims', 'expedition_deck', 'expedition_slots',
    ];

    public function handle(SettingsService $settings, BotService $bots): int
    {
        require_once base_path('config/legacy/constants.php');

        $database = DB::connection()->getDatabaseName();
        if ($this->option('confirm') !== $database) {
            $this->error("Refusing: add --confirm={$database} (this deletes every player and bot).");

            return self::FAILURE;
        }
        if ($settings->getBool('game_enable')) {
            $this->error('Refusing: close the game first (Admin > Server > game open = off).');

            return self::FAILURE;
        }

        $admins = User::where('authlevel', UserRanksEnumerator::ADMIN)->get();
        if ($admins->isEmpty()) {
            $this->error('Refusing: no admin account would be left.');

            return self::FAILURE;
        }

        $this->info("Resetting {$database}: keeping admins " . $admins->pluck('name')->implode(', '));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::WIPE as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::table($table)->truncate(); // TRUNCATE also restarts the ids
                }
            }
            DB::statement('DROP TABLE IF EXISTS fleets_bak_20260909');
            $deleted = DB::table('users')->whereNotIn('id', $admins->pluck('id'))->delete();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->line("  deleted {$deleted} accounts, emptied " . count(self::WIPE) . ' tables');

        // x1 universe, 0 starting dark matter, fresh counters
        foreach ([
            'game_speed' => 2500, 'fleet_speed' => 2500, 'resource_multiplier' => 1,
            'registration_dark_matter' => 0,
            'lastsettedgalaxypos' => 1, 'lastsettedsystempos' => 1, 'lastsettedplanetpos' => 1,
            'stat_last_update' => 0, 'last_cleanup' => time(),
        ] as $name => $value) {
            $settings->write($name, $value);
        }

        foreach ($admins as $admin) {
            $this->recreateAccount($admin);
        }

        $created = $bots->createBotsAt($this->botLayout(
            (int) $this->option('spawn-bots'),
            (int) $this->option('other-bots'),
            (int) $this->option('galaxies')
        ));
        $this->line("  created {$created} bots");

        return $this->verify() ? self::SUCCESS : self::FAILURE;
    }

    /** Fresh rows + home planet for an account that survives the reset (the admins). */
    private function recreateAccount(User $user): void
    {
        DB::table('research')->insert(['research_user_id' => $user->id]);
        DB::table('preferences')->insert(['preference_user_id' => $user->id]);
        DB::table('users_statistics')->insert(['user_statistic_user_id' => $user->id]);
        DB::table('premium')->insert([
            'premium_user_id' => $user->id,
            'premium_dark_matter' => 0,
            'premium_officier_commander' => OfficerService::PERMANENT,
            'premium_officier_admiral' => OfficerService::PERMANENT,
            'premium_officier_engineer' => OfficerService::PERMANENT,
            'premium_officier_geologist' => OfficerService::PERMANENT,
            'premium_officier_technocrat' => OfficerService::PERMANENT,
        ]);

        (new PlanetLib())->setNewPlanet((int) $user->galaxy, (int) $user->system, (int) $user->planet, $user->id, '', true);
        $planetId = (int) Planets::where([
            'planet_galaxy' => $user->galaxy, 'planet_system' => $user->system,
            'planet_planet' => $user->planet, 'planet_type' => 1,
        ])->value('planet_id');

        DB::table('users')->where('id', $user->id)->update([
            'home_planet_id' => $planetId, 'current_planet' => $planetId,
            'ally_id' => 0, 'ally_request' => 0, 'ally_request_text' => null, 'ally_register_time' => 0, 'ally_rank_id' => 0,
            'fleet_shortcuts' => '', 'onlinetime' => time(),
        ]);
    }

    /**
     * One [galaxy, system] per bot: spawnBots spread evenly over the spawn systems, otherBots spread
     * evenly over every other system of galaxies 1..galaxies.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function botLayout(int $spawnBots, int $otherBots, int $galaxies): array
    {
        $layout = [];
        $spawn = PlanetService::SPAWN_SYSTEMS;
        for ($i = 0; $i < $spawnBots; $i++) {
            $layout[] = [PlanetService::SPAWN_GALAXY, 1 + intdiv($i * $spawn, max(1, $spawnBots)) % $spawn];
        }

        $rest = [];
        for ($galaxy = 1; $galaxy <= $galaxies; $galaxy++) {
            for ($system = 1; $system <= MAX_SYSTEM_IN_GALAXY; $system++) {
                if (!($galaxy === PlanetService::SPAWN_GALAXY && $system <= $spawn)) {
                    $rest[] = [$galaxy, $system];
                }
            }
        }
        for ($i = 0; $i < $otherBots; $i++) {
            $layout[] = $rest[intdiv($i * count($rest), max(1, $otherBots))];
        }

        return $layout;
    }

    private function verify(): bool
    {
        $users = DB::table('users')->count();
        $bots = DB::table('users')->whereNotNull('bot_profile')->count();
        $humans = $users - $bots;
        $planets = DB::table('planets')->count();
        $orphans = DB::table('users')->leftJoin('planets', 'planets.planet_id', '=', 'users.home_planet_id')
            ->whereNull('planets.planet_id')->count();
        $notOwn = DB::table('users')->join('planets', 'planets.planet_id', '=', 'users.home_planet_id')
            ->whereColumn('planets.planet_user_id', '<>', 'users.id')->count();
        $rows = [];
        foreach (['buildings', 'defenses', 'ships'] as $t) {
            $rows[$t] = DB::table($t)->count();
        }
        $missing = DB::table('users')
            ->leftJoin('research', 'research.research_user_id', '=', 'users.id')
            ->leftJoin('premium', 'premium.premium_user_id', '=', 'users.id')
            ->leftJoin('preferences', 'preferences.preference_user_id', '=', 'users.id')
            ->leftJoin('users_statistics', 'users_statistics.user_statistic_user_id', '=', 'users.id')
            ->where(fn ($q) => $q->whereNull('research.research_user_id')->orWhereNull('premium.premium_user_id')
                ->orWhereNull('preferences.preference_user_id')->orWhereNull('users_statistics.user_statistic_user_id'))
            ->count();
        $notPermanent = DB::table('premium')->where('premium_officier_commander', '<', OfficerService::PERMANENT)->count();
        $spawnBots = DB::table('users')->join('planets', 'planets.planet_id', '=', 'users.home_planet_id')
            ->whereNotNull('users.bot_profile')->where('planets.planet_galaxy', PlanetService::SPAWN_GALAXY)
            ->where('planets.planet_system', '<=', PlanetService::SPAWN_SYSTEMS)->count();

        $ok = $orphans === 0 && $notOwn === 0 && $missing === 0 && $notPermanent === 0
            && $planets === $users && $rows['buildings'] === $planets && $rows['defenses'] === $planets && $rows['ships'] === $planets;

        $this->line(sprintf(
            '  VERIFY users %d (admins/humans %d, bots %d, %d in the spawn area) | planets %d | buildings/defenses/ships %d/%d/%d | homeless %d | wrong owner %d | missing rows %d | officers not permanent %d => %s',
            $users, $humans, $bots, $spawnBots, $planets, $rows['buildings'], $rows['defenses'], $rows['ships'],
            $orphans, $notOwn, $missing, $notPermanent, $ok ? 'RESET OK' : 'PROBLEM'
        ));

        return $ok;
    }
}

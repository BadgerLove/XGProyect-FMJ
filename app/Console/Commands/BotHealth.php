<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Watchdog for the bot tick. Read-only apart from its own state file.
 *
 * Reads the one-line summaries that BotTick appends to storage/logs/bot-tick-YYYY-MM.log
 * plus a few DB counts, and raises ONE alert (Discord webhook, or just the health log when
 * BOT_ALERT_WEBHOOK is empty) when the bots look dead. The same alert is not repeated
 * within the cooldown; a single "recovered" message is sent when it clears.
 *
 * Run via: php artisan bot:health        (Windows Task Scheduler "OGame Bot Health", every 30 min —
 *                                          separate from the tick so it can report the tick dying)
 *          php artisan bot:health --digest   (once a day: post the headline numbers even when healthy)
 *
 * Why this exists: the Aug 9 → Sep 4 2026 stall went unnoticed for 26 days because the
 * tick's output was discarded. See ogame-vault/bot-ai/Bot Self-Healing Plan.md.
 */
class BotHealth extends Command
{
    protected $signature = 'bot:health
        {--digest : Also post the headline numbers when everything is healthy}
        {--no-alert : Print the verdict only; do not post or touch the state file}
        {--log= : Read this log file instead of the current month (testing)}';

    protected $description = 'Check the bot tick log + DB and alert (Discord webhook) when the bots look stuck';

    /** Tick is expected every 15 min; allow three misses before shouting. */
    private const TICK_MAX_AGE_MIN = 45;

    /** Rule 2: this many consecutive ticks with zero build/ship/research actions = brain idle (8 × 15 min = 2 h). */
    private const IDLE_TICKS = 8;

    /** Rule 4: planets that have exhausted the escalation ladder before a human is asked to look. */
    private const STUCK_PLANETS = 50;

    /** Rule 5: ticks to look back for spy activity (96 × 15 min = 24 h). */
    private const SPY_LOOKBACK_TICKS = 96;

    /** Rule 5: only complain about missing spy missions once the bots actually own probes. */
    private const MIN_PROBES_FOR_SPY_RULE = 500;

    /** Don't repeat the same alert within this many hours. */
    private const COOLDOWN_HOURS = 6;

    public function handle(): int
    {
        $lines = $this->readTickLog();
        $verdict = $this->evaluate($lines);

        $this->line($verdict['ok'] ? 'OK: ' . $verdict['summary'] : 'ALERT [' . $verdict['key'] . ']: ' . $verdict['summary']);
        foreach ($verdict['detail'] as $d) {
            $this->line('  ' . $d);
        }

        if ($this->option('no-alert')) {
            return $verdict['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->appendHealthLog(($verdict['ok'] ? 'OK ' : 'ALERT ' . $verdict['key'] . ' ') . $verdict['summary']);

        $state = $this->readState();
        $now = time();

        if (!$verdict['ok']) {
            $sameAlert = ($state['last_alert_key'] ?? null) === $verdict['key'];
            $inCooldown = $sameAlert && ($now - (int) ($state['last_alert_at'] ?? 0)) < self::COOLDOWN_HOURS * 3600;

            if (!$inCooldown) {
                $this->post("🚨 **OGame bots: {$verdict['summary']}**\n" . implode("\n", $verdict['detail']));
                $state['last_alert_key'] = $verdict['key'];
                $state['last_alert_at'] = $now;
            }
            $state['alerting'] = true;
        } else {
            if (!empty($state['alerting'])) {
                $this->post("✅ **OGame bots recovered** — {$verdict['summary']}");
            }
            $state['alerting'] = false;
            $state['last_alert_key'] = null;

            if ($this->option('digest')) {
                $this->post("📊 **OGame bots daily digest** — {$verdict['summary']}\n" . implode("\n", $verdict['detail']));
            }
        }

        $state['last_check_at'] = $now;
        $this->writeState($state);

        return $verdict['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Apply the rules in order; the first failure wins.
     *
     * @param  array<int, array<string, int|string>>  $lines  parsed non-DRY tick lines, oldest first
     * @return array{ok: bool, key: string, summary: string, detail: array<int, string>}
     */
    private function evaluate(array $lines): array
    {
        $db = $this->dbCounts();
        $detail = $this->describeDb($db);

        // Rule 1 — tick not running
        $last = end($lines) ?: null;
        $ageMin = $last ? (int) floor((time() - (int) $last['ts']) / 60) : null;
        if ($last === null || $ageMin > self::TICK_MAX_AGE_MIN) {
            $since = $last ? "last tick {$ageMin} min ago ({$last['raw']})" : 'no tick log lines at all';
            return $this->verdict('tick-dead', "tick not running — {$since}", $detail);
        }

        $tail = array_slice($lines, -3);
        $recent = array_map(fn ($l) => $l['raw'], $tail);

        // Rule 3 — tick erroring (checked before idle: a crashing tick also reports 0 actions)
        if ((int) $last['processed'] > 0 && (int) $last['errors'] > (int) $last['processed'] / 2) {
            return $this->verdict('tick-errors', "tick erroring — {$last['errors']} errors for {$last['processed']} bots", array_merge($recent, $detail));
        }

        // Rule 2 — brain idle: N consecutive ticks with bots processed but nothing queued
        if (count($lines) >= self::IDLE_TICKS) {
            $window = array_slice($lines, -self::IDLE_TICKS);
            $allIdle = true;
            foreach ($window as $l) {
                $actions = (int) $l['built'] + (int) $l['ships'] + (int) $l['research'];
                if ((int) $l['processed'] === 0 || $actions > 0) {
                    $allIdle = false;
                    break;
                }
            }
            if ($allIdle) {
                $hours = round(self::IDLE_TICKS * 15 / 60, 1);
                return $this->verdict('brain-idle', "brain idle — 0 buildings/ships/research for the last " . self::IDLE_TICKS . " ticks (~{$hours} h)", array_merge($recent, $detail));
            }
        }

        // Rule 4 — escalation ladder exhausted on many planets
        if ((int) $last['stuck'] >= self::STUCK_PLANETS) {
            return $this->verdict('ladder-stuck', "{$last['stuck']} planets have exhausted the escalation ladder", array_merge($recent, $detail));
        }

        // Rule 5 — probes exist, intel empty, nobody has spied for a day
        if ($db['intel_rows'] === 0 && $db['probes'] >= self::MIN_PROBES_FOR_SPY_RULE && count($lines) >= self::SPY_LOOKBACK_TICKS) {
            $spies = 0;
            foreach (array_slice($lines, -self::SPY_LOOKBACK_TICKS) as $l) {
                $spies += (int) $l['spies'];
            }
            if ($spies === 0) {
                return $this->verdict('no-spying', "no spy missions in 24 h despite {$db['probes']} probes and empty intel", array_merge($recent, $detail));
            }
        }

        $summary = sprintf(
            'last tick %d min ago: processed=%d built=%d ships=%d research=%d attacks=%d spies=%d errors=%d',
            $ageMin, $last['processed'], $last['built'], $last['ships'], $last['research'], $last['attacks'], $last['spies'], $last['errors']
        );

        return ['ok' => true, 'key' => '', 'summary' => $summary, 'detail' => $detail];
    }

    /** @param array<int, string> $detail */
    private function verdict(string $key, string $summary, array $detail): array
    {
        return ['ok' => false, 'key' => $key, 'summary' => $summary, 'detail' => $detail];
    }

    /**
     * Parse the tick log (current month, plus the previous month when the current one is short).
     * DRY lines are skipped. Returns oldest → newest.
     *
     * @return array<int, array<string, int|string>>
     */
    private function readTickLog(): array
    {
        $override = $this->option('log');
        $files = $override
            ? [$override]
            : [
                storage_path('logs/bot-tick-' . date('Y-m', strtotime('first day of last month')) . '.log'),
                storage_path('logs/bot-tick-' . date('Y-m') . '.log'),
            ];

        $parsed = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
                $row = $this->parseLine($raw);
                if ($row !== null) {
                    $parsed[] = $row;
                }
            }
        }

        // Keep a bounded tail — rule 5 needs at most SPY_LOOKBACK_TICKS.
        return array_slice($parsed, -max(self::SPY_LOOKBACK_TICKS, self::IDLE_TICKS));
    }

    /**
     * "2026-09-04 22:15:03 | processed=231 ... | idle_planets=0 escalated=0 stuck=0 | 41.2s"
     *
     * @return array<string, int|string>|null
     */
    private function parseLine(string $raw): ?array
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \| (DRY )?(.+)$/', $raw, $m)) {
            return null;
        }
        if ($m[2] !== '') {
            return null; // dry run — not a real tick
        }

        $row = ['ts' => (int) strtotime($m[1]), 'raw' => $raw];
        preg_match_all('/([a-z_]+)=(\d+)/', $m[3], $kv, PREG_SET_ORDER);
        foreach ($kv as [, $k, $v]) {
            $row[$k] = (int) $v;
        }
        foreach (['processed', 'built', 'ships', 'research', 'attacks', 'spies', 'errors', 'stuck'] as $k) {
            $row[$k] ??= 0;
        }

        return $row;
    }

    /** @return array<string, int> */
    private function dbCounts(): array
    {
        try {
            $prefix = DB::getTablePrefix();
            $row = DB::selectOne("
                SELECT
                  (SELECT COUNT(*) FROM `{$prefix}bot_intel`) AS intel_rows,
                  (SELECT COUNT(*) FROM `{$prefix}bot_combat_log` WHERE created_at > NOW() - INTERVAL 24 HOUR) AS combats_24h,
                  (SELECT COALESCE(SUM(s.ship_espionage_probe),0) FROM `{$prefix}ships` s JOIN `{$prefix}planets` p ON p.planet_id=s.ship_planet_id JOIN `{$prefix}users` u ON u.id=p.planet_user_id WHERE u.bot_profile IS NOT NULL) AS probes,
                  (SELECT COUNT(DISTINCT q.planet_id) FROM `{$prefix}building_queues` q JOIN `{$prefix}planets` p ON p.planet_id=q.planet_id JOIN `{$prefix}users` u ON u.id=p.planet_user_id WHERE u.bot_profile IS NOT NULL AND q.end_time > UNIX_TIMESTAMP()) AS planets_building_live,
                  (SELECT COUNT(*) FROM `{$prefix}planets` p JOIN `{$prefix}users` u ON u.id=p.planet_user_id WHERE u.bot_profile IS NOT NULL AND p.planet_b_hangar_id<>'' AND p.planet_b_hangar_id IS NOT NULL) AS planets_hangar_busy,
                  (SELECT COUNT(*) FROM `{$prefix}planets` p JOIN `{$prefix}users` u ON u.id=p.planet_user_id WHERE u.bot_profile IS NOT NULL AND p.planet_type=1 AND p.planet_energy_max + p.planet_energy_used < 0) AS planets_in_deficit,
                  (SELECT COUNT(*) FROM `{$prefix}fleets` f JOIN `{$prefix}users` u ON u.id=f.fleet_owner WHERE u.bot_profile IS NOT NULL) AS bot_fleets_out
            ");

            return array_map('intval', (array) $row);
        } catch (\Throwable $e) {
            $this->warn('DB check failed: ' . $e->getMessage());

            return ['intel_rows' => -1, 'combats_24h' => -1, 'probes' => -1, 'planets_building_live' => -1, 'planets_hangar_busy' => -1, 'planets_in_deficit' => -1, 'bot_fleets_out' => -1];
        }
    }

    /**
     * @param  array<string, int>  $db
     * @return array<int, string>
     */
    private function describeDb(array $db): array
    {
        return [sprintf(
            'db: planets_building=%d hangar_busy=%d energy_deficit=%d fleets_out=%d probes=%d intel=%d combats_24h=%d',
            $db['planets_building_live'], $db['planets_hangar_busy'], $db['planets_in_deficit'], $db['bot_fleets_out'], $db['probes'], $db['intel_rows'], $db['combats_24h']
        )];
    }

    /**
     * Post to the Discord webhook from .env BOT_ALERT_WEBHOOK. Empty key = log only.
     * The URL is a secret: never echo it, never put it on a command line.
     */
    private function post(string $message): void
    {
        $this->appendHealthLog('POST ' . str_replace("\n", ' / ', $message));

        $url = (string) env('BOT_ALERT_WEBHOOK', '');
        if ($url === '') {
            $this->line('(no BOT_ALERT_WEBHOOK set — not posted)');

            return;
        }

        try {
            $resp = Http::timeout(10)->post($url, ['content' => mb_substr($message, 0, 1900)]);
            if (!$resp->successful()) {
                $this->warn('Webhook returned HTTP ' . $resp->status());
                $this->appendHealthLog('POST FAILED http=' . $resp->status());
            }
        } catch (\Throwable $e) {
            $this->warn('Webhook failed: ' . $e->getMessage());
            $this->appendHealthLog('POST FAILED ' . $e->getMessage());
        }
    }

    private function appendHealthLog(string $line): void
    {
        try {
            file_put_contents(storage_path('logs/bot-health.log'), date('Y-m-d H:i:s') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // never fail the check because of logging
        }
    }

    private function statePath(): string
    {
        return storage_path('app/bot-health-state.json');
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        $path = $this->statePath();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        file_put_contents($this->statePath(), json_encode($state, JSON_PRETTY_PRINT) . "\n", LOCK_EX);
    }
}

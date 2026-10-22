<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bot's notebook between ticks (one JSON row per bot in bot_mind_memory): what its expeditions and
 * recyclers brought home, its points over time (for the doctor), which incoming attacks it noticed,
 * the doctor's prescriptions, and a short diary of its decisions with the reasons.
 * Only the bot's own experience goes in here.
 */
final class Memory
{
    public const TABLE = 'bot_mind_memory';

    private const DIARY_KEEP = 40;

    private static bool $ready = false;

    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function ensureTable(): void
    {
        if (self::$ready) {
            return;
        }
        if (!Schema::hasTable(self::TABLE)) {
            DB::statement('CREATE TABLE ' . self::TABLE . ' (bot_id INT UNSIGNED NOT NULL PRIMARY KEY, data MEDIUMTEXT NOT NULL, updated_at INT UNSIGNED NOT NULL) ENGINE=InnoDB');
        }
        self::$ready = true;
    }

    /** @return array<string, mixed> */
    public function load(int $botId): array
    {
        $this->ensureTable();
        if (!isset($this->cache[$botId])) {
            $raw = DB::table(self::TABLE)->where('bot_id', $botId)->value('data');
            $this->cache[$botId] = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        }

        return $this->cache[$botId];
    }

    /** @param array<string, mixed> $data */
    public function save(int $botId, array $data): void
    {
        $this->ensureTable();
        if (isset($data['diary']) && count($data['diary']) > self::DIARY_KEEP) {
            $data['diary'] = array_slice($data['diary'], -self::DIARY_KEEP);
        }
        $this->cache[$botId] = $data;
        DB::table(self::TABLE)->updateOrInsert(['bot_id' => $botId], ['data' => json_encode($data), 'updated_at' => time()]);
    }

    /** Drop entries older than $seconds from a list of [time, ...] rows. */
    public static function prune(array $rows, int $seconds): array
    {
        $cut = time() - $seconds;

        return array_values(array_filter($rows, fn ($r) => (int) ($r[0] ?? 0) >= $cut));
    }
}

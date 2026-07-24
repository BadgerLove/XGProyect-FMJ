<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Support\Facades\DB;

/**
 * Grudge System — bots remember who raided them and prioritize revenge.
 *
 * When a bot gets attacked, it records a grudge against the attacker.
 * On future ticks, bots prioritize grudge targets for attacks.
 * Grudges decay over 7 days and escalate with repeated attacks.
 */
class GrudgeService
{
    /** Grudge severity levels */
    public const SEVERITY_MILD = 'mild';       // 1 attack
    public const SEVERITY_ANNOYED = 'annoyed'; // 2 attacks
    public const SEVERITY_VENDETTA = 'vendetta'; // 3+ attacks

    /** How long grudges last before decaying (seconds) */
    private const GRUDGE_TTL = 7 * 24 * 3600; // 7 days

    /**
     * Record a grudge when a bot gets attacked.
     *
     * @param  int  $defenderId  The bot that got attacked
     * @param  int  $attackerId  The player/bot that attacked
     */
    public function recordGrudge(int $defenderId, int $attackerId): void
    {
        if ($defenderId <= 0 || $attackerId <= 0 || $defenderId === $attackerId) {
            return;
        }

        $existing = DB::table('bot_grudges')
            ->where('defender_id', $defenderId)
            ->where('attacker_id', $attackerId)
            ->first();

        if ($existing) {
            $newCount = $existing->attack_count + 1;
            $severity = $this->calculateSeverity($newCount);

            DB::table('bot_grudges')
                ->where('id', $existing->id)
                ->update([
                    'attack_count' => $newCount,
                    'severity' => $severity,
                    'last_attack' => now(),
                ]);
        } else {
            DB::table('bot_grudges')->insert([
                'defender_id' => $defenderId,
                'attacker_id' => $attackerId,
                'attack_count' => 1,
                'severity' => self::SEVERITY_MILD,
                'last_attack' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Get all active grudges for a bot (not expired).
     *
     * @return list<array{attacker_id: int, attack_count: int, severity: string}>
     */
    public function getGrudges(int $defenderId): array
    {
        $cutoff = now()->subSeconds(self::GRUDGE_TTL);

        return DB::table('bot_grudges')
            ->where('defender_id', $defenderId)
            ->where('last_attack', '>', $cutoff)
            ->orderBy('attack_count', 'desc')
            ->get()
            ->map(fn ($row) => [
                'attacker_id' => (int) $row->attacker_id,
                'attack_count' => (int) $row->attack_count,
                'severity' => $row->severity,
            ])
            ->toArray();
    }

    /**
     * Check if a bot has a grudge against a specific attacker.
     *
     * @return array{attack_count: int, severity: string}|null
     */
    public function hasGrudge(int $defenderId, int $attackerId): ?array
    {
        $cutoff = now()->subSeconds(self::GRUDGE_TTL);

        $grudge = DB::table('bot_grudges')
            ->where('defender_id', $defenderId)
            ->where('attacker_id', $attackerId)
            ->where('last_attack', '>', $cutoff)
            ->first();

        if (!$grudge) {
            return null;
        }

        return [
            'attack_count' => (int) $grudge->attack_count,
            'severity' => $grudge->severity,
        ];
    }

    /**
     * Cleanup expired grudges.
     *
     * @return int Number of deleted rows
     */
    public function cleanup(): int
    {
        $cutoff = now()->subSeconds(self::GRUDGE_TTL);

        return DB::table('bot_grudges')
            ->where('last_attack', '<', $cutoff)
            ->delete();
    }

    /**
     * Calculate severity based on attack count.
     */
    private function calculateSeverity(int $attackCount): string
    {
        if ($attackCount >= 3) {
            return self::SEVERITY_VENDETTA;
        }
        if ($attackCount >= 2) {
            return self::SEVERITY_ANNOYED;
        }
        return self::SEVERITY_MILD;
    }
}

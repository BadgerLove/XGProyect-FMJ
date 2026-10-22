<?php

declare(strict_types=1);

namespace App\Services\Bot\Mind;

/**
 * Who a bot is (Dale, 21 Oct 2026: "give it its own kind of personality"). Eight traits between 0 and
 * ~1.2 that tilt how the mind values things; they never decide on their own. The starting point is the
 * bot's build path and old personality, then every trait gets its own +-25 % from the bot id, so two
 * raiders still play differently. Fixed per bot (crc32), so a bot keeps its character tick to tick.
 */
final class Personality
{
    public const TRAITS = ['greed', 'war', 'expand', 'science', 'guard', 'raid', 'explore', 'caution'];

    /** Path starting points: greed war expand science guard raid explore caution. */
    private const BASE = [
        'warlord' => [0.6, 0.8, 0.6, 0.5, 0.3, 0.6, 0.8, 0.3],
        'raider' => [0.5, 0.7, 0.4, 0.4, 0.2, 1.0, 0.3, 0.3],
        'pirate' => [0.5, 0.5, 0.4, 0.5, 0.3, 0.7, 1.0, 0.4],
        'conqueror' => [0.7, 0.4, 1.0, 0.6, 0.4, 0.5, 0.3, 0.4],
        'miner' => [1.0, 0.2, 0.7, 0.5, 0.8, 0.1, 0.2, 0.6],
        'emperor' => [0.7, 0.6, 0.9, 0.8, 0.5, 0.4, 0.7, 0.4],
    ];

    /** Old personality nudges (added before the jitter). */
    private const NUDGE = [
        'turtle' => ['guard' => 0.3, 'caution' => 0.2, 'war' => -0.1],
        'passive' => ['war' => -0.3, 'raid' => -0.4, 'guard' => 0.2, 'caution' => 0.2],
        'raider' => ['raid' => 0.1, 'war' => 0.1],
        'balanced' => [],
    ];

    /** @param array<string, float> $t */
    private function __construct(private readonly array $t, public readonly string $label)
    {
    }

    public static function forBot(int $botId, string $path, string $oldPersonality): self
    {
        $base = array_combine(self::TRAITS, self::BASE[$path] ?? self::BASE['raider']);
        foreach (self::NUDGE[$oldPersonality] ?? [] as $trait => $delta) {
            $base[$trait] += $delta;
        }
        foreach ($base as $trait => $value) {
            $jitter = 0.75 + (crc32("mind:{$botId}:{$trait}") % 1000) / 2000; // 0.75 .. 1.25
            $base[$trait] = max(0.0, min(1.2, $value * $jitter));
        }

        return new self($base, $path . '/' . $oldPersonality);
    }

    public function __get(string $name): float
    {
        return $this->t[$name] ?? 0.0;
    }

    /** x1 hours the bot will wait for the best thing before taking second best (greedy players save longer). */
    public function patienceHours(): float
    {
        return 6.0 + 18.0 * min(1.0, $this->t['greed']);
    }

    /** @return array<string, float> */
    public function toArray(): array
    {
        return $this->t;
    }
}

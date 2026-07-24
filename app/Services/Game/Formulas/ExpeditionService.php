<?php

declare(strict_types=1);

namespace App\Services\Game\Formulas;

use App\Services\SettingsService;

class ExpeditionService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * Also applies for resources
     */
    public function getMaxExpeditionPoints(int | float $topPlayerPoints): int
    {
        if ($topPlayerPoints < 100000) {
            return 2500;
        }

        if ($topPlayerPoints < 1000000) {
            return 6000;
        }

        if ($topPlayerPoints < 5000000) {
            return 9000;
        }

        if ($topPlayerPoints < 25000000) {
            return 12000;
        }

        if ($topPlayerPoints < 50000000) {
            return 15000;
        }

        if ($topPlayerPoints < 75000000) {
            return 18000;
        }

        if ($topPlayerPoints < 100000000) {
            return 21000;
        }

        return 25000;
    }

    public function getMaxShipsExpeditionPoints(int | float $topPlayerPoints): int
    {
        return $this->getMaxExpeditionPoints($topPlayerPoints) * 100;
    }

    public function calculateExpeditionPoints(int $structuralIntegrity): int
    {
        return ($structuralIntegrity * 5 / 1000);
    }

    public function getExpeditionResult(int $galaxy = 0, int $system = 0): string
    {
        $weights = $this->getExpeditionResultWeights();

        // Apply depletion: boost "nothing" chance for depleted systems
        if ($galaxy > 0 && $system > 0) {
            $boost = $this->getDepletionNothingBoost($galaxy, $system);
            $weights['nothing'] = ($weights['nothing'] ?? 0) + $boost;
        }

        return $this->pickWeighted($weights, 'nothing');
    }

    public function calculateDarkMatterSourceSize(): string
    {
        return $this->pickWeighted($this->getDarkMatterSourceSizeWeights(), 'small');
    }

    public function getDarkMatterSourceSize(string $discoveryType): int
    {
        if ($discoveryType === 'medium') {
            return mt_rand(80000, 150000);
        }

        if ($discoveryType === 'large') {
            return mt_rand(200000, 500000);
        }

        return mt_rand(30000, 50000); // $discoveryType === 'small'
    }

    public function calculateResourceTypeObtained(): string
    {
        return $this->pickWeighted($this->getResourceTypeWeights(), 'metal');
    }

    public function calculateResourceSourceSize(): string
    {
        return $this->pickWeighted($this->getResourceSourceSizeWeights(), 'normal');
    }

    public function getResourceSourceSizeMultChances(string $discoveryType): int
    {
        if ($discoveryType === 'large') {
            return mt_rand(50, 100);
        }

        if ($discoveryType === 'xl') {
            return mt_rand(100, 200);
        }

        return mt_rand(10, 50); // $discoveryType === 'normal'
    }

    public function getResourceFoundAmount(int $chancesMultiplier, int $expeditionPoints, string $resourceType): int
    {
        $resource = [
            'metal' => 1,
            'crystal' => 2,
            'deuterium' => 3,
        ];

        return (int) floor($chancesMultiplier * $expeditionPoints / $resource[$resourceType]);
    }

    public function calculateShipFoundAmount(int $chancesMultiplier, int $expeditionPoints): int
    {
        return (int) floor($chancesMultiplier * $expeditionPoints / 2);
    }

    /**
     * Only these ships are computed for the expeditions points
     *
     * @return array<Int, Int>
     */
    public function getPossibleShips(): array
    {
        return [
            202, // ship_small_cargo_ship
            203, // ship_big_cargo_ship
            204, // ship_light_fighter
            205, // ship_heavy_fighter
            206, // ship_cruiser
            207, // ship_battleship
            210, // ship_espionage_probe
            211, // ship_bomber
            213, // ship_destroyer
            215, // ship_reaper
        ];
    }

    /**
     * Only these ships are obtainable on an expedition
     *
     * @return array<Int, Float>
     */
    public function getShipsObtainableChances(): array
    {
        return [
            202 => 0.1, // ship_small_cargo_ship
            203 => 0.1, // ship_big_cargo_ship
            204 => 0.1, // ship_light_fighter
            205 => 0.5, // ship_heavy_fighter
            206 => 0.25, // ship_cruiser
            207 => 0.125, // ship_battleship
            210 => 0.1, // ship_espionage_probe
            211 => 0.0625, // ship_bomber
            213 => 0.0625, // ship_destroyer
            215 => 0.0625, // ship_reaper
        ];
    }

    public function getFleetDeplay(): int
    {
        return $this->pickWeighted($this->getFleetDelayWeights(), 2);
    }

    /** @return array<string, int> */
    public function getExpeditionResultWeights(): array
    {
        return [
            'darkMatter' => $this->settings->getInt('expedition_result_dark_matter_weight'),
            'ships' => $this->settings->getInt('expedition_result_ships_weight'),
            'resources' => $this->settings->getInt('expedition_result_resources_weight'),
            'pirates' => $this->settings->getInt('expedition_result_pirates_weight'),
            'aliens' => $this->settings->getInt('expedition_result_aliens_weight'),
            'delay' => $this->settings->getInt('expedition_result_delay_weight'),
            'early' => $this->settings->getInt('expedition_result_early_weight'),
            'nothing' => $this->settings->getInt('expedition_result_nothing_weight'),
            'merchant' => $this->settings->getInt('expedition_result_merchant_weight'),
            'blackHole' => $this->settings->getInt('expedition_result_black_hole_weight'),
        ];
    }

    /** @return array<string, int> */
    public function getDarkMatterSourceSizeWeights(): array
    {
        return [
            'small' => $this->settings->getInt('expedition_dark_matter_source_small_weight'),
            'medium' => $this->settings->getInt('expedition_dark_matter_source_medium_weight'),
            'large' => $this->settings->getInt('expedition_dark_matter_source_large_weight'),
        ];
    }

    /** @return array<string, int> */
    public function getResourceTypeWeights(): array
    {
        return [
            'metal' => $this->settings->getInt('expedition_resource_type_metal_weight'),
            'crystal' => $this->settings->getInt('expedition_resource_type_crystal_weight'),
            'deuterium' => $this->settings->getInt('expedition_resource_type_deuterium_weight'),
        ];
    }

    /** @return array<string, int> */
    public function getResourceSourceSizeWeights(): array
    {
        return [
            'normal' => $this->settings->getInt('expedition_resource_source_normal_weight'),
            'large' => $this->settings->getInt('expedition_resource_source_large_weight'),
            'xl' => $this->settings->getInt('expedition_resource_source_xl_weight'),
        ];
    }

    /** @return array<int, int> */
    public function getFleetDelayWeights(): array
    {
        return [
            2 => $this->settings->getInt('expedition_fleet_delay_2_weight'),
            3 => $this->settings->getInt('expedition_fleet_delay_3_weight'),
            5 => $this->settings->getInt('expedition_fleet_delay_5_weight'),
        ];
    }

    /**
     * @template T of array-key
     *
     * @param array<T, int> $weights
     * @param T $fallback
     *
     * @return T
     */
    private function pickWeighted(array $weights, string | int $fallback): string | int
    {
        $randomNumber = mt_rand(1, array_sum($weights));
        $sum = 0;

        foreach ($weights as $result => $weight) {
            $sum += $weight;

            if ($randomNumber <= $sum) {
                return $result;
            }
        }

        return $fallback;
    }

    // ─── Deck System (Per-Player Shuffled Deck) ───

    /** Deck size — higher = more accurate distribution */
    private const DECK_SIZE = 1000;

    /**
     * Get the expedition result using the per-player deck system.
     * Deals the next card from the player's shuffled deck.
     * Depletion can override a positive outcome to "nothing".
     */
    public function getExpeditionResultFromDeck(int $userId, int $galaxy = 0, int $system = 0): string
    {
        $deck = $this->getPlayerDeck($userId);
        $outcome = $deck['deck'][$deck['pointer']];

        // Advance pointer
        $newPointer = $deck['pointer'] + 1;

        // Reshuffle when deck is exhausted
        if ($newPointer >= self::DECK_SIZE) {
            $this->rebuildDeck($userId);
        } else {
            \Illuminate\Support\Facades\DB::table('expedition_deck')
                ->where('user_id', $userId)
                ->update(['pointer' => $newPointer, 'updated_at' => now()]);
        }

        // Apply depletion override: depleted systems can convert positive outcomes to nothing
        if ($galaxy > 0 && $system > 0 && $outcome !== 'nothing') {
            $boost = $this->getDepletionNothingBoost($galaxy, $system);
            if ($boost > 0) {
                $totalWeight = array_sum($this->getExpeditionResultWeights());
                $overrideChance = $boost / ($totalWeight + $boost);
                if (mt_rand(1, 10000) <= (int) ($overrideChance * 10000)) {
                    $outcome = 'nothing';
                }
            }
        }

        return $outcome;
    }

    /**
     * Get or create a player's expedition deck.
     */
    private function getPlayerDeck(int $userId): array
    {
        $row = \Illuminate\Support\Facades\DB::table('expedition_deck')
            ->where('user_id', $userId)
            ->first();

        if (!$row) {
            return $this->createDeck($userId);
        }

        // Check if admin weights changed since deck was built
        $currentWeights = $this->getExpeditionResultWeights();
        $storedWeights = json_decode($row->weights ?? '{}', true);

        if ($currentWeights !== $storedWeights) {
            return $this->rebuildDeck($userId);
        }

        return [
            'deck' => json_decode($row->deck, true),
            'pointer' => $row->pointer,
        ];
    }

    /**
     * Create a fresh deck for a player based on current admin weights.
     */
    private function createDeck(int $userId): array
    {
        $deck = $this->buildDeck();

        \Illuminate\Support\Facades\DB::table('expedition_deck')->updateOrInsert(
            ['user_id' => $userId],
            [
                'deck' => json_encode($deck),
                'pointer' => 0,
                'weights' => json_encode($this->getExpeditionResultWeights()),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return ['deck' => $deck, 'pointer' => 0];
    }

    /**
     * Rebuild (reshuffle) a player's deck.
     */
    private function rebuildDeck(int $userId): array
    {
        $deck = $this->buildDeck();

        \Illuminate\Support\Facades\DB::table('expedition_deck')
            ->where('user_id', $userId)
            ->update([
                'deck' => json_encode($deck),
                'pointer' => 0,
                'weights' => json_encode($this->getExpeditionResultWeights()),
                'updated_at' => now(),
            ]);

        return ['deck' => $deck, 'pointer' => 0];
    }

    /**
     * Build a shuffled deck of outcome strings based on current weights.
     * Uses DECK_SIZE cards for accurate distribution.
     *
     * @return string[] Shuffled array of outcome names
     */
    private function buildDeck(): array
    {
        $weights = $this->getExpeditionResultWeights();
        $totalWeight = array_sum($weights);
        $deck = [];

        // Convert weights to card counts
        $cardCounts = [];
        $assigned = 0;
        $i = 0;
        $weightKeys = array_keys($weights);

        foreach ($weights as $outcome => $weight) {
            $i++;
            if ($i === count($weights)) {
                // Last outcome gets the remainder to ensure exact DECK_SIZE
                // max(0) prevents negative counts in rare rounding edge cases
                $cardCounts[$outcome] = max(0, self::DECK_SIZE - $assigned);
            } else {
                $count = (int) round($weight / $totalWeight * self::DECK_SIZE);
                $cardCounts[$outcome] = $count;
                $assigned += $count;
            }
        }

        // Build the deck array
        foreach ($cardCounts as $outcome => $count) {
            for ($j = 0; $j < $count; $j++) {
                $deck[] = $outcome;
            }
        }

        // Shuffle using Fisher-Yates
        for ($j = count($deck) - 1; $j > 0; $j--) {
            $k = mt_rand(0, $j);
            [$deck[$j], $deck[$k]] = [$deck[$k], $deck[$j]];
        }

        return $deck;
    }

    // ─── System Depletion ───

    /** How many expeditions before a system is fully depleted */
    private const DEPLETION_THRESHOLD = 20;

    /** How many expeditions recover per hour */
    private const RECOVERY_RATE = 2;

    /**
     * Record an expedition in a system.
     */
    public function recordExpedition(int $galaxy, int $system): void
    {
        $now = now();

        // Apply recovery first
        $this->applyRecovery($galaxy, $system);

        $activity = \Illuminate\Support\Facades\DB::table('expedition_activity')
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->first();

        if ($activity) {
            \Illuminate\Support\Facades\DB::table('expedition_activity')
                ->where('id', $activity->id)
                ->update([
                    'expedition_count' => $activity->expedition_count + 1,
                    'last_expedition' => $now,
                    'updated_at' => $now,
                ]);
        } else {
            \Illuminate\Support\Facades\DB::table('expedition_activity')->insert([
                'galaxy' => $galaxy,
                'system' => $system,
                'expedition_count' => 1,
                'last_expedition' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Get the depletion multiplier for a system (1.0 = no depletion, 0.0 = fully depleted).
     * This multiplies the chance of getting a positive result.
     */
    public function getDepletionMultiplier(int $galaxy, int $system): float
    {
        $this->applyRecovery($galaxy, $system);

        $activity = \Illuminate\Support\Facades\DB::table('expedition_activity')
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->first();

        if (!$activity) {
            return 1.0; // No activity = no depletion
        }

        $count = $activity->expedition_count;

        if ($count <= 0) {
            return 1.0;
        }

        // Linear depletion: each expedition reduces yield by (1 / THRESHOLD)
        $depletion = min($count / self::DEPLETION_THRESHOLD, 1.0);

        // Return multiplier (1.0 at 0 expeditions, 0.5 at threshold)
        // We don't go to 0.0 — minimum 50% yield even when depleted
        return max(1.0 - ($depletion * 0.5), 0.5);
    }

    /**
     * Get depletion level as a percentage (0-100).
     * For display to players (probe reports).
     */
    public function getDepletionPercent(int $galaxy, int $system): int
    {
        $this->applyRecovery($galaxy, $system);

        $activity = \Illuminate\Support\Facades\DB::table('expedition_activity')
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->first();

        if (!$activity) {
            return 0;
        }

        return (int) min(($activity->expedition_count / self::DEPLETION_THRESHOLD) * 100, 100);
    }

    /**
     * Get the "nothing" weight boost from depletion.
     * Depleted systems have higher chance of "nothing" results.
     */
    public function getDepletionNothingBoost(int $galaxy, int $system): int
    {
        $depletion = 1.0 - $this->getDepletionMultiplier($galaxy, $system);

        // At full depletion, add up to 2000 to "nothing" weight (out of 10000 total)
        return (int) ($depletion * 2000);
    }

    /**
     * Apply recovery to a system (reduce expedition_count based on time elapsed).
     */
    private function applyRecovery(int $galaxy, int $system): void
    {
        $activity = \Illuminate\Support\Facades\DB::table('expedition_activity')
            ->where('galaxy', $galaxy)
            ->where('system', $system)
            ->first();

        if (!$activity || $activity->expedition_count <= 0) {
            return;
        }

        $lastExpedition = \Carbon\Carbon::parse($activity->last_expedition);
        $hoursElapsed = $lastExpedition->diffInHours(now());

        if ($hoursElapsed <= 0) {
            return;
        }

        $recovered = (int) ($hoursElapsed * self::RECOVERY_RATE);
        $newCount = max(0, $activity->expedition_count - $recovered);

        if ($newCount !== $activity->expedition_count) {
            \Illuminate\Support\Facades\DB::table('expedition_activity')
                ->where('id', $activity->id)
                ->update([
                    'expedition_count' => $newCount,
                    'updated_at' => now(),
                ]);
        }
    }
}

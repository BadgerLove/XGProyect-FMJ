<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotCombatLog extends Model
{
    protected $table = 'bot_combat_log';

    protected $fillable = [
        'attacker_id',
        'defender_id',
        'fleet_id',
        'target_coords',
        'result',
        'attacker_ships_sent',
        'attacker_ships_lost',
        'defender_ships_sent',
        'defender_ships_lost',
        'loot_metal',
        'loot_crystal',
        'loot_deuterium',
        'debris_metal',
        'debris_crystal',
        'attacker_fleet',
        'defender_fleet',
        'loss_rate',
    ];

    protected $casts = [
        'attacker_ships_sent' => 'integer',
        'attacker_ships_lost' => 'integer',
        'defender_ships_sent' => 'integer',
        'defender_ships_lost' => 'integer',
        'loot_metal' => 'integer',
        'loot_crystal' => 'integer',
        'loot_deuterium' => 'integer',
        'debris_metal' => 'integer',
        'debris_crystal' => 'integer',
        'loss_rate' => 'float',
    ];

    public function attacker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attacker_id');
    }

    public function defender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'defender_id');
    }

    // Scope: get combat history for a user (as attacker or defender)
    public function scopeForUser($query, int $userId)
    {
        return $query->where('attacker_id', $userId)
            ->orWhere('defender_id', $userId);
    }

    // Scope: get wins only
    public function scopeWins($query, int $userId)
    {
        return $query->where('attacker_id', $userId)
            ->where('result', 'win');
    }

    // Scope: get losses only
    public function scopeLosses($query, int $userId)
    {
        return $query->where('defender_id', $userId)
            ->where('result', 'win');
    }

    // Get win rate for a user
    public static function getWinRate(int $userId): float
    {
        $attacks = self::where('attacker_id', $userId)->count();
        if ($attacks === 0) {
            return 0;
        }
        $wins = self::where('attacker_id', $userId)->where('result', 'win')->count();
        return round($wins / $attacks, 2);
    }

    // Get total loot for a user
    public static function getTotalLoot(int $userId): array
    {
        $wins = self::where('attacker_id', $userId)->where('result', 'win')->get();
        return [
            'metal' => $wins->sum('loot_metal'),
            'crystal' => $wins->sum('loot_crystal'),
            'deuterium' => $wins->sum('loot_deuterium'),
        ];
    }

    // Get recent attacks against a target
    public static function getRecentAttacks(int $attackerId, int $defenderId, int $limit = 10)
    {
        return self::where('attacker_id', $attackerId)
            ->where('defender_id', $defenderId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}

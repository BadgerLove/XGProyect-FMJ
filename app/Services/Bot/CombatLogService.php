<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\BotCombatLog;
use Xgp\App\Libraries\BattleEngine\Core\BattleReport;
use Xgp\App\Libraries\FleetsLib;

/**
 * Log combat outcomes for bot tracking and analysis.
 */
class CombatLogService
{
    /**
     * Log a combat result after a battle resolves.
     *
     * @param array $fleet_row The attacking fleet data
     * @param BattleReport $report The battle report
     * @param array $defender The defender user data
     */
    public function logCombat(array $fleet_row, BattleReport $report, array $defender): void
    {
        try {
            $attackerId = (int) $fleet_row['fleet_owner'];
            $defenderId = (int) ($defender['id'] ?? 0);

            // Determine result
            if ($report->attackerHasWin()) {
                $result = 'win';
            } elseif ($report->isAdraw()) {
                $result = 'draw';
            } else {
                $result = 'loss';
            }

            // Get fleet data before battle
            $attackersBefore = $report->getRound('START')->getAfterBattleAttackers();
            $attackersAfter = $report->getAfterBattleAttackers();

            // Count attacker ships
            $attackerSent = 0;
            $attackerLost = 0;
            $attackerFleet = [];

            foreach ($attackersBefore->getIterator() as $player) {
                foreach ($player->getIterator() as $fleet) {
                    foreach ($fleet->getIterator() as $shipType => $ship) {
                        $count = $ship->getCount();
                        $attackerSent += $count;
                        $attackerFleet[$shipType] = ($attackerFleet[$shipType] ?? 0) + $count;
                    }
                }
            }

            $attackerAfterCount = 0;
            foreach ($attackersAfter->getIterator() as $player) {
                foreach ($player->getIterator() as $fleet) {
                    foreach ($fleet->getIterator() as $shipType => $ship) {
                        $attackerAfterCount += $ship->getCount();
                    }
                }
            }
            $attackerLost = $attackerSent - $attackerAfterCount;

            // Count defender ships
            $defendersBefore = $report->getRound('START')->getAfterBattleDefenders();
            $defendersAfter = $report->getAfterBattleDefenders();

            $defenderSent = 0;
            $defenderFleet = [];

            foreach ($defendersBefore->getIterator() as $player) {
                foreach ($player->getIterator() as $fleet) {
                    foreach ($fleet->getIterator() as $shipType => $ship) {
                        $count = $ship->getCount();
                        $defenderSent += $count;
                        $defenderFleet[$shipType] = ($defenderFleet[$shipType] ?? 0) + $count;
                    }
                }
            }

            $defenderAfterCount = 0;
            foreach ($defendersAfter->getIterator() as $player) {
                foreach ($player->getIterator() as $fleet) {
                    foreach ($fleet->getIterator() as $shipType => $ship) {
                        $defenderAfterCount += $ship->getCount();
                    }
                }
            }
            $defenderLost = $defenderSent - $defenderAfterCount;

            // Get loot
            $steal = $report->getSteal();
            $lootMetal = (int) ($steal['metal'] ?? 0);
            $lootCrystal = (int) ($steal['crystal'] ?? 0);
            $lootDeuterium = (int) ($steal['deuterium'] ?? 0);

            // Get debris
            list($debrisMetal, $debrisCrystal) = $report->getDebris();

            // Calculate loss rate
            $lossRate = $attackerSent > 0 ? $attackerLost / $attackerSent : 0;

            // Build coords string
            $coords = $fleet_row['fleet_end_galaxy'] . ':'
                . $fleet_row['fleet_end_system'] . ':'
                . $fleet_row['fleet_end_planet'];

            BotCombatLog::create([
                'attacker_id' => $attackerId,
                'defender_id' => $defenderId,
                'fleet_id' => (int) $fleet_row['fleet_id'],
                'target_coords' => $coords,
                'result' => $result,
                'attacker_ships_sent' => $attackerSent,
                'attacker_ships_lost' => $attackerLost,
                'defender_ships_sent' => $defenderSent,
                'defender_ships_lost' => $defenderLost,
                'loot_metal' => $lootMetal,
                'loot_crystal' => $lootCrystal,
                'loot_deuterium' => $lootDeuterium,
                'debris_metal' => (int) $debrisMetal,
                'debris_crystal' => (int) $debrisCrystal,
                'attacker_fleet' => json_encode($attackerFleet),
                'defender_fleet' => json_encode($defenderFleet),
                'loss_rate' => round($lossRate, 4),
            ]);
        } catch (\Exception $e) {
            // Don't let logging failures break the game
            \Log::warning('CombatLogService failed: ' . $e->getMessage());
        }
    }
}

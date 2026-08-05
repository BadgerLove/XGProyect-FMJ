<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Missions;

use App\Core\GameObjects\GameObjectRegistry;
use App\Core\GameObjects\Ship;
use App\Models\UsersStatistics;
use App\Services\FormatService;
use App\Services\Game\Formulas\ExpeditionService;
use App\Services\Game\Formulas\FleetsService;
use Xgp\App\Libraries\FleetsLib;
use Xgp\App\Libraries\Functions;

/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class Expedition extends Missions
{
    private int $resourceExpeditionPoints = 0;
    private int $shipExpeditionPoints = 0;
    private int $fleetTotalValue = 0;
    private int $fleetCapacity = 0;

    public function __construct(
        private ExpeditionService $expeditionService,
        private FormatService $formatService,
        private GameObjectRegistry $registry
    ) {
        parent::__construct();
    }

    public function expeditionMission(array $fleet): void
    {
        // do mission
        if (parent::canStartMission($fleet)) {
            $this->setExpeditionPoints($fleet);

            // Record expedition activity for depletion tracking
            $expGalaxy = (int) $fleet['fleet_end_galaxy'];
            $expSystem = (int) $fleet['fleet_end_system'];
            $this->expeditionService->recordExpedition($expGalaxy, $expSystem);

            // Check for probes in fleet — send depletion report
            $fleetShips = \Xgp\App\Libraries\FleetsLib::getFleetShipsArray($fleet['fleet_array']);
            if (isset($fleetShips[210]) && $fleetShips[210] > 0) {
                $depletionPct = $this->expeditionService->getDepletionPercent($expGalaxy, $expSystem);
                $this->expeditionMessage(
                    (int) $fleet['fleet_owner'],
                    sprintf('System %d:%d depletion level: %d%%', $expGalaxy, $expSystem, $depletionPct),
                    (int) $fleet['fleet_end_stay'],
                    ['galaxy' => $expGalaxy, 'system' => $expSystem, 'planet' => $fleet['fleet_end_planet']]
                );
            }

            switch ($this->expeditionService->getExpeditionResultFromDeck((int) $fleet['fleet_owner'], $expGalaxy, $expSystem)) {
                case 'darkMatter':
                    $this->resultDarkMatter($fleet);
                    break;
                case 'ships':
                    $this->resultShips($fleet);
                    break;
                case 'resources':
                    $this->resultResources($fleet);
                    break;
                case 'pirates':
                    $this->resultPirates($fleet);
                    break;
                case 'aliens':
                    $this->resultAliens($fleet);
                    break;
                case 'delay':
                    $this->resultDelay($fleet);
                    break;
                case 'early':
                    $this->resultEarly($fleet);
                    break;
                case 'merchant':
                    //$this->resultMerchant($fleet);
                    $this->resultNothing($fleet);
                    break;
                case 'blackHole':
                    $this->resultBlackHole($fleet);
                    break;
                case 'nothing':
                default:
                    $this->resultNothing($fleet);
                    break;
            }
        } elseif (parent::canCompleteMission($fleet)) {
            $fleetUsedStorage = $fleet['fleet_resource_metal'] + $fleet['fleet_resource_crystal'] + $fleet['fleet_resource_deuterium'];

            if ($fleetUsedStorage === 0) {
                $message = sprintf(
                    __('game/missions.mi_fleet_back_without_resources'),
                    $fleet['planet_end_name'],
                    $this->formatService->prettyCoords((int)$fleet['fleet_end_galaxy'], (int)$fleet['fleet_end_system'], (int)$fleet['fleet_end_planet']),
                    $fleet['planet_start_name'],
                    $this->formatService->prettyCoords((int)$fleet['fleet_start_galaxy'], (int)$fleet['fleet_start_system'], (int)$fleet['fleet_start_planet']),
                );

                $this->expeditionMessage(
                    (int) $fleet['fleet_owner'],
                    $message,
                    (int) $fleet['fleet_end_stay'],
                    [
                        'galaxy' => $fleet['fleet_end_galaxy'],
                        'system' => $fleet['fleet_end_system'],
                        'planet' => $fleet['fleet_end_planet'],
                    ]
                );
            } else {
                $message = sprintf(
                    __('game/missions.mi_fleet_back_with_resources'),
                    $fleet['planet_end_name'],
                    $this->formatService->prettyCoords((int) $fleet['fleet_end_galaxy'], (int) $fleet['fleet_end_system'], (int) $fleet['fleet_end_planet']),
                    $fleet['planet_start_name'],
                    $this->formatService->prettyCoords((int) $fleet['fleet_start_galaxy'], (int) $fleet['fleet_start_system'], (int) $fleet['fleet_start_planet']),
                    $this->formatService->prettyNumber((int) $fleet['fleet_resource_metal']),
                    $this->formatService->prettyNumber((int) $fleet['fleet_resource_crystal']),
                    $this->formatService->prettyNumber((int) $fleet['fleet_resource_deuterium'])
                );

                $this->expeditionMessage(
                    (int) $fleet['fleet_owner'],
                    $message,
                    (int) $fleet['fleet_end_stay'],
                    [
                        'galaxy' => $fleet['fleet_end_galaxy'],
                        'system' => $fleet['fleet_end_system'],
                        'planet' => $fleet['fleet_end_planet'],
                    ]
                );
            }

            parent::restoreFleet($fleet, true);
            parent::removeFleet($fleet['fleet_id']);
        }
    }

    private function setExpeditionPoints(array $fleet): void
    {
        $expeditionPoints = 0;

        foreach (FleetsLib::getFleetShipsArray($fleet['fleet_array']) as $id => $count) {
            $ship = $this->registry->ships()->get((int) $id);

            if (!$ship instanceof Ship) {
                continue;
            }

            $price = $ship->getPrice();

            if (in_array((int) $id, $this->expeditionService->getPossibleShips(), true)) {
                $expeditionPoints += $this->expeditionService->calculateExpeditionPoints(
                    $price->getMetal() + $price->getCrystal()
                ) * $count;
            }

            $this->fleetTotalValue += ($price->getMetal() + $price->getCrystal() + $price->getDeuterium()) * $count;

            $this->fleetCapacity += app(FleetsService::class)->getMaxStorage(
                $ship->getCapacity(),
                (int) $fleet['research_hyperspace_technology']
            ) * $count;
        }

        $topPlayerPoints = UsersStatistics::max('user_statistic_total_points');

        if (!is_numeric($topPlayerPoints)) {
            $topPlayerPoints = 0.0;
        }

        $topPlayerPoints = (float) $topPlayerPoints;

        $maxResourceFindExpeditionPoints = $this->expeditionService->getMaxExpeditionPoints(
            $topPlayerPoints
        );
        $maxShipsFindExpeditionPoints = $this->expeditionService->getMaxShipsExpeditionPoints(
            $topPlayerPoints
        );

        $this->resourceExpeditionPoints = $expeditionPoints;
        $this->shipExpeditionPoints = $expeditionPoints;

        // limit the amount of resources that can be found
        if ($expeditionPoints > $maxResourceFindExpeditionPoints) {
            $this->resourceExpeditionPoints = $maxResourceFindExpeditionPoints;
        }

        // limit the amount of ships that can be found
        if ($expeditionPoints > $maxShipsFindExpeditionPoints) {
            $this->shipExpeditionPoints = $maxShipsFindExpeditionPoints;
        }
    }

    /**
     * @todo needs polishing, there are 3 types of packages
     * small package: 300-400 DM
     * medium package: 500-700 DM
     * large package: 1.000-1.800 DM
     *
     * needs review because I replicated previous used logic for resources
     * I couldn't find any rule behind this...
     */
    private function resultDarkMatter(array $fleet): void
    {
        $stayDuration = max(0, (int) $fleet['fleet_end_stay'] - (int) $fleet['fleet_start_time']);
        $durationMultiplier = $this->getDurationMultiplier($stayDuration);
        $darkMatterFound = (int) ($this->expeditionService->getDarkMatterSourceSize(
            $this->expeditionService->calculateDarkMatterSourceSize()
        ) * $durationMultiplier);

        $message = sprintf(
            __('game/expedition.exp_dm_' . mt_rand(1, 5)),
            $this->formatService->prettyNumber($darkMatterFound)
        );

        $this->expeditionMessage(
            (int) $fleet['fleet_owner'],
            $message,
            (int) $fleet['fleet_end_stay'],
            [
                'galaxy' => $fleet['fleet_end_galaxy'],
                'system' => $fleet['fleet_end_system'],
                'planet' => $fleet['fleet_end_planet'],
            ]
        );

        $this->updateDarkMatter((int) $fleet['fleet_owner'], $darkMatterFound);

        parent::returnFleet($fleet['fleet_id']);
    }

    /**
     * @todo probably not 100% like the original game
     */
    private function resultShips(array $fleet): void
    {
        $currentFleet = FleetsLib::getFleetShipsArray($fleet['fleet_array']);
        $possibleShips = $this->expeditionService->getPossibleShips();
        
        $eligibleShips = [];
        $totalEligibleCount = 0;
        
        foreach ($currentFleet as $shipId => $count) {
            if ($count > 0 && in_array((int)$shipId, $possibleShips, true)) {
                $eligibleShips[$shipId] = $count;
                $totalEligibleCount += $count;
            }
        }
        
        if (empty($eligibleShips) || $totalEligibleCount === 0) {
            $this->resultNothing($fleet);
            return;
        }

        // Determine tier percentages based on total eligible ship count
        if ($totalEligibleCount <= 100) {
            $minPct = 10;
            $maxPct = 100;
        } elseif ($totalEligibleCount <= 1000) {
            $minPct = 10;
            $maxPct = 50;
        } elseif ($totalEligibleCount <= 10000) {
            $minPct = 5;
            $maxPct = 30;
        } else {
            $minPct = 3;
            $maxPct = 20;
        }
        
        $rollPercent = mt_rand($minPct, $maxPct);
        
        $stayDuration = max(0, (int) $fleet['fleet_end_stay'] - (int) $fleet['fleet_start_time']);
        $durationMultiplier = $this->getDurationMultiplier($stayDuration);
        
        $finalMultiplier = ($rollPercent / 100.0) * $durationMultiplier;
        
        $foundShip = [];
        $totalFound = 0;
        
        foreach ($eligibleShips as $shipId => $count) {
            // Fractional probability: e.g., 1.4 ships = 1 guaranteed, 40% chance of a 2nd
            $exactAmount = $count * $finalMultiplier;
            $guaranteed = (int) floor($exactAmount);
            $fraction = $exactAmount - $guaranteed;
            
            if ($fraction > 0 && mt_rand(1, 10000) <= (int) ($fraction * 10000)) {
                $guaranteed++;
            }
            
            if ($guaranteed > 0) {
                $foundShip[$shipId] = $guaranteed;
                $totalFound += $guaranteed;
            }
        }
        
        if ($totalFound === 0) {
            $this->resultNothing($fleet);
            return;
        }

        foreach ($foundShip as $shipId => $count) {
            $currentFleet[$shipId] += $count;
        }

        $newShips = [];
        $found_ship_message = '';
        
        foreach ($currentFleet as $ship => $count) {
            if ($count > 0) {
                $newShips[$ship] = $count;
            }
        }
        
        foreach ($foundShip as $ship => $count) {
            if ($count > 0) {
                $found_ship_message .= __('game/ships.' . $this->resource[$ship]) . ': ' . $this->formatService->prettyNumber($count) . '<br>';
            }
        }
        
        $this->updateFleetArrayById([
            'ships' => FleetsLib::setFleetShipsArray($newShips),
            'fleet_id' => $fleet['fleet_id'],
        ]);

        $message = sprintf(
            __('game/expedition.exp_new_ships_' . mt_rand(1, 7)),
            $found_ship_message
        );

        $this->expeditionMessage(
            (int) $fleet['fleet_owner'],
            $message,
            (int) $fleet['fleet_end_stay'],
            [
                'galaxy' => $fleet['fleet_end_galaxy'],
                'system' => $fleet['fleet_end_system'],
                'planet' => $fleet['fleet_end_planet'],
            ]
        );
    }

    private function resultResources(array $fleet): void
    {
        // fleet capacity
        $fleetUsedStorage = $fleet['fleet_resource_metal'] + $fleet['fleet_resource_crystal'] + $fleet['fleet_resource_deuterium'];
        $fleetMaxCapacity = $this->fleetCapacity - $fleetUsedStorage;

        // New: fleet-value-based resource calculation
        $typeObtained = $this->expeditionService->calculateResourceTypeObtained();

        [$minPct, $maxPct] = $this->expeditionService->getFleetResourceTier($this->fleetTotalValue);
        $rollPercent = mt_rand($minPct, $maxPct);

        $resourceDivider = match ($typeObtained) {
            'metal' => 1,
            'crystal' => 2,
            'deuterium' => 3,
        };

        $foundAmount = (int) floor(($this->fleetTotalValue * $rollPercent / 100) / $resourceDivider);

        $stayDuration = max(0, (int) $fleet['fleet_end_stay'] - (int) $fleet['fleet_start_time']);
        $durationMultiplier = $this->getDurationMultiplier($stayDuration);
        $foundAmount = (int) ($foundAmount * $durationMultiplier);

        if ($foundAmount > $fleetMaxCapacity) {
            $fillFleetStorage = $fleetMaxCapacity;
        } else {
            $fillFleetStorage = $foundAmount;
        }

        $this->updateFleetResourcesById(
            (int) $fleet['fleet_id'],
            $typeObtained,
            $fillFleetStorage
        );

        $message = sprintf(
            __('game/expedition.exp_new_resources_' . mt_rand(1, 4)),
            $this->formatService->prettyNumber($fillFleetStorage),
            __('game/resources.' . strtolower($typeObtained))
        );

        $this->expeditionMessage(
            (int) $fleet['fleet_owner'],
            $message,
            (int) $fleet['fleet_end_stay'],
            [
                'galaxy' => $fleet['fleet_end_galaxy'],
                'system' => $fleet['fleet_end_system'],
                'planet' => $fleet['fleet_end_planet'],
            ]
        );

        parent::returnFleet($fleet['fleet_id']);
    }

    /**
     * Handle abstract pirate combat (fleet loss)
     */
    private function resultPirates(array $fleet): void
    {
        $roll = mt_rand(1, 100);
        if ($roll <= 89) {
            $lossPercent = mt_rand(5, 15) / 100; // Normal: 5-15%
        } elseif ($roll <= 99) {
            $lossPercent = mt_rand(15, 30) / 100; // Large: 15-30%
        } else {
            $lossPercent = mt_rand(30, 50) / 100; // XL: 30-50%
        }

        $this->applyCombatLoss($fleet, $lossPercent, 'Pirates');
    }

    /**
     * Handle abstract alien combat (fleet loss)
     */
    private function resultAliens(array $fleet): void
    {
        $roll = mt_rand(1, 100);
        if ($roll <= 89) {
            $lossPercent = mt_rand(10, 25) / 100; // Normal: 10-25%
        } elseif ($roll <= 99) {
            $lossPercent = mt_rand(25, 50) / 100; // Large: 25-50%
        } else {
            $lossPercent = mt_rand(50, 80) / 100; // XL: 50-80%
        }

        $this->applyCombatLoss($fleet, $lossPercent, 'Aliens');
    }

    /**
     * Helper to apply abstract combat loss
     */
    private function applyCombatLoss(array $fleet, float $lossPercent, string $enemyType): void
    {
        $currentFleet = FleetsLib::getFleetShipsArray($fleet['fleet_array']);
        $newShips = [];
        $lostShips = [];
        $survivingCount = 0;

        foreach ($currentFleet as $ship => $count) {
            if ($count > 0) {
                // Ensure at least some survive if it's not a full wipe, but handle rounding
                $surviving = (int) floor($count * (1.0 - $lossPercent));
                $lost = $count - $surviving;

                if ($surviving > 0) {
                    $newShips[$ship] = $surviving;
                    $survivingCount += $surviving;
                }
                
                if ($lost > 0) {
                    $lostShips[$ship] = $lost;
                }
            }
        }

        if ($survivingCount > 0) {
            $message = sprintf(
                "Your expedition was ambushed by %s! You suffered a %d%% fleet loss but managed to escape.",
                $enemyType,
                (int)($lossPercent * 100)
            );

            $this->expeditionMessage(
                $fleet['fleet_owner'],
                $message,
                (int) $fleet['fleet_end_stay'],
                [
                    'galaxy' => $fleet['fleet_end_galaxy'],
                    'system' => $fleet['fleet_end_system'],
                    'planet' => $fleet['fleet_end_planet'],
                ]
            );

            if (!empty($lostShips)) {
                $this->updateLostShipsAndDefensePoints($fleet['fleet_owner'], $lostShips);
            }

            $this->updateFleetArrayById([
                'ships' => FleetsLib::setFleetShipsArray($newShips),
                'fleet_id' => $fleet['fleet_id'],
            ]);
        } else {
            // Entire fleet wiped out
            $message = sprintf(
                "Your expedition was ambushed and completely destroyed by %s!",
                $enemyType
            );

            $this->expeditionMessage(
                $fleet['fleet_owner'],
                $message,
                (int) $fleet['fleet_end_stay'],
                [
                    'galaxy' => $fleet['fleet_end_galaxy'],
                    'system' => $fleet['fleet_end_system'],
                    'planet' => $fleet['fleet_end_planet'],
                ]
            );

            $this->updateLostShipsAndDefensePoints(
                $fleet['fleet_owner'],
                $currentFleet
            );
            parent::removeFleet($fleet['fleet_id']);
        }
    }

    /**
     * @todo probably not 100% like the original game
     */
    private function resultDelay(array $fleet): void
    {
        $fleetDelayMultiplier = $this->expeditionService->getFleetDeplay();
        $returnTime = (int) $fleet['fleet_end_time'] - (int) $fleet['fleet_end_stay'];

        $this->updateFleetEndTime(
            (int) $fleet['fleet_id'],
            (int) ($fleet['fleet_end_time'] + ($returnTime * $fleetDelayMultiplier))
        );

        $this->expeditionMessage(
            (int) $fleet['fleet_owner'],
            __('game/expedition.exp_delay_' . mt_rand(1, 5)),
            (int) $fleet['fleet_end_stay'],
            [
                'galaxy' => $fleet['fleet_end_galaxy'],
                'system' => $fleet['fleet_end_system'],
                'planet' => $fleet['fleet_end_planet'],
            ]
        );

        parent::returnFleet($fleet['fleet_id']);
    }

    /**
     * @todo probably not 100% like the original game
     */
    private function resultEarly(array $fleet): void
    {
        $returnTime = (int) $fleet['fleet_end_time'] - (int) $fleet['fleet_end_stay'];

        $this->updateFleetEndTime(
            (int) $fleet['fleet_id'],
            (int) ($fleet['fleet_end_time'] - ($returnTime / 2))
        );

        $this->expeditionMessage(
            (int) $fleet['fleet_owner'],
            __('game/expedition.exp_early_' . mt_rand(1, 5)),
            (int) $fleet['fleet_end_stay'],
            [
                'galaxy' => $fleet['fleet_end_galaxy'],
                'system' => $fleet['fleet_end_system'],
                'planet' => $fleet['fleet_end_planet'],
            ]
        );

        parent::returnFleet($fleet['fleet_id']);
    }

    /**
     * @todo implement
     */
    private function resultMerchant(array $fleet): void
    {
    }

    /**
     * @todo probably not 100% like the original game
     */
    private function resultBlackHole(array $fleet): void
    {
        $lostChances = (mt_rand(0, 3) * 33 + 1) / 100;

        if ($lostChances == 1) {
            $this->expeditionMessage(
                $fleet['fleet_owner'],
                __('game/expedition.exp_lost_1'),
                (int) $fleet['fleet_end_stay'],
                [
                    'galaxy' => $fleet['fleet_end_galaxy'],
                    'system' => $fleet['fleet_end_system'],
                    'planet' => $fleet['fleet_end_planet'],
                ]
            );

            $this->updateLostShipsAndDefensePoints(
                $fleet['fleet_owner'],
                FleetsLib::getFleetShipsArray($fleet['fleet_array'])
            );
            parent::removeFleet($fleet['fleet_id']);
        } else {
            $newShips = [];
            $lostShips = [];
            $lostAll = true;

            foreach (FleetsLib::getFleetShipsArray($fleet['fleet_array']) as $ship => $amount) {
                if (floor($amount * $lostChances) != 0) {
                    $lostShips[$ship] = floor($amount * $lostChances);
                    $newShips[$ship] = ($amount - $lostShips[$ship]);
                    $lostAll = false;
                }
            }

            if (!$lostAll) {
                $this->expeditionMessage(
                    $fleet['fleet_owner'],
                    __('game/expedition.exp_lost_1'),
                    (int) $fleet['fleet_end_stay'],
                    [
                        'galaxy' => $fleet['fleet_end_galaxy'],
                        'system' => $fleet['fleet_end_system'],
                        'planet' => $fleet['fleet_end_planet'],
                    ]
                );

                $this->updateLostShipsAndDefensePoints($fleet['fleet_owner'], $lostShips);
                $this->updateFleetArrayById([
                    'ships' => FleetsLib::setFleetShipsArray($newShips),
                    'fleet_id' => $fleet['fleet_id'],
                ]);
            } else {
                $this->expeditionMessage(
                    $fleet['fleet_owner'],
                    __('game/expedition.exp_lost_1'),
                    (int) $fleet['fleet_end_stay'],
                    [
                        'galaxy' => $fleet['fleet_end_galaxy'],
                        'system' => $fleet['fleet_end_system'],
                        'planet' => $fleet['fleet_end_planet'],
                    ]
                );

                $this->updateLostShipsAndDefensePoints(
                    $fleet['fleet_owner'],
                    FleetsLib::getFleetShipsArray($fleet['fleet_array'])
                );
                parent::removeFleet($fleet['fleet_id']);
            }
        }
    }

    private function resultNothing(array $fleet): void
    {
        $this->expeditionMessage(
            $fleet['fleet_owner'],
            __('game/expedition.exp_nothing_' . mt_rand(1, 6)),
            (int) $fleet['fleet_end_stay'],
            [
                'galaxy' => $fleet['fleet_end_galaxy'],
                'system' => $fleet['fleet_end_system'],
                'planet' => $fleet['fleet_end_planet'],
            ]
        );

        parent::returnFleet($fleet['fleet_id']);
    }

    private function expeditionMessage(int $owner, string $message, int $time, array $coords): void
    {
        $subject = sprintf(
            __('game/expedition.exp_report_title'),
            $this->formatService->prettyCoords((int) $coords['galaxy'], (int) $coords['system'], (int) $coords['planet'])
        );

        Functions::sendMessage(
            $owner,
            0,
            $time,
            5,
            __('game/missions.mi_fleet_command'),
            $subject,
            $message
        );
    }

    /**
     * Get a multiplier based on how many hours the fleet stayed.
     * Adjusted to scale from 1.0x at 1 hour to 3.0x at 8 hours.
     * Formula: 1 + (hours - 1) * (2 / 7)
     */
    private function getDurationMultiplier(int $staySeconds): float
    {
        $hours = max(1, round($staySeconds / 3600));
        return 1.0 + ($hours - 1) * (2 / 7);
    }
}

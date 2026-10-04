<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\GameObjects\GameObjectRegistry;
use App\Models\BuildingQueue;
use App\Models\Planets;
use App\Models\UsersStatistics;
use App\Services\FormatService;
use App\Services\Game\Formulas\DevelopmentsService;
use Xgp\App\Core\Enumerators\BuildingsEnumerator;
use Xgp\App\Core\Enumerators\ResearchEnumerator;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\StatisticsLibrary;

/**
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class BuildingQueueService
{
    private const MAX_QUEUE_SIZE = 5;

    public function __construct(
        private QueueSequenceService $queueSequenceService,
        private DevelopmentsService $developmentsService,
        private DevelopmentDataService $developmentDataService,
        private FormatService $formatService,
        private GameObjectRegistry $registry,
    ) {
    }

    /**
     * @return array{length: int, to_destroy: int, items: array<int, array<string, mixed>>}
     */
    public function getQueueData(Planets $planet): array
    {
        $items = $planet->buildingQueue()->get();

        return [
            'length' => $items->count(),
            'to_destroy' => $items->where('mode', 'destroy')->count(),
            'items' => $items->values()->toArray(),
        ];
    }

    /**
     * @param  array<string,mixed>  $user
     *
     * @SuppressWarnings("PHPMD.NPathComplexity")
     * @SuppressWarnings("PHPMD.ElseExpression")
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    public function add(Planets $planet, array $user, int $buildingId, string $mode = 'build'): bool
    {
        $queue = $planet->buildingQueue()->get();
        $count = $queue->count();

        if ($count >= self::MAX_QUEUE_SIZE) {
            return false;
        }

        if ($mode === 'build') {
            $terraformerCol = $this->registry->get(BuildingsEnumerator::BUILDING_TERRAFORMER)->getName();
            $terraformerLvl = (int) ($planet->buildings?->$terraformerCol ?? 0);
            $maxFields = $this->developmentsService->maxFields($planet->planet_field_max, $terraformerLvl);

            if ($planet->planet_field_current >= $maxFields - $count) {
                return false;
            }
        }

        $levels = $this->developmentDataService->levelsFromPlanet($planet, $user);

        if (!$this->developmentsService->isDevelopmentAllowed($buildingId, $levels)) {
            return false;
        }

        $buildingCol = $this->registry->get($buildingId)->getName();
        $roboticsCol = $this->registry->get(BuildingsEnumerator::BUILDING_ROBOT_FACTORY)->getName();
        $naniteCol = $this->registry->get(BuildingsEnumerator::BUILDING_NANO_FACTORY)->getName();
        $alreadyQueued = $queue->where('building_id', $buildingId)->count();
        $currentLevel = (int) ($planet->buildings?->$buildingCol ?? 0);
        $robotics = (int) ($planet->buildings?->$roboticsCol ?? 0);
        $nanite = (int) ($planet->buildings?->$naniteCol ?? 0);

        if ($mode === 'build') {
            $levelForCalc = $currentLevel + $alreadyQueued;
            $targetLevel = $levelForCalc + 1;
            $duration = $this->developmentsService->developmentTime(
                $buildingId,
                $levelForCalc,
                $robotics,
                $nanite,
                0,
                0,
                false
            );
        } else {
            $levelForCalc = $currentLevel - $alreadyQueued;
            $targetLevel = $levelForCalc - 1;
            $duration = (int) $this->developmentsService->tearDownTime(
                $buildingId,
                $levelForCalc,
                $robotics,
                $nanite
            );
        }

        $forDestroy = $mode === 'destroy';
        $ionTech = $forDestroy
            ? (int) ($user[$this->registry->get(ResearchEnumerator::research_ionic_technology)->getName()] ?? 0)
            : 0;

        if ($count === 0) {
            if (!$this->developmentsService->isDevelopmentPayable(
                $this->developmentDataService->planetResources($planet),
                $buildingId,
                $levelForCalc,
                true,
                $forDestroy,
                $ionTech
            )) {
                return false;
            }

            $price = $this->developmentsService->developmentPrice($buildingId, $levelForCalc, true, $forDestroy, $ionTech);
            $planet->planet_metal -= $price['metal'] ?? 0;
            $planet->planet_crystal -= $price['crystal'] ?? 0;
            $planet->planet_deuterium -= $price['deuterium'] ?? 0;
        }

        $endTime = $count === 0
            ? time() + $duration
            : $queue->last()->end_time + $duration;

        BuildingQueue::create([
            'planet_id' => $planet->planet_id,
            'position' => $count + 1,
            'building_id' => $buildingId,
            'target_level' => $targetLevel,
            'mode' => $mode,
            'duration' => $duration,
            'end_time' => $endTime,
        ]);

        if ($count === 0) {
            $planet->planet_b_building = $endTime;
            $planet->save();
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $user
     */
    public function cancelFirst(Planets $planet, array $user): bool
    {
        $firstItem = $planet->buildingQueue()->where('position', 1)->first();

        if (!$firstItem) {
            return false;
        }

        $forDestroy = $firstItem->mode === 'destroy';
        $ionTechCol = $this->registry->get(ResearchEnumerator::research_ionic_technology)->getName();
        $ionTech = $forDestroy ? (int) ($user[$ionTechCol] ?? 0) : 0;
        $buildingCol = $this->registry->get($firstItem->building_id)->getName();
        $currentLevel = (int) ($planet->buildings?->$buildingCol ?? 0);

        $refund = $this->developmentsService->developmentPrice(
            $firstItem->building_id,
            $currentLevel,
            true,
            $forDestroy,
            $ionTech
        );

        $planet->planet_metal += $refund['metal'] ?? 0;
        $planet->planet_crystal += $refund['crystal'] ?? 0;
        $planet->planet_deuterium += $refund['deuterium'] ?? 0;

        $firstItem->delete();

        $remaining = $planet->buildingQueue()->orderBy('position')->get();

        if ($remaining->isEmpty()) {
            $planet->planet_b_building = 0;
            $planet->save();

            return true;
        }

        $roboticsCol = $this->registry->get(BuildingsEnumerator::BUILDING_ROBOT_FACTORY)->getName();
        $naniteCol = $this->registry->get(BuildingsEnumerator::BUILDING_NANO_FACTORY)->getName();
        $robotics = (int) ($planet->buildings?->$roboticsCol ?? 0);
        $nanite = (int) ($planet->buildings?->$naniteCol ?? 0);
        $this->queueSequenceService->rebuildAfterHeadRemoval(
            $remaining,
            $firstItem->building_id,
            time(),
            static fn (BuildingQueue $item): int => $item->building_id,
            static function (BuildingQueue $item): void {
                $item->target_level -= 1;
            },
            function (BuildingQueue $item) use ($planet, $robotics, $nanite): int {
                $itemCol = $this->registry->get($item->building_id)->getName();
                $itemLvl = (int) ($planet->buildings?->$itemCol ?? 0);

                return $item->mode === 'build'
                    ? $this->developmentsService->developmentTime($item->building_id, $itemLvl, $robotics, $nanite, 0, 0, false)
                    : (int) $this->developmentsService->tearDownTime($item->building_id, $itemLvl, $robotics, $nanite);
            }
        );

        foreach ($remaining as $item) {
            $item->save();
        }

        $nextItem = $remaining->first();

        if (!$nextItem instanceof BuildingQueue) {
            return true;
        }

        $planet->planet_b_building = $nextItem->end_time;
        $planet->save();

        return true;
    }

    public function removeAt(Planets $planet, int $position): void
    {
        if ($position <= 1) {
            return;
        }

        $this->queueSequenceService->removeTailOccurrenceAtPosition(
            $planet->buildingQueue()->orderBy('position')->get(),
            $position,
            static fn (BuildingQueue $item): int => $item->building_id
        );
    }

    /**
     * @param  array<string,mixed>  $user
     */
    public function processCompletions(Planets $planet, array $user): void
    {
        while (true) {
            $first = $planet->buildingQueue()->where('position', 1)->first();

            if (!$first || $first->end_time > time()) {
                break;
            }

            // the next item starts when this one finished (not now), so queues keep running while the player is away
            $finishedAt = (int) $first->end_time;
            $this->applyCompletion($planet, $first);
            $this->advanceQueue($planet, $user, $finishedAt);
        }
    }

    /**
     * @SuppressWarnings("PHPMD.ElseExpression")
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    private function applyCompletion(Planets $planet, BuildingQueue $item): void
    {
        $buildingCol = $this->registry->get($item->building_id)->getName();
        $buildings = $planet->buildings;
        $isDestroy = $item->mode === 'destroy';

        if ($buildings) {
            $buildings->$buildingCol = $buildings->$buildingCol + ($isDestroy ? -1 : 1);
            $buildings->save();
        }

        if ($item->building_id === BuildingsEnumerator::BUILDING_MONDBASIS) {
            $planet->planet_field_current += 1;
            $planet->planet_field_max += FIELDS_BY_MOONBASIS_LEVEL;
        } elseif ($isDestroy) {
            $planet->planet_field_current -= 1;
        } else {
            $planet->planet_field_current += 1;
        }

        $newLevel = (int) ($buildings?->$buildingCol ?? 0);
        $points = StatisticsLibrary::calculatePoints($item->building_id, $newLevel);

        UsersStatistics::where('user_statistic_user_id', $planet->planet_user_id)
            ->increment('user_statistic_buildings_points', $points);

        $planet->planet_b_building = 0;
        $planet->save();

        $item->delete();

        foreach ($planet->buildingQueue()->orderBy('position')->get() as $r) {
            $r->position -= 1;
            $r->save();
        }
    }

    /**
     * @param  array<string,mixed>  $user
     */
    private function advanceQueue(Planets $planet, array $user, int $startTime): void
    {
        while (true) {
            $first = $planet->buildingQueue()->where('position', 1)->first();

            if (!$first) {
                break;
            }

            $forDestroy = $first->mode === 'destroy';
            $ionTechCol = $this->registry->get(ResearchEnumerator::research_ionic_technology)->getName();
            $ionTech = $forDestroy ? (int) ($user[$ionTechCol] ?? 0) : 0;
            $buildingCol = $this->registry->get($first->building_id)->getName();
            $currentLevel = (int) ($planet->buildings?->$buildingCol ?? 0);

            if ($forDestroy && $currentLevel === 0) {
                $first->delete();
                $this->shiftPositionsDown($planet, 1);

                continue;
            }

            $isPaid = $this->developmentsService->isDevelopmentPayable(
                $this->developmentDataService->planetResources($planet),
                $first->building_id,
                $currentLevel,
                true,
                $forDestroy,
                $ionTech
            );

            if ($isPaid) {
                $price = $this->developmentsService->developmentPrice(
                    $first->building_id,
                    $currentLevel,
                    true,
                    $forDestroy,
                    $ionTech
                );

                $planet->planet_metal -= $price['metal'] ?? 0;
                $planet->planet_crystal -= $price['crystal'] ?? 0;
                $planet->planet_deuterium -= $price['deuterium'] ?? 0;
                $planet->planet_b_building = $this->retimeQueue($planet, $startTime);
                $planet->save();
                break;
            }

            $this->sendInsufficientResourcesMessage($planet, $user, $first, $currentLevel);
            $first->delete();
            $this->shiftPositionsDown($planet, 1);
        }
    }

    /**
     * Work the queue's times out again from the planet's CURRENT Robotics/Nanite levels, starting at $startTime.
     * Times were fixed when each item was queued, so a building queued behind a Robotics Factory kept its slow
     * time, and items behind one dropped for lack of resources still waited out its time (Dale, 4 Oct 2026).
     *
     * @return int end time of the first item
     */
    private function retimeQueue(Planets $planet, int $startTime): int
    {
        $roboticsCol = $this->registry->get(BuildingsEnumerator::BUILDING_ROBOT_FACTORY)->getName();
        $naniteCol = $this->registry->get(BuildingsEnumerator::BUILDING_NANO_FACTORY)->getName();
        $robotics = (int) ($planet->buildings?->$roboticsCol ?? 0);
        $nanite = (int) ($planet->buildings?->$naniteCol ?? 0);
        $runningTime = $startTime;
        $firstEnd = $startTime;

        foreach ($planet->buildingQueue()->orderBy('position')->get() as $item) {
            $item->duration = $item->mode === 'build'
                ? $this->developmentsService->developmentTime($item->building_id, $item->target_level - 1, $robotics, $nanite, 0, 0, false)
                : (int) $this->developmentsService->tearDownTime($item->building_id, $item->target_level + 1, $robotics, $nanite);
            $runningTime += $item->duration;
            $item->end_time = $runningTime;
            $item->save();

            if ($item->position === 1) {
                $firstEnd = $runningTime;
            }
        }

        return $firstEnd;
    }

    private function shiftPositionsDown(Planets $planet, int $deletedPosition): void
    {
        foreach (
            $planet->buildingQueue()->where('position', '>', $deletedPosition)->orderBy('position')->get() as $item
        ) {
            $item->position -= 1;
            $item->save();
        }
    }

    /**
     * @param  array<string,mixed>  $user
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    private function sendInsufficientResourcesMessage(
        Planets $planet,
        array $user,
        BuildingQueue $item,
        int $currentLevel,
    ): void {
        $forDestroy = $item->mode === 'destroy';
        $ionTechCol = $this->registry->get(ResearchEnumerator::research_ionic_technology)->getName();
        $ionTech = $forDestroy ? (int) ($user[$ionTechCol] ?? 0) : 0;

        $price = $this->developmentsService->developmentPrice(
            $item->building_id,
            $currentLevel,
            true,
            $forDestroy,
            $ionTech
        );

        $insufficient = [];

        if (($price['metal'] ?? 0) > $planet->planet_metal) {
            $insufficient[] = __('game/global.metal');
        }
        if (($price['crystal'] ?? 0) > $planet->planet_crystal) {
            $insufficient[] = __('game/global.crystal');
        }
        if (($price['deuterium'] ?? 0) > $planet->planet_deuterium) {
            $insufficient[] = __('game/global.deuterium');
        }

        if (empty($insufficient)) {
            return;
        }

        $buildingName = $this->registry->get($item->building_id)->getName();
        $elementName = __('game/constructions.' . $buildingName);
        $galaxyUrl = 'game.php?page=galaxy&mode=3&galaxy=' . $planet->planet_galaxy . '&system=' . $planet->planet_system;
        $coordsText = $planet->planet_name . ' ' . $this->formatService->prettyCoords(
            $planet->planet_galaxy,
            $planet->planet_system,
            $planet->planet_planet
        );
        $coordsLink = $this->formatService->link($galaxyUrl, $coordsText);

        $message = sprintf(
            __('game/buildings.bd_building_queue_not_enough_resources'),
            __('game/buildings.bd_building_queue_' . $item->mode . '_order'),
            $elementName,
            $item->target_level,
            $coordsLink,
            implode(', ', $insufficient)
        );

        Functions::sendMessage(
            $planet->planet_user_id,
            0,
            0,
            5,
            __('game/buildings.bd_building_queue_not_enough_resources_from'),
            __('game/buildings.bd_building_queue_not_enough_resources_subject'),
            $message,
            true
        );
    }
}

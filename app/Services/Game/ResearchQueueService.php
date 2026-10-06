<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Core\GameObjects\GameObjectRegistry;
use App\Models\Buildings;
use App\Models\Planets;
use App\Models\ResearchQueue;
use App\Models\User;
use App\Models\UsersStatistics;
use App\Services\FormatService;
use App\Services\Game\Formulas\DevelopmentsService;
use Illuminate\Support\Collection;
use Xgp\App\Core\Enumerators\BuildingsEnumerator;
use Xgp\App\Core\Enumerators\ResearchEnumerator;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\StatisticsLibrary;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
class ResearchQueueService
{
    public const MAX_QUEUE_SIZE = 5;

    public function __construct(
        private GameObjectRegistry $registry,
        private QueueSequenceService $queueSequenceService,
        private DevelopmentDataService $developmentDataService,
        private DevelopmentsService $developmentsService,
        private FormatService $formatService,
    ) {
    }

    /**
     * @param  array<string,mixed>  $userData
     */
    public function add(User $user, Planets $planet, array $userData, int $techId, bool $technocrateActive): bool
    {
        $queue = $user->researchQueue()->orderBy('position')->get();
        $count = $queue->count();

        if ($count >= self::MAX_QUEUE_SIZE) {
            return false;
        }

        $levels = $this->developmentDataService->levelsFromPlanet($planet, $userData);

        if (!$this->developmentsService->isDevelopmentAllowed($techId, $levels)) {
            return false;
        }

        $techCol = $this->registry->get($techId)->getName();
        $alreadyQueued = $queue->where('tech_id', $techId)->count();
        $currentLevel = (int) ($userData[$techCol] ?? 0);
        $levelForCalc = $currentLevel + $alreadyQueued;

        $labLevel = $this->labLevel($user, $planet);
        $astrophysicsCol = $this->registry->get(ResearchEnumerator::research_astrophysics)->getName();
        $astrophysicsLevel = (int) ($userData[$astrophysicsCol] ?? 0);

        $duration = $this->developmentsService->developmentTime(
            $techId,
            $levelForCalc,
            0,
            0,
            $labLevel,
            $astrophysicsLevel,
            $technocrateActive
        );

        $endTime = (int) $queue->last()?->end_time + $duration;

        if ($count === 0) {
            if (!$this->developmentsService->isDevelopmentPayable(
                $this->developmentDataService->planetResources($planet),
                $techId,
                $levelForCalc
            )) {
                return false;
            }

            $endTime = time() + $duration;

            $cost = $this->developmentsService->developmentPrice($techId, $levelForCalc);
            $planet->planet_metal -= $cost['metal'] ?? 0;
            $planet->planet_crystal -= $cost['crystal'] ?? 0;
            $planet->planet_deuterium -= $cost['deuterium'] ?? 0;
            $planet->planet_b_tech_id = $techId;
            $planet->planet_b_tech = $endTime;
            $planet->save();

            $user->research->research_current_research = $planet->planet_id;
            $user->research->save();
        }

        ResearchQueue::create([
            'user_id' => $user->id,
            'planet_id' => $planet->planet_id,
            'position' => $count + 1,
            'tech_id' => $techId,
            'target_level' => $levelForCalc + 1,
            'duration' => $duration,
            'end_time' => $endTime,
        ]);

        return true;
    }

    public function cancel(User $user): bool
    {
        $item = $user->researchQueue()->where('position', 1)->first();

        if ($item === null) {
            return false;
        }

        $workingPlanet = Planets::find($item->planet_id);

        if ($workingPlanet !== null) {
            $cost = $this->developmentsService->developmentPrice($item->tech_id, $item->target_level - 1);
            $workingPlanet->planet_metal += $cost['metal'] ?? 0;
            $workingPlanet->planet_crystal += $cost['crystal'] ?? 0;
            $workingPlanet->planet_deuterium += $cost['deuterium'] ?? 0;
            $workingPlanet->planet_b_tech_id = 0;
            $workingPlanet->planet_b_tech = 0;
            $workingPlanet->save();
        }

        $item->delete();
        $currentTime = time();
        $this->retimeAfterHeadRemoval($user, $item->tech_id, $currentTime);
        $this->advanceQueue($user, $currentTime);

        return true;
    }

    public function removeAt(User $user, int $position): void
    {
        if ($position <= 1) {
            return;
        }

        $this->queueSequenceService->removeTailOccurrenceAtPosition(
            $user->researchQueue()->orderBy('position')->get(),
            $position,
            static fn (ResearchQueue $item): int => $item->tech_id
        );
    }

    public function processCompletions(User $user): void
    {
        while (true) {
            $item = $user->researchQueue()->where('position', 1)->first();

            if ($item === null || $item->end_time > time()) {
                break;
            }

            $techCol = $this->registry->get($item->tech_id)->getName();
            $newLevel = (int) $user->research->$techCol + 1;

            $user->research->$techCol = $newLevel;
            $user->research->research_current_research = 0;
            $user->research->save();

            $points = StatisticsLibrary::calculatePoints((string) $item->tech_id, $newLevel, 'tech');

            UsersStatistics::where('user_statistic_user_id', $user->id)
                ->increment('user_statistic_technology_points', $points);

            $activePlanet = Planets::find($item->planet_id);

            if ($activePlanet !== null) {
                $activePlanet->planet_b_tech_id = 0;
                $activePlanet->planet_b_tech = 0;
                $activePlanet->save();
            }

            $item->delete();
            $this->shiftPositionsDown($user);
            $this->advanceQueue($user, (int) $item->end_time);
        }
    }

    public function labLevel(User $user, Planets $planet): int
    {
        $intergalCol = $this->registry->get(ResearchEnumerator::research_intergalactic_research_network)->getName();
        $intergalLevel = (int) $user->research->$intergalCol;

        if ($intergalLevel < 1) {
            $labCol = $this->registry->get(BuildingsEnumerator::BUILDING_LABORATORY)->getName();

            return (int) ($planet->buildings?->$labCol ?? 0);
        }

        return (int) Buildings::selectRaw('SUM(building_laboratory) AS total_level')
            ->join('planets', 'planet_id', '=', 'building_planet_id')
            ->where('planet_user_id', $user->id)
            ->orderBy('building_laboratory', 'DESC')
            ->limit($intergalLevel + 1)
            ->value('total_level');
    }

    /**
     * Start the item now at the head of the queue, paying for it from its planet. Queued items were only paid for
     * when they started and nothing checked the planet could afford it, so a queue outran the stock and left the
     * planet negative; the next resource update then clamped it to 0 and the debt vanished (Dale, 6 Oct 2026).
     * An item the planet can't pay for is dropped with a message, like the building queue does, and the rest of
     * the queue is re-timed from $startTime.
     */
    private function advanceQueue(User $user, int $startTime): void
    {
        while (true) {
            $item = $user->researchQueue()->orderBy('position')->first();

            if ($item === null) {
                $user->research->research_current_research = 0;
                $user->research->save();

                return;
            }

            $planet = Planets::find($item->planet_id);
            $level = $item->target_level - 1;

            if ($planet !== null && $this->developmentsService->isDevelopmentPayable(
                $this->developmentDataService->planetResources($planet),
                $item->tech_id,
                $level
            )) {
                $cost = $this->developmentsService->developmentPrice($item->tech_id, $level);
                $planet->planet_metal -= $cost['metal'] ?? 0;
                $planet->planet_crystal -= $cost['crystal'] ?? 0;
                $planet->planet_deuterium -= $cost['deuterium'] ?? 0;
                $planet->planet_b_tech_id = $item->tech_id;
                $planet->planet_b_tech = $item->end_time;
                $planet->save();

                $user->research->research_current_research = $item->planet_id;
                $user->research->save();

                return;
            }

            if ($planet !== null) {
                $this->sendInsufficientResourcesMessage($planet, $item);
            }

            $item->delete();
            $this->retimeAfterHeadRemoval($user, $item->tech_id, $startTime);
        }
    }

    private function retimeAfterHeadRemoval(User $user, int $removedTechId, int $startTime): void
    {
        $remaining = $user->researchQueue()->orderBy('position')->get();

        if ($remaining->isEmpty()) {
            return;
        }

        $astrophysicsCol = $this->registry->get(ResearchEnumerator::research_astrophysics)->getName();
        $astrophysicsLevel = (int) $user->research->$astrophysicsCol;
        $technocrateActive = $user->premium->premium_officier_technocrat > time();

        $this->rebuildQueueAfterHeadRemoval(
            $remaining,
            $removedTechId,
            $startTime,
            fn (ResearchQueue $queuedItem): int => $this->resolveDuration(
                $user,
                $queuedItem,
                $astrophysicsLevel,
                $technocrateActive
            )
        );

        foreach ($remaining as $queuedItem) {
            $queuedItem->save();
        }
    }

    private function sendInsufficientResourcesMessage(Planets $planet, ResearchQueue $item): void
    {
        $price = $this->developmentsService->developmentPrice($item->tech_id, $item->target_level - 1);
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

        $techName = __('game/technologies.' . $this->registry->get($item->tech_id)->getName());
        $galaxyUrl = 'game.php?page=galaxy&mode=3&galaxy=' . $planet->planet_galaxy . '&system=' . $planet->planet_system;
        $coordsText = $planet->planet_name . ' ' . $this->formatService->prettyCoords(
            $planet->planet_galaxy,
            $planet->planet_system,
            $planet->planet_planet
        );

        Functions::sendMessage(
            $planet->planet_user_id,
            0,
            0,
            5,
            __('game/research.re_queue_not_enough_resources_from'),
            __('game/research.re_queue_not_enough_resources_subject'),
            sprintf(
                __('game/research.re_queue_not_enough_resources'),
                $techName,
                $item->target_level,
                $this->formatService->link($galaxyUrl, $coordsText),
                implode(', ', $insufficient)
            ),
            true
        );
    }

    /**
     * @param  Collection<int, ResearchQueue>  $queue
     */
    private function rebuildQueueAfterHeadRemoval(
        Collection $queue,
        int $removedTechId,
        int $startTime,
        callable $durationResolver,
    ): void {
        $this->queueSequenceService->rebuildAfterHeadRemoval(
            $queue,
            $removedTechId,
            $startTime,
            static fn (ResearchQueue $item): int => $item->tech_id,
            static function (ResearchQueue $item): void {
                $item->target_level -= 1;
            },
            $durationResolver
        );
    }

    private function resolveDuration(
        User $user,
        ResearchQueue $item,
        int $astrophysicsLevel,
        bool $technocrateActive,
    ): int {
        $planet = Planets::with('buildings')->find($item->planet_id);

        if ($planet === null) {
            return $item->duration;
        }

        return $this->developmentsService->developmentTime(
            $item->tech_id,
            $item->target_level - 1,
            0,
            0,
            $this->labLevel($user, $planet),
            $astrophysicsLevel,
            $technocrateActive
        );
    }

    private function shiftPositionsDown(User $user): void
    {
        foreach ($user->researchQueue()->orderBy('position')->get() as $item) {
            $item->position -= 1;
            $item->save();
        }
    }
}

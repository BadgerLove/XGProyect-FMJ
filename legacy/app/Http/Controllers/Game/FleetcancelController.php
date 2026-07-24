<?php

declare(strict_types=1);

namespace Xgp\App\Http\Controllers\Game;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;
use Xgp\App\Libraries\Functions;
use Xgp\App\Libraries\Users;

/**
 * Handle fleet cancel/recall requests.
 *
 * When a player clicks "Cancel" on an outbound fleet, this controller
 * marks the fleet as returning. The fleet flies back to the origin,
 * taking the same duration as the outbound flight.
 */
class FleetcancelController extends BaseController
{
    use PreparesLegacySql;

    public function __invoke(): void
    {
        $user = Users::getInstance()->getUserData();
        $fleetId = (int) filter_input(INPUT_GET, 'fleetid', FILTER_VALIDATE_INT);

        if ($fleetId <= 0) {
            Functions::redirect('game.php?page=overview');
            return;
        }

        // Load the fleet
        $fleet = DB::selectOne(
            $this->prepareSql(
                'SELECT * FROM `' . FLEETS . '` WHERE `fleet_id` = \'' . $fleetId . '\''
            )
        );

        if (!$fleet) {
            Functions::redirect('game.php?page=overview');
            return;
        }

        $fleet = (array) $fleet;

        // Only the owner can cancel
        if ((int) $fleet['fleet_owner'] !== (int) $user['id']) {
            Functions::redirect('game.php?page=overview');
            return;
        }

        // Can only cancel outbound fleets (fleet_mess == 0 means outbound)
        if ((int) $fleet['fleet_mess'] !== 0) {
            Functions::redirect('game.php?page=overview');
            return;
        }

        $now = time();
        $startTime = (int) $fleet['fleet_start_time'];

        // Can only cancel before the fleet arrives at destination
        if ($startTime <= $now) {
            Functions::redirect('game.php?page=overview');
            return;
        }

        // Calculate how far the fleet has traveled
        // fleet_creation = when the fleet was dispatched
        // Time traveled = now - fleet_creation
        // Return flight takes the same time as already traveled
        $departedAt = (int) ($fleet['fleet_creation'] ?? $now);
        $timeTraveled = max(1, $now - $departedAt);

        $returnArrival = $now + $timeTraveled;

        // Mark fleet as returning: swap start/end, set return times
        DB::statement(
            $this->prepareSql(
                'UPDATE `' . FLEETS . '` SET
                    `fleet_mess` = 1,
                    `fleet_start_time` = \'' . $now . '\',
                    `fleet_end_time` = \'' . $returnArrival . '\',
                    `fleet_end_stay` = 0
                WHERE `fleet_id` = \'' . $fleetId . '\'
                AND `fleet_mess` = 0'
            )
        );

        Functions::redirect('game.php?page=overview');
    }
}

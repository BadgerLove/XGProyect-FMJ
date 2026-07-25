<?php

declare(strict_types=1);

namespace Xgp\App\Libraries\Missions;

use Illuminate\Support\Facades\DB;
use Xgp\App\Core\Concerns\PreparesLegacySql;

class Acs extends Missions
{
    use PreparesLegacySql;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * ACS Attack - joint attack between several players
     *
     * ACS member fleets (mission=2) arrive at the target and wait for the
     * Attack leader's fleet (mission=1) to trigger the actual battle via
     * the Attack mission handler, which combines all ACS group fleets.
     *
     * @param array $fleet Fleet row
     *
     * @return void
     */
    public function acsMission(array $fleet): void
    {
        // When the fleet arrives at the target and the battle hasn't fired yet...
        if (parent::canStartMission($fleet)) {
            // Check if the ACS group still exists (Attack handler deletes it when battle fires)
            $acsExists = $fleet['fleet_group'] > 0 ? (bool) DB::selectOne(
                $this->prepareSql(
                    'SELECT 1
                    FROM `' . ACS . "`
                    WHERE `acs_id` = '" . (int) $fleet['fleet_group'] . "'
                    LIMIT 1"
                )
            ) : false;

            if ($acsExists) {
                // ACS group still exists — the Attack leader hasn't fired yet.
                // The ACS member fleet holds position until the Attack handler
                // processes the group (which sets fleet_mess=1 for all members).
                // Do nothing — wait for the Attack leader.
                return;
            }

            // ACS group no longer exists (Attack handler already ran and deleted it,
            // or the group was disbanded by the leader). The fleet should now return.
            // The Attack handler already set fleet_mess=1, but if it didn't
            // (e.g., the leader cancelled before arrival), we mark it ourselves.
            if ($fleet['fleet_mess'] == 0) {
                parent::returnFleet($fleet['fleet_id']);
            }
        }

        // When the fleet finishes its return journey...
        if (parent::canCompleteMission($fleet)) {
            // Restore surviving ships (fleet_array was already updated by the
            // Attack handler with post-battle counts) and return home.
            parent::restoreFleet($fleet);
            parent::removeFleet($fleet['fleet_id']);
        }
    }
}
